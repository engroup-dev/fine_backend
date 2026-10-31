<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvanceInstallmentStatus;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W5-02 (HR-12): one month of an advance's recovery schedule. Payroll deducts
 * what is left of every pending installment due by its period; recovered_amount
 * grows with each posted payroll and cash repayment.
 */
final class AdvanceInstallment extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'employee_advance_id',
        'period',
        'amount',
        'recovered_amount',
        'payslip_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdvanceInstallmentStatus::class,
            'amount' => 'decimal:4',
            'recovered_amount' => 'decimal:4',
        ];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id')->withoutGlobalScopes();
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->recovered_amount, 4));
    }
}
