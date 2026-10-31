<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\EmployeeAdvanceService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Payslip extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'operating_unit_id',
        'base_pay',
        'attendance_pay',
        'labor_log_pay',
        'gross_pay',
        'deductions',
        'net_pay',
        'journal_entry_id',
    ];

    protected $casts = [
        'base_pay' => 'decimal:4',
        'attendance_pay' => 'decimal:4',
        'labor_log_pay' => 'decimal:4',
        'gross_pay' => 'decimal:4',
        'deductions' => 'array',
        'net_pay' => 'decimal:4',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function totalDeductions(): float
    {
        return round(array_sum(array_column($this->deductions ?? [], 'amount')), 4);
    }

    /**
     * HR-12: the part of the deductions that recovers employee advances (CR 1215).
     */
    public function advanceDeductions(): float
    {
        return round(array_sum(array_column(EmployeeAdvanceService::advanceLines($this), 'amount')), 4);
    }
}
