<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CutterWorkOrderStatus;
use App\Enums\SaleComponentStatus;
use App\Enums\SaleFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\SalesRuleException;
use App\Models\CutterWorkOrder;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\SaleBundleComponent;
use App\Models\SaleComponentAllocation;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Support\InventoryAccounts;
use App\Support\SalesAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A sold bundle after checkout: its pieces are defined (item, quantity,
 * L × W × H), set aside from stock or cut, and handed over. Revenue was booked
 * at checkout; cost of goods is booked here, piece by piece, as they leave.
 */
final class BundleFulfillmentService
{
    /** Two sizes within half a centimetre are the same piece. */
    private const SIZE_TOLERANCE_M = 0.005;

    /** What the cutter plant produces (InventoryAccounts: 1132). */
    private const CUTTABLE_TYPES = ['cut_template_piece', 'slice'];

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly StockIssueService $stockIssueService,
        private readonly SaleCheckoutService $checkoutService,
        private readonly OperatingUnitService $operatingUnitService,
    ) {}

    /**
     * Define (or redefine) what a sold bundle contains. Allowed while none of
     * its pieces has been set aside or sent to the cutter.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: float|int|string, length_m?: float|int|string|null, width_m?: float|int|string|null, height_m?: float|int|string|null, notes?: string|null}>  $rows
     */
    public function define(SalesOrderLine $line, array $rows): SalesOrderLine
    {
        if (! $line->isBundle()) {
            throw new SalesRuleException('Only a bundle line has pieces to define.', 'NOT_A_BUNDLE');
        }

        if ($rows === []) {
            throw new SalesRuleException('A bundle needs at least one piece.', 'EMPTY_BUNDLE');
        }

        return DB::transaction(function () use ($line, $rows): SalesOrderLine {
            $sale = $this->lockSoldSale($line->sales_order_id);

            if ($line->components()->where('status', '!=', SaleComponentStatus::Pending->value)->exists()) {
                throw new SalesRuleException(
                    'Some pieces are already set aside or at the cutter; release them before redefining the bundle.',
                    'DEFINITION_LOCKED',
                );
            }

            $line->components()->delete();

            ksort($rows);

            foreach (array_values($rows) as $position => $row) {
                $item = InventoryItem::findOrFail($row['inventory_item_id']);
                $quantity = (float) $row['quantity'];
                $size = $this->size($row);

                $line->components()->create([
                    'position' => $position,
                    'inventory_item_id' => $item->id,
                    'quantity' => $quantity,
                    'length_m' => $size[0] ?? null,
                    'width_m' => $size[1] ?? null,
                    'height_m' => $size[2] ?? null,
                    'reference_price' => $item->priceFor($quantity, ...($size ?? [null, null, null])),
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            $this->refreshFulfillment($sale);

            return $line->fresh(['components.inventoryItem']);
        });
    }

    /**
     * Available lots that can fill a piece: same item, same size (in any
     * orientation, within half a centimetre), in the selling unit or the
     * cutter plant that serves it.
     *
     * @return Collection<int, StockLot>
     */
    public function matchingStock(SaleBundleComponent $component): Collection
    {
        $unitIds = $this->sourceUnitIds($component->line->salesOrder);

        return StockLot::withoutGlobalScopes()
            ->with(['warehouse' => fn ($q) => $q->withoutGlobalScopes()->with('operatingUnit:id,name')])
            ->where('inventory_item_id', $component->inventory_item_id)
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()->whereIn('operating_unit_id', $unitIds))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $this->sizeMatches($component, $lot))
            ->values();
    }

    /**
     * Set lots aside for a piece. The lots must cover the whole quantity.
     * Part of a larger lot (6 m off a 50 m roll) is split into its own
     * reserved lot, so FIFO draws elsewhere can never take it.
     *
     * @param  array<int, array{stock_lot_id: string, quantity: float|int|string}>  $picks
     */
    public function reserve(SaleBundleComponent $component, array $picks): SaleBundleComponent
    {
        return DB::transaction(function () use ($component, $picks): SaleBundleComponent {
            $locked = SaleBundleComponent::with('line')->whereKey($component->getKey())->lockForUpdate()->firstOrFail();
            $sale = $this->lockSoldSale($locked->line->sales_order_id);

            if ($locked->status !== SaleComponentStatus::Pending) {
                throw new SalesRuleException('This piece is already set aside, at the cutter or delivered.', 'COMPONENT_NOT_PENDING');
            }

            $requested = round(array_sum(array_map(fn (array $p): float => (float) $p['quantity'], $picks)), 4);

            if (abs($requested - (float) $locked->quantity) > 0.0001) {
                throw new SalesRuleException(
                    "The chosen stock covers {$requested}; this piece needs {$locked->quantity}.",
                    'ALLOCATION_MISMATCH',
                );
            }

            $unitIds = $this->sourceUnitIds($sale);

            foreach ($picks as $pick) {
                $quantity = (float) $pick['quantity'];

                $lot = StockLot::withoutGlobalScopes()
                    ->whereKey($pick['stock_lot_id'])
                    ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()->whereIn('operating_unit_id', $unitIds))
                    ->lockForUpdate()
                    ->first();

                if ($lot === null || $lot->inventory_item_id !== $locked->inventory_item_id || $lot->status !== 'available') {
                    throw new SalesRuleException('A chosen lot is not available stock of this item here.', 'LOT_REJECTED');
                }

                if (! $this->sizeMatches($locked, $lot)) {
                    throw new SalesRuleException("Lot {$lot->lot_number} is not the size of this piece.", 'LOT_SIZE_MISMATCH');
                }

                if ((float) $lot->quantity < $quantity) {
                    throw new SalesRuleException("Lot {$lot->lot_number} holds only {$lot->quantity}.", 'LOT_REJECTED');
                }

                $reserved = $this->setAside($lot, $quantity, $sale);

                SaleComponentAllocation::create([
                    'sale_bundle_component_id' => $locked->id,
                    'stock_lot_id' => $reserved->id,
                    'quantity' => $quantity,
                ]);
            }

            $locked->status = SaleComponentStatus::Ready;
            $locked->save();

            $this->refreshFulfillment($sale);

            return $locked->fresh(['inventoryItem', 'allocations.stockLot']);
        });
    }

    /**
     * Undo a reservation: the lots go back on the shelf and the piece is
     * pending again.
     */
    public function release(SaleBundleComponent $component): SaleBundleComponent
    {
        return DB::transaction(function () use ($component): SaleBundleComponent {
            $locked = SaleBundleComponent::with('line')->whereKey($component->getKey())->lockForUpdate()->firstOrFail();
            $sale = $this->lockSoldSale($locked->line->sales_order_id);

            if ($locked->status !== SaleComponentStatus::Ready || $locked->cutter_work_order_line_id !== null) {
                throw new SalesRuleException('Only a piece set aside from stock can be released.', 'COMPONENT_NOT_RESERVED');
            }

            foreach ($locked->allocations as $allocation) {
                StockLot::withoutGlobalScopes()->whereKey($allocation->stock_lot_id)->lockForUpdate()
                    ->where('status', 'reserved')->update(['status' => 'available']);
                $allocation->delete();
            }

            $locked->status = SaleComponentStatus::Pending;
            $locked->save();

            $this->refreshFulfillment($sale);

            return $locked->fresh(['inventoryItem']);
        });
    }

    /**
     * Pieces the shelves cannot fill go to the cutter plant — the seller
     * itself when it is the cutter, else the company's cutter — joining an
     * open cutter order or starting one. Only cut pieces with a size can be
     * cut to order.
     *
     * @param  array<int, string>  $componentIds
     */
    public function sendToCutter(SalesOrder $sale, array $componentIds, ?string $cutterOrderId = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($sale, $componentIds, $cutterOrderId): CutterWorkOrder {
            $locked = $this->lockSoldSale($sale->id);

            $components = SaleBundleComponent::with(['inventoryItem', 'line'])
                ->whereHas('line', fn ($q) => $q->where('sales_order_id', $locked->id))
                ->whereKey($componentIds)
                ->lockForUpdate()
                ->get();

            if ($components->count() !== count(array_unique($componentIds))
                || $components->contains(fn (SaleBundleComponent $c) => $c->status !== SaleComponentStatus::Pending)) {
                throw new SalesRuleException('Only pending pieces of this sale can go to the cutter.', 'COMPONENT_NOT_PENDING');
            }

            foreach ($components as $component) {
                if (! in_array($component->inventoryItem?->item_type, self::CUTTABLE_TYPES, true)) {
                    throw new SalesRuleException(
                        "{$component->inventoryItem?->name} is not something the cutter makes; take it from stock.",
                        'NOT_A_CUT_PIECE',
                    );
                }

                if (! $component->hasSize()) {
                    throw new SalesRuleException(
                        "Give {$component->inventoryItem?->name} its length, width and height before it can be cut.",
                        'PIECE_SIZE_REQUIRED',
                    );
                }
            }

            $cutterUnit = $this->cutterUnitFor($locked);

            $order = app(CutterWorkOrderService::class)->appendSaleComponents($cutterUnit, $components, $cutterOrderId);

            $this->refreshFulfillment($locked);

            return $order;
        });
    }

    /**
     * Open (requested) orders at the cutter that serves this sale's unit —
     * pieces may join one instead of starting a new order.
     *
     * @return Collection<int, CutterWorkOrder>
     */
    public function openCutterOrdersFor(OperatingUnit $seller): Collection
    {
        $cutter = $this->operatingUnitService->cutterUnitFor($seller->loadMissing('blueprint'));

        if ($cutter === null) {
            return collect();
        }

        return CutterWorkOrder::withoutGlobalScopes()
            ->where('operating_unit_id', $cutter->id)
            ->where('status', CutterWorkOrderStatus::Requested->value)
            ->withCount('lines')
            ->latest()
            ->get(['id', 'order_number', 'status', 'operating_unit_id', 'created_at']);
    }

    /**
     * Hand ready pieces to the client: their reserved lots leave stock and
     * the cost of goods is booked against the sale (SALE-07). Pieces cut in
     * another unit leave that unit's inventory — the same cross-unit shape
     * as an internal transfer, collapsed into the sale.
     *
     * @param  array<int, string>|null  $componentIds  null = every ready piece
     */
    public function deliver(SalesOrder $sale, ?array $componentIds = null): SalesOrder
    {
        return DB::transaction(function () use ($sale, $componentIds): SalesOrder {
            $locked = $this->lockSoldSale($sale->id);

            $components = SaleBundleComponent::with(['allocations', 'line'])
                ->whereHas('line', fn ($q) => $q->where('sales_order_id', $locked->id))
                ->where('status', SaleComponentStatus::Ready->value)
                ->when($componentIds !== null, fn ($q) => $q->whereKey($componentIds))
                ->lockForUpdate()
                ->get();

            if ($components->isEmpty()) {
                throw new SalesRuleException('There are no ready pieces to deliver.', 'NOTHING_TO_DELIVER');
            }

            $creditsByUnitAccount = [];
            $totalCost = 0.0;

            foreach ($components as $component) {
                $componentCost = 0.0;

                foreach ($component->allocations as $allocation) {
                    [$cost, $lot] = $this->stockIssueService->consumeReserved(
                        $allocation->stock_lot_id,
                        (float) $allocation->quantity,
                        'SalesOrder',
                        $locked->id,
                        'bundle_delivery',
                    );

                    $allocation->update(['delivered_at' => now()]);

                    $componentCost += $cost;
                    $unitId = $lot->warehouse()->withoutGlobalScopes()->value('operating_unit_id');
                    $inventoryItem = $lot->inventoryItem;
                    if ($unitId === null || $inventoryItem === null) {
                        throw new SalesRuleException(
                            'Bundle component lot is missing its warehouse unit or inventory item.',
                            'BUNDLE_COMPONENT_LINK_MISSING',
                        );
                    }
                    // INV-11: the cost leaves through the lot's own account.
                    $account = InventoryAccounts::forItem($inventoryItem);
                    $key = $account.'|'.$unitId;
                    $creditsByUnitAccount[$key] = round(($creditsByUnitAccount[$key] ?? 0) + $cost, 4);
                }

                $component->unit_cost_actual = (float) $component->quantity > 0 ? round($componentCost / (float) $component->quantity, 4) : 0;
                $component->status = SaleComponentStatus::Delivered;
                $component->save();

                $totalCost += $componentCost;
            }

            $totalCost = round($totalCost, 4);

            if ($totalCost > 0) {
                $journalLines = [
                    ['account_code' => SalesAccounts::COGS, 'debit' => $totalCost, 'operating_unit_id' => $locked->operating_unit_id],
                ];

                foreach ($creditsByUnitAccount as $key => $amount) {
                    [$account, $unitId] = explode('|', $key);
                    $journalLines[] = ['account_code' => $account, 'credit' => $amount, 'operating_unit_id' => $unitId];
                }

                $this->accountingService->postJournal(
                    "Bundle delivery on sale {$locked->order_number}",
                    $journalLines,
                    'SalesOrder',
                    $locked->id,
                    $locked->operatingUnit?->company_id,
                );
            }

            $locked->total_cost = round((float) $locked->total_cost + $totalCost, 4);
            $locked->save();

            foreach ($components->pluck('line')->unique('id') as $line) {
                $lineCost = (float) $line->components()->sum(DB::raw('quantity * unit_cost_actual'));
                $line->unit_cost_actual = (float) $line->quantity > 0 ? round($lineCost / (float) $line->quantity, 4) : 0;
                $line->save();
            }

            $this->refreshFulfillment($locked);
            $this->checkoutService->refreshStatus($locked);

            return $locked->fresh(['lines.components.inventoryItem', 'lines.components.allocations.stockLot']);
        });
    }

    /**
     * The sale's goods progress, derived from its bundle pieces.
     */
    public function refreshFulfillment(SalesOrder $sale): void
    {
        $bundleLines = $sale->lines()->where('line_type', SalesOrderLine::TYPE_BUNDLE)->with('components')->get();

        if ($bundleLines->isEmpty()) {
            return;
        }

        $statuses = $bundleLines->flatMap(fn (SalesOrderLine $line) => $line->components->pluck('status'));

        $sale->fulfillment_status = match (true) {
            $bundleLines->contains(fn (SalesOrderLine $line) => $line->components->isEmpty()) => SaleFulfillmentStatus::AwaitingDefinition,
            $statuses->every(fn (SaleComponentStatus $s) => $s === SaleComponentStatus::Delivered) => SaleFulfillmentStatus::Delivered,
            $statuses->every(fn (SaleComponentStatus $s) => in_array($s, [SaleComponentStatus::Ready, SaleComponentStatus::Delivered], true)) => SaleFulfillmentStatus::Ready,
            default => SaleFulfillmentStatus::InProgress,
        };
        $sale->save();
    }

    /**
     * Receiving/delivery note — no prices. Item lines, and each bundle line
     * with its pieces, so the client can count what they take home and the
     * same sheet can travel with the order.
     *
     * @return array<string, mixed>
     */
    public function deliveryNote(SalesOrder $sale): array
    {
        $sale->loadMissing([
            'lines.inventoryItem', 'lines.stockLot', 'lines.components.inventoryItem',
            'client.entity.primaryContact', 'buyerUnit', 'operatingUnit',
        ]);

        return [
            'document_number' => 'DN-'.$sale->order_number,
            'sale_number' => $sale->order_number,
            'date' => $sale->created_at?->toIso8601String(),
            'seller' => $sale->operatingUnit?->name,
            'buyer' => $sale->client?->entity?->name ?? $sale->buyerUnit?->name,
            'buyer_phone' => $sale->client?->entity?->primaryContact?->phone,
            'fulfillment_status' => $sale->fulfillment_status?->value,
            'lines' => $sale->lines->map(fn (SalesOrderLine $line) => [
                'line_type' => $line->line_type,
                'description' => $line->description ?? $line->inventoryItem?->name,
                'sku' => $line->inventoryItem?->code,
                'quantity' => (float) $line->quantity,
                'length_m' => $line->length_m !== null ? (float) $line->length_m : null,
                'width_m' => $line->width_m !== null ? (float) $line->width_m : null,
                'height_m' => $line->height_m !== null ? (float) $line->height_m : null,
                'lot_number' => $line->stockLot?->lot_number,
                'pieces' => $line->components->map(fn (SaleBundleComponent $c) => [
                    'id' => $c->id,
                    'item' => $c->inventoryItem?->name,
                    'sku' => $c->inventoryItem?->code,
                    'quantity' => (float) $c->quantity,
                    'length_m' => $c->length_m !== null ? (float) $c->length_m : null,
                    'width_m' => $c->width_m !== null ? (float) $c->width_m : null,
                    'height_m' => $c->height_m !== null ? (float) $c->height_m : null,
                    'status' => $c->status->value,
                    'notes' => $c->notes,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * The units whose shelves may fill a piece: the seller, and the cutter
     * plant that serves it.
     *
     * @return array<int, string>
     */
    public function sourceUnitIds(SalesOrder $sale): array
    {
        $seller = OperatingUnit::with('blueprint')->findOrFail($sale->operating_unit_id);
        $cutter = $this->operatingUnitService->cutterUnitFor($seller);

        return array_values(array_unique(array_filter([$seller->id, $cutter?->id])));
    }

    private function cutterUnitFor(SalesOrder $sale): OperatingUnit
    {
        $seller = OperatingUnit::with('blueprint')->findOrFail($sale->operating_unit_id);
        $cutter = $this->operatingUnitService->cutterUnitFor($seller);

        if ($cutter === null) {
            throw new SalesRuleException('No single cutter unit is set up to cut these pieces.', 'NO_CUTTER_UNIT');
        }

        return $cutter;
    }

    private function setAside(StockLot $lot, float $quantity, SalesOrder $sale): StockLot
    {
        if (abs((float) $lot->quantity - $quantity) < 0.0001) {
            $lot->status = 'reserved';
            $lot->save();

            return $lot;
        }

        // Part of a lot: split it so the reserved part is its own lot.
        $lot->quantity = round((float) $lot->quantity - $quantity, 4);
        $lot->save();

        $splits = StockLot::withoutGlobalScopes()->where('source_stock_lot_id', $lot->id)->count();
        $reserved = StockLot::create([
            'inventory_item_id' => $lot->inventory_item_id,
            'warehouse_id' => $lot->warehouse_id,
            'lot_number' => $lot->lot_number.'-R'.($splits + 1),
            'quantity' => $quantity,
            'unit_cost' => $lot->unit_cost,
            'length_m' => $lot->length_m,
            'width_m' => $lot->width_m,
            'height_m' => $lot->height_m,
            'grade' => $lot->grade ?? 'standard',
            'source_stock_lot_id' => $lot->id,
            'status' => 'reserved',
        ]);

        $unitId = $lot->warehouse()->withoutGlobalScopes()->value('operating_unit_id');
        $sku = $lot->inventoryItem?->code ?? 'ITEM';

        foreach ([[$lot, -$quantity, 'from_warehouse_id'], [$reserved, $quantity, 'to_warehouse_id']] as [$movedLot, $delta, $side]) {
            InventoryMovement::create([
                'operating_unit_id' => $unitId,
                'stock_lot_id' => $movedLot->id,
                $side => $lot->warehouse_id,
                'sku' => $sku,
                'movement_type' => 'transfer',
                'quantity_delta' => $delta,
                'unit_cost' => (float) $lot->unit_cost,
                'reason' => 'reserve_for_sale',
                'reference_document_type' => 'SalesOrder',
                'reference_id' => $sale->id,
            ]);
        }

        return $reserved;
    }

    private function lockSoldSale(string $saleId): SalesOrder
    {
        $sale = SalesOrder::whereKey($saleId)->lockForUpdate()->firstOrFail();

        if (! in_array($sale->status, [SalesOrderStatus::Open, SalesOrderStatus::Completed], true)) {
            throw new SalesRuleException(
                "Sale {$sale->order_number} is {$sale->status->value}; its pieces cannot move.",
                'SALE_NOT_ACTIVE',
            );
        }

        return $sale;
    }

    private function sizeMatches(SaleBundleComponent $component, StockLot $lot): bool
    {
        if (! $component->hasSize()) {
            return true;
        }

        if ($lot->length_m === null || $lot->width_m === null || $lot->height_m === null) {
            return false;
        }

        $want = [(float) $component->length_m, (float) $component->width_m, (float) $component->height_m];
        $have = [(float) $lot->length_m, (float) $lot->width_m, (float) $lot->height_m];
        sort($want);
        sort($have);

        foreach ($want as $i => $dimension) {
            if (abs($dimension - $have[$i]) > self::SIZE_TOLERANCE_M) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function size(array $row): ?array
    {
        if (empty($row['length_m']) || empty($row['width_m']) || empty($row['height_m'])) {
            return null;
        }

        return [(float) $row['length_m'], (float) $row['width_m'], (float) $row['height_m']];
    }
}
