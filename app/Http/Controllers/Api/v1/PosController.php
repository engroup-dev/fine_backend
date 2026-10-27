<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\PosDailyClose;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Services\AccountingService;
use App\Support\CurrentUnitContext;
use App\Support\LedgerAccounts;
use App\Support\TreasuryAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The register day: what the counter sold and what money came in, measured
 * in the company's own timezone — a sale at 01:00 Tripoli time belongs to
 * that day, not to the previous UTC one.
 *
 * Rule TRS-06: The POS daily close posts its cash difference to 625 (shortage) / 44 (surplus).
 */
class PosController extends Controller
{
    public function __construct(
        public CurrentUnitContext $unitContext,
        public AccountingService $accountingService,
    ) {}

    /**
     * Cash-drawer reconciliation: the day's sales by payment method, and the
     * money actually received by method and by treasury.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        [$date, $start, $end, $timezone] = $this->dayWindow($request->query('date'));

        $unitId = $this->unitContext->getUnitId();

        $sales = $this->soldBetween($start, $end);
        $paymentsQuery = SalePayment::with('cashAccount')->whereBetween('received_at', [$start, $end]);

        if ($unitId !== null) {
            $paymentsQuery->where('operating_unit_id', $unitId);
        }

        $payments = $paymentsQuery->get();
        $cashPayments = $payments->where('method', 'cash');

        $expectedCashByAccount = $cashPayments
            ->whereNotNull('cash_account_id')
            ->groupBy('cash_account_id')
            ->map(fn ($group) => [
                'cash_account_id' => $group->first()->cash_account_id,
                'name' => $group->first()->cashAccount?->name,
                'expected' => round((float) $group->sum('amount'), 4),
            ])
            ->values();

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'sales_count' => $sales->count(),
            'total' => round((float) $sales->sum('total_amount'), 4),
            'total_cost' => round((float) $sales->sum('total_cost'), 4),
            'by_method' => $sales->groupBy('payment_method')->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('total_amount'), 4),
            ]),
            'collected' => $payments->groupBy('method')->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('amount'), 4),
            ]),
            'by_cash_account' => $payments->groupBy('cash_account_id')->map(fn ($group) => [
                'cash_account_id' => $group->first()->cash_account_id,
                'name' => $group->first()->cashAccount?->name,
                'kind' => $group->first()->cashAccount?->kind,
                'total' => round((float) $group->sum('amount'), 4),
            ])->values(),
            'expected_cash' => round((float) $cashPayments->sum('amount'), 4),
            'expected_cash_by_account' => $expectedCashByAccount,
        ]);
    }

    /**
     * The recorded register close for a date, if the drawer was counted.
     */
    public function showDailyClose(Request $request): JsonResponse
    {
        [$date] = $this->dayWindow($request->query('date'));
        $unitId = $this->unitContext->getUnitId();

        $query = PosDailyClose::with(['closedBy', 'cashAccount', 'journalEntry'])->whereDate('close_date', $date);

        if ($unitId !== null) {
            $query->where('operating_unit_id', $unitId);
        }

        return response()->json([
            'data' => $query->first(),
        ]);
    }

