<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Support\LotSize;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W4-07: one component of an assembly order. On a draft the lot is optional
 * (picked by hand, or drawn oldest first on completion); a completed line
 * names the lot it was drawn from and that lot's actual unit cost. A line
 * with a size (from the bundle template) draws only lots of that size. Reached
 * through its order, which carries the unit scope.
 */
final class AssemblyOrderLine extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'assembly_order_id',
        'inventory_item_id',
        'stock_lot_id',
        'quantity',
        'length_m',
        'width_m',
        'height_m',
        'unit_cost',
        'position',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'position' => 'integer',
    ];

    /**
     * The piece size this line must draw, or null when any lot will do.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function size(): ?array
    {
        return LotSize::of($this->length_m, $this->width_m, $this->height_m);
    }

    public function assemblyOrder(): BelongsTo
    {
        return $this->belongsTo(AssemblyOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class)->withTrashed();
    }

    /**
     * The lot, whatever the caller's unit scope (lots are warehouse-scoped).
     */
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class)->withoutGlobalScopes();
    }
}
