<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a stock count: a snapshotted lot, or found stock with no lot.
 */
final class StockCountLine extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'stock_count_id',
        'stock_lot_id',
        'inventory_item_id',
        'system_qty',
        'counted_qty',
        'unit_cost',
        'variance_qty',
        'variance_value',
        'counted_by_user_id',
        'new_stock_lot_id',
        'note',
    ];

    protected $casts = [
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'variance_qty' => 'decimal:4',
        'variance_value' => 'decimal:4',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * The lot, whatever the caller's unit scope (lots are warehouse-scoped).
     */
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class)->withoutGlobalScopes();
    }

    /**
     * Who entered the counted quantity standing on this line.
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }

    public function newStockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'new_stock_lot_id')->withoutGlobalScopes();
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class)->withTrashed();
    }

    /**
     * A line added during the count for stock with no lot on record.
     */
    public function isFound(): bool
    {
        return $this->stock_lot_id === null;
    }
}
