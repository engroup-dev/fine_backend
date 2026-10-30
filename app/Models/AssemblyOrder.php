<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssemblyOrderStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W4-07 (decision #12): a furniture assembly order (ASM-). Written only by
 * AssemblyOrderService.
 */
final class AssemblyOrder extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'operating_unit_id',
        'number',
        'finished_item_id',
        'quantity',
        'output_warehouse_id',
        'status',
        'bundle_id',
        'notes',
        'total_cost',
        'finished_stock_lot_id',
        'journal_entry_id',
        'created_by_user_id',
        'completed_by_user_id',
        'completed_at',
        'cancelled_at',
        'record_version',
    ];

    protected $casts = [
        'status' => AssemblyOrderStatus::class,
        'quantity' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(AssemblyOrderLine::class)->orderBy('position')->orderBy('created_at');
    }

    public function finishedItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'finished_item_id')->withTrashed();
    }

    public function outputWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'output_warehouse_id')->withoutGlobalScopes();
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class)->withTrashed();
    }

    /**
     * The finished-goods lot produced on completion, whatever the caller's unit scope.
     */
    public function finishedStockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'finished_stock_lot_id')->withoutGlobalScopes();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
