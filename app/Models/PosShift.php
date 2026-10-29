<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FR-SLREP-008: a cashier shift on one cash drawer — opened with a float,
 * closed with a counted Z-report whose difference posts to 625 / 44.
 */
final class PosShift extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'operating_unit_id',
        'cash_account_id',
        'cashier_user_id',
        'number',
        'status',
        'opened_at',
        'opening_float',
        'closed_at',
        'closed_by_user_id',
        'expected_cash',
        'counted_cash',
        'difference',
        'totals',
        'notes',
        'journal_entry_id',
        'record_version',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'counted_cash' => 'decimal:4',
        'difference' => 'decimal:4',
        'totals' => 'array',
        'record_version' => 'integer',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
