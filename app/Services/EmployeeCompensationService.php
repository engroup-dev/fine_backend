<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollRun;
use App\Support\CompanyClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HR-11: an employee's pay terms (pay type, monthly salary, personal hourly
 * rate, daily rate since W5-04) are versioned by effective date. A change appends a version instead
 * of overwriting the employee row, and payroll resolves the terms per day.
 * The employee row keeps a copy of the terms in force today: it is synced
 * when a change is recorded and daily by `hr:sync-pay-terms`, so a
 * future-dated change reaches the row once its date arrives. Anything that
 * must be exact on a given date resolves the terms with inEffectOn().
 */
final class EmployeeCompensationService
{
    /**
     * The first version, written when the employee is hired: the terms on the
     * employee row, effective from the hire date.
     */
    public function recordOpening(Employee $employee, ?string $userId = null): EmployeeCompensation
    {
        return EmployeeCompensation::create([
            'employee_id' => $employee->id,
            'pay_type' => $employee->pay_type,
            'monthly_salary' => $employee->monthly_salary,
            'hourly_rate' => $employee->hourly_rate,
            'daily_rate' => $employee->daily_rate,
            'effective_from' => $employee->hire_date->toDateString(),
            'reason' => EmployeeCompensation::OPENING_REASON,
            'created_by' => $userId,
        ]);
    }

