<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvanceInstallmentStatus;
use App\Enums\EmployeeAdvanceStatus;
use App\Enums\EmployeeAdvanceType;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W5-02 (decision #14, HR-12): a salary advance or loan. Written only by
 * EmployeeAdvanceService.
 */
final class EmployeeAdvance extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'operating_unit_id',
        'type',
        'amount',
        'installments',
        'monthly_amount',
        'start_period',
        'cash_account_id',
        'status',
        'notes',
        'requested_by',
        'approved_by',
        'approved_at',
        'payout_entry_id',
        'paid_at',
        'settled_at',
        'cancelled_at',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmployeeAdvanceType::class,
            'status' => EmployeeAdvanceStatus::class,
            'amount' => 'decimal:4',
            'installments' => 'integer',
            'monthly_amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'record_version' => 'integer',
        ];
    }

    /**
     * The employee, even after a soft delete, so the advance can still be settled.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed()->withoutGlobalScope(OperatingUnitScope::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function installmentRows(): HasMany
    {
        return $this->hasMany(AdvanceInstallment::class)->orderBy('period');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class)->withoutGlobalScopes();
    }

    public function payoutEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payout_entry_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * What is still to recover: the unrecovered part of every pending installment.
     */
    public function outstanding(): float
    {
        $rows = $this->relationLoaded('installmentRows') ? $this->installmentRows : $this->installmentRows()->get();

        return round((float) $rows
            ->where('status', AdvanceInstallmentStatus::Pending)
            ->sum(fn (AdvanceInstallment $row) => $row->remaining()), 4);
    }
}
