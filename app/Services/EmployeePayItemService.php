<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeductionAccountRole;
use App\Enums\EmployeeAdjustmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\PayItemCalculation;
use App\Enums\PayItemKind;
use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\LedgerRuleException;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeePayItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ReferenceLookup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W5-03 (HR-13): rewards and deductions.
 *
 * - Standing pay items recur in every period they are in force
 *   (effective_from..effective_to, while active): a fixed amount, or a
 *   percentage of the period's base (base salary + attendance pay). Fixed
 *   amounts are not prorated for a part month.
 * - One-off adjustments belong to one period and count once approved.
 * - Codes come from the allowance-types (addition) and deduction-types
 *   (deduction) lookups. A deduction is credited by its type's
 *   `fields.account_role`: liability → 225, expense_reduction → 57 (a type
 *   without a role is a withholding, 225). Advance recovery (DED-ADV) is not a
 *   pay item: it comes from the advance schedule (HR-12).
 * - Payroll copies them onto the payslip as calculated: additions into
 *   `additions`, deductions as locked lines the payslip editor cannot change.
 *   For the advance cap they count like manual deductions (the cap is a share
 *   of gross − these − manual deductions).
 * - A period whose payroll has left review is frozen: what applied in it can
 *   no longer change (PAY_ITEM_FROZEN, PAY_PERIOD_CLOSED). Every period up to
 *   the latest frozen one is closed to new terms: no item may start in it and
 *   no adjustment may be entered, moved into or approved for it, since no run
 *   the company still calculates would pick it up. A draft adjustment never
 *   reached a payslip, so it can always be removed or moved to an open period.
 * - A change to an item reaching a calculated run that has the employee's
 *   payslip flags it for recalculation; approving or removing an approved
 *   adjustment flags the period's calculated run whether or not the employee
 *   has a payslip in it (submission re-checks PAYROLL_ADJUSTMENT_UNPAID).
 * - An item is in force for a whole month when it covers any day of it, so
 *   two active items of one code may not share a month (PAY_ITEM_OVERLAP):
 *   to change terms, end the old one on the last day of its last month.
 * - Only an employee payroll pays (not terminated, not piece rate) takes
 *   items and adjustments (PAY_EMPLOYEE_NOT_ON_PAYROLL).
 */
final class EmployeePayItemService
{
    /** Where a payslip line came from. */
    public const SOURCE_PAY_ITEM = 'pay_item';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    /** Advance recovery types (payroll's own line type and the DED-ADV lookup). */
    private const ADVANCE_CODES = ['ADVANCE', 'DED-ADV'];

    private const FROZEN_RUN_STATUSES = [
        PayrollRunStatus::PendingApproval,
        PayrollRunStatus::Approved,
        PayrollRunStatus::Paid,
        PayrollRunStatus::Posted,
    ];

