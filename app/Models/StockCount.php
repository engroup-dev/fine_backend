<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockCountStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W4-01 / INV-06: a physical stock count of one warehouse (CNT-).
 */
final class StockCount extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'operating_unit_id',
        'warehouse_id',
        'item_category_id',
        'number',
        'count_date',
        'status',
        'blind',
        'created_by_user_id',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
        'journal_entry_id',
        'notes',
        'record_version',
    ];

    protected $casts = [
        'status' => StockCountStatus::class,
        'blind' => 'boolean',
        'count_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /**
     * Every counted quantity entered on the count, and by whom (append-only).
     */
    public function entries(): HasMany
    {
        return $this->hasMany(StockCountEntry::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
