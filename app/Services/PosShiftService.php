<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;
use App\Models\OperatingUnit;
use App\Models\PosShift;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\CompanyClock;
use App\Support\TreasuryAccounts;
use Illuminate\Support\Facades\DB;

/**
 * FR-SLREP-008: cashier shifts with an opening float and a shift Z-report.
 *
 * - A shift opens only in a unit that has enabled shifts, only on a
 *   cash-kind treasury and only while the unit's register day is open.
 * - One open shift per cash drawer company-wide (drawers are shared); a
 *   shift belongs to the unit that opened it and only that unit's cash is
 *   stamped with it.
 * - In a unit with shifts enabled, cash taken or refunded through a drawer
 *   is stamped with the unit's open shift on it; with none, it is refused.
 *   Units without shifts never stamp.
 * - Closing counts the drawer: expected = opening float + stamped cash
 *   (refunds are negative rows). The difference posts to 625 / 44 (TRS-06).
 */
final class PosShiftService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PosDayGuard $posDayGuard,
        private readonly CashCountPostingService $cashCountPosting,
    ) {}

    /**
     * @throws SalesRuleException
     */
    public function open(OperatingUnit $unit, CashAccount $drawer, float $float, User $cashier): PosShift
    {
        if (! $unit->pos_shifts_enabled) {
            throw new SalesRuleException(
                "'{$unit->name}' does not run cashier shifts. Enable shifts for the unit first.",
                'SHIFTS_NOT_ENABLED',
            );
        }

        if ($drawer->kind !== CashAccount::KIND_CASH) {
            throw new SalesRuleException(
                "'{$drawer->name}' is a {$drawer->kind} account; a shift opens on a cash drawer.",
                'SHIFT_DRAWER_NOT_CASH',
            );
        }

        // A drawer the chart does not know cannot post the shift's count
        // difference (TRS-06); refuse it now, not at close.
        TreasuryAccounts::codeFor($drawer->loadMissing('account'));

        if ($float < 0) {
            throw new SalesRuleException('The opening float cannot be negative.', 'SHIFT_FLOAT_INVALID');
        }

        $this->posDayGuard->ensureOpen($unit);

        return DB::transaction(function () use ($unit, $drawer, $float, $cashier): PosShift {
            // The drawer row serialises concurrent opens on it.
            CashAccount::whereKey($drawer->id)->lockForUpdate()->first();

            $existing = PosShift::withoutGlobalScopes()
                ->with('cashier:id,name')
                ->where('cash_account_id', $drawer->id)
                ->where('status', PosShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new SalesRuleException(
                    "Drawer '{$drawer->name}' already has open shift {$existing->number}.",
                    'SHIFT_ALREADY_OPEN',
                    ['shift_number' => $existing->number, 'cashier' => $existing->cashier?->name],
                );
            }

            $today = CompanyClock::today($unit);

            return PosShift::create([
                'operating_unit_id' => $unit->id,
                'cash_account_id' => $drawer->id,
                'cashier_user_id' => $cashier->id,
                'number' => $this->documentNumbers->next(DocumentNumberService::POS_SHIFT, (int) substr($today, 0, 4)),
                'status' => PosShift::STATUS_OPEN,
                'opened_at' => now(),
                'opening_float' => round($float, 4),
            ]);
        });
    }

    /**
     * The drawer's open shift, or null. With a unit, only a shift that unit
     * opened counts: drawers are company-wide, a shift is not.
     */
    public function currentFor(CashAccount $drawer, ?string $operatingUnitId = null, bool $lock = false): ?PosShift
    {
        return PosShift::withoutGlobalScopes()
            ->where('cash_account_id', $drawer->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->when($operatingUnitId !== null, fn ($query) => $query->where('operating_unit_id', $operatingUnitId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Refuse cash through a drawer with no open shift when the unit runs
     * shifts. Called before anything is written.
     *
     * @throws SalesRuleException
     */
    public function ensureShiftFor(OperatingUnit $unit, ?CashAccount $drawer): void
    {
        if (! $unit->pos_shifts_enabled || $drawer === null) {
            return;
        }

        if ($this->currentFor($drawer, $unit->id) === null) {
            throw $this->noOpenShift($drawer);
        }
    }

    /**
     * The shift a payment (or refund) is stamped with: only cash, only in a
     * unit running shifts, only the unit's own open shift on the drawer —
     * looked up under lock inside the caller's transaction. A shift that
     * closed since ensureShiftFor() refuses the payment (NO_OPEN_SHIFT) so
     * the whole transaction rolls back rather than leaving cash in no shift.
     *
     * With $stampOrder the sale is stamped too when it has no shift yet (the
     * caller saves the order); refunds pass false so an older sale is never
     * re-attributed to the shift that refunded it.
     *
     * @throws SalesRuleException
     */
    public function shiftForPayment(SalesOrder $order, string $method, ?CashAccount $drawer, bool $stampOrder = true): ?string
    {
        if ($method !== 'cash' || $drawer === null) {
            return null;
        }

        $unitEnabled = (bool) OperatingUnit::whereKey($order->operating_unit_id)->value('pos_shifts_enabled');

        if (! $unitEnabled) {
            return null;
        }

        $shift = $this->currentFor($drawer, $order->operating_unit_id, lock: true);

        if ($shift === null) {
            throw $this->noOpenShift($drawer);
        }

        if ($stampOrder && $order->pos_shift_id === null) {
            $order->pos_shift_id = $shift->id;
        }

        return $shift->id;
    }

    /**
     * FR-SLREP-008: shifts switch on or off only on a clean register day, so
     * a day is reconciled wholly from shifts or wholly from the legacy drawer
     * count — never both (which would post the same difference twice) and
     * never neither (which would leave cash out of every count).
     *
     * - Never while the unit has an open shift.
     * - While today's register day is still open: switching off is refused
     *   once a shift closed today; switching on is refused once today took
     *   or refunded cash outside a shift. Close the day first — the change
     *   then takes effect from the next day.
     *
     * @throws SalesRuleException
     */
    public function ensureSettingChangeAllowed(OperatingUnit $unit, bool $enable): void
    {
        if ((bool) $unit->pos_shifts_enabled === $enable) {
            return;
        }

        $openShifts = PosShift::withoutGlobalScopes()
            ->where('operating_unit_id', $unit->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->orderBy('number')
            ->pluck('number')
            ->all();

        if ($openShifts !== []) {
            throw new SalesRuleException(
                'Close every open shift before changing the shift setting.',
                'OPEN_SHIFTS_EXIST',
                ['shift_numbers' => $openShifts],
            );
        }

        $today = CompanyClock::now($unit);

        if ($this->posDayGuard->isClosed($unit->id, $today->toDateString())) {
            return;
        }

        $start = $today->startOfDay()->utc();
        $end = $today->endOfDay()->utc();

        $dayInProgress = $enable
            ? SalePayment::withoutGlobalScopes()
                ->where('operating_unit_id', $unit->id)
                ->where('method', 'cash')
                ->whereNull('pos_shift_id')
                ->whereBetween('received_at', [$start, $end])
                ->exists()
            : PosShift::withoutGlobalScopes()
                ->where('operating_unit_id', $unit->id)
                ->where('status', PosShift::STATUS_CLOSED)
                ->whereBetween('closed_at', [$start, $end])
                ->exists();

        if ($dayInProgress) {
            throw new SalesRuleException(
                $enable
                    ? 'Today already took cash outside a shift. Close the register day first; shifts then start from the next day.'
                    : 'Today was run on shifts. Close the register day first; the change then takes effect from the next day.',
                'POS_DAY_IN_PROGRESS',
            );
        }
    }

    /**
     * The shift's Z/X-report figures from what was stamped with it.
     *
     * Bank and cheque portions are not drawer cash and are never stamped;
     * they are reported from the shift's own sales (split sales whose cash
     * portion went through this drawer) while the shift was open.
     *
     * @return array{
     *     opening_float: float,
     *     cash_in: float,
     *     cash_refunds: float,
     *     net_cash: float,
     *     bank: float,
     *     cheque: float,
     *     sales_count: int,
     *     returns_count: int,
     *     expected_cash: float,
     * }
     */
    public function totals(PosShift $shift): array
    {
        $stamped = SalePayment::withoutGlobalScopes()->where('pos_shift_id', $shift->id)->get();
        $cash = $stamped->where('method', 'cash');

        $cashIn = round((float) $cash->filter(fn (SalePayment $p): bool => (float) $p->amount > 0)->sum('amount'), 4);
        $cashRefunds = round(abs((float) $cash->filter(fn (SalePayment $p): bool => (float) $p->amount < 0)->sum('amount')), 4);
        $netCash = round((float) $cash->sum('amount'), 4);

        $stampedOrderIds = SalesOrder::withoutGlobalScopes()
            ->where('pos_shift_id', $shift->id)
            ->pluck('id');

        $nonCash = SalePayment::withoutGlobalScopes()
            ->whereIn('sales_order_id', $stampedOrderIds)
            ->whereIn('method', ['bank', 'cheque'])
            ->whereNull('pos_shift_id')
            ->where('received_at', '>=', $shift->opened_at)
            ->where('received_at', '<=', $shift->closed_at ?? now())
            ->get();

        $salesCount = $stamped
            ->filter(fn (SalePayment $p): bool => (float) $p->amount > 0)
            ->pluck('sales_order_id')
            ->merge($stampedOrderIds)
            ->unique()
            ->count();

        $openingFloat = round((float) $shift->opening_float, 4);

        return [
            'opening_float' => $openingFloat,
            'cash_in' => $cashIn,
            'cash_refunds' => $cashRefunds,
            'net_cash' => $netCash,
            'bank' => round((float) $stamped->where('method', 'bank')->sum('amount') + (float) $nonCash->where('method', 'bank')->sum('amount'), 4),
            'cheque' => round((float) $stamped->where('method', 'cheque')->sum('amount') + (float) $nonCash->where('method', 'cheque')->sum('amount'), 4),
            'sales_count' => $salesCount,
            'returns_count' => $stamped->whereNotNull('sales_return_id')->pluck('sales_return_id')->unique()->count(),
            'expected_cash' => round($openingFloat + $netCash, 4),
        ];
    }

    /**
     * Close the shift with the drawer count and post its difference.
     *
     * @throws SalesRuleException
     */
    public function close(PosShift $shift, float $counted, ?string $notes, User $user): PosShift
    {
        if ($user->id !== $shift->cashier_user_id && ! $this->managesShift($user, $shift)) {
            throw new SalesRuleException(
                'Only the shift\'s cashier or a manager can close this shift.',
                'SHIFT_CLOSE_NOT_ALLOWED',
                ['shift_number' => $shift->number],
            );
        }

        if ($counted < 0) {
            throw new SalesRuleException('The counted cash cannot be negative.', 'INVALID_AMOUNT');
        }

        return DB::transaction(function () use ($shift, $counted, $notes, $user): PosShift {
            $locked = PosShift::withoutGlobalScopes()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new SalesRuleException(
                    "Shift {$locked->number} is already closed.",
                    'SHIFT_ALREADY_CLOSED',
                    ['shift_number' => $locked->number],
                );
            }

            $locked->closed_at = now();
            $totals = $this->totals($locked);
            $expected = $totals['expected_cash'];
            $counted = round($counted, 4);

            $unit = OperatingUnit::findOrFail($locked->operating_unit_id);
            $drawer = CashAccount::with('account')->findOrFail($locked->cash_account_id);

            $entry = $this->cashCountPosting->post(
                $unit,
                $drawer,
                $expected,
                $counted,
                $locked,
                CompanyClock::today($unit),
                $user->id,
            );

            $locked->fill([
                'status' => PosShift::STATUS_CLOSED,
                'closed_by_user_id' => $user->id,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => round($counted - $expected, 4),
                'totals' => $totals,
                'notes' => $notes,
                'journal_entry_id' => $entry?->id,
            ]);
            $locked->save();

            return $locked;
        });
    }

    /**
     * The owner, or a holder of approve-credit through a role held in the
     * shift's unit or company-wide — a manager role in another unit does not
     * reach this unit's shifts.
     */
    private function managesShift(User $user, PosShift $shift): bool
    {
        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->roles()
            ->where(fn ($q) => $q->where('user_roles.operating_unit_id', $shift->operating_unit_id)->orWhereNull('user_roles.operating_unit_id'))
            ->whereHas('permissions', fn ($q) => $q->where('permissions.slug', 'approve-credit'))
            ->exists();
    }

    private function noOpenShift(CashAccount $drawer): SalesRuleException
    {
        return new SalesRuleException(
            "Drawer '{$drawer->name}' has no open shift in this unit. Open a shift before taking or refunding cash.",
            'NO_OPEN_SHIFT',
            ['cash_account_id' => $drawer->id],
        );
    }
}
