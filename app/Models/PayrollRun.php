<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PayrollRun extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'period',
        'status',
        'needs_recalculation',
        'total_gross',
        'total_deductions',
        'total_net',
        'opened_by_user_id',
        'approved_by_user_id',
        'payment_journal_entry_id',
        'record_version',
    ];

    protected $casts = [
        'status' => PayrollRunStatus::class,
        'needs_recalculation' => 'boolean',
        'total_gross' => 'decimal:4',
        'total_deductions' => 'decimal:4',
        'total_net' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }
}
