<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LedgerRuleException;
use App\Models\ItemStockLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * W4-02: reorder levels and shelf locations per item per warehouse
 * (FR-STK-004, FR-STK-007, BR-STK-004), and the reorder report built on them.
 *
 * On hand is what the warehouse's available lots hold — the same source as
 * WarehouseController::stockSummary. Reserved, quarantined and consumed lots
 * do not count, and a parent warehouse does not roll up its children.
 */
final class StockLevelService
{
    /**
     * @param  array{inventory_item_id: string, warehouse_id: string, min_qty?: float|string|null,
     *     max_qty?: float|string|null, reorder_qty?: float|string|null, bin_location?: string|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function create(array $data): ItemStockLevel
    {
        return DB::transaction(function () use ($data): ItemStockLevel {
            $existing = ItemStockLevel::withoutGlobalScopes()
                ->where('inventory_item_id', $data['inventory_item_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new LedgerRuleException(
                    'This item already has stock levels in this warehouse; edit them instead.',
                    'STOCK_LEVEL_EXISTS',
                    ['item_stock_level_id' => $existing->id],
                );
            }

            $attributes = $this->normalise($data);
            $this->guardLimits($attributes['min_qty'], $attributes['max_qty']);

            try {
                $level = ItemStockLevel::create([
                    'inventory_item_id' => $data['inventory_item_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    ...$attributes,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the row after the check above
                // (locking a missing row locks nothing): same refusal.
                throw new LedgerRuleException(
                    'This item already has stock levels in this warehouse; edit them instead.',
                    'STOCK_LEVEL_EXISTS',
                );
            }

            return $level->load(['inventoryItem', 'warehouse']);
        });
    }

    /**
     * @param  array{min_qty?: float|string|null, max_qty?: float|string|null, reorder_qty?: float|string|null,
     *     bin_location?: string|null, record_version: int}  $data
     *
     * @throws LedgerRuleException
     */
    public function update(ItemStockLevel $level, array $data): ItemStockLevel
    {
        return DB::transaction(function () use ($level, $data): ItemStockLevel {
            $locked = ItemStockLevel::withoutGlobalScopes()->whereKey($level->id)->lockForUpdate()->firstOrFail();

            $merged = [
                'min_qty' => array_key_exists('min_qty', $data) ? $data['min_qty'] : $locked->min_qty,
                'max_qty' => array_key_exists('max_qty', $data) ? $data['max_qty'] : $locked->max_qty,
                'reorder_qty' => array_key_exists('reorder_qty', $data) ? $data['reorder_qty'] : $locked->reorder_qty,
                'bin_location' => array_key_exists('bin_location', $data) ? $data['bin_location'] : $locked->bin_location,
            ];

            $attributes = $this->normalise($merged);
            $this->guardLimits($attributes['min_qty'], $attributes['max_qty']);

            $locked->fill([...$attributes, 'record_version' => $data['record_version']]);
            $locked->save();

            return $locked->load(['inventoryItem', 'warehouse']);
        });
    }

