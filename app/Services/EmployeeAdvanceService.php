<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdvanceInstallmentStatus;
use App\Enums\EmployeeAdvanceStatus;
use App\Enums\EmployeeAdvanceType;
use App\Enums\EmployeeStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\LedgerRuleException;
use App\Models\AdvanceInstallment;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Support\CompanyClock;
use App\Support\EmployeeAdvanceAccounts;
use App\Support\TreasuryAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W5-02 (decision #14, HR-12): salary advances and loans.
 *
 * - Limits (company settings): one advance ≤ advance_max_salary_multiple ×
 *   the monthly base salary in force today (ADVANCE_LIMIT_EXCEEDED), and the
 *   employee's open monthly installments ≤ advance_max_deduction_pct % of it
 *   (ADVANCE_DEDUCTION_LIMIT). Checked on request and again on approval.
 * - Payout: DR 1215 (employee) / CR treasury. Cash repayment: DR treasury /
 *   CR 1215 (employee).
 * - Payroll deducts the unrecovered part of every pending installment due by
 *   its period, capped by the deduction percentage of net pay; posting the
 *   run records what was recovered. Whatever was not recovered stays pending
 *   and is due again in the next period (carry-forward).
 */
final class EmployeeAdvanceService
{
    /** The payslip deduction line type payroll writes for an installment. */
    public const LINE_TYPE = 'advance';

    private const TOLERANCE = 0.0001;

    /** A payroll run in one of these has left review: its deductions are fixed. */
    private const CLOSED_RUN_STATUSES = [
        PayrollRunStatus::PendingApproval,
        PayrollRunStatus::Approved,
        PayrollRunStatus::Paid,
        PayrollRunStatus::Posted,
    ];

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly EmployeeCompensationService $compensations,
    ) {}

    /**
     * Record a request and its recovery schedule: equal monthly installments
     * from start_period, the last one absorbing the rounding.
     *
     * @param  array{employee_id: string, type: string, amount: float|string, installments: int|string, start_period: string, notes?: string|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function request(array $data, User $user): EmployeeAdvance
    {
        return DB::transaction(function () use ($data, $user): EmployeeAdvance {
            // Unit-scoped: a unit user can only name an employee of their unit.
            $employee = Employee::query()->lockForUpdate()->find($data['employee_id']);

            if ($employee === null) {
                throw new LedgerRuleException('Employee not found.', 'EMPLOYEE_NOT_FOUND');
            }

            if ($employee->status === EmployeeStatus::Terminated) {
                throw new LedgerRuleException('A terminated employee cannot take an advance.', 'ADVANCE_EMPLOYEE_INACTIVE');
            }

            $amount = round((float) $data['amount'], 4);
            $count = (int) $data['installments'];

            if ($amount <= 0) {
                throw new LedgerRuleException('An advance must be greater than zero.', 'INVALID_AMOUNT');
            }

            if ($count < 1) {
                throw new LedgerRuleException('An advance needs at least one installment.', 'INVALID_INSTALLMENTS');
            }

            $startPeriod = (string) $data['start_period'];
            $currentPeriod = substr(CompanyClock::today(), 0, 7);

            if ($startPeriod < $currentPeriod) {
                throw new LedgerRuleException(
                    "Recovery cannot start in a past month ({$startPeriod}).",
                    'ADVANCE_START_PERIOD_PAST',
                );
            }

            // Its payroll has left review, so the first installment would only
            // fold into the next month on top of that month's own.
            if ($this->isPeriodClosed($startPeriod)) {
                throw new LedgerRuleException(
                    "Payroll {$startPeriod} has already left review; start the recovery in a later month.",
                    'ADVANCE_START_PERIOD_CLOSED',
                );
            }

            // Floor to the dirham so the last installment is never below the others.
            $monthly = floor($amount / $count * 1000) / 1000;

            if ($monthly <= 0) {
                throw new LedgerRuleException('The amount is too small for that many installments.', 'INVALID_INSTALLMENTS');
            }

            $this->assertLimits($employee, $amount, $monthly);

            $advance = EmployeeAdvance::create([
                'employee_id' => $employee->id,
                'operating_unit_id' => $employee->operating_unit_id,
                'type' => EmployeeAdvanceType::from($data['type']),
                'amount' => $amount,
                'installments' => $count,
                'monthly_amount' => $monthly,
                'start_period' => $startPeriod,
                'status' => EmployeeAdvanceStatus::Requested,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $user->id,
            ]);

            $period = Carbon::createFromFormat('!Y-m', $startPeriod);

            for ($i = 0; $i < $count; $i++) {
                $isLast = $i === $count - 1;

                AdvanceInstallment::create([
                    'employee_advance_id' => $advance->id,
                    'period' => $period->copy()->addMonthsNoOverflow($i)->format('Y-m'),
                    'amount' => $isLast ? round($amount - $monthly * ($count - 1), 4) : $monthly,
                    'recovered_amount' => 0,
                    'status' => AdvanceInstallmentStatus::Pending,
                ]);
            }

            return $advance;
        });
    }

    /**
     * @throws LedgerRuleException
     */
    public function approve(EmployeeAdvance $advance, User $user): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $user): EmployeeAdvance {
            $locked = $this->lock($advance);
            $this->guardTransition($locked, EmployeeAdvanceStatus::Approved);

            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->employee_id);

            if ($employee->trashed() || $employee->status === EmployeeStatus::Terminated) {
                throw new LedgerRuleException('The employee is no longer active, so the advance cannot be approved.', 'ADVANCE_EMPLOYEE_INACTIVE');
            }

            // The limits may have changed since the request.
            $this->assertLimits($employee, (float) $locked->amount, (float) $locked->monthly_amount, $locked->id);

            $locked->update([
                'status' => EmployeeAdvanceStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Pay an approved advance out of a treasury: DR 1215 (employee) / CR treasury.
     * A schedule whose first months have already passed (or whose payroll has
     * left review) moves forward to the first open period as a whole, and a
     * calculated payroll from that period on that already has the employee's
     * payslip must be recalculated to pick the installment up, so a late payout
     * never makes two installments due at once.
     *
     * @throws LedgerRuleException
     */
    public function payout(EmployeeAdvance $advance, ?string $cashAccountId, User $user): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $cashAccountId, $user): EmployeeAdvance {
            $locked = $this->lock($advance);
            $this->guardTransition($locked, EmployeeAdvanceStatus::Paid);

            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->employee_id);

            // Payroll skips terminated employees, so nothing would ever be recovered.
            if ($employee->trashed() || $employee->status === EmployeeStatus::Terminated) {
                throw new LedgerRuleException('The employee is no longer active, so the advance cannot be paid out.', 'ADVANCE_EMPLOYEE_INACTIVE');
            }

            $treasuryCode = $this->lydTreasuryCode($cashAccountId);
            $this->shiftScheduleToOpenPeriod($locked);
            $amount = round((float) $locked->amount, 4);
            $label = $locked->type->label();
            $name = $employee->entity?->name ?? $employee->id;

            $entry = $this->accountingService->postJournal(
                "صرف {$label} للموظف {$name}",
                [
                    [
                        'account_code' => EmployeeAdvanceAccounts::ROOT_CODE,
                        'debit' => $amount,
                        'operating_unit_id' => $employee->operating_unit_id,
                        'employee_id' => $employee->id,
                        'memo' => "{$label}: {$name}",
                    ],
                    [
                        'account_code' => $treasuryCode,
                        'credit' => $amount,
                        'operating_unit_id' => $employee->operating_unit_id,
                        'memo' => "{$label}: {$name}",
                    ],
                ],
                'EmployeeAdvance',
                $locked->id,
                Company::query()->value('id'),
                userId: $user->id,
            );

            $locked->update([
                'status' => EmployeeAdvanceStatus::Paid,
                'cash_account_id' => $cashAccountId,
                'payout_entry_id' => $entry->id,
                'paid_at' => now(),
            ]);

            $this->flagCalculatedRunsFrom($employee->id, (string) $locked->start_period);

            return $locked;
        });
    }

    /**
     * A cash repayment: DR treasury / CR 1215 (employee). It shortens the
     * schedule from its last installment backwards, never touching what a
     * payroll past review has already deducted. A calculated payroll that
     * deducts an installment it reduces must be recalculated.
     *
     * @throws LedgerRuleException
     */
    public function repay(EmployeeAdvance $advance, float $amount, ?string $cashAccountId, User $user): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $amount, $cashAccountId, $user): EmployeeAdvance {
            $locked = $this->lock($advance);

            if ($locked->status !== EmployeeAdvanceStatus::Paid) {
                throw new LedgerRuleException(
                    "Only a paid-out advance can be repaid; this one is {$locked->status->value}.",
                    'INVALID_STATE_TRANSITION',
                );
            }

            $amount = round($amount, 4);

            if ($amount <= 0) {
                throw new LedgerRuleException('A repayment must be greater than zero.', 'INVALID_AMOUNT');
            }

            $treasuryCode = $this->lydTreasuryCode($cashAccountId);

            $installments = AdvanceInstallment::query()
                ->where('employee_advance_id', $locked->id)
                ->where('status', AdvanceInstallmentStatus::Pending)
                ->orderByDesc('period')
                ->lockForUpdate()
                ->get();

            $frozen = $this->reservedByInstallment(
                $locked->employee_id,
                [PayrollRunStatus::PendingApproval, PayrollRunStatus::Approved, PayrollRunStatus::Paid],
            );

            $available = round((float) $installments->sum(
                fn (AdvanceInstallment $row) => max(0.0, $row->remaining() - ($frozen[$row->id] ?? 0.0))
            ), 4);

            if ($amount - $available > self::TOLERANCE) {
                throw new LedgerRuleException(
                    "The repayment exceeds what is left to recover ({$available}).",
                    'ADVANCE_REPAYMENT_EXCEEDS',
                    ['available' => $available],
                );
            }

            $employee = $locked->employee()->firstOrFail();
            $name = $employee->entity?->name ?? $employee->id;
            $label = $locked->type->label();

            $this->accountingService->postJournal(
                "سداد نقدي من {$label} الموظف {$name}",
                [
                    [
                        'account_code' => $treasuryCode,
                        'debit' => $amount,
                        'operating_unit_id' => $employee->operating_unit_id,
                        'memo' => "سداد {$label}: {$name}",
                    ],
                    [
                        'account_code' => EmployeeAdvanceAccounts::ROOT_CODE,
                        'credit' => $amount,
                        'operating_unit_id' => $employee->operating_unit_id,
                        'employee_id' => $employee->id,
                        'memo' => "سداد {$label}: {$name}",
                    ],
                ],
                'EmployeeAdvance',
                $locked->id,
                Company::query()->value('id'),
                userId: $user->id,
            );

            $left = $amount;
            $touched = [];

            foreach ($installments as $row) {
                if ($left <= self::TOLERANCE) {
                    break;
                }

                $capacity = round(max(0.0, $row->remaining() - ($frozen[$row->id] ?? 0.0)), 4);
                $take = round(min($capacity, $left), 4);

                if ($take <= 0) {
                    continue;
                }

                $row->recovered_amount = round((float) $row->recovered_amount + $take, 4);

                if ($row->remaining() <= self::TOLERANCE) {
                    $row->status = AdvanceInstallmentStatus::Recovered;
                }

                $row->save();
                $left = round($left - $take, 4);
                $touched[] = $row->id;
            }

            $this->flagCalculatedRuns($locked->employee_id, $touched);
            $this->settleIfRecovered($locked);

            return $locked->refresh();
        });
    }

    /**
     * Cancel a request before it is paid out; its schedule is cancelled too.
     * Undoing an approval needs an approver ($mayCancelApproved).
     *
     * @throws LedgerRuleException
     */
    public function cancel(EmployeeAdvance $advance, bool $mayCancelApproved = true): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $mayCancelApproved): EmployeeAdvance {
            $locked = $this->lock($advance);
            $this->guardTransition($locked, EmployeeAdvanceStatus::Cancelled);

            if ($locked->status === EmployeeAdvanceStatus::Approved && ! $mayCancelApproved) {
                throw new LedgerRuleException(
                    'Only an approve-advances holder can cancel an approved advance.',
                    'ADVANCE_CANCEL_FORBIDDEN',
                );
            }

            AdvanceInstallment::query()
                ->where('employee_advance_id', $locked->id)
                ->get()
                ->each(fn (AdvanceInstallment $row) => $row->update(['status' => AdvanceInstallmentStatus::Cancelled]));

            $locked->update([
                'status' => EmployeeAdvanceStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Payroll: the locked deduction lines for one payslip. Every pending
     * installment of a paid-out advance due by the run's period is deducted,
     * oldest first, less what another unposted run already deducts, up to the
     * deduction percentage of net pay (which also keeps net ≥ 0).
     *
     * @return list<array{type: string, employee_advance_id: string, installment_id: string, period: string, amount: float, locked: true}>
     */
    public function dueDeductions(Employee $employee, PayrollRun $run, float $netPay): array
    {
        if ($netPay <= 0) {
            return [];
        }

        $advanceIds = EmployeeAdvance::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeAdvanceStatus::Paid)
            ->pluck('id');

        if ($advanceIds->isEmpty()) {
            return [];
        }

        $installments = AdvanceInstallment::query()
            ->whereIn('employee_advance_id', $advanceIds)
            ->where('status', AdvanceInstallmentStatus::Pending)
            ->where('period', '<=', $run->period)
            ->orderBy('period')
            ->orderBy('created_at')
            ->get();

        $reserved = $this->reservedByInstallment(
            $employee->id,
            [PayrollRunStatus::Calculated, PayrollRunStatus::PendingApproval, PayrollRunStatus::Approved, PayrollRunStatus::Paid],
            $run->id,
        );

        $capLeft = round(min($netPay, $netPay * $this->maxDeductionPct() / 100), 4);
        $lines = [];

        foreach ($installments as $row) {
            if ($capLeft <= self::TOLERANCE) {
                break;
            }

            $available = round(max(0.0, $row->remaining() - ($reserved[$row->id] ?? 0.0)), 4);
            $take = round(min($available, $capLeft), 4);

            if ($take <= self::TOLERANCE) {
                continue;
            }

            $lines[] = [
                'type' => self::LINE_TYPE,
                'employee_advance_id' => $row->employee_advance_id,
                'installment_id' => $row->id,
                'period' => $row->period,
                'amount' => $take,
                'locked' => true,
            ];

            $capLeft = round($capLeft - $take, 4);
        }

        return $lines;
    }

    /**
     * Payroll posting: record what each payslip recovered. An installment
     * reduced since the run was calculated (a cash repayment) refuses the post.
     *
     * @throws LedgerRuleException
     */
    public function applyRecoveries(PayrollRun $run): void
    {
        $advanceIds = [];

        foreach ($run->payslips()->get() as $payslip) {
            foreach (self::advanceLines($payslip) as $line) {
                $row = AdvanceInstallment::query()->lockForUpdate()->find($line['installment_id']);
                $amount = round((float) $line['amount'], 4);

                if ($row === null || $row->status !== AdvanceInstallmentStatus::Pending || $amount - $row->remaining() > self::TOLERANCE) {
                    throw new LedgerRuleException(
                        "An advance installment changed after payroll {$run->period} was calculated; recalculate it before posting.",
                        'ADVANCE_RECALCULATE_REQUIRED',
                    );
                }

                $row->recovered_amount = round((float) $row->recovered_amount + $amount, 4);
                $row->payslip_id = $payslip->id;

                if ($row->remaining() <= self::TOLERANCE) {
                    $row->status = AdvanceInstallmentStatus::Recovered;
                }

                $row->save();
                $advanceIds[$row->employee_advance_id] = true;
            }
        }

        foreach (array_keys($advanceIds) as $advanceId) {
            $advance = EmployeeAdvance::withoutGlobalScopes()->lockForUpdate()->find($advanceId);

            if ($advance !== null) {
                $this->settleIfRecovered($advance);
            }
        }
    }

    /**
     * HR-12: a credit to 1215 for the employee outside an advance's repayment
     * or payroll (a receipt voucher, a manual journal) may take only what they
     * owe beyond the schedules of their paid-out advances, i.e. a balance a
     * payment voucher put there (W5-00). The scheduled part is recovered by
     * payroll or a repayment, which shortens the schedule; crediting it any
     * other way would leave payroll deducting it again.
     *
     * Static so AccountingService can call it without a circular dependency.
     *
     * @throws LedgerRuleException ADVANCE_USE_REPAY
     */
    public static function assertUnscheduledCredit(string $employeeId, float $credit): void
    {
        $advanceIds = EmployeeAdvance::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeAdvanceStatus::Paid)
            ->pluck('id')
            ->all();

        if ($advanceIds === []) {
            return;
        }

        $scheduled = round((float) AdvanceInstallment::query()
            ->whereIn('employee_advance_id', $advanceIds)
            ->where('status', AdvanceInstallmentStatus::Pending)
            ->selectRaw('COALESCE(SUM(amount), 0) - COALESCE(SUM(recovered_amount), 0) AS outstanding')
            ->value('outstanding'), 4);

        $balance = round((float) JournalLine::query()
            ->posted()
            ->where('employee_id', $employeeId)
            ->whereIn('account_id', EmployeeAdvanceAccounts::subtreeIds())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->value('balance'), 4);

        $unscheduled = round(max(0.0, $balance - $scheduled), 4);

        if (round($credit, 4) - $unscheduled > self::TOLERANCE) {
            throw new LedgerRuleException(
                "The employee has an advance being recovered; only {$unscheduled} of their balance is outside its schedule. "
                .'Record cash against the advance as its repayment (POST /employee-advances/{id}/repay).',
                'ADVANCE_USE_REPAY',
                ['employee_advance_ids' => $advanceIds, 'unscheduled_balance' => $unscheduled],
            );
        }
    }

    /**
     * HR-12: a reversal (ACC-13) or a voucher cancellation mirrors an entry
     * without the party checks, so the schedule rule is applied here.
     *
     * - An advance payout or repayment, or a payroll run that recovered
     *   installments, keeps its schedule in step with the ledger only through
     *   the advance flow (cancel before payout, repay); reversing its entry
     *   would leave the schedule deducting money no longer owed, or owed money
     *   no longer scheduled (ADVANCE_USE_ADVANCE_FLOW).
     * - Any other entry on an employee's 1215 may be reversed only while the
     *   credit it posts stays within their balance outside the schedule, so
     *   the posted balance never drops below the pending installments payroll
     *   still deducts (ADVANCE_USE_REPAY).
     *
     * Static so AccountingService can call it without a circular dependency.
     *
     * @throws LedgerRuleException
     */
    public static function assertReversible(JournalEntry $entry): void
    {
        $sourceType = $entry->source_document_type;
        $sourceId = $entry->source_document_id;

        $isAdvanceEntry = $sourceType === 'EmployeeAdvance';
        $isRecoveringPayroll = $sourceType === 'PayrollRun' && $sourceId !== null
            && Payslip::query()
                ->where('payroll_run_id', $sourceId)
                ->get()
                ->contains(fn (Payslip $payslip) => self::advanceLines($payslip) !== []);

        if ($isAdvanceEntry || $isRecoveringPayroll) {
            throw new LedgerRuleException(
                $isAdvanceEntry
                    ? "Entry {$entry->reference} belongs to an employee advance; its schedule follows only the advance itself. "
                        .'Cancel the advance before payout, or record cash returned as its repayment (POST /employee-advances/{id}/repay).'
                    : "Entry {$entry->reference} belongs to a payroll run that recovered advance installments; reversing it would leave them marked recovered. "
                        .'Correct the advance through its own flow (POST /employee-advances/{id}/repay).',
                'ADVANCE_USE_ADVANCE_FLOW',
                ['source_document_type' => $sourceType, 'source_document_id' => $sourceId],
            );
        }

        $advanceAccountIds = EmployeeAdvanceAccounts::subtreeIds();

        /** @var array<string, float> $reversalCredits */
        $reversalCredits = [];

        foreach ($entry->lines as $line) {
            if ($line->employee_id === null || ! in_array($line->account_id, $advanceAccountIds, true)) {
                continue;
            }

            // The mirror swaps sides: the original's debit becomes a credit.
            $reversalCredits[$line->employee_id] = round(
                ($reversalCredits[$line->employee_id] ?? 0.0) + (float) $line->debit - (float) $line->credit,
                4,
            );
        }

        foreach ($reversalCredits as $employeeId => $credit) {
            if ($credit > self::TOLERANCE) {
                self::assertUnscheduledCredit((string) $employeeId, $credit);
            }
        }
    }

    /**
     * The advance deduction lines on a payslip.
     *
     * @return list<array<string, mixed>>
     */
    public static function advanceLines(Payslip $payslip): array
    {
        return array_values(array_filter(
            $payslip->deductions ?? [],
            fn ($line) => is_array($line) && self::isAdvanceLine($line),
        ));
    }

    /**
     * Whether a payslip deduction line is payroll's advance recovery line.
     *
     * @param  array<string, mixed>  $line
     */
    public static function isAdvanceLine(array $line): bool
    {
        return ($line['type'] ?? null) === self::LINE_TYPE && isset($line['installment_id']);
    }

    /**
     * ADVANCE_NO_MONTHLY_SALARY / ADVANCE_LIMIT_EXCEEDED / ADVANCE_DEDUCTION_LIMIT
     * against the monthly base salary in force today.
     *
     * @throws LedgerRuleException
     */
    private function assertLimits(Employee $employee, float $amount, float $monthly, ?string $excludeAdvanceId = null): void
    {
        $company = Company::query()->first();
        $multiple = (float) ($company?->advance_max_salary_multiple ?? 1);
        $salary = $this->monthlySalary($employee);

        // Owner decision (HR-12): only monthly pay terms give a base to advance
        // against; hourly and piece-rate employees are not eligible.
        if ($salary <= 0) {
            throw new LedgerRuleException(
                'Only an employee on a monthly salary can take an advance or loan; this employee has no monthly base salary in force.',
                'ADVANCE_NO_MONTHLY_SALARY',
            );
        }

        $maxAmount = round($salary * $multiple, 4);

        if ($amount - $maxAmount > self::TOLERANCE) {
            throw new LedgerRuleException(
                "The advance exceeds the limit of {$maxAmount} ({$multiple} × the monthly base salary of {$salary}).",
                'ADVANCE_LIMIT_EXCEEDED',
                ['max_amount' => $maxAmount, 'monthly_salary' => $salary],
            );
        }

        $otherMonthly = round((float) EmployeeAdvance::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->whereIn('status', [EmployeeAdvanceStatus::Requested, EmployeeAdvanceStatus::Approved, EmployeeAdvanceStatus::Paid])
            ->when($excludeAdvanceId !== null, fn ($query) => $query->whereKeyNot($excludeAdvanceId))
            ->sum('monthly_amount'), 4);

        $maxMonthly = round($salary * $this->maxDeductionPct() / 100, 4);

        if ($monthly + $otherMonthly - $maxMonthly > self::TOLERANCE) {
            throw new LedgerRuleException(
                "The monthly recovery of {$monthly} plus {$otherMonthly} from open advances exceeds {$maxMonthly} "
                .'('.$this->maxDeductionPct().'% of the monthly base salary).',
                'ADVANCE_DEDUCTION_LIMIT',
                ['max_monthly' => $maxMonthly, 'open_monthly' => $otherMonthly],
            );
        }
    }

    /**
     * The monthly base salary in force today (HR-11); 0 for hourly terms.
     */
    private function monthlySalary(Employee $employee): float
    {
        $terms = $this->compensations->inEffectOn($employee, CompanyClock::today()) ?? $employee;

        if ($terms->pay_type !== PayType::Monthly) {
            return 0.0;
        }

        return round((float) ($terms->monthly_salary ?? 0), 4);
    }

    /**
     * The ledger code of a local-currency treasury. Advances are kept in LYD,
     * so a foreign treasury would record LYD against its foreign balance.
     *
     * @throws LedgerRuleException
     */
    private function lydTreasuryCode(?string $cashAccountId): string
    {
        $cashAccount = $cashAccountId !== null ? CashAccount::with('account')->find($cashAccountId) : null;

        if ($cashAccount !== null && strtoupper(trim((string) $cashAccount->currency)) !== 'LYD') {
            throw new LedgerRuleException(
                "Treasury '{$cashAccount->name}' holds {$cashAccount->currency}; advances are paid and repaid in LYD only.",
                'ADVANCE_TREASURY_NOT_LYD',
            );
        }

        return TreasuryAccounts::codeForId($cashAccountId);
    }

    private function isPeriodClosed(string $period): bool
    {
        return PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', self::CLOSED_RUN_STATUSES)
            ->exists();
    }

    /**
     * Move the whole schedule forward when its start is before the first
     * period payroll can still deduct from (this month or later, past any run
     * that has left review).
     */
    private function shiftScheduleToOpenPeriod(EmployeeAdvance $advance): void
    {
        $start = Carbon::createFromFormat('!Y-m', (string) $advance->start_period);
        $open = Carbon::createFromFormat('!Y-m', max((string) $advance->start_period, substr(CompanyClock::today(), 0, 7)));

        while ($this->isPeriodClosed($open->format('Y-m'))) {
            $open->addMonthNoOverflow();
        }

        $months = (int) round($start->diffInMonths($open));

        if ($months <= 0) {
            return;
        }

        // Latest first, so no row lands on a period another row still holds.
        AdvanceInstallment::query()
            ->where('employee_advance_id', $advance->id)
            ->orderByDesc('period')
            ->lockForUpdate()
            ->get()
            ->each(function (AdvanceInstallment $row) use ($months): void {
                $row->update([
                    'period' => Carbon::createFromFormat('!Y-m', $row->period)->addMonthsNoOverflow($months)->format('Y-m'),
                ]);
            });

        $advance->update(['start_period' => $open->format('Y-m')]);
    }

    private function maxDeductionPct(): float
    {
        return (float) (Company::query()->value('advance_max_deduction_pct') ?? 50);
    }

    /**
     * What unposted payroll runs already deduct, per installment.
     *
     * @param  list<PayrollRunStatus>  $statuses
     * @return array<string, float>
     */
    private function reservedByInstallment(string $employeeId, array $statuses, ?string $exceptRunId = null): array
    {
        $runIds = PayrollRun::query()
            ->whereIn('status', $statuses)
            ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return [];
        }

        $reserved = [];

        Payslip::query()
            ->whereIn('payroll_run_id', $runIds)
            ->where('employee_id', $employeeId)
            ->get()
            ->each(function (Payslip $payslip) use (&$reserved): void {
                foreach (self::advanceLines($payslip) as $line) {
                    $id = (string) $line['installment_id'];
                    $reserved[$id] = round(($reserved[$id] ?? 0.0) + (float) $line['amount'], 4);
                }
            });

        return $reserved;
    }

    /**
     * A calculated run that deducts an installment a repayment just reduced
     * carries a stale amount; it cannot be submitted until recalculated.
     *
     * @param  list<string>  $installmentIds
     */
    private function flagCalculatedRuns(string $employeeId, array $installmentIds): void
    {
        if ($installmentIds === []) {
            return;
        }

        $runIds = PayrollRun::query()->where('status', PayrollRunStatus::Calculated)->pluck('id');

        $stale = Payslip::query()
            ->whereIn('payroll_run_id', $runIds)
            ->where('employee_id', $employeeId)
            ->get()
            ->filter(fn (Payslip $payslip) => collect(self::advanceLines($payslip))
                ->contains(fn (array $line) => in_array($line['installment_id'], $installmentIds, true)))
            ->pluck('payroll_run_id')
            ->unique();

        PayrollRun::query()->whereIn('id', $stale)->get()
            ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
    }

    /**
     * A calculated run from $fromPeriod on that has the employee's payslip was
     * worked out before this advance was paid out, so it lacks the installment;
     * it cannot be submitted until recalculated.
     */
    private function flagCalculatedRunsFrom(string $employeeId, string $fromPeriod): void
    {
        $runIds = PayrollRun::query()
            ->where('status', PayrollRunStatus::Calculated)
            ->where('period', '>=', $fromPeriod)
            ->whereHas('payslips', fn ($query) => $query->where('employee_id', $employeeId))
            ->pluck('id');

        PayrollRun::query()->whereIn('id', $runIds)->get()
            ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
    }

    private function settleIfRecovered(EmployeeAdvance $advance): void
    {
        $pending = AdvanceInstallment::query()
            ->where('employee_advance_id', $advance->id)
            ->where('status', AdvanceInstallmentStatus::Pending)
            ->exists();

        if (! $pending && $advance->status === EmployeeAdvanceStatus::Paid) {
            $advance->update([
                'status' => EmployeeAdvanceStatus::Settled,
                'settled_at' => now(),
            ]);
        }
    }

    private function lock(EmployeeAdvance $advance): EmployeeAdvance
    {
        return EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
    }

    /**
     * @throws LedgerRuleException
     */
    private function guardTransition(EmployeeAdvance $advance, EmployeeAdvanceStatus $target): void
    {
        if (! $advance->status->canTransitionTo($target)) {
            throw new LedgerRuleException(
                "The advance cannot move from {$advance->status->value} to {$target->value}.",
                'INVALID_STATE_TRANSITION',
            );
        }
    }
}
