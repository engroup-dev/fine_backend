<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W4-05: a chemical drawn from one lot into a foam batch, at that lot's actual
 * cost. Written and removed only by ProductionBatchService; removing a line
 * returns its quantity to the lot and soft-deletes the row, so the history
 * stays. Reached through its batch, which carries the unit scope.
 */
final class ProductionBatchMaterial extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $fillable = [
        'production_batch_id',
        'inventory_item_id',
        'stock_lot_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'issued_by',
        'inventory_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
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

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
