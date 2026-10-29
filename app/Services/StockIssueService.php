<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientComponentStockException;
use App\Exceptions\SalesRuleException;
use App\Models\InventoryMovement;
use App\Models\ItemStockLevel;
use App\Models\StockLot;
use App\Models\Warehouse;

/**
 * Stock leaving a unit for a sale or a transfer, and landing in another unit.
 * Every draw writes its INV-06 movement; the caller owns the transaction and
 * the journal. Cost is always the cost of the lots actually drawn.
 *
 * A "portion" is one slice of one lot: ['lot' => StockLot, 'quantity' => float,
 * 'unit_cost' => float]. Landing goods lot-by-lot keeps each piece's own size
 * and cost — a cut piece arrives in the showroom with its L × W × H.
 */
final class StockIssueService
{
    /**
     * Issue a quantity of an item from a unit: the given lot, or FIFO.
     *
     * @return array{0: float, 1: array<int, array{lot: StockLot, quantity: float, unit_cost: float}>}
     */
    public function issue(
        string $itemId,
        float $quantity,
        string $unitId,
        ?string $lotId,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        if ($lotId !== null) {
            return $this->drawSpecificLot($lotId, $itemId, $quantity, $unitId, $referenceType, $referenceId, $reason.'_specific_lot');
        }

        return $this->drawFromUnit($itemId, $quantity, $unitId, $referenceType, $referenceId, $reason);
    }

    /**
     * FIFO draw of one item from one unit's stock, movement included.
     *
     * @return array{0: float, 1: array<int, array{lot: StockLot, quantity: float, unit_cost: float}>}
     */
    public function drawFromUnit(
        string $itemId,
        float $quantity,
        string $unitId,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        // withoutGlobalScopes on both levels: the caller's unit context must not
        // leak into the warehouse subquery, or a restock could never see the
        // *source* unit's stock.
        $lots = StockLot::withoutGlobalScopes()
            ->where('inventory_item_id', $itemId)
            ->where('status', 'available')
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()->where('operating_unit_id', $unitId))
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $available = (float) $lots->sum('quantity');

        if ($available < $quantity) {
            $sku = $lots->first()?->inventoryItem?->code ?? $itemId;

            throw new InsufficientComponentStockException(
                "Stock cannot cover the sale — {$sku}: need {$quantity}, have {$available}."
            );
        }

