<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstallmentFrequency;
use App\Enums\InstallmentLineStatus;
use App\Exceptions\SalesRuleException;
use App\Models\InstallmentPlan;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\CompanyClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * W5-08 (decision #6): optional installment schedules on receivable sales.
 *
 * - A plan splits what the client still owes (after an optional down payment)
 *   into equal monthly installments from a chosen first due date. No interest
 *   or fees; the last installment absorbs the rounding.
 * - The schedule moves no money and posts nothing. Collections keep going
 *   through the sale's own payments (SaleCheckoutService::collectPayment), and
 *   a line's paid status is derived FIFO, oldest due first, from the sale's
 *   amount_paid since the plan opened (opening_paid, which includes the down
 *   payment and anything collected before the plan).
 * - Treasury receipt vouchers and cheque bounces change the client's
 *   receivable but not the sale's amount_paid, so they do not allocate to
 *   installments.
 * - Goods returned on the sale after the plan opened cut the schedule from the
 *   last installment backwards. A cash or bank refund raises returned_amount
 *   and lowers amount_paid by the same amount, leaving the debt unchanged, so
 *   the part of a refund not covered by collections since the plan opened is
 *   not a cut: the remaining total equals the sale's outstanding.
 * - A credit sale without a plan stays open commercial credit.
 */
final class InstallmentPlanService
{
    public const MAX_INSTALLMENTS = 60;

    public const REPORT_STATUSES = ['due', 'overdue', 'all'];

    /**
     * Validation rules for an installment plan payload, under an optional
     * prefix (`installment_plan` at checkout, none on the sale endpoint).
     *
     * @return array<string, array<int, mixed>|ValidationRule>
     */
    public static function rules(string $prefix = ''): array
    {
        $key = fn (string $field): string => $prefix === '' ? $field : "{$prefix}.{$field}";
        $required = $prefix === '' ? 'required' : "required_with:{$prefix}";

        return [
            ...($prefix === '' ? [] : [$prefix => ['nullable', 'array']]),
            $key('count') => [$required, 'integer', 'min:1', 'max:'.self::MAX_INSTALLMENTS],
            $key('first_due_date') => [$required, 'date_format:Y-m-d'],
            $key('down_payment') => ['nullable', 'numeric', 'min:0'],
            $key('down_payment_method') => ['nullable', Rule::in(['cash', 'bank'])],
            $key('cash_account_id') => ['nullable', 'uuid'],
        ];
    }

    /**
     * Equal monthly installments of $financed. Amounts are floored to the
     * dirham (3 decimals, computed in integer dirhams) and the last
     * installment takes the remainder.
     *
     * @return array<int, array{number: int, due_date: string, amount: float}>
     */
    public function buildLines(float $financed, int $count, string $firstDueDate): array
    {
        if ($count < 1 || $count > self::MAX_INSTALLMENTS) {
            throw new SalesRuleException(
                'An installment plan has between 1 and '.self::MAX_INSTALLMENTS.' installments.',
                'INSTALLMENT_COUNT_INVALID',
            );
        }

        $financed = round($financed, 4);
        // Divide in whole dirhams: flooring a float quotient can land a hair
        // under an exact split (350.7 / 3 -> 116.899) and skew the last line.
        $dirhams = (int) round($financed * 1000);
        $base = intdiv($dirhams, $count) / 1000;

        // Every installment is at least one dirham; otherwise all but the last
        // would be zero and read as cancelled.
        if ($count > 1 && $base <= 0) {
            $maxCount = max(1, min(self::MAX_INSTALLMENTS, $dirhams));

            throw new SalesRuleException(
                "{$financed} cannot be split into {$count} installments; at most {$maxCount}.",
                'INSTALLMENT_COUNT_INVALID',
                ['max_count' => $maxCount],
            );
        }
        $first = CarbonImmutable::parse($firstDueDate)->startOfDay();
        $lines = [];

        for ($number = 1; $number <= $count; $number++) {
            $isLast = $number === $count;

            $lines[] = [
                'number' => $number,
                'due_date' => $first->addMonthsNoOverflow($number - 1)->toDateString(),
                'amount' => $isLast ? round($financed - $base * ($count - 1), 4) : round($base, 4),
            ];
        }

        return $lines;
    }

    /**
     * Open a plan on a sold receivable sale over what it still owes. The
     * caller holds the sale's lock and has already collected any down payment
     * (it is part of the sale's amount_paid by now).
     */
    public function create(SalesOrder $order, int $count, string $firstDueDate, float $downPayment, User $user): InstallmentPlan
    {
        $this->ensureEligible($order);

        if (InstallmentPlan::withoutGlobalScopes()->where('sales_order_id', $order->id)->exists()) {
            throw new SalesRuleException(
                "Sale {$order->order_number} already has an installment plan.",
                'INSTALLMENT_PLAN_EXISTS',
            );
        }

        $financed = $order->outstanding();

        if ($financed <= 0.0001) {
            throw new SalesRuleException(
                "Sale {$order->order_number} has nothing outstanding to put on installments.",
                'INSTALLMENT_NOTHING_OUTSTANDING',
            );
        }

        $lines = $this->buildLines($financed, $count, $firstDueDate);

        $plan = InstallmentPlan::create([
            'operating_unit_id' => $order->operating_unit_id,
            'sales_order_id' => $order->id,
            'client_id' => $order->client_id,
            'financed_amount' => $financed,
            'down_payment' => round($downPayment, 4),
            'opening_paid' => round((float) $order->amount_paid, 4),
            'opening_returned' => round((float) ($order->returned_amount ?? 0), 4),
            'installment_count' => $count,
            'frequency' => InstallmentFrequency::Monthly,
            'first_due_date' => $lines[0]['due_date'],
            'created_by' => $user->id,
        ]);

        foreach ($lines as $line) {
            $plan->lines()->create($line);
        }

        return $plan->load('lines');
    }

    /**
     * INSTALLMENTS_NEED_RECEIVABLE: only a registered client's receivable
     * sale that has actually been sold can carry a schedule.
     */
    public function ensureEligible(SalesOrder $order): void
    {
        if (! $order->isReceivable() || $order->client_id === null || $order->isInternal() || ! $order->status->isSold()) {
            throw new SalesRuleException(
                'Installments apply only to a registered client\'s credit (receivable) sale.',
                'INSTALLMENTS_NEED_RECEIVABLE',
            );
        }
    }

    /**
     * The plan with every installment's paid amount, remaining amount and
     * status derived from the sale as it stands.
     *
     * @return array<string, mixed>
     */
    public function schedule(InstallmentPlan $plan, ?SalesOrder $order = null, ?string $today = null): array
    {
        $order ??= $plan->salesOrder()->withoutGlobalScopes()->firstOrFail();
        $today ??= CompanyClock::today($order->operatingUnit);
        $lines = $plan->relationLoaded('lines') ? $plan->lines : $plan->lines()->get();
        $lines = $lines->sortBy('number')->values();

        // Money collected since the plan opened (net of cash/bank refunds) and
        // goods returned since then. A refund paid out of money collected
        // before the plan, or out of the down payment, leaves the debt as it
        // was, so it offsets the return instead of cutting installments.
        $paidDelta = round((float) $order->amount_paid - (float) $plan->opening_paid, 4);
        $returnedDelta = round((float) ($order->returned_amount ?? 0) - (float) $plan->opening_returned, 4);

        // Returns cut the last installments first.
        $returnedLeft = max(0.0, round($returnedDelta + min(0.0, $paidDelta), 4));
        $effective = [];
        foreach ($lines->reverse() as $line) {
            $amount = round((float) $line->amount, 4);
            $cut = min($amount, $returnedLeft);
            $returnedLeft = round($returnedLeft - $cut, 4);
            $effective[$line->number] = round($amount - $cut, 4);
        }

        // Collections pay the oldest installments first.
        $paidLeft = max(0.0, $paidDelta);
        $todayDate = CarbonImmutable::parse($today)->startOfDay();
        $rows = [];
        $totals = ['amount' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'overdue' => 0.0];

        foreach ($lines as $line) {
            $amount = $effective[$line->number];
            $paid = round(min($amount, $paidLeft), 4);
            $paidLeft = round($paidLeft - $paid, 4);
            $remaining = round($amount - $paid, 4);
            $dueDate = CarbonImmutable::parse($line->due_date)->startOfDay();

            $status = match (true) {
                $amount <= 0.0001 => InstallmentLineStatus::Cancelled,
                $remaining <= 0.0001 => InstallmentLineStatus::Paid,
                $dueDate->lessThan($todayDate) => InstallmentLineStatus::Overdue,
                $paid > 0.0001 => InstallmentLineStatus::Partial,
                default => InstallmentLineStatus::Upcoming,
            };

            $rows[] = [
                'id' => $line->id,
                'number' => $line->number,
                'due_date' => $dueDate->toDateString(),
                'scheduled_amount' => round((float) $line->amount, 4),
                'amount' => $amount,
                'paid_amount' => $paid,
                'remaining' => $status->isOpen() ? $remaining : 0.0,
                'status' => $status->value,
                'status_label' => $status->label(),
                'days_overdue' => $status === InstallmentLineStatus::Overdue ? (int) $dueDate->diffInDays($todayDate) : 0,
            ];

            $totals['amount'] = round($totals['amount'] + $amount, 4);
            $totals['paid'] = round($totals['paid'] + $paid, 4);
            $totals['remaining'] = round($totals['remaining'] + ($status->isOpen() ? $remaining : 0.0), 4);
            if ($status === InstallmentLineStatus::Overdue) {
                $totals['overdue'] = round($totals['overdue'] + $remaining, 4);
            }
        }

        $frequency = $plan->frequency instanceof InstallmentFrequency ? $plan->frequency : InstallmentFrequency::Monthly;

        return [
            'id' => $plan->id,
            'sales_order_id' => $plan->sales_order_id,
            'client_id' => $plan->client_id,
            'financed_amount' => round((float) $plan->financed_amount, 4),
            'down_payment' => round((float) $plan->down_payment, 4),
            'installment_count' => $plan->installment_count,
            'frequency' => $frequency->value,
            'frequency_label' => $frequency->label(),
            'first_due_date' => CarbonImmutable::parse($plan->first_due_date)->toDateString(),
            'created_by' => $plan->created_by,
            'created_at' => $plan->created_at?->toIso8601String(),
            'as_of' => $todayDate->toDateString(),
            'lines' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * GET /installments: installments across the unit's plans with days
     * overdue and totals. `due` lists everything still to pay, `overdue` only
     * what is past its due date, `all` every installment including paid and
     * cancelled ones. from/to bound the due date.
     *
     * @param  array{status?: string|null, from?: string|null, to?: string|null, client_id?: string|null}  $filters
     * @return array{as_of: string, status: string, data: array<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function report(array $filters): array
    {
        $status = $filters['status'] ?? 'due';
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $today = CompanyClock::today();

        // Narrow in SQL: only plans with a line in the date range (and, for
        // overdue, a line already past due), and for due/overdue only sales
        // that still owe something. Every line of a matching plan is still
        // loaded, since payments and returns allocate across the whole plan.
        $plans = InstallmentPlan::query()
            ->with(['lines', 'salesOrder', 'client.entity'])
            ->when($filters['client_id'] ?? null, fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->when($from !== null || $to !== null || $status === 'overdue', fn ($q) => $q->whereHas('lines', function ($lines) use ($from, $to, $status, $today): void {
                $lines->when($from !== null, fn ($l) => $l->whereDate('due_date', '>=', $from))
                    ->when($to !== null, fn ($l) => $l->whereDate('due_date', '<=', $to))
                    ->when($status === 'overdue', fn ($l) => $l->whereDate('due_date', '<', $today));
            }))
            ->when($status !== 'all', fn ($q) => $q->whereHas('salesOrder', fn ($order) => $order
                ->whereRaw('total_amount - COALESCE(returned_amount, 0) - amount_paid > 0.0001')))
            ->get();

        $rows = [];

        foreach ($plans as $plan) {
            $order = $plan->salesOrder;

            if ($order === null || ! $order->status->isSold()) {
                continue;
            }

            $schedule = $this->schedule($plan, $order, $today);

            foreach ($schedule['lines'] as $line) {
                $isOpen = InstallmentLineStatus::from($line['status'])->isOpen();

                if ($status === 'due' && ! $isOpen) {
                    continue;
                }
                if ($status === 'overdue' && $line['status'] !== InstallmentLineStatus::Overdue->value) {
                    continue;
                }
                if ($from !== null && $line['due_date'] < $from) {
                    continue;
                }
                if ($to !== null && $line['due_date'] > $to) {
                    continue;
                }

                $rows[] = [
                    ...$line,
                    'installment_plan_id' => $plan->id,
                    'installment_count' => $plan->installment_count,
                    'sales_order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'client_id' => $plan->client_id,
                    'client_name' => $plan->client?->entity?->name,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['due_date'], $a['order_number'], $a['number']] <=> [$b['due_date'], $b['order_number'], $b['number']]);

        $sum = fn (string $field): float => round(array_sum(array_column($rows, $field)), 4);

        return [
            'as_of' => $today,
            'status' => $status,
            'data' => $rows,
            'totals' => [
                'count' => count($rows),
                'amount' => $sum('amount'),
                'paid' => $sum('paid_amount'),
                'remaining' => $sum('remaining'),
                'overdue' => round(array_sum(array_map(
                    fn (array $row): float => $row['status'] === InstallmentLineStatus::Overdue->value ? (float) $row['remaining'] : 0.0,
                    $rows,
                )), 4),
            ],
        ];
    }
}
