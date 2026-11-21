<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankReconciliationStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 08 Wave 2 / TRS-04: Bank reconciliation header (التسوية المصرفية).
 */
final class BankReconciliation extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'cash_account_id',
        'statement_date',
        'statement_balance',
        'status',
        'notes',
        'created_by_user_id',
        'finished_by_user_id',
        'finished_at',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankReconciliationStatus::class,
            'statement_balance' => 'decimal:4',
            'statement_date' => 'date',
            'finished_at' => 'datetime',
            'record_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function finishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class, 'bank_reconciliation_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BankReconciliationAdjustment::class, 'bank_reconciliation_id');
    }
}
