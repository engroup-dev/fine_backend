<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionBatchStatus;
use App\Models\StockLot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * W4-04: stagnant stock — lots holding stock that have not really moved for a
 * number of days.
 *
 * A lot's last real movement is its newest inventory movement other than a
 * warehouse transfer or a reservation split (both are `transfer` movements;
 * the split carries the reason `reserve_for_sale`), since neither consumes,
 * sells or produces anything. A lot with no real movement falls back to its
 * `received_at`, then `created_at`. A split lot (`-XFER`, `-R`) has no real
 * movement of its own at first, so its `received_at` is set at split time to
 * the parent's idle-since (see idleSince()): the split neither refreshes nor
 * ages the goods.
 *
 * Value is the lot's own qty × unit_cost: production_output movements record
 * cost at registration (often 0), so movement costs are never used here.
 * Lots counted: quantity > 0 and status available, reserved (set aside for a
 * sale that may never be delivered) or quarantined; each row carries its
 * status so the page can label or filter it. This is wider than W4-02's
 * on-hand (available only) on purpose: goods held back still sit idle and
 * hold value. Left out are consumed lots, blocks attached to a cutter work
 * order, which are in production (their value sits in 1122), and blocks of a
 * production batch that is not closed yet, which are work in process with no
 * value posted (the same rules as the INV-11 reconciliation and stock
 * counts). Scoped to the caller's unit through the lot's warehouse.
 */
final class StagnantStockService
{
    public const DEFAULT_DAYS = 90;

    /** Warehouse transfers and reservation splits: not real movement. */
    private const SPLIT_MOVEMENT_TYPE = 'transfer';

    // Redundant while setAside() uses the transfer type; guards in case it stops.
    private const RESERVATION_SPLIT_REASON = 'reserve_for_sale';

    /** Lot statuses that still hold stock in a warehouse. */
    public const COUNTED_STATUSES = ['available', 'reserved', 'quarantined'];

    /**
     * When a lot last really moved, by the same rule as the report: its newest
     * movement other than a transfer or reservation split, else received_at,
     * else created_at. A split passes this to its new lot as `received_at`, so
     * the new lot is exactly as idle as its parent was.
     */
    public static function idleSince(StockLot $lot): CarbonInterface
    {
        $lastMovement = DB::table('inventory_movements')
            ->where('stock_lot_id', $lot->id)
            ->where('movement_type', '<>', self::SPLIT_MOVEMENT_TYPE)
            ->where('reason', '<>', self::RESERVATION_SPLIT_REASON)
            ->max('created_at');

        if ($lastMovement !== null) {
            return CarbonImmutable::parse((string) $lastMovement);
        }

        return $lot->received_at ?? $lot->created_at ?? CarbonImmutable::now();
    }

    /**
     * @param  array{days?: int|string|null, warehouse_id?: string|null, category_id?: string|null}  $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     summary: array{days: int, cutoff: string, lots: int, total_value: float},
     * }
     */
    public function report(array $filters = []): array
    {
        $days = filled($filters['days'] ?? null) ? (int) $filters['days'] : self::DEFAULT_DAYS;
        $now = CarbonImmutable::now();
        $cutoff = $now->subDays($days);

        // Correlated per lot so the (stock_lot_id, created_at) index serves it
        // and the cost follows the lots in scope, not all movement history.
        $lastMovement = '(SELECT MAX(im.created_at) FROM inventory_movements im'
            .' WHERE im.stock_lot_id = stock_lots.id'
            ." AND im.movement_type <> '".self::SPLIT_MOVEMENT_TYPE."'"
            ." AND im.reason <> '".self::RESERVATION_SPLIT_REASON."')";
        $idleSince = "COALESCE({$lastMovement}, stock_lots.received_at, stock_lots.created_at)";

        $query = StockLot::query()
            ->join('warehouses', 'warehouses.id', '=', 'stock_lots.warehouse_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_lots.inventory_item_id')
            ->whereNull('inventory_items.deleted_at')
            ->whereIn('stock_lots.status', self::COUNTED_STATUSES)
            ->where('stock_lots.quantity', '>', 0)
            // A block attached to a cutter work order is in production, not
            // idle stock: its value already moved to 1122 (WIP — cutter).
            ->whereNotExists(fn (QueryBuilder $blocks) => $blocks
                ->selectRaw('1')
                ->from('cutter_work_order_blocks')
                ->whereColumn('cutter_work_order_blocks.stock_lot_id', 'stock_lots.id'))
            // Blocks of a foam batch that is not closed: work in process with
            // no value posted yet (as INV-11 and stock counts treat them).
            ->whereNotExists(fn (QueryBuilder $batches) => $batches
                ->selectRaw('1')
                ->from('production_batches')
                ->whereColumn('production_batches.id', 'stock_lots.production_batch_id')
                ->where('production_batches.status', '!=', ProductionBatchStatus::Closed->value))
            ->whereRaw("{$idleSince} < ?", [$cutoff->toDateTimeString()])
            ->select('stock_lots.*')
            ->addSelect([
                'warehouses.name as warehouse_name',
                'warehouses.operating_unit_id as operating_unit_id',
                'inventory_items.code as item_code',
                'inventory_items.name as item_name',
                'inventory_items.unit_of_measure as unit_of_measure',
                'inventory_items.category_id as category_id',
            ])
            ->selectRaw("{$lastMovement} as last_movement_at")
            ->selectRaw("{$idleSince} as idle_since")
            ->orderBy('idle_since')
            ->orderBy('stock_lots.lot_number');

        if (filled($filters['warehouse_id'] ?? null)) {
            $query->where('stock_lots.warehouse_id', $filters['warehouse_id']);
        }

        if (filled($filters['category_id'] ?? null)) {
            $query->where('inventory_items.category_id', $filters['category_id']);
        }

        $rows = $query->get()->map(function (StockLot $lot) use ($now): array {
            $quantity = round((float) $lot->quantity, 4);
            $unitCost = round((float) $lot->unit_cost, 4);
            $idleSince = CarbonImmutable::parse((string) $lot->getAttribute('idle_since'));
            $lastMovementAt = $lot->getAttribute('last_movement_at');

            return [
                'stock_lot_id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'inventory_item_id' => $lot->inventory_item_id,
                'item_code' => $lot->getAttribute('item_code'),
                'item_name' => $lot->getAttribute('item_name'),
                'unit_of_measure' => $lot->getAttribute('unit_of_measure'),
                'category_id' => $lot->getAttribute('category_id'),
                'warehouse_id' => $lot->warehouse_id,
                'warehouse_name' => $lot->getAttribute('warehouse_name'),
                'operating_unit_id' => $lot->getAttribute('operating_unit_id'),
                'bin_location' => $lot->bin_location,
                'status' => $lot->status,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'value' => round($quantity * $unitCost, 2),
                'received_at' => $lot->received_at?->toIso8601String(),
                'last_movement_at' => $lastMovementAt !== null
                    ? CarbonImmutable::parse((string) $lastMovementAt)->toIso8601String()
                    : null,
                'idle_since' => $idleSince->toIso8601String(),
                'days_idle' => (int) floor($idleSince->diffInDays($now)),
            ];
        })->all();

        return [
            'rows' => $rows,
            'summary' => [
                'days' => $days,
                'cutoff' => $cutoff->toIso8601String(),
                'lots' => count($rows),
                'total_value' => round(array_sum(array_column($rows, 'value')), 2),
            ],
        ];
    }
}
