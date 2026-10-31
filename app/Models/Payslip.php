<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeductionAccountRole;
use App\Services\EmployeeAdvanceService;
use App\Services\EmployeePayItemService;
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
        'overtime_pay',
        'labor_log_pay',
        'additions',
        'additions_pay',
        'gross_pay',
        'deductions',
        'net_pay',
        'journal_entry_id',
    ];

    protected $casts = [
        'base_pay' => 'decimal:4',
        'attendance_pay' => 'decimal:4',
        'overtime_pay' => 'decimal:4',
        'labor_log_pay' => 'decimal:4',
        'additions' => 'array',
        'additions_pay' => 'decimal:4',
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
     * W5-03 (HR-13): the deductions credited to each account role, advance
     * recovery left out (it credits 1215). A line without a role (manual lines
     * from before W5-03) is a withholding (CR 225), as it always was.
     *
     * @return array{liability: float, expense_reduction: float}
     */
    public function deductionsByRole(): array
    {
        $totals = [DeductionAccountRole::Liability->value => 0.0, DeductionAccountRole::ExpenseReduction->value => 0.0];

        foreach ($this->deductions ?? [] as $line) {
            if (! is_array($line) || EmployeeAdvanceService::isAdvanceLine($line)) {
                continue;
            }

            $role = EmployeePayItemService::roleOfLine($line)->value;
            $totals[$role] = round($totals[$role] + round((float) ($line['amount'] ?? 0), 4), 4);
        }

        return $totals;
    }

    /**
     * HR-12: the part of the deductions that recovers employee advances (CR 1215).
     */
    public function advanceDeductions(): float
    {
        return round(array_sum(array_column(EmployeeAdvanceService::advanceLines($this), 'amount')), 4);
    }
}
