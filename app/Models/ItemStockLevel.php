<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WarehouseOperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W4-02: an item's stock limits and bin in one warehouse (FR-STK-004,
 * FR-STK-007). Items are company-wide, so the row belongs to a unit only
 * through its warehouse, like a stock lot. A row whose max is 0 sets no
 * limit and only records the bin.
 *
 * No SoftDeletes: the row is configuration, unique per (item, warehouse),
 * and a soft-deleted twin would block setting the levels again.
 */
final class ItemStockLevel extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'inventory_item_id',
        'warehouse_id',
        'min_qty',
        'max_qty',
        'reorder_qty',
        'bin_location',
        'record_version',
    ];

    protected $casts = [
        'min_qty' => 'decimal:4',
        'max_qty' => 'decimal:4',
        'reorder_qty' => 'decimal:4',
        'record_version' => 'integer',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(new WarehouseOperatingUnitScope);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The bin set for an item in a warehouse, if any. Read across unit scope:
     * callers (intake, transfer, landing) have already resolved the warehouse.
     */
    public static function binFor(string $inventoryItemId, string $warehouseId): ?string
    {
        $bin = self::withoutGlobalScopes()
            ->where('inventory_item_id', $inventoryItemId)
            ->where('warehouse_id', $warehouseId)
            ->value('bin_location');

        return filled($bin) ? (string) $bin : null;
    }
}