    /**
     * @param  array{kind: string, code: string, calc: string, value: float|string, effective_from: string, effective_to?: string|null, active?: bool, notes?: string|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function createItem(Employee $employee, array $data, ?string $userId): EmployeePayItem
    {
        return DB::transaction(function () use ($employee, $data, $userId): EmployeePayItem {
            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($employee->id);

            $kind = PayItemKind::from($data['kind']);
            $calc = PayItemCalculation::from($data['calc']);
            $code = $this->assertCode($kind, (string) $data['code']);
            $value = round((float) $data['value'], 4);
            $from = Carbon::parse($data['effective_from'])->toDateString();
            $to = isset($data['effective_to']) && $data['effective_to'] !== null
                ? Carbon::parse($data['effective_to'])->toDateString()
                : null;

            $this->assertOnPayroll($employee);
            $this->assertValue($calc, $value);
            $this->assertRange($from, $to);

            $frozenUntil = $this->frozenUntil();

            if ($frozenUntil !== null && $from <= $frozenUntil) {
                throw new LedgerRuleException(
                    "Payroll up to {$frozenUntil} has left review; a pay item can only start after it.",
                    'PAY_ITEM_PERIOD_CLOSED',
                    ['frozen_until' => $frozenUntil],
                );
            }

            if ((bool) ($data['active'] ?? true)) {
                $this->assertNoOverlap($employee->id, $kind, $code, $from, $to, null);
            }

            $item = EmployeePayItem::create([
                'employee_id' => $employee->id,
                'code' => $code,
                'kind' => $kind,
                'calc' => $calc,
                'value' => $value,
                'effective_from' => $from,
                'effective_to' => $to,
                'active' => (bool) ($data['active'] ?? true),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->flagCalculatedRuns($employee->id, fn (string $start, string $end) => $item->coversRange($start, $end));

            return $item->refresh();
        });
    }

    /**
     * A pay item that applied in a frozen period keeps its terms there: its
     * code, kind, calculation, value and start date are fixed, and its end
     * date may only move on or after the first day of the latest frozen
     * month (it still covers that month, so no frozen payslip changes). To
     * change the terms, end it on the last day of the last month it should
     * pay and add a new one from the next month.
     *
     * @param  array{kind?: string, code?: string, calc?: string, value?: float|string, effective_from?: string, effective_to?: string|null, active?: bool, notes?: string|null, record_version?: int}  $data
     *
     * @throws LedgerRuleException
     */
    public function updateItem(EmployeePayItem $item, array $data): EmployeePayItem
    {
        return DB::transaction(function () use ($item, $data): EmployeePayItem {
            // The employee lock serializes the overlap check with createItem.
            Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($item->employee_id);
            $item = EmployeePayItem::query()->lockForUpdate()->findOrFail($item->id);

            if (isset($data['record_version'])) {
                $item->record_version = (int) $data['record_version'];
            }

            $old = clone $item;

            $kind = array_key_exists('kind', $data) ? PayItemKind::from($data['kind']) : $item->kind;
            $calc = array_key_exists('calc', $data) ? PayItemCalculation::from($data['calc']) : $item->calc;
            $codeChanged = array_key_exists('code', $data) && strtoupper(trim((string) $data['code'])) !== strtoupper(trim($item->code));
            $code = $codeChanged || $kind !== $item->kind
                ? $this->assertCode($kind, (string) ($data['code'] ?? $item->code))
                : $item->code;
            $value = array_key_exists('value', $data) ? round((float) $data['value'], 4) : round((float) $item->value, 4);
            $from = array_key_exists('effective_from', $data)
                ? Carbon::parse($data['effective_from'])->toDateString()
                : $item->effective_from->toDateString();
            $to = array_key_exists('effective_to', $data)
                ? ($data['effective_to'] !== null ? Carbon::parse($data['effective_to'])->toDateString() : null)
                : $item->effective_to?->toDateString();

            $this->assertValue($calc, $value);
            $this->assertRange($from, $to);

            $frozenUntil = $this->frozenUntil();

            if ($frozenUntil !== null) {
                $oldFrom = $old->effective_from->toDateString();
                $oldTo = $old->effective_to?->toDateString();
                // Coverage is by whole month: an end inside the latest frozen
                // month still covers it, so only an end before it changes a
                // frozen payslip.
                $frozenMonthStart = Carbon::parse($frozenUntil)->startOfMonth()->toDateString();

                if ($oldFrom <= $frozenUntil) {
                    $termsChanged = $code !== $old->code
                        || $kind !== $old->kind
                        || $calc !== $old->calc
                        || abs($value - (float) $old->value) > 0.00001
                        || $from !== $oldFrom;
                    $endMoved = $to !== $oldTo
                        && (($oldTo !== null && $oldTo < $frozenMonthStart) || ($to !== null && $to < $frozenMonthStart));

                    if ($termsChanged || $endMoved) {
                        throw new LedgerRuleException(
                            "This pay item already applied in payroll up to {$frozenUntil}, which has left review; only its end date (on or after {$frozenMonthStart}), its active flag and notes can change. To change its terms, end it on the last day of the last month it should pay (at the earliest {$frozenUntil}) and add a new one from the next month.",
                            'PAY_ITEM_FROZEN',
                            ['frozen_until' => $frozenUntil, 'earliest_end' => $frozenMonthStart],
                        );
                    }
                } elseif ($from <= $frozenUntil) {
                    throw new LedgerRuleException(
                        "Payroll up to {$frozenUntil} has left review; a pay item can only start after it.",
                        'PAY_ITEM_PERIOD_CLOSED',
                        ['frozen_until' => $frozenUntil],
                    );
                }
            }

            $active = array_key_exists('active', $data) ? (bool) $data['active'] : $item->active;
            $reachChanged = $code !== $old->code
                || $kind !== $old->kind
                || $from !== $old->effective_from->toDateString()
                || $to !== $old->effective_to?->toDateString()
                || $active !== $old->active;

            if ($active && $reachChanged) {
                $this->assertNoOverlap($item->employee_id, $kind, $code, $from, $to, $item->id);
            }

            $item->fill([
                'code' => $code,
                'kind' => $kind,
                'calc' => $calc,
                'value' => $value,
                'effective_from' => $from,
                'effective_to' => $to,
                'active' => $active,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $item->notes,
            ]);

            if ($item->isDirty()) {
                $item->save();
            }

            $this->flagCalculatedRuns(
                $item->employee_id,
                fn (string $start, string $end) => $old->coversRange($start, $end) || $item->coversRange($start, $end),
            );

            return $item->refresh();
        });
    }