    public function delete(ItemStockLevel $level): void
    {
        DB::transaction(function () use ($level): void {
            ItemStockLevel::withoutGlobalScopes()->whereKey($level->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * Every warehouse/item pair that has limits (max > 0), with what it holds
     * and what to order: suggested = max − on_hand once on_hand ≤ min, else 0.
     * Rows needing a reorder come first. Scoped to the caller's unit through
     * the warehouse.
     *
     * @param  array{warehouse_id?: string|null, category_id?: string|null}  $filters
     * @return array{rows: array<int, array<string, mixed>>, summary: array{rows: int, needs_reorder_count: int}}
     */
    public function reorderReport(array $filters = []): array
    {
        $rows = $this->reorderRows(ItemStockLevel::query(), $filters);

        return [
            'rows' => $rows,
            'summary' => [
                'rows' => count($rows),
                'needs_reorder_count' => count(array_filter($rows, fn (array $row): bool => $row['needs_reorder'])),
            ],
        ];
    }

    /**
     * Company-wide count of warehouse/item pairs at or below their minimum,
     * for the owner dashboard.
     */
    public function lowStockCount(): int
    {
        $rows = $this->reorderRows(ItemStockLevel::withoutGlobalScopes(), []);

        return count(array_filter($rows, fn (array $row): bool => $row['needs_reorder']));
    }

    /**
     * @param  Builder<ItemStockLevel>  $query
     * @param  array{warehouse_id?: string|null, category_id?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function reorderRows(Builder $query, array $filters): array
    {
        $onHand = DB::table('stock_lots')
            ->select('inventory_item_id', 'warehouse_id')
            ->selectRaw('SUM(quantity) as on_hand')
            ->where('status', 'available')
            ->whereNull('deleted_at')
            ->groupBy('inventory_item_id', 'warehouse_id');

        $query
            ->join('warehouses', 'warehouses.id', '=', 'item_stock_levels.warehouse_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'item_stock_levels.inventory_item_id')
            ->leftJoinSub($onHand, 'on_hand', function ($join): void {
                $join->on('on_hand.inventory_item_id', '=', 'item_stock_levels.inventory_item_id')
                    ->on('on_hand.warehouse_id', '=', 'item_stock_levels.warehouse_id');
            })
            ->whereNull('inventory_items.deleted_at')
            ->where('item_stock_levels.max_qty', '>', 0)
            ->select('item_stock_levels.*')
            ->addSelect([
                'warehouses.name as warehouse_name',
                'warehouses.operating_unit_id as operating_unit_id',
                'inventory_items.code as item_code',
                'inventory_items.name as item_name',
                'inventory_items.unit_of_measure as unit_of_measure',
                'inventory_items.category_id as category_id',
            ])
            ->selectRaw('COALESCE(on_hand.on_hand, 0) as on_hand_qty')
            ->orderBy('warehouses.name')
            ->orderBy('inventory_items.name');

        if (filled($filters['warehouse_id'] ?? null)) {
            $query->where('item_stock_levels.warehouse_id', $filters['warehouse_id']);
        }

        if (filled($filters['category_id'] ?? null)) {
            $query->where('inventory_items.category_id', $filters['category_id']);
        }

        $rows = $query->get()->map(function (ItemStockLevel $level): array {
            $onHand = round((float) $level->getAttribute('on_hand_qty'), 4);
            $min = round((float) $level->min_qty, 4);
            $max = round((float) $level->max_qty, 4);
            $needsReorder = $onHand <= $min;

            return [
                'id' => $level->id,
                'inventory_item_id' => $level->inventory_item_id,
                'item_code' => $level->getAttribute('item_code'),
                'item_name' => $level->getAttribute('item_name'),
                'unit_of_measure' => $level->getAttribute('unit_of_measure'),
                'category_id' => $level->getAttribute('category_id'),
                'warehouse_id' => $level->warehouse_id,
                'warehouse_name' => $level->getAttribute('warehouse_name'),
                'operating_unit_id' => $level->getAttribute('operating_unit_id'),
                'bin_location' => $level->bin_location,
                'on_hand' => $onHand,
                'min_qty' => $min,
                'max_qty' => $max,
                'reorder_qty' => $level->reorder_qty !== null ? round((float) $level->reorder_qty, 4) : null,
                'needs_reorder' => $needsReorder,
                'suggested_qty' => $needsReorder ? max(0.0, round($max - $onHand, 4)) : 0.0,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => (int) $b['needs_reorder'] <=> (int) $a['needs_reorder']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{min_qty: float, max_qty: float, reorder_qty: float|null, bin_location: string|null}
     */
    private function normalise(array $data): array
    {
        $bin = isset($data['bin_location']) ? trim((string) $data['bin_location']) : '';

        return [
            'min_qty' => round((float) ($data['min_qty'] ?? 0), 4),
            'max_qty' => round((float) ($data['max_qty'] ?? 0), 4),
            'reorder_qty' => isset($data['reorder_qty']) ? round((float) $data['reorder_qty'], 4) : null,
            'bin_location' => $bin !== '' ? $bin : null,
        ];
    }

    /**
     * BR-STK-004: the maximum limit cannot be less than the minimum limit.
     *
     * @throws LedgerRuleException
     */
    private function guardLimits(float $min, float $max): void
    {
        if ($max < $min) {
            throw new LedgerRuleException(
                "The maximum ({$max}) cannot be less than the minimum ({$min}).",
                'STOCK_LEVEL_INVALID',
                ['min_qty' => $min, 'max_qty' => $max],
            );
        }
    }
}
