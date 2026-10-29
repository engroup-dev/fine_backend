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
     * The whole block leaves stock either way (v1: no clean-remainder
     * restocking). Its cost splits into the template's share and the offcut's,
     * and those two always sum back to the block's full cost so nothing is left
     * unaccounted between the block and its outputs.
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

        return DB::transaction(function () use ($order, $weightKg, $byproductItemId, $warehouseId, $weighedBy): ByproductYield {
            // The offcut carries the part of the block cost the template did not.
            $remainderCost = round((float) $order->consumptions()->sum('remainder_cost'), 4);

            $lot = null;

            if ($weightKg > 0 && $byproductItemId !== null && $warehouseId !== null) {
                $lot = StockLot::create([
                    'inventory_item_id' => $byproductItemId,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => 'BYP-'.$order->order_number.'-'.now()->format('YmdHis'),
                    'quantity' => $weightKg,
                    'weight_kg' => $weightKg,
                    'unit_cost' => $weightKg > 0 ? round($remainderCost / $weightKg, 4) : 0,
                    'status' => 'available',
                    'bin_location' => ItemStockLevel::binFor($byproductItemId, $warehouseId),
                ]);

                // Movement 2 of 3: byproduct fill enters cutter inventory.
                InventoryMovement::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'to_warehouse_id' => $warehouseId,
                    'sku' => InventoryItem::find($byproductItemId)?->code ?? 'BYPRODUCT-FILL',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $weightKg,
                    'unit_cost' => $lot->unit_cost,
                    'reason' => 'cutter_byproduct',
                    'reference_document_type' => 'CutterWorkOrder',
                    'reference_id' => $order->id,
                ]);
            }

            return ByproductYield::create([
                'cutter_work_order_id' => $order->id,
                'stock_lot_id' => $lot?->id,
                'weight_kg' => $weightKg,
                // Nothing salvaged means the offcut was genuinely lost, so its
                // value stays in WIP and lands on the cut pieces instead.
                'yield_cost' => $weightKg > 0 ? $remainderCost : 0,
                'weighed_by_user_id' => $weighedBy?->id,
                'weighed_at' => now(),
            ]);
        });
    }

    public function transition(CutterWorkOrder $order, CutterWorkOrderStatus $target): CutterWorkOrder
    {
        return DB::transaction(function () use ($order, $target): CutterWorkOrder {
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
                $this->produceOutputs($locked);
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
     * @return array<int, FoamBlockConsumption>
     */
    public function consumeReservedBlocks(CutterWorkOrder $order): array
    {
        $firstLine = $order->lines()->first();
        $consumptions = [];

        foreach ($order->blocks()->get() as $attached) {
            $locked = StockLot::whereKey($attached->stock_lot_id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'reserved') {
                continue;
            }

            $consumptions[] = FoamBlockConsumption::create([
                'cutter_work_order_line_id' => $firstLine?->id,
                'stock_lot_id' => $locked->id,
                'block_volume_m3' => (float) $locked->volume_m3,
                'volume_consumed_m3' => (float) $locked->volume_m3,
                'consumption_type' => 'full',
                'consumed_cost' => (float) $attached->unit_cost_snapshot,
                'remainder_cost' => 0,
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
     * On completion the order's accumulated material moves out of WIP and into
     * the cut pieces, less whatever the byproduct already absorbed. Every line
     * gets its pieces; the cost is shared by the volume each line cut (a
     * 2 m seat carries more foam than a cushion). Pieces cut for a sale land
     * reserved and set aside for it.
     */
    private function produceOutputs(CutterWorkOrder $order): void
    {
        $byproductCost = round((float) $order->byproductYields()->sum('yield_cost'), 4);
        $pieceCost = round((float) $order->wip_cost - $byproductCost, 4);

        $lines = $order->lines()->with(['consumptions.stockLot', 'saleComponent.line', 'outputItem'])->get();
        $totalPieces = (int) $lines->sum('quantity');

        if ($totalPieces === 0) {
            return;
        }

        $firstBlock = $order->blocks()->with('stockLot')->first();
        $warehouseId = $firstBlock?->stockLot?->warehouse_id
            ?? $lines->flatMap->consumptions->first()?->stockLot?->warehouse_id
            ?? $this->stockIssueService->receivingWarehouse($order->operating_unit_id)->id;

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

        $journalLines = [];

        if ($pieceCost > 0) {
            foreach ($pieceCostByAccount as $account => $amount) {
                if ($amount > 0) {
                    $journalLines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
                }
            }
        }

        if ($byproductCost > 0) {
            // INV-11: the fill lot's own account; a yield with no lot keeps 1133.
            $byproductByAccount = [];
            foreach ($order->byproductYields()->with(['stockLot' => fn ($q) => $q->withoutGlobalScopes()->with('inventoryItem')])->get() as $yield) {
                $account = $yield->stockLot !== null
                    ? InventoryAccounts::forLot($yield->stockLot)
                    : InventoryAccounts::forItemType('byproduct_fill');
                $byproductByAccount[$account] = round(($byproductByAccount[$account] ?? 0) + (float) $yield->yield_cost, 4);
            }

            foreach ($byproductByAccount as $account => $amount) {
                if ($amount > 0) {
                    $journalLines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
                }
            }
        }

        $total = round($pieceCost + $byproductCost, 4);

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
}
