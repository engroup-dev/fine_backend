<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CutterWorkOrderStatus;
use App\Enums\SaleComponentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\SalesRuleException;
use App\Models\ByproductYield;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderBlock;
use App\Models\CutterWorkOrderLine;
use App\Models\FoamBlockConsumption;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemStockLevel;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\SaleBundleComponent;
use App\Models\SaleComponentAllocation;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryAccounts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CutterWorkOrderService
{
    /**
     * A block whose remaining volume is within this fraction of the template is
     * treated as fully consumed — there is no usable offcut worth tracking.
     */
    private const FULL_CONSUMPTION_TOLERANCE = 0.02;

    /**
     * Lot volumes are kept to 4 decimals and template volumes to 6, so every
     * block, remnant and line in a volume comparison may be off by up to half
     * a unit in the 4th decimal. The tolerance scales with how many there are.
     */
    private const VOLUME_ROUNDING_TOLERANCE = 0.00005;

    /**
     * Reason on the zero-quantity revaluation movement that records a fill
     * lot re-priced by a later weigh-in or the completion (W4-06).
     */
    private const BYPRODUCT_REPRICE_REASON = 'cutter_byproduct_reprice';

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly MaterialResolutionService $materialResolution,
        private readonly DocumentNumberService $documentNumbers,
        private readonly StockIssueService $stockIssueService,
        private readonly BundleFulfillmentService $bundleFulfillment,
    ) {}

    /**
     * CUT-block-sale: create the order and, optionally, reserve its first
     * block. More blocks can be attached until cutting starts.
     */
    public function createWithBlock(array $orderAttrs, ?StockLot $block = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($orderAttrs, $block) {
            $order = CutterWorkOrder::create([
                ...$orderAttrs,
                'order_number' => $orderAttrs['order_number'] ?? $this->documentNumbers->next(DocumentNumberService::CUTTER_WORK_ORDER),
            ]);

            if ($order->output_warehouse_id !== null) {
                $this->assertOutputWarehouse($order, $order->output_warehouse_id);
            }

            if ($block !== null) {
                $this->reserveBlock($order, $block);
            }

            return $order->refresh();
        });
    }

    /**
     * Reserve one more foam block for the order. Its cost and size are
     * snapshotted so the price is locked in, the lot flips to `reserved`
     * (quantity kept — the cut has not happened), and its value moves into
     * the order's WIP. Allowed while the order is requested or confirmed.
     */
    public function attachBlock(CutterWorkOrder $order, StockLot $block): CutterWorkOrder
    {
        return DB::transaction(function () use ($order, $block): CutterWorkOrder {
            $lockedOrder = $this->lockForBlockChange($order);

            if ($lockedOrder->blocks()->where('stock_lot_id', $block->id)->exists()) {
                return $lockedOrder->fresh(['blocks.stockLot', 'lines', 'client']);
            }

            $this->reserveBlock($lockedOrder, $block);

            return $lockedOrder->fresh(['blocks.stockLot', 'lines', 'client']);
        });
    }

    /**
     * Release a reserved block before cutting starts. With one block attached
     * the block may be omitted.
     */
    public function detachBlock(CutterWorkOrder $order, ?string $stockLotId = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($order, $stockLotId): CutterWorkOrder {
            $lockedOrder = $this->lockForBlockChange($order);
            $blocks = $lockedOrder->blocks()->get();

            $attached = $stockLotId !== null
                ? $blocks->firstWhere('stock_lot_id', $stockLotId)
                : ($blocks->count() === 1 ? $blocks->first() : null);

            if ($attached === null) {
                if ($blocks->isEmpty() || $stockLotId !== null) {
                    return $lockedOrder->fresh(['blocks.stockLot', 'lines', 'client']);
                }

                throw new InvalidArgumentException('This order has several blocks; name the one to release.');
            }

            $this->releaseBlock($lockedOrder, $attached);

            return $lockedOrder->fresh(['blocks.stockLot', 'lines', 'client']);
        });
    }

    /**
     * A sale's bundle pieces the seller could not find on the shelf: they
     * join an open (requested) order of the cutter plant, or start a new
     * one. One order may carry pieces from several sales next to lines the
     * cutter added itself. Each line is sized from the piece and lands its
     * cut pieces straight on the sale when the order completes.
     *
     * @param  Collection<int, SaleBundleComponent>  $components
     */
    public function appendSaleComponents(OperatingUnit $cutterUnit, Collection $components, ?string $orderId = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($cutterUnit, $components, $orderId): CutterWorkOrder {
            if ($orderId !== null) {
                $order = CutterWorkOrder::withoutGlobalScopes()
                    ->where('operating_unit_id', $cutterUnit->id)
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null || $order->status !== CutterWorkOrderStatus::Requested) {
                    throw new SalesRuleException(
                        'Pieces can only join an open (requested) order of the cutter.',
                        'CUTTER_ORDER_NOT_OPEN',
                    );
                }
            } else {
                $order = CutterWorkOrder::create([
                    'operating_unit_id' => $cutterUnit->id,
                    'order_number' => $this->documentNumbers->next(DocumentNumberService::CUTTER_WORK_ORDER),
                    'status' => CutterWorkOrderStatus::Requested,
                    'notes' => 'Pieces for sale bundles',
                ]);
            }

            foreach ($components as $component) {
                $line = CutterWorkOrderLine::create([
                    'cutter_work_order_id' => $order->id,
                    'requested_spec' => $component->label(),
                    'quantity' => (int) ceil((float) $component->quantity),
                    // CUT-05: the piece is cut to the size the client bought.
                    'template_length_m' => $component->length_m,
                    'template_width_m' => $component->width_m,
                    'template_height_m' => $component->height_m,
                    'output_inventory_item_id' => $component->inventory_item_id,
                    'sale_bundle_component_id' => $component->id,
                ]);

                $component->cutter_work_order_line_id = $line->id;
                $component->status = SaleComponentStatus::AtCutter;
                $component->save();
            }

            return $order->fresh(['lines']);
        });
    }

    /**
     * The cutter's job sheet — no prices. Every line with its size, and the
     * sale and client it is cut for, so the same sheet the client took home
     * travels with the order.
     *
     * @return array<string, mixed>
     */
    public function jobSheet(CutterWorkOrder $order): array
    {
        $order->load([
            'operatingUnit:id,name',
            'blocks.stockLot:id,lot_number,length_m,width_m,height_m',
            'lines.outputItem:id,name,code',
            'lines.saleComponent.line.salesOrder' => fn ($q) => $q->withoutGlobalScopes(),
            'lines.saleComponent.line.salesOrder.client' => fn ($q) => $q->withoutGlobalScopes(),
            'lines.saleComponent.line.salesOrder.client.entity',
        ]);

        return [
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'date' => $order->created_at?->toIso8601String(),
            'cutter' => $order->operatingUnit?->name,
            'notes' => $order->notes,
            'blocks' => $order->blocks->map(fn (CutterWorkOrderBlock $b) => [
                'lot_number' => $b->stockLot?->lot_number,
                'length_m' => $b->length_m_snapshot !== null ? (float) $b->length_m_snapshot : null,
                'width_m' => $b->width_m_snapshot !== null ? (float) $b->width_m_snapshot : null,
                'height_m' => $b->height_m_snapshot !== null ? (float) $b->height_m_snapshot : null,
            ])->values(),
            'lines' => $order->lines->map(function (CutterWorkOrderLine $line) {
                $sale = $line->saleComponent?->line?->salesOrder;

                return [
                    'requested_spec' => $line->requested_spec,
                    'quantity' => (int) $line->quantity,
                    'length_m' => $line->template_length_m !== null ? (float) $line->template_length_m : null,
                    'width_m' => $line->template_width_m !== null ? (float) $line->template_width_m : null,
                    'height_m' => $line->template_height_m !== null ? (float) $line->template_height_m : null,
                    'output_item' => $line->outputItem?->name,
                    'output_sku' => $line->outputItem?->code,
                    'sale_number' => $sale?->order_number,
                    'bundle' => $line->saleComponent?->line?->description,
                    'client' => $sale?->client?->entity?->name,
                ];
            })->values(),
        ];
    }

    private function lockForBlockChange(CutterWorkOrder $order): CutterWorkOrder
    {
        $locked = CutterWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->status->acceptsBlockSelection()) {
            throw new InvalidStateTransitionException(
                "Blocks cannot be selected or changed while order {$locked->order_number} is {$locked->status->value}."
            );
        }

        return $locked;
    }

    private function reserveBlock(CutterWorkOrder $order, StockLot $block): CutterWorkOrderBlock
    {
        $locked = StockLot::whereKey($block->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'available') {
            throw new InvalidArgumentException(
                "Block {$locked->lot_number} is {$locked->status} and cannot be reserved."
            );
        }

        $locked->status = 'reserved';
        $locked->save();

        $unitCost = (float) $locked->unit_cost;

        $attached = CutterWorkOrderBlock::create([
            'cutter_work_order_id' => $order->id,
            'stock_lot_id' => $locked->id,
            'unit_cost_snapshot' => $unitCost,
            'length_m_snapshot' => $locked->length_m,
            'width_m_snapshot' => $locked->width_m,
            'height_m_snapshot' => $locked->height_m,
            'volume_m3_snapshot' => $locked->volume_m3,
        ]);

        $order->wip_cost = round((float) $order->wip_cost + $unitCost, 4);
        $order->save();

        if ($unitCost > 0) {
            $this->accountingService->postJournal(
                "Block {$locked->lot_number} reserved for cutter order {$order->order_number}",
                [
                    ['account_code' => '1122', 'debit' => $unitCost, 'operating_unit_id' => $order->operating_unit_id],
                    // INV-11: the block's value leaves its own account into WIP.
                    ['account_code' => InventoryAccounts::forLot($locked), 'credit' => $unitCost, 'operating_unit_id' => $order->operating_unit_id],
                ],
                'CutterWorkOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );
        }

        return $attached;
    }

    private function releaseBlock(CutterWorkOrder $order, CutterWorkOrderBlock $attached): void
    {
        $block = StockLot::whereKey($attached->stock_lot_id)->lockForUpdate()->first();

        if ($block !== null && $block->status === 'reserved') {
            $block->status = 'available';
            $block->save();
        }

        $cost = (float) $attached->unit_cost_snapshot;

        if ($cost > 0) {
            $this->accountingService->postJournal(
                "Reversal: Block reservation removed from cutter order {$order->order_number}",
                [
                    // INV-11: back into the block's own account.
                    ['account_code' => $block !== null ? InventoryAccounts::forLot($block) : InventoryAccounts::forItemType('foam_block'), 'debit' => $cost, 'operating_unit_id' => $order->operating_unit_id],
                    ['account_code' => '1122', 'credit' => $cost, 'operating_unit_id' => $order->operating_unit_id],
                ],
                'CutterWorkOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );
        }

        $attached->delete();

        $order->wip_cost = round(max(0, (float) $order->wip_cost - $cost), 4);
        $order->save();
    }

    /**
     * Blocks a cutter manager may choose from for a line.
     *
     * CUT-02: this filters and presents. It deliberately does not score, rank by
     * fit, or pick — the manager is standing in front of the blocks and knows
     * things the system does not (damage, position in the yard, what is easy to
     * reach). Auto-assignment would be guessing with authority.
     */
    public function availableBlocksFor(CutterWorkOrderLine $line, int $perPage = 25): LengthAwarePaginator
    {
        if (! $line->hasTemplate()) {
            throw new InvalidArgumentException(
                'Assign a template shape before selecting blocks; there is nothing to size the selection against.'
            );
        }

        return StockLot::query()
            ->with(['inventoryItem', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', fn (Builder $q) => $q->where('item_type', 'foam_block'))
            ->where('volume_m3', '>=', $line->requiredVolumeM3())
            ->whereDoesntHave('cutterConsumption')
            // Smallest adequate block first: cutting a large block for a small
            // template wastes the difference.
            ->orderBy('volume_m3')
            ->paginate($perPage);
    }

    /**
     * Inventory-wide list of available foam blocks for the create-order picker.
     * Doesn't require a line context (unlike availableBlocksFor).
     */
    public function availableFoamBlocks(int $perPage = 50): LengthAwarePaginator
    {
        return StockLot::query()
            ->with(['inventoryItem', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', fn (Builder $q) => $q->where('item_type', 'foam_block'))
            ->whereDoesntHave('cutterConsumption')
            ->orderBy('volume_m3')
            ->paginate($perPage);
    }

    /**
     * Record the manager's choice and move the block's value into the order.
     *
     * The whole block leaves stock either way. Its cost splits into the
     * template's share and the offcut's, and those two always sum back to the
     * block's full cost so nothing is left unaccounted between the block and
     * its outputs. Completion makes the final split by volume across pieces,
     * restocked remnants and byproduct (W4-06, see splitCost()).
     */
    public function selectBlock(CutterWorkOrderLine $line, StockLot $block): FoamBlockConsumption
    {
        return DB::transaction(function () use ($line, $block): FoamBlockConsumption {
            $order = $line->cutterWorkOrder;

            if (! $order->status->acceptsBlockSelection()) {
                throw new InvalidStateTransitionException(
                    "Blocks cannot be selected while order {$order->order_number} is {$order->status->value}."
                );
            }

            if (! $line->hasTemplate()) {
                throw new InvalidArgumentException('Assign a template shape before selecting blocks.');
            }

            $locked = StockLot::whereKey($block->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'available') {
                throw new InvalidArgumentException(
                    "Block {$locked->lot_number} is {$locked->status} and cannot be cut."
                );
            }

            $blockVolume = (float) $locked->volume_m3;
            $required = $line->requiredVolumeM3();

            if ($blockVolume < $required) {
                throw new InvalidArgumentException(
                    "Block {$locked->lot_number} holds {$blockVolume} m³, short of the {$required} m³ this line needs."
                );
            }

            $blockCost = (float) $locked->unit_cost;
            $isFull = ($blockVolume - $required) <= ($required * self::FULL_CONSUMPTION_TOLERANCE);

            // CUT-07 / CUT-08
            $consumedCost = $isFull ? $blockCost : round($blockCost * ($required / $blockVolume), 4);
            $remainderCost = round($blockCost - $consumedCost, 4);

            $consumption = FoamBlockConsumption::create([
                'cutter_work_order_line_id' => $line->id,
                'stock_lot_id' => $locked->id,
                'block_volume_m3' => $blockVolume,
                'volume_consumed_m3' => $required,
                'consumption_type' => $isFull ? 'full' : 'partial',
                'consumed_cost' => $consumedCost,
                'remainder_cost' => $remainderCost,
            ]);

            $locked->status = 'consumed';
            $locked->quantity = 0;
            $locked->save();

            // Movement 1 of 3 (CUT-09): the block leaves foam inventory.
            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $locked->id,
                'from_warehouse_id' => $locked->warehouse_id,
                'sku' => $locked->inventoryItem?->code ?? 'FOAM-BLOCK',
                'movement_type' => 'consumption',
                'quantity_delta' => -1,
                'unit_cost' => $blockCost,
                'reason' => 'cutter_consumption',
                'reference_document_type' => 'CutterWorkOrder',
                'reference_id' => $order->id,
            ]);

            // The block's whole value moves into the order, offcut included —
            // the offcut becomes byproduct rather than disappearing.
            $order->wip_cost = round((float) $order->wip_cost + $blockCost, 4);
            $order->save();

            $this->accountingService->postJournal(
                "Block {$locked->lot_number} into cutter order {$order->order_number}",
                [
                    ['account_code' => '1122', 'debit' => $blockCost, 'operating_unit_id' => $order->operating_unit_id],
                    // INV-11: the block's value leaves its own account into WIP.
                    ['account_code' => InventoryAccounts::forLot($locked), 'credit' => $blockCost, 'operating_unit_id' => $order->operating_unit_id],
                ],
                'CutterWorkOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );

            return $consumption;
        });
    }

    /**
     * CUT-03 / CUT-04: record the weigh-in.
     *
     * Zero is a valid, meaningful answer — it says someone looked and there was
     * nothing to salvage. A missing row says nobody checked, which is why the
     * order cannot move past this point without one.
     *
     * W4-06: anything weighed in becomes a fill lot (so a byproduct item is
     * required), in the named warehouse, else the order's output warehouse.
     * The byproduct carries the block cost of the volume the templates did
     * not take (C × S / V; remnants are only known at completion), shared by
     * weight across every weigh-in of the order. A later weigh-in re-shares
     * it, re-pricing the earlier fill lots (each through an appended
     * revaluation movement), so all the order's fill carries one price and a
     * completion without remnants never changes it. It is refused if an
     * earlier fill lot has already moved (BYPRODUCT_ALREADY_MOVED).
     */
    public function recordWeighIn(
        CutterWorkOrder $order,
        float $weightKg,
        ?string $byproductItemId,
        ?string $warehouseId,
        ?User $weighedBy = null,
    ): ByproductYield {
        if ($weightKg < 0) {
            throw new InvalidArgumentException('Byproduct weight cannot be negative.');
        }

        if ($order->status !== CutterWorkOrderStatus::AwaitingByproductWeighIn) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} is {$order->status->value}; a weigh-in belongs at the awaiting_byproduct_weigh_in step."
            );
        }

        if ($weightKg > 0 && $byproductItemId === null) {
            throw new SalesRuleException(
                "Choose the byproduct fill item the {$weightKg} kg weighed in on order {$order->order_number} is stocked as, or enter 0 if nothing was salvaged.",
                'BYPRODUCT_ITEM_REQUIRED',
            );
        }

        return DB::transaction(function () use ($order, $weightKg, $byproductItemId, $warehouseId, $weighedBy): ByproductYield {
            $locked = CutterWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CutterWorkOrderStatus::AwaitingByproductWeighIn) {
                throw new InvalidStateTransitionException(
                    "Order {$locked->order_number} is {$locked->status->value}; a weigh-in belongs at the awaiting_byproduct_weigh_in step."
                );
            }

            $lot = null;
            $yieldCost = 0.0;

            if ($weightKg > 0) {
                $lines = $locked->lines()->with('consumptions.stockLot')->get();
                $warehouseId ??= $this->outputWarehouseId($locked, $locked->blocks()->with('stockLot')->first(), $lines);

                $earlier = $this->salvagedYields($locked);
                $byproductCost = $this->splitCost($locked, $lines, [], true)['byproduct_cost'];
                $shares = $this->shareByWeight(
                    [...$earlier->map(fn (ByproductYield $y) => (float) $y->weight_kg)->all(), $weightKg],
                    $byproductCost,
                );
                $yieldCost = (float) array_pop($shares);

                $this->reshareEarlierWeighIns($locked, $earlier, $shares);

                $lot = StockLot::create([
                    'inventory_item_id' => $byproductItemId,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => 'BYP-'.$locked->order_number.'-'.now()->format('YmdHis'),
                    'quantity' => $weightKg,
                    'weight_kg' => $weightKg,
                    'unit_cost' => round($yieldCost / $weightKg, 4),
                    'status' => 'available',
                    'bin_location' => ItemStockLevel::binFor($byproductItemId, $warehouseId),
                ]);

                // Movement 2 of 3: byproduct fill enters cutter inventory.
                InventoryMovement::create([
                    'operating_unit_id' => $locked->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'to_warehouse_id' => $warehouseId,
                    'sku' => InventoryItem::find($byproductItemId)?->code ?? 'BYPRODUCT-FILL',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $weightKg,
                    'unit_cost' => $lot->unit_cost,
                    'reason' => 'cutter_byproduct',
                    'reference_document_type' => 'CutterWorkOrder',
                    'reference_id' => $locked->id,
                ]);
            }

            return ByproductYield::create([
                'cutter_work_order_id' => $locked->id,
                'stock_lot_id' => $lot?->id,
                'weight_kg' => $weightKg,
                // Nothing salvaged means the offcut was genuinely lost, so its
                // value stays in WIP and lands on the cut pieces instead.
                'yield_cost' => $yieldCost,
                'weighed_by_user_id' => $weighedBy?->id,
                'weighed_at' => now(),
            ]);
        });
    }

    /**
     * W4-06: a new weigh-in re-shares the byproduct cost, so each earlier one
     * takes its new weight share. An earlier fill lot whose price changes must
     * still hold its weigh-in untouched (else BYPRODUCT_ALREADY_MOVED, before
     * anything is written); it is re-priced through an appended revaluation
     * movement.
     *
     * @param  Collection<int, ByproductYield>  $earlier
     * @param  list<float>  $shares  each earlier yield's new share, in order
     */
    private function reshareEarlierWeighIns(CutterWorkOrder $order, Collection $earlier, array $shares): void
    {
        $reprices = [];

        foreach ($earlier->values() as $index => $yield) {
            $lot = $yield->stockLot;

            if ($lot === null) {
                continue;
            }

            $unitCost = round($shares[$index] / (float) $yield->weight_kg, 4);

            if ($unitCost === round((float) $lot->unit_cost, 4)) {
                continue;
            }

            if ($this->byproductLotMoved($lot, (float) $yield->weight_kg)) {
                throw new SalesRuleException(
                    "Byproduct lot {$lot->lot_number} of an earlier weigh-in on order {$order->order_number} has moved, so a further weigh-in cannot re-share the byproduct cost with it; record no further weigh-in, or bring the lot back to its weighed quantity first.",
                    'BYPRODUCT_ALREADY_MOVED',
                    ['stock_lot_id' => $lot->id],
                );
            }

            $reprices[$index] = $unitCost;
        }

        foreach ($earlier->values() as $index => $yield) {
            $yield->yield_cost = $shares[$index];
            $yield->save();

            if (isset($reprices[$index])) {
                $this->repriceFillLot($order, $yield->stockLot, $reprices[$index]);
            }
        }
    }

    /**
     * Remnants are the usable offcuts registered when the order completes
     * (W4-06); any other step takes none. Each may name the cut block it came
     * from (stock_lot_id), else it is the order's first block. The completion
     * may also set the order's output warehouse.
     *
     * @param  list<array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string, stock_lot_id?: string|null}>  $remnants
     */
    public function transition(CutterWorkOrder $order, CutterWorkOrderStatus $target, array $remnants = [], ?string $outputWarehouseId = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($order, $target, $remnants, $outputWarehouseId): CutterWorkOrder {
            $locked = CutterWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                $allowed = array_map(fn (CutterWorkOrderStatus $s) => $s->value, $current->allowedNext());

                throw new InvalidStateTransitionException(
                    $allowed === []
                        ? "Order {$locked->order_number} is invoiced and cannot change state."
                        : "Cannot move order {$locked->order_number} from {$current->value} to {$target->value}; expected ".implode(' or ', $allowed).'.'
                );
            }

            $this->guardTransition($locked, $target);

            if ($target === CutterWorkOrderStatus::Completed && $outputWarehouseId !== null) {
                $this->assertOutputWarehouse($locked, $outputWarehouseId);
                $locked->output_warehouse_id = $outputWarehouseId;
            }

            $locked->status = $target;
            $locked->save();

            // CUT-block-sale: the cut moment. Every reserved block is now
            // physically consumed (status flips to consumed, quantity zeroed)
            // and its FoamBlockConsumption record is created. Orders whose
            // blocks were picked per line (selectBlock) are already consumed.
            if ($target === CutterWorkOrderStatus::InProduction) {
                $this->consumeReservedBlocks($locked);
            }

            if ($target === CutterWorkOrderStatus::Completed) {
                $this->produceOutputs($locked, $remnants);
                $this->fulfillMaterialRequestsFor($locked);
            }

            return $locked->fresh(['lines', 'byproductYields']);
        });
    }

    /**
     * When this cutter order was created to fulfil a material request, mark
     * that request fulfilled. Only requests that name this order in
     * requested_for are touched — a completed order must never close other
     * units' or other orders' requests.
     */
    private function fulfillMaterialRequestsFor(CutterWorkOrder $order): void
    {
        $requests = MaterialRequest::query()
            ->whereIn('status', [MaterialRequest::STATUS_PENDING, MaterialRequest::STATUS_IN_PROGRESS])
            ->where('fulfilling_module', MaterialRequest::MODULE_CUTTER)
            ->where('requested_for_type', 'cutter_work_order')
            ->where('requested_for_id', $order->id)
            ->get();

        foreach ($requests as $request) {
            $this->materialResolution->markFulfilled($request, $order);
        }
    }

    /**
     * Flip every reserved block to consumed and record its consumption.
     * Called when the order advances to in_production. Idempotent: a block
     * no longer reserved is skipped. The consumption rows hang off the first
     * line — they record that the block went into this order.
     *
     * W4-06: each block's cost splits between the templates and the offcut by
     * the order's template volume over all its block volume (the same share
     * for every block), instead of the whole cost being booked as consumed.
     * Completion makes the final split once remnants are known.
     *
     * @return array<int, FoamBlockConsumption>
     */
    public function consumeReservedBlocks(CutterWorkOrder $order): array
    {
        $lines = $order->lines()->get();
        $firstLine = $lines->first();
        $consumptions = [];

        $attachedBlocks = $order->blocks()->with('stockLot')->get();
        $reserved = $attachedBlocks->filter(fn (CutterWorkOrderBlock $b) => $b->stockLot?->status === 'reserved');
        $templateVolume = (float) $lines->sum(fn (CutterWorkOrderLine $l) => $l->requiredVolumeM3());
        $blockVolume = (float) $reserved->sum(fn (CutterWorkOrderBlock $b) => $this->attachedVolume($b))
            + (float) $order->consumptions()->sum('block_volume_m3');
        $usedShare = $blockVolume > 0 ? min(1.0, $templateVolume / $blockVolume) : 1.0;
        $isFull = (1 - $usedShare) <= ($usedShare * self::FULL_CONSUMPTION_TOLERANCE);

        foreach ($attachedBlocks as $attached) {
            $locked = StockLot::whereKey($attached->stock_lot_id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'reserved') {
                continue;
            }

            $cost = (float) $attached->unit_cost_snapshot;
            $volume = $this->attachedVolume($attached);
            // CUT-07 / CUT-08
            $consumedCost = $isFull ? $cost : round($cost * $usedShare, 4);

            $consumptions[] = FoamBlockConsumption::create([
                'cutter_work_order_line_id' => $firstLine?->id,
                'stock_lot_id' => $locked->id,
                'block_volume_m3' => $volume,
                'volume_consumed_m3' => $isFull ? $volume : round($volume * $usedShare, 6),
                'consumption_type' => $isFull ? 'full' : 'partial',
                'consumed_cost' => $consumedCost,
                'remainder_cost' => round($cost - $consumedCost, 4),
            ]);

            $locked->status = 'consumed';
            $locked->quantity = 0;
            $locked->save();

            // Movement 1 of 3 (CUT-09): the block leaves foam inventory.
            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $locked->id,
                'from_warehouse_id' => $locked->warehouse_id,
                'sku' => $locked->inventoryItem?->code ?? 'FOAM-BLOCK',
                'movement_type' => 'consumption',
                'quantity_delta' => -1,
                'unit_cost' => (float) $attached->unit_cost_snapshot,
                'reason' => 'cutter_consumption',
                'reference_document_type' => 'CutterWorkOrder',
                'reference_id' => $order->id,
            ]);
        }

        return $consumptions;
    }

    /**
     * An attached block's volume: the lot's own, else the size snapshotted
     * when it was reserved.
     */
    private function attachedVolume(CutterWorkOrderBlock $attached): float
    {
        return (float) ($attached->stockLot?->volume_m3 ?? $attached->volume_m3_snapshot ?? 0);
    }

    private function guardTransition(CutterWorkOrder $order, CutterWorkOrderStatus $target): void
    {
        // CUT-01: production cannot start on a line nobody has sized.
        if ($target === CutterWorkOrderStatus::InProduction) {
            $lines = $order->lines()->get();

            if ($lines->isEmpty()) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has no lines to produce."
                );
            }

            $untemplated = $lines->filter(fn (CutterWorkOrderLine $l) => ! $l->hasTemplate());

            if ($untemplated->isNotEmpty()) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has {$untemplated->count()} line(s) with no template shape assigned."
                );
            }

            if ($order->blocks()->doesntExist() && $order->consumptions()->count() === 0) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has no foam block assigned; select a block to cut before starting production."
                );
            }
        }

        // A cut cannot be reported finished if no block was ever taken for it.
        if ($target === CutterWorkOrderStatus::AwaitingByproductWeighIn
            && $order->consumptions()->count() === 0) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} has no block consumption recorded; nothing was cut."
            );
        }

        // Every cut piece must land somewhere: a line without an output item
        // would carry cost into 1132 with no lot to show for it.
        if ($target === CutterWorkOrderStatus::Completed) {
            $homeless = $order->lines()->whereNull('output_inventory_item_id')->count();

            if ($homeless > 0) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has {$homeless} line(s) with no output item; choose what each line produces before completing."
                );
            }
        }

        // CUT-03: the hard gate. Without it the weigh-in is the step everyone
        // skips, and the byproduct silently disappears from the books.
        if ($target === CutterWorkOrderStatus::QualityCheck
            && $order->byproductYields()->count() === 0) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} cannot pass to quality check before the byproduct weigh-in is recorded. Enter 0 if there was none."
            );
        }
    }

    /**
     * W4-06: the block cost C consumed by the order (its WIP) splits by
     * volume. V is the volume of every block cut, P the template volume
     * (template × quantity of every line), R the volume of the usable
     * remnants restocked, and S = V − P − R the offcut left as byproduct:
     *
     *   pieces    C × P / V
     *   remnants  C × r / V each
     *   byproduct C × S / V, only when something was weighed in; a zero
     *             weigh-in means the offcut was lost, so its value lands on
     *             the pieces instead
     *
     * Rounding goes into the pieces, so the three always sum back to C. Blocks
     * with no recorded size (V = 0) put the whole cost on the pieces.
     *
     * @param  Collection<int, CutterWorkOrderLine>  $lines
     * @param  list<float>  $remnantVolumes
     * @return array{block_volume: float, piece_volume: float, remnant_volume: float, byproduct_volume: float, piece_cost: float, remnant_costs: list<float>, byproduct_cost: float}
     */
    private function splitCost(CutterWorkOrder $order, Collection $lines, array $remnantVolumes, bool $byproductSalvaged): array
    {
        $cost = round((float) $order->wip_cost, 4);
        $blockVolume = round((float) $order->consumptions()->sum('block_volume_m3'), 6);
        $pieceVolume = round((float) $lines->sum(fn (CutterWorkOrderLine $l) => $l->requiredVolumeM3()), 6);
        $remnantVolume = round(array_sum($remnantVolumes), 6);
        $byproductVolume = round(max(0.0, $blockVolume - $pieceVolume - $remnantVolume), 6);

        $remnantCosts = [];
        $byproductCost = 0.0;

        if ($blockVolume > 0) {
            foreach ($remnantVolumes as $volume) {
                $remnantCosts[] = round($cost * $volume / $blockVolume, 4);
            }

            if ($byproductSalvaged) {
                $byproductCost = round($cost * $byproductVolume / $blockVolume, 4);
            }
        }

        return [
            'block_volume' => $blockVolume,
            'piece_volume' => $pieceVolume,
            'remnant_volume' => $remnantVolume,
            'byproduct_volume' => $byproductVolume,
            'piece_cost' => round($cost - array_sum($remnantCosts) - $byproductCost, 4),
            'remnant_costs' => $remnantCosts,
            'byproduct_cost' => $byproductCost,
        ];
    }

    /**
     * A remnant's volume exactly as its lot will store it (dimensions kept to
     * 3 decimals, volume to 4), so its cost share matches the lot.
     *
     * @param  array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string}  $remnant
     */
    private function remnantVolume(array $remnant): float
    {
        return round(
            round((float) $remnant['length_m'], 3) * round((float) $remnant['width_m'], 3) * round((float) $remnant['height_m'], 3),
            4,
        );
    }

    /**
     * Where the order's outputs (cut pieces and restocked remnants) land: the
     * order's output warehouse, else the warehouse of its first block, else of
     * its first consumed block, else the unit's first warehouse.
     *
     * @param  Collection<int, CutterWorkOrderLine>  $lines
     */
    private function outputWarehouseId(CutterWorkOrder $order, ?CutterWorkOrderBlock $firstBlock, Collection $lines): string
    {
        return $order->output_warehouse_id
            ?? $firstBlock?->stockLot?->warehouse_id
            ?? $lines->flatMap->consumptions->first()?->stockLot?->warehouse_id
            ?? $this->stockIssueService->receivingWarehouse($order->operating_unit_id)->id;
    }

    /**
     * The output warehouse must be one of the order's own unit.
     */
    private function assertOutputWarehouse(CutterWorkOrder $order, string $warehouseId): void
    {
        $unitId = Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->value('operating_unit_id');

        if ($unitId === null || $unitId !== $order->operating_unit_id) {
            throw new SalesRuleException(
                "The output warehouse of order {$order->order_number} must belong to its own unit.",
                'OUTPUT_WAREHOUSE_OTHER_UNIT',
                ['warehouse_id' => $warehouseId],
            );
        }
    }

    /**
     * On completion the order's accumulated material moves out of WIP and is
     * split by volume (W4-06, splitCost()) into the cut pieces, the usable
     * remnants registered now (restocked as smaller blocks of the block's
     * item) and the byproduct weighed in. Every line gets its pieces; the
     * pieces' cost is shared by the volume each line cut (a 2 m seat carries
     * more foam than a cushion). Pieces cut for a sale land reserved and set
     * aside for it.
     *
     * Journal: DR 1132 pieces + DR 1131 remnants + DR 1133 byproduct / CR 1122.
     *
     * @param  list<array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string}>  $remnants
     */
    private function produceOutputs(CutterWorkOrder $order, array $remnants = []): void
    {
        $lines = $order->lines()->with(['consumptions.stockLot', 'saleComponent.line', 'outputItem'])->get();
        $totalPieces = (int) $lines->sum('quantity');

        if ($totalPieces === 0) {
            return;
        }

        $salvaged = $this->salvagedYields($order);

        $firstBlock = $order->blocks()->with('stockLot')->first();
        $consumptions = $lines->flatMap->consumptions->values();
        $remnants = $this->resolveRemnants($order, array_values($remnants), $consumptions, $firstBlock);
        $remnantVolumes = array_column($remnants, 'volume');

        $split = $this->splitCost($order, $lines, $remnantVolumes, $salvaged->isNotEmpty());

        // P + R cannot exceed the blocks actually cut: a remnant can only come
        // out of what the pieces left. With no remnant claimed nothing is
        // refused (pieces larger than the blocks just carry the whole cost),
        // so an order that got past the block step can always complete.
        if ($remnants !== []) {
            $room = max(0.0, $split['block_volume'] - $split['piece_volume']);
            $tolerance = self::VOLUME_ROUNDING_TOLERANCE * ($consumptions->count() + count($remnants) + $lines->count());

            if ($tolerance < $split['remnant_volume'] - $room) {
                $claimed = round($split['piece_volume'] + $split['remnant_volume'], 6);

                throw new SalesRuleException(
                    "Order {$order->order_number} claims {$claimed} m³ of pieces and remnants from {$split['block_volume']} m³ of blocks.",
                    'CUT_VOLUME_EXCEEDS_BLOCKS',
                    [
                        'block_volume_m3' => $split['block_volume'],
                        'piece_volume_m3' => $split['piece_volume'],
                        'remnant_volume_m3' => $split['remnant_volume'],
                    ],
                );
            }
        }

        // What the weigh-ins priced the fill at: the same split with no
        // remnant. Only remnants can change a fill price from here.
        $baseByproductCost = $remnants === []
            ? $split['byproduct_cost']
            : $this->splitCost($order, $lines, [], $salvaged->isNotEmpty())['byproduct_cost'];
        $byproduct = $this->resolveByproduct($order, $salvaged, $split['byproduct_cost'], $baseByproductCost, $remnants !== []);

        // Whatever of the byproduct share the fill does not carry (a legacy
        // weigh-in with no lot, or a moved lot kept at its weigh-in price)
        // stays on the pieces, so the outputs still sum to C.
        $pieceCost = round($split['piece_cost'] + $byproduct['to_pieces'], 4);

        if ($pieceCost < 0) {
            throw new SalesRuleException(
                "The byproduct of order {$order->order_number} has moved since its weigh-in at a price the completion can no longer carry; bring the fill lots back to their weighed quantity first.",
                'BYPRODUCT_ALREADY_MOVED',
                ['piece_cost' => $pieceCost],
            );
        }

        $warehouseId = $this->outputWarehouseId($order, $firstBlock, $lines);

        $totalVolume = (float) $lines->sum(fn (CutterWorkOrderLine $l) => $l->requiredVolumeM3());
        $allocated = 0.0;
        $created = 0;
        $touchedSales = [];
        $pieceCostByAccount = [];

        foreach ($lines as $line) {
            // INV-11: each piece enters its output item type's account — by
            // id, so an output item archived since the order keeps its type.
            $pieceAccount = InventoryAccounts::forItemId($line->output_inventory_item_id);

            $lineShare = $totalVolume > 0
                ? $pieceCost * ($line->requiredVolumeM3() / $totalVolume)
                : $pieceCost * ($line->quantity / $totalPieces);
            $costPerPiece = round($lineShare / $line->quantity, 4);
            $component = $line->saleComponent;
            // W4-02: pieces land on the output item's bin in that warehouse.
            $pieceBin = ItemStockLevel::binFor($line->output_inventory_item_id, $warehouseId);

            for ($i = 1; $i <= $line->quantity; $i++) {
                $created++;
                // The last piece absorbs the rounding remainder so the pieces
                // sum back to exactly the value taken out of WIP.
                $cost = $created === $totalPieces ? round($pieceCost - $allocated, 4) : $costPerPiece;
                $allocated = round($allocated + $cost, 4);
                $pieceCostByAccount[$pieceAccount] = round(($pieceCostByAccount[$pieceAccount] ?? 0) + $cost, 4);

                // CUT-05: the piece is stocked at its template dimensions, not
                // the shape the client described. It carries the first block
                // as its source for traceability.
                $piece = StockLot::create([
                    'inventory_item_id' => $line->output_inventory_item_id,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => sprintf('CUT-%s-%03d', $order->order_number, $created),
                    'quantity' => 1,
                    'length_m' => $line->template_length_m,
                    'width_m' => $line->template_width_m,
                    'height_m' => $line->template_height_m,
                    'unit_cost' => $cost,
                    'status' => $component !== null ? 'reserved' : 'available',
                    'source_stock_lot_id' => $firstBlock?->stock_lot_id,
                    'source_block_length_m' => $firstBlock?->length_m_snapshot,
                    'source_block_width_m' => $firstBlock?->width_m_snapshot,
                    'source_block_height_m' => $firstBlock?->height_m_snapshot,
                    'bin_location' => $pieceBin,
                ]);

                // Movement 3 of 3: the cut piece enters cutter inventory.
                InventoryMovement::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'stock_lot_id' => $piece->id,
                    'to_warehouse_id' => $warehouseId,
                    'sku' => $line->outputItem?->code ?? 'CUT-PIECE',
                    'movement_type' => 'production_output',
                    'quantity_delta' => 1,
                    'unit_cost' => $cost,
                    'reason' => 'cutter_output',
                    'reference_document_type' => 'CutterWorkOrder',
                    'reference_id' => $order->id,
                ]);

                if ($component !== null) {
                    SaleComponentAllocation::create([
                        'sale_bundle_component_id' => $component->id,
                        'stock_lot_id' => $piece->id,
                        'quantity' => 1,
                    ]);
                }
            }

            if ($component !== null && $component->status === SaleComponentStatus::AtCutter) {
                $component->status = SaleComponentStatus::Ready;
                $component->save();
                $touchedSales[$component->line->sales_order_id] = true;
            }
        }

        foreach (array_keys($touchedSales) as $saleId) {
            $sale = SalesOrder::withoutGlobalScopes()->find($saleId);

            if ($sale !== null) {
                $this->bundleFulfillment->refreshFulfillment($sale);
            }
        }

        $remnantCostByAccount = $this->restockRemnants($order, $remnants, $split['remnant_costs'], $warehouseId);
        $byproductByAccount = $this->priceByproduct($order, $byproduct['entries']);

        $journalLines = [];

        foreach ([$pieceCostByAccount, $remnantCostByAccount, $byproductByAccount] as $byAccount) {
            foreach ($byAccount as $account => $amount) {
                if ($amount > 0) {
                    $journalLines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
                }
            }
        }

        $total = round($pieceCost + array_sum($split['remnant_costs']) + array_sum(array_column($byproduct['entries'], 'value')), 4);

        if ($total <= 0) {
            return;
        }

        $journalLines[] = ['account_code' => '1122', 'credit' => $total, 'operating_unit_id' => $order->operating_unit_id];

        $this->accountingService->postJournal(
            "Cutter order {$order->order_number} completed",
            $journalLines,
            'CutterWorkOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );

        $order->wip_cost = 0;
        $order->save();
    }

    /**
     * W4-06: match each registered remnant with the cut block it came from:
     * the block it names (stock_lot_id, one of the blocks this order cut),
     * else the order's first block. A remnant must have a volume, its block
     * a recorded size, and the remnants of one block must fit inside it.
     *
     * @param  list<array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string, stock_lot_id?: string|null}>  $remnants
     * @param  Collection<int, FoamBlockConsumption>  $consumptions
     * @return list<array{dimensions: array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string}, volume: float, parent: StockLot}>
     */
    private function resolveRemnants(CutterWorkOrder $order, array $remnants, Collection $consumptions, ?CutterWorkOrderBlock $firstBlock): array
    {
        if ($remnants === []) {
            return [];
        }

        $byLot = $consumptions->filter(fn (FoamBlockConsumption $c) => $c->stockLot !== null)->keyBy('stock_lot_id');
        $defaultLotId = $firstBlock !== null && $byLot->has($firstBlock->stock_lot_id)
            ? $firstBlock->stock_lot_id
            : $byLot->keys()->first();

        $resolved = [];
        $volumeByLot = [];
        $countByLot = [];

        foreach ($remnants as $remnant) {
            $lotId = ($remnant['stock_lot_id'] ?? null) ?: $defaultLotId;
            $consumption = $lotId !== null ? $byLot->get($lotId) : null;

            if ($consumption === null) {
                throw new SalesRuleException(
                    $lotId === null
                        ? "Order {$order->order_number} has no cut block to restock remnants of."
                        : "Order {$order->order_number} did not cut the block a remnant names.",
                    'CUT_REMNANT_NO_SOURCE_BLOCK',
                    ['stock_lot_id' => $lotId],
                );
            }

            $blockVolume = (float) $consumption->block_volume_m3;

            if ($blockVolume <= 0) {
                throw new SalesRuleException(
                    "Block {$consumption->stockLot->lot_number} has no recorded size, so no remnant of it can be priced.",
                    'BLOCK_DIMENSIONS_REQUIRED',
                    ['stock_lot_id' => $lotId],
                );
            }

            $volume = $this->remnantVolume($remnant);

            if ($volume <= 0) {
                throw new SalesRuleException(
                    'A remnant must measure at least 0.0001 m³ to be restocked.',
                    'CUT_REMNANT_TOO_SMALL',
                    ['length_m' => $remnant['length_m'], 'width_m' => $remnant['width_m'], 'height_m' => $remnant['height_m']],
                );
            }

            $volumeByLot[$lotId] = round(($volumeByLot[$lotId] ?? 0) + $volume, 6);
            $countByLot[$lotId] = ($countByLot[$lotId] ?? 0) + 1;

            if (self::VOLUME_ROUNDING_TOLERANCE * ($countByLot[$lotId] + 1) < $volumeByLot[$lotId] - $blockVolume) {
                throw new SalesRuleException(
                    "Remnants of {$volumeByLot[$lotId]} m³ cannot come out of block {$consumption->stockLot->lot_number} of {$blockVolume} m³.",
                    'CUT_VOLUME_EXCEEDS_BLOCKS',
                    ['stock_lot_id' => $lotId, 'block_volume_m3' => $blockVolume, 'remnant_volume_m3' => $volumeByLot[$lotId]],
                );
            }

            $resolved[] = [
                'dimensions' => $remnant,
                'volume' => $volume,
                'parent' => $consumption->stockLot,
            ];
        }

        return $resolved;
    }

    /**
     * W4-06: each usable remnant goes back into stock as a smaller block of
     * the item of the block it came from, at its volume share of the block
     * cost, in the order's output warehouse. It is numbered after that block
     * (BLOCK-R1, -R2…) and keeps it as remnant_of_lot_id.
     *
     * @param  list<array{dimensions: array{length_m: float|int|string, width_m: float|int|string, height_m: float|int|string}, volume: float, parent: StockLot}>  $remnants
     * @param  list<float>  $remnantCosts
     * @return array<string, float> account code => value restocked
     */
    private function restockRemnants(CutterWorkOrder $order, array $remnants, array $remnantCosts, string $warehouseId): array
    {
        $byAccount = [];
        $revisions = [];

        foreach ($remnants as $index => $remnant) {
            $parent = $remnant['parent'];
            $dimensions = $remnant['dimensions'];
            $revisions[$parent->id] = ($revisions[$parent->id]
                ?? StockLot::withoutGlobalScopes()->withTrashed()->where('remnant_of_lot_id', $parent->id)->count()) + 1;
            $cost = $remnantCosts[$index] ?? 0.0;

            $lot = StockLot::create([
                'inventory_item_id' => $parent->inventory_item_id,
                'warehouse_id' => $warehouseId,
                'bin_location' => ItemStockLevel::binFor($parent->inventory_item_id, $warehouseId),
                'lot_number' => $parent->lot_number.'-R'.$revisions[$parent->id],
                'remnant_of_lot_id' => $parent->id,
                'pressure' => $parent->pressure,
                'grade' => $parent->grade,
                'production_batch_id' => $parent->production_batch_id,
                'quantity' => 1,
                'length_m' => (float) $dimensions['length_m'],
                'width_m' => (float) $dimensions['width_m'],
                'height_m' => (float) $dimensions['height_m'],
                'unit_cost' => $cost,
                'status' => 'available',
            ]);

            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $lot->id,
                'to_warehouse_id' => $warehouseId,
                'sku' => $parent->inventoryItem?->code ?? 'FOAM-BLOCK',
                'movement_type' => 'production_output',
                'quantity_delta' => 1,
                'unit_cost' => $cost,
                'reason' => 'cutter_remnant_restock',
                'reference_document_type' => 'CutterWorkOrder',
                'reference_id' => $order->id,
            ]);

            // INV-11: the remnant is a block again, in its item's account.
            $account = InventoryAccounts::forLot($lot);
            $byAccount[$account] = round(($byAccount[$account] ?? 0) + $cost, 4);
        }

        return $byAccount;
    }

    /**
     * The order's weigh-ins that salvaged something, in the order they were
     * weighed (the last absorbs the rounding of a weight share), with their
     * fill lots whatever their warehouse or deletion.
     *
     * @return Collection<int, ByproductYield>
     */
    private function salvagedYields(CutterWorkOrder $order): Collection
    {
        return $order->byproductYields()
            ->where('weight_kg', '>', 0)
            ->with(['stockLot' => fn ($q) => $q->withoutGlobalScopes()->with('inventoryItem')])
            ->orderBy('weighed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Share a cost by weight; the last share absorbs the rounding.
     *
     * @param  list<float>  $weights
     * @return list<float>
     */
    private function shareByWeight(array $weights, float $cost): array
    {
        $totalWeight = array_sum($weights);
        $allocated = 0.0;
        $shares = [];
        $last = count($weights) - 1;

        foreach ($weights as $index => $weight) {
            $share = $index === $last
                ? round($cost - $allocated, 4)
                : ($totalWeight > 0 ? round($cost * $weight / $totalWeight, 4) : 0.0);
            $allocated = round($allocated + $share, 4);
            $shares[] = $share;
        }

        return $shares;
    }

    /**
     * W4-06: the completion split is the byproduct's final price, shared by
     * weight across the weigh-ins exactly as they shared it (the weigh-ins
     * priced the fill with no remnant, so without remnants nothing changes).
     * Decided before anything is written:
     *
     *   - a fill lot already at its final price keeps it;
     *   - an untouched lot whose price changes is re-priced (an appended
     *     revaluation movement; existing movements are never edited);
     *   - a lot that moved since its weigh-in cannot be re-priced, since what
     *     left it was costed at the weigh-in price. If the remnants are what
     *     change its price the completion is refused (BYPRODUCT_ALREADY_MOVED;
     *     completing without remnants keeps the price). Any other difference
     *     (a lot weighed in before W4-06 priced the reserve-path fill at 0)
     *     keeps the lot's price and the difference stays on the pieces;
     *   - a positive weigh-in with no fill lot (only possible before W4-06)
     *     stocks nothing, so its share stays on the pieces, as with a zero
     *     weigh-in, rather than sitting in 1133 with no lot behind it.
     *
     * @param  Collection<int, ByproductYield>  $salvaged
     * @return array{entries: list<array{yield: ByproductYield, value: float, reprice: float|null}>, to_pieces: float}
     */
    private function resolveByproduct(CutterWorkOrder $order, Collection $salvaged, float $byproductCost, float $baseByproductCost, bool $hasRemnants): array
    {
        $weights = $salvaged->map(fn (ByproductYield $y) => (float) $y->weight_kg)->all();
        $finalShares = $this->shareByWeight($weights, $byproductCost);
        $baseShares = $this->shareByWeight($weights, $baseByproductCost);

        $entries = [];
        $toPieces = 0.0;

        foreach ($salvaged as $index => $yield) {
            $weight = (float) $yield->weight_kg;
            $share = $finalShares[$index];
            $lot = $yield->stockLot;

            if ($lot === null) {
                $entries[] = ['yield' => $yield, 'value' => 0.0, 'reprice' => null];
                $toPieces = round($toPieces + $share, 4);

                continue;
            }

            $unitCost = round($share / $weight, 4);
            $lotUnitCost = round((float) $lot->unit_cost, 4);

            if ($unitCost === $lotUnitCost) {
                $entries[] = ['yield' => $yield, 'value' => $share, 'reprice' => null];

                continue;
            }

            if (! $this->byproductLotMoved($lot, $weight)) {
                $entries[] = ['yield' => $yield, 'value' => $share, 'reprice' => $unitCost];

                continue;
            }

            if ($hasRemnants && round($baseShares[$index] / $weight, 4) === $lotUnitCost) {
                throw new SalesRuleException(
                    "Byproduct lot {$lot->lot_number} has moved since its weigh-in, so the remnants registered now cannot lower its price; complete the order without remnants, or bring the lot back to its weighed quantity first.",
                    'BYPRODUCT_ALREADY_MOVED',
                    ['stock_lot_id' => $lot->id],
                );
            }

            $kept = round($lotUnitCost * $weight, 4);
            $entries[] = ['yield' => $yield, 'value' => $kept, 'reprice' => null];
            $toPieces = round($toPieces + $share - $kept, 4);
        }

        return ['entries' => $entries, 'to_pieces' => $toPieces];
    }

    /**
     * A fill lot has moved when it no longer holds its weigh-in, is not
     * available, was split or transferred, or has any movement besides its
     * byproduct yield and this module's revaluations of it.
     */
    private function byproductLotMoved(StockLot $lot, float $weightKg): bool
    {
        return $lot->trashed()
            || $lot->status !== 'available'
            || abs((float) $lot->quantity - $weightKg) > 0.00005
            || InventoryMovement::where('stock_lot_id', $lot->id)
                ->where('movement_type', '!=', 'byproduct_yield')
                ->where(fn ($q) => $q->where('movement_type', '!=', 'adjustment')
                    ->orWhereNull('reason')
                    ->orWhere('reason', '!=', self::BYPRODUCT_REPRICE_REASON))
                ->exists()
            || StockLot::withoutGlobalScopes()->withTrashed()->where('source_stock_lot_id', $lot->id)->exists();
    }

    /**
     * Re-price an untouched fill lot. Movements are append-only, so the change
     * is recorded as a zero-quantity revaluation movement carrying the new
     * unit cost; the lot's byproduct-yield movement keeps its weigh-in price.
     */
    private function repriceFillLot(CutterWorkOrder $order, StockLot $lot, float $unitCost): void
    {
        $lot->unit_cost = $unitCost;
        $lot->save();

        InventoryMovement::create([
            'operating_unit_id' => $order->operating_unit_id,
            'stock_lot_id' => $lot->id,
            'to_warehouse_id' => $lot->warehouse_id,
            'sku' => $lot->inventoryItem?->code ?? 'BYPRODUCT-FILL',
            'movement_type' => 'adjustment',
            'quantity_delta' => 0,
            'unit_cost' => $unitCost,
            'reason' => self::BYPRODUCT_REPRICE_REASON,
            'reference_document_type' => 'CutterWorkOrder',
            'reference_id' => $order->id,
        ]);
    }

    /**
     * Book each weigh-in's final value on its yield (and re-price its fill
     * lot where resolveByproduct() decided so).
     *
     * @param  list<array{yield: ByproductYield, value: float, reprice: float|null}>  $entries
     * @return array<string, float> account code => byproduct value
     */
    private function priceByproduct(CutterWorkOrder $order, array $entries): array
    {
        $byAccount = [];

        foreach ($entries as $entry) {
            $yield = $entry['yield'];
            $yield->yield_cost = $entry['value'];
            $yield->save();

            $lot = $yield->stockLot;

            if ($lot === null) {
                continue;
            }

            if ($entry['reprice'] !== null) {
                $this->repriceFillLot($order, $lot, $entry['reprice']);
            }

            // INV-11: the fill lot's own account.
            $account = InventoryAccounts::forLot($lot);
            $byAccount[$account] = round(($byAccount[$account] ?? 0) + $entry['value'], 4);
        }

        return $byAccount;
    }
}
