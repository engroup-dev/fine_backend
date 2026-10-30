<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRoleRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\CompanyClock;
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

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly EmployeeCompensationService $compensations,
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

            foreach ($employees as $employee) {
                // HR-11: pay terms resolve by date; a mid-month change splits the period.
                $segments = $this->compensations->segments($employee, $start, $end);

                $basePay = $this->monthlyBasePay($employee, $segments, $start, $end);
                $attendancePay = $this->attendancePay($employee, $segments, $start, $end);

                $gross = round($basePay + $attendancePay, 4);

                if ($gross <= 0.0) {
                    continue;
                }

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'operating_unit_id' => $employee->operating_unit_id,
                    'base_pay' => $basePay,
                    'attendance_pay' => $attendancePay,
                    'labor_log_pay' => 0,
                    'gross_pay' => $gross,
                    'deductions' => [],
                    'net_pay' => $gross,
                ]);

                $totalGross = round($totalGross + $gross, 4);
            }

            $run->update([
                'status' => PayrollRunStatus::Calculated,
                'needs_recalculation' => false,
                'total_gross' => $totalGross,
                'total_deductions' => 0,
                'total_net' => $totalGross,
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

            $total = 0.0;

            foreach ($deductions as $deduction) {
                $amount = round((float) $deduction['amount'], 4);

                if ($amount < 0) {
                    throw new InvalidArgumentException('A deduction cannot be negative.');
                }

                $total = round($total + $amount, 4);
            }

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
            // calculated, so its payslips carry the old terms.
            if ($target === PayrollRunStatus::PendingApproval && $run->needs_recalculation) {
                throw new InvalidArgumentException(
                    "Pay terms changed after payroll {$run->period} was calculated; recalculate it before submitting."
                );
            }

            if ($target === PayrollRunStatus::Approved) {
                $run->approved_by_user_id = $userId;
            }

            if ($target === PayrollRunStatus::Posted) {
                $this->postJournal($run, $cashAccountId);
            }

            $run->status = $target;
            $run->save();

            return $run->refresh();
        });
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
     *   DR 57      Wage Expense               (gross pay)
     *   CR 226     Wages Payable              (net pay payable)
     *   CR 225     Payroll deductions payable (withheld)
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
            $expensePortion = round((float) $unitSlips->sum('gross_pay'), 4);
            $net = round((float) $unitSlips->sum('net_pay'), 4);
            $deductions = round((float) $unitSlips->sum(fn (Payslip $p) => $p->totalDeductions()), 4);
            $totalNet = round($totalNet + $net, 4);

            if ($expensePortion > 0) {
                $accrualLines[] = [
                    'account_code' => '57', // Wage Expense
                    'debit' => $expensePortion,
                    'operating_unit_id' => $unitId,
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
