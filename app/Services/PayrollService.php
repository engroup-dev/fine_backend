<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeductionAccountRole;
use App\Enums\EmployeeAdjustmentStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\LedgerRuleException;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\LaborRoleRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\CompanyClock;
use App\Support\EmployeeAdvanceAccounts;
use App\Support\TreasuryAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 09 §9.4–9.7. A run opens a period, aggregates attendance into
 * payslips (§9.6), passes review and approval gates (HR-06), and posts to
 * the ledger on the final transition (HR-08).
 */
final class PayrollService
{
    /** Withholdings owed on to the tax office / social security until remitted. */
    public const DEDUCTIONS_PAYABLE = '225';

    /** Net pay accrued at period end awaiting disbursement. */
    public const WAGES_PAYABLE = '226';

    /**
     * HR-12: deduction types that mean advance recovery (payroll's own line
     * type and the DED-ADV lookup); upper-cased for comparison.
     */
    private const ADVANCE_LINE_TYPES = ['ADVANCE', 'DED-ADV'];

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly EmployeeCompensationService $compensations,
        private readonly EmployeeAdvanceService $advances,
        private readonly EmployeePayItemService $payItems,
    ) {}

    public function open(string $period, ?string $userId = null): PayrollRun
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Period must be YYYY-MM, got \"{$period}\".");
        }

        if (PayrollRun::withTrashed()->where('period', $period)->exists()) {
            throw new InvalidArgumentException("A payroll run for {$period} already exists.");
        }

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            throw new InvalidArgumentException('No company exists to run payroll for.');
        }

        return PayrollRun::create([
            'company_id' => $companyId,
            'period' => $period,
            'status' => PayrollRunStatus::Draft,
            'opened_by_user_id' => $userId,
        ]);
    }

    /**
     * §9.6: gross = base salary + payable attendance hours × role rate, with
     * the pay terms resolved per day from the compensation history (HR-11).
     * Recalculating while still in Calculated wipes and regenerates the
     * payslips.
     *
     * HR-13: standing pay items and approved one-off adjustments are added:
     * gross = base + attendance + additions, and their deductions become
     * locked lines. Percentages apply to base + attendance pay. An employee
     * who earned nothing in the period (base + attendance = 0) gets no payslip
     * and no allowances. Deductions above gross are refused
     * (PAYROLL_DEDUCTIONS_EXCEED_GROSS, listing every such employee), never
     * cut silently. An approved one-off adjustment of the period for an
     * employee who gets no payslip refuses the calculation too
     * (PAYROLL_ADJUSTMENT_UNPAID): it would otherwise never be paid.
     *
     * HR-12: due advance installments become locked deduction lines, capped
     * by the company's deduction percentage of net pay after the HR-13
     * deductions (they count like manual deductions).
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);

            $this->guardTransition($run, PayrollRunStatus::Calculated);

            $run->payslips()->delete();

            $start = Carbon::createFromFormat('!Y-m', $run->period)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $employees = Employee::withoutGlobalScopes()
                ->where('status', '!=', 'terminated')
                ->get();

            $totalGross = 0.0;
            $totalDeductions = 0.0;
            $paidEmployeeIds = [];
            $overdrawn = [];

            foreach ($employees as $employee) {
                // HR-11: pay terms resolve by date; a mid-month change splits the period.
                $segments = $this->compensations->segments($employee, $start, $end);

                $basePay = $this->monthlyBasePay($employee, $segments, $start, $end);
                $attendancePay = $this->attendancePay($employee, $segments, $start, $end);

                $earned = round($basePay + $attendancePay, 4);

                if ($earned <= 0.0) {
                    continue;
                }

                // HR-13: standing items and the period's approved adjustments.
                $lines = $this->payItems->linesFor($employee, $run->period, $earned);
                $additionsPay = round(array_sum(array_column($lines['additions'], 'amount')), 4);
                $gross = round($earned + $additionsPay, 4);
                $itemDeductions = round(array_sum(array_column($lines['deductions'], 'amount')), 4);

                if ($itemDeductions - $gross > 0.00001) {
                    $overdrawn[] = ['employee_id' => $employee->id, 'gross_pay' => $gross, 'deductions' => $itemDeductions];

                    continue;
                }

                $paidEmployeeIds[] = $employee->id;

                $deductions = [
                    ...$lines['deductions'],
                    ...$this->advances->dueDeductions($employee, $run, round($gross - $itemDeductions, 4)),
                ];
                $deductionTotal = round(array_sum(array_column($deductions, 'amount')), 4);

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'operating_unit_id' => $employee->operating_unit_id,
                    'base_pay' => $basePay,
                    'attendance_pay' => $attendancePay,
                    'labor_log_pay' => 0,
                    'additions' => $lines['additions'],
                    'additions_pay' => $additionsPay,
                    'gross_pay' => $gross,
                    'deductions' => $deductions,
                    'net_pay' => round($gross - $deductionTotal, 4),
                ]);

                $totalGross = round($totalGross + $gross, 4);
                $totalDeductions = round($totalDeductions + $deductionTotal, 4);
            }

            if ($overdrawn !== []) {
                $first = $overdrawn[0];

                throw new LedgerRuleException(
                    'The standing and one-off deductions of '.count($overdrawn)." employee(s) exceed their gross pay for {$run->period} (first: {$first['employee_id']}, {$first['deductions']} against {$first['gross_pay']}); the payslip would go negative. Remove or lower the one-off deduction, or for a standing item end it on the last day of the previous month and add it again from the next month (or deactivate it for this calculation), then recalculate.",
                    'PAYROLL_DEDUCTIONS_EXCEED_GROSS',
                    [...$first, 'employees' => $overdrawn],
                );
            }

            $this->assertAdjustmentsPaid($run, $paidEmployeeIds);

            $run->update([
                'status' => PayrollRunStatus::Calculated,
                'needs_recalculation' => false,
                'total_gross' => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net' => round($totalGross - $totalDeductions, 4),
            ]);

            return $run->refresh()->load('payslips.employee.entity');
        });
    }

    /**
     * BR-PAY-004: a salaried employee hired inside the period is paid only for
     * the calendar days they were employed (the hire day counts as worked);
     * one hired after the period has not earned anything yet.
     *
     * HR-11: each run of days on monthly terms earns its own salary pro rata
     * (days / days in month), so a mid-month raise pays the old salary up to
     * the day before and the new one from its effective date.
     *
     * @param  list<array{from: Carbon, to: Carbon, pay_type: PayType|null, monthly_salary: float|null, hourly_rate: float|null}>  $segments
     */
    private function monthlyBasePay(Employee $employee, array $segments, Carbon $start, Carbon $end): float
    {
        $hireDate = $employee->hire_date?->copy()->startOfDay();

        if ($hireDate !== null && $hireDate->gt($end)) {
            return 0.0;
        }

        $employedFrom = $hireDate !== null && $hireDate->gt($start) ? $hireDate : $start->copy()->startOfDay();
        $daysInMonth = $start->daysInMonth;
        $pay = 0.0;

        foreach ($segments as $segment) {
            if ($segment['pay_type'] !== PayType::Monthly) {
                continue;
            }

            $from = $segment['from']->copy()->max($employedFrom);
            $to = $segment['to'];

            if ($from->gt($to)) {
                continue;
            }

            $salary = (float) ($segment['monthly_salary'] ?? 0);
            $days = $to->day - $from->day + 1;

            $pay += $days === $daysInMonth ? $salary : $salary * $days / $daysInMonth;
        }

        return round($pay, 4);
    }

    /**
     * HR-07: deductions are extensible line items; net is derived, never
     * accepted from the caller. Only while the run sits in Calculated.
     *
     * HR-12: the locked advance lines come from the schedule only; a locked or
     * installment line in the input is ignored (the editor sends them back),
     * so it can be neither edited, removed nor doubled. They are re-capped to
     * the deduction percentage of net pay after the manual lines
     * (recapAdvanceLines). A manual line typed as an advance recovery is
     * refused (ADVANCE_DEDUCTION_MANUAL).
     *
     * HR-13: the locked standing/one-off deduction lines are kept likewise and
     * count like manual deductions for the advance cap. A manual line takes the
     * account role of the deduction type it names (`liability` → 225,
     * `expense_reduction` → 57); any other type is a withholding (225).
     *
     * @param  list<array{type: string, amount: float}>  $deductions
     */
    public function setDeductions(Payslip $payslip, array $deductions): Payslip
    {
        return DB::transaction(function () use ($payslip, $deductions) {
            $run = PayrollRun::lockForUpdate()->findOrFail($payslip->payroll_run_id);

            if ($run->status !== PayrollRunStatus::Calculated) {
                throw new InvalidStateTransitionException(
                    'Deductions can only change while the run is in review (calculated).'
                );
            }

            $locked = array_values(array_filter(
                $payslip->deductions ?? [],
                fn ($line) => is_array($line) && ($line['locked'] ?? false) === true,
            ));

            $manual = array_values(array_filter(
                $deductions,
                fn ($line) => ($line['locked'] ?? false) !== true && ! isset($line['installment_id']),
            ));

            // Advance recovery comes from the schedule (or a repayment): a typed
            // advance line would credit 225 instead of 1215 and leave the
            // installment due again next month.
            foreach ($manual as $line) {
                $type = strtoupper(trim((string) ($line['type'] ?? '')));

                if (in_array($type, self::ADVANCE_LINE_TYPES, true)) {
                    throw new LedgerRuleException(
                        'Advance and loan recovery is deducted from the advance schedule by payroll (or repaid in cash through the advance); it cannot be entered as a manual deduction.',
                        'ADVANCE_DEDUCTION_MANUAL',
                    );
                }
            }

            foreach ([...$locked, ...$manual] as $deduction) {
                if (round((float) $deduction['amount'], 4) < 0) {
                    throw new InvalidArgumentException('A deduction cannot be negative.');
                }
            }

            $manual = $this->payItems->withAccountRoles(array_map(
                fn (array $line) => ['type' => $line['type'], 'amount' => $line['amount']],
                $manual,
            ));

            $locked = $this->recapAdvanceLines($payslip, $run, $locked, $manual);
            $deductions = [...$locked, ...$manual];
            $total = round(array_sum(array_map(fn (array $line) => round((float) $line['amount'], 4), $deductions)), 4);

            $net = round((float) $payslip->gross_pay - $total, 4);

            if ($net < 0) {
                throw new InvalidArgumentException(
                    'Deductions exceed gross pay; the payslip would go negative.'
                );
            }

            $payslip->update(['deductions' => $deductions, 'net_pay' => $net]);

            $run->update([
                'total_deductions' => round((float) $run->payslips()->get()->sum(fn (Payslip $p) => $p->totalDeductions()), 4),
                'total_net' => round((float) $run->payslips()->sum('net_pay'), 4),
            ]);

            return $payslip->refresh();
        });
    }

    /**
     * HR-12 (decision #14): the advance cap is a share of net pay, so it is
     * measured after the manual deductions. The locked advance lines are
     * worked out again from the schedule against gross − manual deductions:
     * a manual penalty lowers them (never below zero net), removing it
     * restores them (up to what calculate() found while the schedule is
     * unchanged; a change flags the run for recalculation), and the part not deducted stays pending and is due again next
     * period, as any carry-forward. Other locked lines are kept as they are.
     *
     * @param  list<array<string, mixed>>  $locked
     * @param  list<array<string, mixed>>  $manual
     * @return list<array<string, mixed>>
     */
    private function recapAdvanceLines(Payslip $payslip, PayrollRun $run, array $locked, array $manual): array
    {
        $otherLocked = array_values(array_filter(
            $locked,
            fn (array $line) => ($line['type'] ?? null) !== EmployeeAdvanceService::LINE_TYPE || ! isset($line['installment_id']),
        ));

        $employee = Employee::withoutGlobalScopes()->findOrFail($payslip->employee_id);
        $manualTotal = round(array_sum(array_map(fn (array $line) => round((float) $line['amount'], 4), $manual)), 4);
        $otherLockedTotal = round(array_sum(array_map(fn (array $line) => round((float) $line['amount'], 4), $otherLocked)), 4);
        $netBeforeAdvances = round((float) $payslip->gross_pay - $manualTotal - $otherLockedTotal, 4);

        return [...$otherLocked, ...$this->advances->dueDeductions($employee, $run, $netBeforeAdvances)];
    }

    /**
     * @param  string|null  $cashAccountId  the treasury net pay leaves from; required to post
     */
    public function transition(PayrollRun $run, PayrollRunStatus $target, ?string $userId = null, ?string $cashAccountId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $target, $userId, $cashAccountId) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);

            if ($target === PayrollRunStatus::Calculated) {
                throw new InvalidArgumentException('Calculation goes through calculate(), not a bare transition.');
            }

            $this->guardTransition($run, $target);

            if ($target === PayrollRunStatus::PendingApproval && $run->payslips()->count() === 0) {
                throw new InvalidArgumentException('An empty payroll run has nothing to approve.');
            }

            // HR-11: a pay-history change reached this period after it was
            // calculated, so its payslips carry the old terms. HR-12: a cash
            // advance repayment reduced an installment one of them deducts.
            if ($target === PayrollRunStatus::PendingApproval && $run->needs_recalculation) {
                throw new InvalidArgumentException(
                    "Pay terms or an advance repayment changed after payroll {$run->period} was calculated; recalculate it before submitting."
                );
            }

            // HR-13: the run is frozen from here, so an approved adjustment of
            // the period whose employee has no payslip would never be paid.
            if ($target === PayrollRunStatus::PendingApproval) {
                $this->assertAdjustmentsPaid($run, $run->payslips()->pluck('employee_id')->all());
            }

            if ($target === PayrollRunStatus::Approved) {
                $run->approved_by_user_id = $userId;
            }

            if ($target === PayrollRunStatus::Posted) {
                $this->postJournal($run, $cashAccountId);
                // HR-12: what each payslip deducted is now recovered.
                $this->advances->applyRecoveries($run);
            }

            $run->status = $target;
            $run->save();

            return $run->refresh();
        });
    }

    /**
     * HR-13: every approved one-off adjustment of the run's period must
     * belong to an employee with a payslip in it (PAYROLL_ADJUSTMENT_UNPAID).
     *
     * @param  list<string>  $paidEmployeeIds
     *
     * @throws LedgerRuleException
     */
    private function assertAdjustmentsPaid(PayrollRun $run, array $paidEmployeeIds): void
    {
        $unpaid = EmployeeAdjustment::query()
            ->where('period', $run->period)
            ->where('status', EmployeeAdjustmentStatus::Approved)
            ->whereNotIn('employee_id', $paidEmployeeIds)
            ->orderBy('created_at')
            ->get(['id', 'employee_id']);

        if ($unpaid->isNotEmpty()) {
            throw new LedgerRuleException(
                "Approved adjustments for {$run->period} belong to employees who get no payslip this period (terminated, piece rate, or nothing earned); they would never be paid or deducted. Remove them or enter them for a period the employee is paid in, then recalculate.",
                'PAYROLL_ADJUSTMENT_UNPAID',
                [
                    'employee_id' => $unpaid->first()->employee_id,
                    'adjustment_ids' => $unpaid->pluck('id')->all(),
                ],
            );
        }
    }

    private function guardTransition(PayrollRun $run, PayrollRunStatus $target): void
    {
        if (! in_array($target, $run->status->allowedNext(), true)) {
            throw new InvalidStateTransitionException(
                "Payroll run {$run->period} cannot move from {$run->status->value} to {$target->value}."
            );
        }
    }

    /**
     * Hourly pay resolves the role rate per attendance date (HR-04), falling
     * back to the personal rate in force that day (HR-11). Only days on hourly
     * terms are paid by the hour. An hourly employee with payable hours but no
     * resolvable rate is a refusal, not a silent zero.
     *
     * @param  list<array{from: Carbon, to: Carbon, pay_type: PayType|null, monthly_salary: float|null, hourly_rate: float|null}>  $segments
     */
    private function attendancePay(Employee $employee, array $segments, Carbon $start, Carbon $end): float
    {
        $hourlySegments = array_values(array_filter(
            $segments,
            fn (array $segment) => $segment['pay_type'] === PayType::Hourly,
        ));

        if ($hourlySegments === []) {
            return 0.0;
        }

        $attendances = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->filter(fn (Attendance $a) => $a->status->isPayable() && (float) $a->hours_worked > 0);

        $pay = 0.0;

        foreach ($attendances as $attendance) {
            $workDate = $attendance->work_date->copy()->startOfDay();

            $segment = collect($hourlySegments)->first(
                fn (array $segment) => $workDate->betweenIncluded($segment['from'], $segment['to'])
            );

            if ($segment === null) {
                continue;
            }

            $rate = $employee->labor_role !== null
                ? LaborRoleRate::rateFor($employee->labor_role, $attendance->work_date)
                : null;

            $rate ??= $segment['hourly_rate'];

            if ($rate === null) {
                throw new InvalidArgumentException(
                    "Employee {$employee->id} has payable hours but no labor role rate and no personal rate."
                );
            }

            $pay = round($pay + ((float) $attendance->hours_worked * $rate), 4);
        }

        return $pay;
    }

    /**
     * HR-08 / phase-08 §8.4 PayrollRun.Posted, per unit:
     *
     * 1. ACCRUAL (dated min(end of period, today)):
     *   DR 57      Wage Expense               (base + attendance pay)
     *   DR 57      Wage Expense               (HR-13 additions: allowances, bonuses)
     *   CR 226     Wages Payable              (net pay payable)
     *   CR 225     Payroll deductions payable (withheld: `liability` deductions and untyped manual lines)
     *   CR 57      Wage Expense               (HR-13 `expense_reduction` deductions: absence, lateness, penalties)
     *   CR 1215    Employee advances, per employee (HR-12 installments)
     *
     * 2. PAYMENT (dated today, when total net > 0):
     *   DR 226     Wages Payable              (net pay)
     *   CR 1211xx  the chosen treasury        (net pay disbursed)
     */
    private function postJournal(PayrollRun $run, ?string $cashAccountId): void
    {
        $treasuryCode = TreasuryAccounts::codeForId($cashAccountId);
        $payslips = $run->payslips()->get();

        $accrualLines = [];
        $totalNet = 0.0;

        foreach ($payslips->groupBy('operating_unit_id') as $unitId => $unitSlips) {
            $additions = round((float) $unitSlips->sum('additions_pay'), 4);
            $expensePortion = round((float) $unitSlips->sum('gross_pay') - $additions, 4);
            $net = round((float) $unitSlips->sum('net_pay'), 4);
            $deductions = round((float) $unitSlips->sum(fn (Payslip $p) => $p->deductionsByRole()[DeductionAccountRole::Liability->value]), 4);
            $expenseReductions = round((float) $unitSlips->sum(fn (Payslip $p) => $p->deductionsByRole()[DeductionAccountRole::ExpenseReduction->value]), 4);
            $totalNet = round($totalNet + $net, 4);

            if ($expensePortion > 0) {
                $accrualLines[] = [
                    'account_code' => '57', // Wage Expense
                    'debit' => $expensePortion,
                    'operating_unit_id' => $unitId,
                ];
            }

            // HR-13: allowances and bonuses are wage expense too.
            if ($additions > 0) {
                $accrualLines[] = [
                    'account_code' => '57',
                    'debit' => $additions,
                    'operating_unit_id' => $unitId,
                    'memo' => 'allowances and bonuses',
                ];
            }

            if ($net > 0) {
                $accrualLines[] = [
                    'account_code' => self::WAGES_PAYABLE,
                    'credit' => $net,
                    'operating_unit_id' => $unitId,
                    'memo' => 'net pay payable',
                ];
            }

            if ($deductions > 0) {
                $accrualLines[] = [
                    'account_code' => self::DEDUCTIONS_PAYABLE,
                    'credit' => $deductions,
                    'operating_unit_id' => $unitId,
                ];
            }

            // HR-13: deductions the company keeps reduce the wage expense.
            if ($expenseReductions > 0) {
                $accrualLines[] = [
                    'account_code' => DeductionAccountRole::ExpenseReduction->accountCode(),
                    'credit' => $expenseReductions,
                    'operating_unit_id' => $unitId,
                    'memo' => 'deductions reducing wage expense',
                ];
            }

            // HR-12: installments recovered reduce each employee's advance balance.
            foreach ($unitSlips as $payslip) {
                $recovered = $payslip->advanceDeductions();

                if ($recovered > 0) {
                    $accrualLines[] = [
                        'account_code' => EmployeeAdvanceAccounts::ROOT_CODE,
                        'credit' => $recovered,
                        'operating_unit_id' => $unitId,
                        'employee_id' => $payslip->employee_id,
                        'memo' => 'advance recovery',
                    ];
                }
            }
        }

        $lastDayOfPeriod = Carbon::createFromFormat('!Y-m', $run->period)->endOfMonth()->toDateString();
        $accrualDate = min($lastDayOfPeriod, CompanyClock::today());

        $accrualEntry = $this->accountingService->postJournal(
            "Payroll {$run->period} accrued",
            $accrualLines,
            'PayrollRun',
            $run->id,
            $run->company_id,
            entryDate: $accrualDate,
        );

        $run->payslips()->update(['journal_entry_id' => $accrualEntry->id]);

        if ($totalNet > 0) {
            $paymentLines = [];

            foreach ($payslips->groupBy('operating_unit_id') as $unitId => $unitSlips) {
                $net = round((float) $unitSlips->sum('net_pay'), 4);

                if ($net > 0) {
                    $paymentLines[] = [
                        'account_code' => self::WAGES_PAYABLE,
                        'debit' => $net,
                        'operating_unit_id' => $unitId,
                    ];

                    $paymentLines[] = [
                        'account_code' => $treasuryCode,
                        'credit' => $net,
                        'operating_unit_id' => $unitId,
                        'memo' => 'net pay disbursed',
                    ];
                }
            }

            $paymentEntry = $this->accountingService->postJournal(
                "Payroll {$run->period} paid",
                $paymentLines,
                'PayrollRun',
                $run->id,
                $run->company_id,
            );

            $run->payment_journal_entry_id = $paymentEntry->id;
        }
    }
}