        $remaining = $quantity;
        $cost = 0.0;
        $portions = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->quantity, $remaining);
            $cost += $take * (float) $lot->unit_cost;
            $remaining = round($remaining - $take, 4);

            $this->takeFromLot($lot, $take, $unitId, $referenceType, $referenceId, $reason);
            $portions[] = ['lot' => $lot, 'quantity' => $take, 'unit_cost' => (float) $lot->unit_cost];
        }

        return [round($cost, 4), $portions];
    }

    /**
     * Draw a specific lot. The caller must point at a real, available lot in
     * the unit that actually carries the line's inventory item — the same
     * validation as the cut-block pick (CutterWorkOrderService::selectBlock).
     *
     * @return array{0: float, 1: array<int, array{lot: StockLot, quantity: float, unit_cost: float}>}
     */
    public function drawSpecificLot(
        string $lotId,
        string $itemId,
        float $quantity,
        string $unitId,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        $lot = StockLot::withoutGlobalScopes()
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()->where('operating_unit_id', $unitId))
            ->whereKey($lotId)
            ->lockForUpdate()
            ->first();

        if ($lot === null) {
            throw new SalesRuleException("Selected stock lot is not in this operating unit's stock.", 'LOT_REJECTED');
        }

        if ($lot->inventory_item_id !== $itemId) {
            throw new SalesRuleException('Selected stock lot does not carry the inventory item on this line.', 'LOT_REJECTED');
        }

        if ($lot->status !== 'available') {
            throw new SalesRuleException("Selected stock lot {$lot->lot_number} is not available ({$lot->status}).", 'LOT_REJECTED');
        }

        if ((float) $lot->quantity < $quantity) {
            throw new InsufficientComponentStockException(
                "Selected stock lot {$lot->lot_number} cannot cover {$quantity}; it has {$lot->quantity}."
            );
        }

        $this->takeFromLot($lot, $quantity, $unitId, $referenceType, $referenceId, $reason);

        return [round($quantity * (float) $lot->unit_cost, 4), [['lot' => $lot, 'quantity' => $quantity, 'unit_cost' => (float) $lot->unit_cost]]];
    }

    /**
     * Consume a lot already set aside (status reserved) for this document —
     * a bundle piece taken from stock or cut to order, handed over now.
     *
     * @return array{0: float, 1: StockLot}
     */
    public function consumeReserved(
        string $lotId,
        float $quantity,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        $lot = StockLot::withoutGlobalScopes()->with('warehouse')->whereKey($lotId)->lockForUpdate()->firstOrFail();

        if ($lot->status !== 'reserved') {
            throw new SalesRuleException("Stock lot {$lot->lot_number} is not reserved ({$lot->status}).", 'LOT_REJECTED');
        }

        $unitId = $lot->warehouse()->withoutGlobalScopes()->value('operating_unit_id');
        $this->takeFromLot($lot, $quantity, $unitId, $referenceType, $referenceId, $reason);

        return [round($quantity * (float) $lot->unit_cost, 4), $lot];
    }

    /**
     * Land drawn portions in another unit at the cost they left with, one new
     * lot per portion so size and grade travel with the goods. The prefix must
     * be unique per document line (e.g. TRF-S-2026-00001-L2); portions are
     * numbered after it. Each lot takes the item's bin in the receiving
     * warehouse (W4-02).
     *
     * @param  array<int, array{lot: StockLot, quantity: float, unit_cost: float}>  $portions
     * @return array<int, StockLot>
     */
    public function land(
        array $portions,
        string $unitId,
        ?string $warehouseId,
        string $lotPrefix,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        $warehouse = $this->receivingWarehouse($unitId, $warehouseId);
        $landed = [];

        foreach ($portions as $index => $portion) {
            $source = $portion['lot'];

            $received = StockLot::create([
                'inventory_item_id' => $source->inventory_item_id,
                'warehouse_id' => $warehouse->id,
                'bin_location' => ItemStockLevel::binFor($source->inventory_item_id, $warehouse->id),
                'lot_number' => $lotPrefix.'-'.($index + 1),
                'quantity' => $portion['quantity'],
                'unit_cost' => $portion['unit_cost'],
                'length_m' => $source->length_m,
                'width_m' => $source->width_m,
                'height_m' => $source->height_m,
                'grade' => $source->grade ?? 'standard',
                'source_stock_lot_id' => $source->id,
                'status' => 'available',
            ]);

            InventoryMovement::create([
                'operating_unit_id' => $unitId,
                'stock_lot_id' => $received->id,
                'to_warehouse_id' => $warehouse->id,
                'sku' => $source->inventoryItem?->code ?? 'ITEM',
                'movement_type' => 'transfer',
                'quantity_delta' => $portion['quantity'],
                'unit_cost' => $portion['unit_cost'],
                'reason' => $reason,
                'reference_document_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $landed[] = $received;
        }

        return $landed;
    }

    /**
     * The warehouse goods arrive in: the one asked for (it must belong to the
     * unit), else the unit's first warehouse.
     */
    public function receivingWarehouse(string $unitId, ?string $warehouseId = null): Warehouse
    {
        $query = Warehouse::withoutGlobalScopes()->where('operating_unit_id', $unitId);

        $warehouse = $warehouseId !== null
            ? $query->whereKey($warehouseId)->first()
            : $query->orderBy('created_at')->first();

        if ($warehouse === null) {
            throw new SalesRuleException('The receiving unit has no warehouse to receive stock.', 'NO_RECEIVING_WAREHOUSE');
        }

        return $warehouse;
    }

    private function takeFromLot(
        StockLot $lot,
        float $quantity,
        string $unitId,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): void {
        $lot->quantity = round((float) $lot->quantity - $quantity, 4);

        if ((float) $lot->quantity <= 0) {
            $lot->quantity = 0;
            $lot->status = 'consumed';
        }

        $lot->save();

        InventoryMovement::create([
            'operating_unit_id' => $unitId,
            'stock_lot_id' => $lot->id,
            'from_warehouse_id' => $lot->warehouse_id,
            'sku' => $lot->inventoryItem?->code ?? 'ITEM',
            'movement_type' => 'sale',
            'quantity_delta' => -$quantity,
            'unit_cost' => (float) $lot->unit_cost,
            'reason' => $reason,
            'reference_document_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
