<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use App\Models\Client;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\TreasuryVoucher;
use Illuminate\Support\Carbon;

/**
 * The client card's money summary.
 *
 * - Balance, aging and overdue come from the receivables ledger
 *   (ReceivablesLedgerService::balances), the same figures as the debtor
 *   statements, so receipt vouchers and cheque bounces are included.
 * - Balance, aging, open sales and the last payment all span every unit
 *   (the client is resolved through its own unit scope first), so a client
 *   that moved unit still shows its older sales next to their debt.
 * - Open sales are document-derived: sold sales whose outstanding
 *   (total − returned − paid) is above zero. Receipt vouchers do not allocate
 *   to sales, so their sum can differ from the ledger balance.
 * - The last payment is the latest collection against the receivable: a
 *   payment on a receivable sale, or a posted receipt voucher for the client.
 *   Return refunds (negative payments) and cheques that bounced or went back
 *   to the drawer are not collections.
 * - Everything is as of today: open sales use the sales' current paid and
 *   returned amounts, so a past date could not be answered consistently.
 */
final class ClientSummaryService
{
    public function __construct(private readonly ReceivablesLedgerService $receivables) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Client $client): array
    {
        $asOf = now()->toDateString();
        $asOfDate = Carbon::parse($asOf)->startOfDay();
        $terms = (int) $client->payment_terms_days;

        $balance = $this->receivables->balances($asOf, null, [$client->id])[$client->id] ?? [
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'net_balance' => 0.0,
            'aging' => ['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0],
            'overdue_amount' => 0.0,
        ];

        // Across units like the ledger figures above: the client itself was
        // resolved through its unit scope, and it may have moved unit since
        // its older receivable sales.
        $openSales = SalesOrder::withoutGlobalScope(OperatingUnitScope::class)
            ->where('client_id', $client->id)
            ->whereNotIn('status', [
                SalesOrderStatus::PendingApproval->value,
                SalesOrderStatus::Rejected->value,
                SalesOrderStatus::Draft->value,
            ])
            ->whereRaw('total_amount - COALESCE(returned_amount, 0) - amount_paid > 0.0001')
            ->orderBy('created_at')
            ->orderBy('order_number')
            ->get()
            ->map(function (SalesOrder $sale) use ($asOfDate, $terms): array {
                $saleDate = $sale->created_at->copy()->startOfDay();
                $dueDate = $saleDate->copy()->addDays($terms);
                $daysOverdue = $dueDate->lt($asOfDate) ? (int) $dueDate->diffInDays($asOfDate) : 0;

                return [
                    'id' => $sale->id,
                    'order_number' => $sale->order_number,
                    'date' => $saleDate->toDateString(),
                    'payment_method' => $sale->payment_method,
                    'total_amount' => (float) $sale->total_amount,
                    'returned_amount' => (float) ($sale->returned_amount ?? 0),
                    'amount_paid' => (float) $sale->amount_paid,
                    'outstanding' => $sale->outstanding(),
                    'due_date' => $dueDate->toDateString(),
                    'days_overdue' => $daysOverdue,
                ];
            })
            ->values();

        return [
            'client_id' => $client->id,
            'as_of' => $asOfDate->toDateString(),
            'balance' => $balance['net_balance'],
            'aging' => $balance['aging'],
            'overdue_amount' => $balance['overdue_amount'],
            'last_payment' => $this->lastPayment($client),
            'open_sales' => $openSales->all(),
            'open_sales_count' => $openSales->count(),
            'open_sales_outstanding' => round((float) $openSales->sum('outstanding'), 4),
        ];
    }

    /**
     * @return array{date: string, amount: float, method: string|null, source: string, reference: string|null}|null
     */
    private function lastPayment(Client $client): ?array
    {
        $payment = SalePayment::withoutGlobalScope(OperatingUnitScope::class)
            ->with('salesOrder:id,order_number')
            ->where('client_id', $client->id)
            ->whereNull('sales_return_id')
            ->where('amount', '>', 0)
            ->whereHas('salesOrder', fn ($sale) => $sale->withoutGlobalScope(OperatingUnitScope::class)->where('payment_method', 'receivable'))
            ->whereDoesntHave('cheque', fn ($cheque) => $cheque->withoutGlobalScope(OperatingUnitScope::class)
                ->whereIn('status', [ChequeStatus::Bounced->value, ChequeStatus::ReturnedToDrawer->value]))
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->first();

        $voucher = TreasuryVoucher::withoutGlobalScope(OperatingUnitScope::class)
            ->where('client_id', $client->id)
            ->where('kind', TreasuryVoucherKind::Receipt->value)
            ->where('status', TreasuryVoucherStatus::Posted->value)
            ->orderByDesc('voucher_date')
            ->orderByDesc('created_at')
            ->first();

        $fromPayment = $payment === null ? null : [
            'date' => Carbon::parse($payment->received_at)->toDateString(),
            'amount' => round((float) $payment->amount, 4),
            'method' => $payment->method,
            'source' => 'sale_payment',
            'reference' => $payment->salesOrder?->order_number,
            'sort' => Carbon::parse($payment->received_at)->toDateString().' '.$payment->created_at?->toDateTimeString(),
        ];

        $fromVoucher = $voucher === null ? null : [
            'date' => $voucher->voucher_date->toDateString(),
            'amount' => round((float) $voucher->amount_lyd, 4),
            'method' => 'voucher',
            'source' => 'receipt_voucher',
            'reference' => $voucher->voucher_number,
            'sort' => $voucher->voucher_date->toDateString().' '.$voucher->created_at?->toDateTimeString(),
        ];

        $latest = match (true) {
            $fromPayment === null => $fromVoucher,
            $fromVoucher === null => $fromPayment,
            default => strcmp($fromPayment['sort'], $fromVoucher['sort']) >= 0 ? $fromPayment : $fromVoucher,
        };

        if ($latest === null) {
            return null;
        }

        unset($latest['sort']);

        return $latest;
    }
}