    /**
     * Only an item that never applied in a frozen period can be removed;
     * otherwise it is ended (effective_to) or deactivated.
     *
     * @throws LedgerRuleException
     */
    public function deleteItem(EmployeePayItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $item = EmployeePayItem::query()->lockForUpdate()->findOrFail($item->id);
            $frozenUntil = $this->frozenUntil();

            if ($frozenUntil !== null && $item->effective_from->toDateString() <= $frozenUntil) {
                throw new LedgerRuleException(
                    "This pay item already applied in payroll up to {$frozenUntil}, which has left review; end it (effective_to) or deactivate it instead.",
                    'PAY_ITEM_FROZEN',
                    ['frozen_until' => $frozenUntil],
                );
            }

            $item->delete();

            $this->flagCalculatedRuns($item->employee_id, fn (string $start, string $end) => $item->coversRange($start, $end));
        });
    }

    /**
     * @param  array{employee_id: string, period: string, kind: string, code: string, amount: float|string, reason: string}  $data
     *
     * @throws LedgerRuleException
     */
    public function createAdjustment(array $data, User $user): EmployeeAdjustment
    {
        return DB::transaction(function () use ($data, $user): EmployeeAdjustment {
            // Unit-scoped: a unit user can only name an employee of their unit.
            $employee = Employee::query()->lockForUpdate()->find($data['employee_id']);

            if ($employee === null) {
                throw new LedgerRuleException('Employee not found.', 'EMPLOYEE_NOT_FOUND');
            }

            $this->assertOnPayroll($employee);

            $kind = PayItemKind::from($data['kind']);
            $code = $this->assertCode($kind, (string) $data['code']);
            $this->assertPeriodOpen($data['period']);
            $this->assertAmount((float) $data['amount']);

            return EmployeeAdjustment::create([
                'employee_id' => $employee->id,
                'period' => $data['period'],
                'kind' => $kind,
                'code' => $code,
                'amount' => round((float) $data['amount'], 4),
                'reason' => $data['reason'],
                'status' => EmployeeAdjustmentStatus::Draft,
                'created_by' => $user->id,
            ])->refresh();
        });
    }

    /**
     * Only a draft can be edited; an approved adjustment is removed and
     * entered again. A draft never reached a payslip, so only the period it
     * ends up in must be open: a draft left in a closed period can be moved
     * to an open one.
     *
     * @param  array{period?: string, kind?: string, code?: string, amount?: float|string, reason?: string, record_version?: int}  $data
     *
     * @throws LedgerRuleException
     */
    public function updateAdjustment(EmployeeAdjustment $adjustment, array $data): EmployeeAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data): EmployeeAdjustment {
            $adjustment = EmployeeAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if (isset($data['record_version'])) {
                $adjustment->record_version = (int) $data['record_version'];
            }

            if ($adjustment->status !== EmployeeAdjustmentStatus::Draft) {
                throw new LedgerRuleException(
                    'Only a draft adjustment can be edited; remove the approved one and enter it again.',
                    'PAY_ADJUSTMENT_NOT_DRAFT',
                );
            }

            $kind = array_key_exists('kind', $data) ? PayItemKind::from($data['kind']) : $adjustment->kind;
            $code = array_key_exists('code', $data) || $kind !== $adjustment->kind
                ? $this->assertCode($kind, (string) ($data['code'] ?? $adjustment->code))
                : $adjustment->code;
            $period = $data['period'] ?? $adjustment->period;
            $amount = array_key_exists('amount', $data) ? round((float) $data['amount'], 4) : round((float) $adjustment->amount, 4);

            $this->assertPeriodOpen($period);
            $this->assertAmount($amount);

            $adjustment->fill([
                'period' => $period,
                'kind' => $kind,
                'code' => $code,
                'amount' => $amount,
                'reason' => $data['reason'] ?? $adjustment->reason,
            ]);

            if ($adjustment->isDirty()) {
                $adjustment->save();
            }

            return $adjustment->refresh();
        });
    }

    /**
     * @throws LedgerRuleException
     */
    public function approveAdjustment(EmployeeAdjustment $adjustment, User $user): EmployeeAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user): EmployeeAdjustment {
            $adjustment = EmployeeAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if (! $adjustment->status->canTransitionTo(EmployeeAdjustmentStatus::Approved)) {
                throw new LedgerRuleException('Only a draft adjustment can be approved.', 'PAY_ADJUSTMENT_NOT_DRAFT');
            }

            $this->assertPeriodOpen($adjustment->period);
            $this->assertOnPayroll(Employee::withoutGlobalScopes()->findOrFail($adjustment->employee_id));

            $adjustment->update([
                'status' => EmployeeAdjustmentStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $this->flagCalculatedRunOf($adjustment->period);

            return $adjustment->refresh();
        });
    }

    /**
     * A draft is removed by HR at any time: it never reached a payslip. An
     * approved adjustment is removed only by an approver (the controller
     * checks), and only while its period's payroll has not left review.
     *
     * @throws LedgerRuleException
     */
    public function deleteAdjustment(EmployeeAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment): void {
            $adjustment = EmployeeAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            $wasApproved = $adjustment->status === EmployeeAdjustmentStatus::Approved;

            if ($wasApproved) {
                $this->assertPeriodNotFrozen($adjustment->period);
            }

            $adjustment->delete();

            if ($wasApproved) {
                $this->flagCalculatedRunOf($adjustment->period);
            }
        });
    }

    /**
     * Payroll: the employee's standing items in force in the period and the
     * period's approved adjustments, as payslip lines. Percentages apply to
     * $basePay (base salary + attendance pay). Deduction lines are locked and
     * carry the account role they credit.
     *
     * @return array{additions: list<array<string, mixed>>, deductions: list<array<string, mixed>>}
     */
    public function linesFor(Employee $employee, string $period, float $basePay): array
    {
        $start = Carbon::createFromFormat('!Y-m', $period)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();
        $roles = $this->accountRoles();

        $additions = [];
        $deductions = [];

        $items = EmployeePayItem::query()
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            $amount = $item->calc === PayItemCalculation::PercentOfBase
                ? round($basePay * (float) $item->value / 100, 4)
                : round((float) $item->value, 4);

            if ($amount <= 0) {
                continue;
            }

            $line = [
                'type' => $item->code,
                'source' => self::SOURCE_PAY_ITEM,
                'pay_item_id' => $item->id,
                'calc' => $item->calc->value,
                'value' => round((float) $item->value, 4),
                'amount' => $amount,
            ];

            if ($item->kind === PayItemKind::Addition) {
                $additions[] = $line;
            } else {
                $deductions[] = [...$line, 'account_role' => $this->roleFor($item->code, $roles)->value, 'locked' => true];
            }
        }

        $adjustments = EmployeeAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('period', $period)
            ->where('status', EmployeeAdjustmentStatus::Approved)
            ->orderBy('created_at')
            ->get();

        foreach ($adjustments as $adjustment) {
            $line = [
                'type' => $adjustment->code,
                'source' => self::SOURCE_ADJUSTMENT,
                'adjustment_id' => $adjustment->id,
                'reason' => $adjustment->reason,
                'amount' => round((float) $adjustment->amount, 4),
            ];

            if ($adjustment->kind === PayItemKind::Addition) {
                $additions[] = $line;
            } else {
                $deductions[] = [...$line, 'account_role' => $this->roleFor($adjustment->code, $roles)->value, 'locked' => true];
            }
        }

        return ['additions' => $additions, 'deductions' => $deductions];
    }

    /**
     * Manual payslip lines take the account role of the deduction type their
     * type names (matched on its code); any other type is a withholding (225).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function withAccountRoles(array $lines): array
    {
        $roles = $this->accountRoles();

        return array_map(
            fn (array $line) => [...$line, 'account_role' => $this->roleFor((string) ($line['type'] ?? ''), $roles)->value],
            $lines,
        );
    }

    /**
     * Where an allowance (addition) or deduction type code is still used: a
     * standing pay item or a one-off adjustment of that kind (removed ones
     * aside), matched case-insensitively like payroll matches it. Null when
     * unused. Codes are unique only per lookup category, so the kind matters.
     */
    public function codeReference(string $code, PayItemKind $kind): ?string
    {
        $code = strtoupper(trim($code));

        if (EmployeePayItem::query()->where('kind', $kind)->whereRaw('UPPER(code) = ?', [$code])->exists()) {
            return 'employee_pay_items';
        }

        if (EmployeeAdjustment::query()->where('kind', $kind)->whereRaw('UPPER(code) = ?', [$code])->exists()) {
            return 'employee_adjustments';
        }

        return null;
    }

    /**
     * A deduction type's account role changed: every calculated run with a
     * payslip line of that type was worked out on the old role and must be
     * recalculated before submission.
     */
    public function flagRunsUsingDeductionCode(string $code): void
    {
        $code = strtoupper(trim($code));
        $runIds = PayrollRun::query()
            ->where('status', PayrollRunStatus::Calculated)
            ->where('needs_recalculation', false)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return;
        }

        $affected = Payslip::query()
            ->whereIn('payroll_run_id', $runIds)
            ->get(['payroll_run_id', 'deductions'])
            ->filter(fn (Payslip $payslip): bool => collect($payslip->deductions ?? [])
                ->contains(fn ($line): bool => is_array($line) && strtoupper(trim((string) ($line['type'] ?? ''))) === $code))
            ->pluck('payroll_run_id')
            ->unique()
            ->values();

        if ($affected->isNotEmpty()) {
            PayrollRun::query()->whereIn('id', $affected)->get()
                ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
        }
    }

    /**
     * The account role a payslip deduction line was calculated with.
     *
     * @param  array<string, mixed>  $line
     */
    public static function roleOfLine(array $line): DeductionAccountRole
    {
        return DeductionAccountRole::tryFrom((string) ($line['account_role'] ?? '')) ?? DeductionAccountRole::Liability;
    }

    /**
     * Deduction-type codes (upper-cased) → their account role.
     *
     * @return array<string, DeductionAccountRole>
     */
    private function accountRoles(): array
    {
        $roles = [];

        foreach (ReferenceLookup::query()->where('category', PayItemKind::Deduction->lookupCategory())->get() as $lookup) {
            $role = DeductionAccountRole::tryFrom((string) ($lookup->fields['account_role'] ?? ''));

            if ($role !== null) {
                $roles[strtoupper(trim((string) $lookup->code))] = $role;
            }
        }

        return $roles;
    }

    /**
     * @param  array<string, DeductionAccountRole>  $roles
     */
    private function roleFor(string $code, array $roles): DeductionAccountRole
    {
        return $roles[strtoupper(trim($code))] ?? DeductionAccountRole::Liability;
    }

    /**
     * The code must be an active entry of the kind's lookup; advance recovery
     * is not a pay item (ADVANCE_DEDUCTION_MANUAL).
     *
     * @throws LedgerRuleException
     */
    private function assertCode(PayItemKind $kind, string $code): string
    {
        $code = strtoupper(trim($code));

        if (in_array($code, self::ADVANCE_CODES, true)) {
            throw new LedgerRuleException(
                'Advance and loan recovery is deducted from the advance schedule by payroll; it cannot be a pay item or adjustment.',
                'ADVANCE_DEDUCTION_MANUAL',
            );
        }

        $lookup = ReferenceLookup::query()
            ->where('category', $kind->lookupCategory())
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where('is_active', true)
            ->first();

        if ($lookup === null) {
            throw new LedgerRuleException(
                "{$code} is not an active {$kind->lookupCategory()} code.",
                'PAY_CODE_UNKNOWN',
                ['category' => $kind->lookupCategory()],
            );
        }

        return (string) $lookup->code;
    }

    /**
     * Payroll gives no payslip to a terminated or piece-rate employee, so an
     * item or adjustment for them would never be paid or deducted.
     *
     * @throws LedgerRuleException
     */
    private function assertOnPayroll(Employee $employee): void
    {
        if ($employee->trashed() || $employee->status === EmployeeStatus::Terminated || $employee->pay_type === PayType::PieceRate) {
            throw new LedgerRuleException(
                'Payroll does not pay this employee (terminated or on piece rate), so a pay item or adjustment for them would never apply.',
                'PAY_EMPLOYEE_NOT_ON_PAYROLL',
                ['employee_id' => $employee->id],
            );
        }
    }

    /**
     * An item is in force for every month it touches, so two active items of
     * one employee and code may not share a month: both would be paid or
     * deducted in full.
     *
     * @throws LedgerRuleException
     */
    private function assertNoOverlap(string $employeeId, PayItemKind $kind, string $code, string $from, ?string $to, ?string $ignoreId): void
    {
        $firstMonthStart = Carbon::parse($from)->startOfMonth()->toDateString();
        $lastMonthEnd = $to !== null ? Carbon::parse($to)->endOfMonth()->toDateString() : null;

        $clash = EmployeePayItem::query()
            ->where('employee_id', $employeeId)
            ->where('kind', $kind)
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->where('active', true)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($lastMonthEnd !== null, fn ($query) => $query->whereDate('effective_from', '<=', $lastMonthEnd))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $firstMonthStart))
            ->first();

        if ($clash !== null) {
            throw new LedgerRuleException(
                "Employee already has an active {$code} item in force in the same months (from {$clash->effective_from->toDateString()}"
                    .($clash->effective_to !== null ? " to {$clash->effective_to->toDateString()}" : '')
                    .'); end it on the last day of the month before this one starts.',
                'PAY_ITEM_OVERLAP',
                ['pay_item_id' => $clash->id],
            );
        }
    }

    /**
     * @throws LedgerRuleException
     */
    private function assertValue(PayItemCalculation $calc, float $value): void
    {
        if ($value <= 0 || ($calc === PayItemCalculation::PercentOfBase && $value > 100)) {
            throw new LedgerRuleException(
                'A pay item value must be above zero, and a percentage at most 100.',
                'PAY_ITEM_INVALID_VALUE',
            );
        }
    }

    /**
     * @throws LedgerRuleException
     */
    private function assertAmount(float $amount): void
    {
        if (round($amount, 4) <= 0) {
            throw new LedgerRuleException('An adjustment amount must be above zero.', 'PAY_ADJUSTMENT_INVALID_AMOUNT');
        }
    }

    /**
     * @throws LedgerRuleException
     */
    private function assertRange(string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) {
            throw new LedgerRuleException('A pay item cannot end before it starts.', 'PAY_ITEM_INVALID_RANGE');
        }
    }

    /**
     * An adjustment may only be entered, moved into or approved for a period
     * after the latest one whose payroll has left review (the same line pay
     * items start after). An earlier period is closed even when it never had
     * a run: no run the company still calculates would pick it up.
     *
     * @throws LedgerRuleException
     */
    private function assertPeriodOpen(string $period): void
    {
        $frozenUntil = $this->frozenUntil();
        $periodEnd = Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();

        if ($frozenUntil !== null && $periodEnd <= $frozenUntil) {
            throw new LedgerRuleException(
                "Payroll up to {$frozenUntil} has left review; adjustments for {$period} can no longer change.",
                'PAY_PERIOD_CLOSED',
                ['period' => $period, 'frozen_until' => $frozenUntil],
            );
        }
    }

    /**
     * The period's own payroll has left review, so what it paid is fixed.
     *
     * @throws LedgerRuleException
     */
    private function assertPeriodNotFrozen(string $period): void
    {
        $frozen = PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', array_map(fn (PayrollRunStatus $s) => $s->value, self::FROZEN_RUN_STATUSES))
            ->exists();

        if ($frozen) {
            throw new LedgerRuleException(
                "Payroll {$period} has left review; its adjustments can no longer change.",
                'PAY_PERIOD_CLOSED',
                ['period' => $period],
            );
        }
    }

    /**
     * The last day of the latest period whose payroll has left review.
     */
    private function frozenUntil(): ?string
    {
        $latest = PayrollRun::query()
            ->whereIn('status', array_map(fn (PayrollRunStatus $s) => $s->value, self::FROZEN_RUN_STATUSES))
            ->max('period');

        return $latest !== null
            ? Carbon::createFromFormat('!Y-m', $latest)->endOfMonth()->toDateString()
            : null;
    }

    /**
     * A calculated run that has the employee's payslip and a period the
     * change reaches was worked out on the old items; it cannot be submitted
     * until recalculated.
     *
     * @param  callable(string, string): bool  $reaches  given the period's first and last day
     */
    private function flagCalculatedRuns(string $employeeId, callable $reaches): void
    {
        PayrollRun::query()
            ->where('status', PayrollRunStatus::Calculated)
            ->where('needs_recalculation', false)
            ->whereHas('payslips', fn ($query) => $query->where('employee_id', $employeeId))
            ->get()
            ->filter(function (PayrollRun $run) use ($reaches): bool {
                $start = Carbon::createFromFormat('!Y-m', $run->period)->startOfMonth()->toDateString();
                $end = Carbon::createFromFormat('!Y-m', $run->period)->endOfMonth()->toDateString();

                return $reaches($start, $end);
            })
            ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
    }

    /**
     * An approved adjustment came or went after the period's run was
     * calculated: flag the run even when the employee has no payslip in it
     * (they may earn one on recalculation, or the run refuses with
     * PAYROLL_ADJUSTMENT_UNPAID).
     */
    private function flagCalculatedRunOf(string $period): void
    {
        PayrollRun::query()
            ->where('status', PayrollRunStatus::Calculated)
            ->where('needs_recalculation', false)
            ->where('period', $period)
            ->get()
            ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
    }
}
