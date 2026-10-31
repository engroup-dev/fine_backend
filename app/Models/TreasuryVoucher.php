<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 08 Wave 2 / TRS-02: Receipt and payment vouchers (سندات القبض والصرف).
 */
final class TreasuryVoucher extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'operating_unit_id',
        'voucher_number',
        'kind',
        'cash_account_id',
        'counter_account_code',
        'client_id',
        'employee_id',
        'amount',
        'currency',
        'fx_rate',
        'amount_lyd',
        'voucher_date',
        'payee',
        'description',
        'status',
        'journal_entry_id',
        'cancel_journal_entry_id',
        'cancel_reason',
        'created_by_user_id',
        'cancelled_by_user_id',
        'cancelled_at',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TreasuryVoucherKind::class,
            'status' => TreasuryVoucherStatus::class,
            'amount' => 'decimal:4',
            'fx_rate' => 'decimal:8',
            'amount_lyd' => 'decimal:4',
            'voucher_date' => 'date',
            'cancelled_at' => 'datetime',
            'record_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * W5-02: the employee on a voucher against the advances subtree (1215),
     * shown even after the employee was deleted.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed()->withoutGlobalScope(OperatingUnitScope::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_code', 'account_code');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function cancelEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'cancel_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