    /**
     * Appends a pay change. One version per employee and effective date, and
     * never dated into a period whose payroll has already left review: those
     * payslips are frozen, so the history must keep matching what was paid.
     * A calculated run the change reaches is flagged for recalculation, so it
     * cannot be submitted on the old terms.
     *
     * @param  array{pay_type: string, monthly_salary?: float|int|string|null, hourly_rate?: float|int|string|null, daily_rate?: float|int|string|null, effective_from: string, reason?: string|null}  $data
     */
    public function record(Employee $employee, array $data, ?string $userId = null): EmployeeCompensation
    {
        return DB::transaction(function () use ($employee, $data, $userId) {
            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($employee->id);

            $effectiveFrom = Carbon::parse($data['effective_from'])->toDateString();
            $hireDate = $employee->hire_date->toDateString();

            // Nothing before the hire date is paid, so a change dated earlier
            // would be recorded and then never take effect.
            if ($effectiveFrom < $hireDate) {
                throw new InvalidArgumentException(
                    "A pay change cannot take effect before the hire date ({$hireDate})."
                );
            }

            $duplicate = EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_from', $effectiveFrom)
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException(
                    "A pay change effective {$effectiveFrom} already exists for this employee; choose another date."
                );
            }

            $this->assertAfterFrozenPayroll($effectiveFrom, 'a pay change must take effect after');

            $earliest = EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->orderBy('effective_from')
                ->first();

            if ($earliest === null) {
                // An employee created outside the API (seeders, imports after
                // the backfill) has no history yet. Open it with the terms on
                // the row from the hire date first, so the change does not
                // reach back over the days before it.
                if ($effectiveFrom !== $hireDate) {
                    $this->recordOpening($employee, $userId);
                }
            } elseif ($effectiveFrom < $earliest->effective_from->toDateString()) {
                throw new InvalidArgumentException(
                    "A pay change cannot take effect before the start of the pay history ({$earliest->effective_from->toDateString()})."
                );
            }

            $compensation = EmployeeCompensation::create([
                'employee_id' => $employee->id,
                'pay_type' => $data['pay_type'],
                'monthly_salary' => $data['monthly_salary'] ?? null,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'daily_rate' => $data['daily_rate'] ?? null,
                'effective_from' => $effectiveFrom,
                'reason' => $data['reason'] ?? null,
                'created_by' => $userId,
            ]);

            $this->flagCalculatedRunsFrom($effectiveFrom);
            $this->syncCurrentTerms($employee);

            return $compensation;
        });
    }

    /**
     * Removes a mistaken pay change. Only a version dated after the last
     * frozen payroll period, and never the opening row: the history must
     * keep matching what was paid, and it always starts at the hire date.
     * Correcting a version is removing it and recording the right one.
     */
    public function remove(Employee $employee, string $compensationId): void
    {
        DB::transaction(function () use ($employee, $compensationId) {
            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($employee->id);

            $version = EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->findOrFail($compensationId);

            $earliest = EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->orderBy('effective_from')
                ->first();

            if ($earliest !== null && $earliest->is($version)) {
                throw new InvalidArgumentException(
                    'The opening pay terms cannot be removed; record a later pay change instead.'
                );
            }

            $effectiveFrom = $version->effective_from->toDateString();

            $this->assertAfterFrozenPayroll($effectiveFrom, 'only a pay change taking effect after');

            $version->delete();

            $this->flagCalculatedRunsFrom($effectiveFrom);
            $this->syncCurrentTerms($employee);
        });
    }

    /**
     * Keeps the pay history starting at the hire date when the hire date is
     * corrected: the first version moves with it. Refused when a later pay
     * change would no longer come after the new hire date, or when the move
     * would change pay in a period whose payroll has left review. Calculated
     * runs the move reaches are flagged for recalculation even when the
     * employee has no pay history, since monthly pay is prorated by the hire
     * date. Runs inside the caller's transaction, after the employee row has
     * been updated.
     */
    public function moveOpening(Employee $employee, string $previousHireDate): void
    {
        $newHireDate = $employee->hire_date->toDateString();

        if ($newHireDate === $previousHireDate) {
            return;
        }

        $history = EmployeeCompensation::query()
            ->where('employee_id', $employee->id)
            ->orderBy('effective_from')
            ->lockForUpdate()
            ->limit(2)
            ->get();

        $opening = $history->get(0);
        $next = $history->get(1);

        if ($next !== null && $newHireDate >= $next->effective_from->toDateString()) {
            throw new InvalidArgumentException(
                "A pay change takes effect on {$next->effective_from->toDateString()}; the hire date must stay before it."
            );
        }

        $this->assertHireDateMoveLeavesFrozenPayroll($previousHireDate, $newHireDate);

        $opening?->update(['effective_from' => $newHireDate]);

        $this->flagCalculatedRunsFrom(min($previousHireDate, $newHireDate));

        if ($opening !== null) {
            $this->syncCurrentTerms($employee);
        }
    }

    /**
     * The version in force on a date: the newest one not after it. A date
     * before the first version falls back to the first one.
     */
    public function inEffectOn(Employee $employee, string $date): ?EmployeeCompensation
    {
        return EmployeeCompensation::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first()
            ?? EmployeeCompensation::query()
                ->where('employee_id', $employee->id)
                ->orderBy('effective_from')
                ->first();
    }

    /**
     * Splits a payroll period into runs of days sharing the same pay terms.
     * The first version also covers any days before it, the last one runs to
     * the end of the period. An employee with no history (created outside the
     * API before HR-11) is paid on the terms on the employee row.
     *
     * @return list<array{from: Carbon, to: Carbon, pay_type: PayType|null, monthly_salary: float|null, hourly_rate: float|null, daily_rate: float|null}>
     */
    public function segments(Employee $employee, Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        $history = EmployeeCompensation::query()
            ->where('employee_id', $employee->id)
            ->orderBy('effective_from')
            ->get()
            ->values();

        if ($history->isEmpty()) {
            return [[
                'from' => $start,
                'to' => $end,
                'pay_type' => $employee->pay_type,
                'monthly_salary' => $employee->monthly_salary !== null ? (float) $employee->monthly_salary : null,
                'hourly_rate' => $employee->hourly_rate !== null ? (float) $employee->hourly_rate : null,
                'daily_rate' => $employee->daily_rate !== null ? (float) $employee->daily_rate : null,
            ]];
        }

        $segments = [];

        foreach ($history as $index => $version) {
            $from = $index === 0 ? $start : $version->effective_from->copy()->startOfDay()->max($start);

            $next = $history->get($index + 1);
            $to = $next !== null
                ? $next->effective_from->copy()->startOfDay()->subDay()->min($end)
                : $end;

            if ($from->gt($to)) {
                continue;
            }

            $segments[] = [
                'from' => $from,
                'to' => $to,
                'pay_type' => $version->pay_type,
                'monthly_salary' => $version->monthly_salary !== null ? (float) $version->monthly_salary : null,
                'hourly_rate' => $version->hourly_rate !== null ? (float) $version->hourly_rate : null,
                'daily_rate' => $version->daily_rate !== null ? (float) $version->daily_rate : null,
            ];
        }

        return $segments;
    }

    /**
     * Refuses a date inside a period whose payroll has left review (submitted
     * or later): those payslips are frozen.
     */
    private function assertAfterFrozenPayroll(string $date, string $rule): void
    {
        $frozenPeriod = PayrollRun::query()
            ->whereNotIn('status', [PayrollRunStatus::Draft->value, PayrollRunStatus::Calculated->value])
            ->max('period');

        if ($frozenPeriod === null) {
            return;
        }

        $frozenUntil = Carbon::createFromFormat('!Y-m', $frozenPeriod)->endOfMonth()->toDateString();

        if ($date <= $frozenUntil) {
            throw new InvalidArgumentException(
                "Payroll for {$frozenPeriod} has already left review; {$rule} {$frozenUntil}."
            );
        }
    }

    /**
     * A hire date change only alters pay in a frozen period when one of the
     * two dates falls inside the frozen span: on or before the start of the
     * earliest frozen period, every frozen month is paid in full either way;
     * after the end of the latest one, none of them is paid either way.
     */
    private function assertHireDateMoveLeavesFrozenPayroll(string $previousHireDate, string $newHireDate): void
    {
        $frozen = PayrollRun::query()
            ->whereNotIn('status', [PayrollRunStatus::Draft->value, PayrollRunStatus::Calculated->value]);

        $latestPeriod = (clone $frozen)->max('period');

        if ($latestPeriod === null) {
            return;
        }

        $earliestPeriod = (clone $frozen)->min('period');

        $frozenFrom = Carbon::createFromFormat('!Y-m', $earliestPeriod)->startOfMonth()->toDateString();
        $frozenUntil = Carbon::createFromFormat('!Y-m', $latestPeriod)->endOfMonth()->toDateString();

        $bothBefore = $previousHireDate <= $frozenFrom && $newHireDate <= $frozenFrom;
        $bothAfter = $previousHireDate > $frozenUntil && $newHireDate > $frozenUntil;

        if ($bothBefore || $bothAfter) {
            return;
        }

        throw new InvalidArgumentException(
            "Payroll for {$latestPeriod} has already left review; the hire date can only move within dates on or before {$frozenFrom} or after {$frozenUntil}."
        );
    }

    /**
     * A calculated run covering any day from $date on was calculated on the
     * old terms. Flag it so it cannot be submitted until it is recalculated.
     */
    private function flagCalculatedRunsFrom(string $date): void
    {
        PayrollRun::query()
            ->where('status', PayrollRunStatus::Calculated->value)
            ->where('period', '>=', substr($date, 0, 7))
            ->where('needs_recalculation', false)
            ->get()
            ->each(fn (PayrollRun $run) => $run->update(['needs_recalculation' => true]));
    }

    /**
     * Copies the terms in force today onto the employee row, which the list
     * and card display. A future-dated change leaves today's terms alone.
     * The row is saved only when its terms actually change, so an unchanged
     * row keeps its record_version and an open employee card stays editable.
     * Returns whether the row changed.
     */
    public function syncCurrentTerms(Employee $employee, ?string $today = null): bool
    {
        $current = $this->inEffectOn($employee, $today ?? CompanyClock::today());

        if ($current === null) {
            return false;
        }

        $employee->forceFill([
            'pay_type' => $current->pay_type,
            'monthly_salary' => $current->monthly_salary,
            'hourly_rate' => $current->hourly_rate,
            'daily_rate' => $current->daily_rate,
        ]);

        if (! $employee->isDirty(['pay_type', 'monthly_salary', 'hourly_rate', 'daily_rate'])) {
            return false;
        }

        $employee->save();

        return true;
    }
}