    /**
     * Persist the Z-report drawer count. Expected cash is derived server-side
     * from the cash actually received that day — sales paid in cash plus cash
     * collections on receivables into that treasury.
     *
     * Rule TRS-06: Differences >= 0.005 post to 625 (shortage) / 44 (surplus).
     */
    public function dailyClose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
            'cash_account_id' => ['nullable', 'uuid', 'exists:cash_accounts,id'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before closing the register.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        [$date, $start, $end] = $this->dayWindow($validated['date'] ?? null);

        // Scoped to this unit: closing one register must not block the others.
        if (PosDailyClose::where('operating_unit_id', $unitId)->whereDate('close_date', $date)->exists()) {
            return response()->json([
                'message' => 'This register day is already closed.',
                'code' => 'POS_DAY_ALREADY_CLOSED',
            ], 422);
        }

        $cashPaymentsQuery = SalePayment::withoutGlobalScopes()
            ->where('operating_unit_id', $unitId)
            ->whereBetween('received_at', [$start, $end])
            ->where('method', 'cash');

        $distinctTreasuryIds = (clone $cashPaymentsQuery)
            ->whereNotNull('cash_account_id')
            ->distinct()
            ->pluck('cash_account_id')
            ->values()
            ->all();

        $counted = round((float) $validated['counted_cash'], 4);
        $cashAccountId = $validated['cash_account_id'] ?? null;

        if ($cashAccountId !== null) {
            $expected = round((float) (clone $cashPaymentsQuery)
                ->where('cash_account_id', $cashAccountId)
                ->sum('amount'), 4);
        } else {
            $count = count($distinctTreasuryIds);

            if ($count === 0) {
                if ($counted < 0.005) {
                    $expected = 0.0;
                    $cashAccountId = null;
                } else {
                    return response()->json([
                        'message' => 'A cash treasury is required when counted cash is greater than zero and no payments were recorded.',
                        'code' => 'CASH_ACCOUNT_REQUIRED',
                        'treasuries' => [],
                    ], 422);
                }
            } elseif ($count === 1) {
                $cashAccountId = $distinctTreasuryIds[0];
                $expected = round((float) (clone $cashPaymentsQuery)
                    ->where('cash_account_id', $cashAccountId)
                    ->sum('amount'), 4);
            } else {
                $treasuries = CashAccount::withoutGlobalScopes()
                    ->whereIn('id', $distinctTreasuryIds)
                    ->get(['id', 'name'])
                    ->map(fn ($ca) => ['id' => $ca->id, 'name' => $ca->name])
                    ->values()
                    ->all();

                return response()->json([
                    'message' => 'Multiple cash treasuries received payments today; cash_account_id is required.',
                    'code' => 'CASH_ACCOUNT_REQUIRED',
                    'treasuries' => $treasuries,
                ], 422);
            }
        }

        $diff = round($counted - $expected, 4);
        $sales = $this->soldBetween($start, $end);
        $salesCount = $sales->count();
        $totalSales = round((float) $sales->sum('total_amount'), 4);

        $close = DB::transaction(function () use ($unitId, $date, $expected, $counted, $diff, $salesCount, $totalSales, $validated, $request, $cashAccountId): PosDailyClose {
            $closeId = (string) Str::uuid();
            $journalEntryId = null;

            if (abs($diff) >= 0.005) {
                $treasuryCode = TreasuryAccounts::codeForId($cashAccountId);
                $diffAmount = abs($diff);

                if ($diff < 0) {
                    // Shortage: DR 625 (عجز الخزائن) / CR treasury
                    $lines = [
                        [
                            'account_code' => LedgerAccounts::CASH_SHORTAGE,
                            'debit' => $diffAmount,
                            'operating_unit_id' => $unitId,
                        ],
                        [
                            'account_code' => $treasuryCode,
                            'credit' => $diffAmount,
                            'operating_unit_id' => $unitId,
                        ],
                    ];
                } else {
                    // Surplus: DR treasury / CR 44 (زيادة الخزائن)
                    $lines = [
                        [
                            'account_code' => $treasuryCode,
                            'debit' => $diffAmount,
                            'operating_unit_id' => $unitId,
                        ],
                        [
                            'account_code' => LedgerAccounts::CASH_OVERAGE,
                            'credit' => $diffAmount,
                            'operating_unit_id' => $unitId,
                        ],
                    ];
                }

                $unit = OperatingUnit::find($unitId);
                $companyId = $unit?->company_id ?? Company::query()->value('id');

                $entry = $this->accountingService->postJournal(
                    description: "POS daily close difference: {$date}",
                    lines: $lines,
                    sourceType: 'PosDailyClose',
                    sourceId: $closeId,
                    companyId: $companyId,
                    isManual: false,
                    userId: $request->user()->id,
                    entryDate: $date,
                );

                $journalEntryId = $entry->id;
            }

            return PosDailyClose::create([
                'id' => $closeId,
                'operating_unit_id' => $unitId,
                'close_date' => $date,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $diff,
                'sales_count' => $salesCount,
                'total_sales' => $totalSales,
                'notes' => $validated['notes'] ?? null,
                'closed_by_user_id' => $request->user()->id,
                'cash_account_id' => $cashAccountId,
                'journal_entry_id' => $journalEntryId,
            ]);
        });

        return response()->json($close->load(['closedBy', 'cashAccount', 'journalEntry']), 201);
    }

    /**
     * Client and walk-in sales that actually happened in the window (not ones
     * waiting on, or refused, credit approval; not internal transfers).
     *
     * @return Collection<int, SalesOrder>
     */
    private function soldBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $unitId = $this->unitContext->getUnitId();

        $query = SalesOrder::where('channel', 'pos')
            ->whereIn('buyer_type', ['client', 'walk_in'])
            ->whereNotIn('status', ['pending_approval', 'rejected'])
            ->whereBetween('created_at', [$start, $end]);

        if ($unitId !== null) {
            $query->where('operating_unit_id', $unitId);
        }

        return $query->get();
    }

    /**
     * The requested local day (default: today) as a UTC window.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    private function dayWindow(?string $date): array
    {
        $timezone = $this->unitContext->unit()?->company?->timezone
            ?? Company::query()->value('timezone')
            ?? 'Africa/Tripoli';

        $day = $date !== null
            ? CarbonImmutable::parse($date, $timezone)
            : CarbonImmutable::now($timezone);

        return [
            $day->toDateString(),
            $day->startOfDay()->utc(),
            $day->endOfDay()->utc(),
            $timezone,
        ];
    }
}
