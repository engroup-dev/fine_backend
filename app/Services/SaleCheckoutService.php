<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Enums\InventoryEventType;
use App\Enums\SaleFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\InventoryAccountNotLinkedException;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\ChequeEvent;
use App\Models\Client;
use App\Models\CreditApprovalRequest;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\ReceivableAccounts;
use App\Support\SalesAccounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The POS is the one place a sale happens (SALE-04). A checkout is atomic:
 * the buyer, payment and lines are checked, stock leaves for item lines, and
 * one balanced journal records money in, revenue and cost out.
 *
 * - Client sales are paid by cash or bank (into a chosen treasury) or put on
 *   the client's receivable account. A receivable that would take the client
 *   past its credit limit waits for a manager (SALE-01/02) with nothing moved.
 * - Bundle lines carry the bundle's name and price only. Revenue is booked at
 *   checkout; their pieces are defined, fulfilled and costed afterwards
 *   (BundleFulfillmentService).
 * - Internal sales to another unit move items at cost with no payment and no
 *   revenue (SALE-03/08).
 */
final class SaleCheckoutService
{
    private const METHODS = ['cash', 'bank', 'receivable', 'split'];

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly StockIssueService $stockIssueService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly PosDayGuard $posDayGuard,
        private readonly ChequeService $chequeService,
    ) {}

    /**
     * Shared validation rules for split checkout payments (FR-INV-024).
     *
     * @return array<string, array<int, mixed>|ValidationRule>
     */
    public static function splitPaymentRules(): array
    {
        return [
            'payments' => [
                'required_if:payment_method,split',
                'nullable',
                'array',
                Rule::when(
                    fn ($input) => ($input['payment_method'] ?? null) === 'split',
                    ['min:2', 'max:4'],
                ),
            ],
            'payments.*.method' => ['required_if:payment_method,split', Rule::in(['cash', 'bank', 'cheque'])],
            'payments.*.amount' => ['required_if:payment_method,split', 'numeric', 'gt:0'],
            'payments.*.cash_account_id' => ['required_if:payments.*.method,cash,bank', 'nullable', 'uuid'],
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0', 'prohibited_unless:payments.*.method,cash'],
            'payments.*.cheque' => ['required_if:payments.*.method,cheque', 'prohibited_unless:payments.*.method,cheque', 'array'],
            'payments.*.cheque.cheque_no' => ['required_if:payments.*.method,cheque', 'string'],
            'payments.*.cheque.bank_name' => ['required_if:payments.*.method,cheque', 'string'],
            'payments.*.cheque.drawer_name' => ['nullable', 'string'],
            'payments.*.cheque.due_date' => ['required_if:payments.*.method,cheque', 'date'],
            'payments.*.cheque.is_certified' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array{
     *     client_id?: string|null,
     *     buyer_unit_id?: string|null,
     *     buyer_warehouse_id?: string|null,
     *     walk_in_name?: string|null,
     *     walk_in_phone?: string|null,
     *     payment_method?: string|null,
     *     cash_account_id?: string|null,
     *     client_request_id?: string|null,
     *     quotation_id?: string|null,
     *     invoice_discount?: float|int|string|null,
     *     notes?: string|null,
     *     lines: array<int, array{
     *         line_type?: string|null,
     *         inventory_item_id?: string|null,
     *         stock_lot_id?: string|null,
     *         bundle_id?: string|null,
     *         description?: string|null,
     *         quantity: float|int|string,
     *         unit_price?: float|int|string|null,
     *         discount_amount?: float|int|string|null,
     *         length_m?: float|int|string|null,
     *         width_m?: float|int|string|null,
     *         height_m?: float|int|string|null,
     *     }>,
     * }  $data
     */
    public function checkout(OperatingUnit $unit, array $data, User $seller): SalesOrder
    {
        $requestId = $data['client_request_id'] ?? null;

        if ($requestId !== null && ($existing = $this->findByRequestId($requestId)) !== null) {
            return $existing;
        }

        $this->posDayGuard->ensureOpen($unit);

        if (($data['payment_method'] ?? null) === 'split') {
            if (! empty($data['buyer_unit_id'])) {
                throw new SalesRuleException('Internal transfers cannot be split.', 'SPLIT_NOT_ALLOWED');
            }
            if (empty($data['client_id']) && collect($data['payments'] ?? [])->contains(fn ($p) => ($p['method'] ?? null) === 'cheque')) {
                throw new SalesRuleException('A registered client is required for credit or cheque sales.', 'CLIENT_REQUIRED');
            }
        }

        [$client, $buyerUnit] = $this->resolveBuyer($unit, $data);
        $isInternal = $buyerUnit !== null;
        $isWalkIn = $client === null && $buyerUnit === null;
        $method = ! $isInternal ? $this->resolveMethod($client, $data['payment_method'] ?? null) : null;
        $cashAccount = in_array($method, ['cash', 'bank'], true)
            ? $this->resolveTreasury($data['cash_account_id'] ?? null, $method)
            : null;
        $lines = $this->normalizeLines($data['lines'] ?? [], isInternal: $isInternal);

        $invoiceDiscount = round((float) ($data['invoice_discount'] ?? 0), 4);
        if ($invoiceDiscount < 0) {
            throw new SalesRuleException('Invoice discount cannot be negative.', 'DISCOUNT_INVALID');
        }
        if ($isInternal && $invoiceDiscount > 0) {
            throw new SalesRuleException('Internal transfers are priced at cost and cannot carry discounts.', 'DISCOUNT_INVALID');
        }

        $gross = round(array_sum(array_map(fn (array $l): float => $l['quantity'] * $l['unit_price'], $lines)), 4);
        $subtotal = round(array_sum(array_map(fn (array $l): float => $l['quantity'] * $l['unit_price'] - $l['discount_amount'], $lines)), 4);

        if ($invoiceDiscount > $subtotal + 0.0001) {
            throw new SalesRuleException(
                "Invoice discount {$invoiceDiscount} exceeds subtotal {$subtotal}.",
                'DISCOUNT_INVALID',
            );
        }

        $total = round($subtotal - $invoiceDiscount, 4);

        if (! $isInternal && $total <= 0) {
            throw new SalesRuleException('A sale to a client must have a total above zero.', 'ZERO_TOTAL');
        }

        $tendered = null;
        $changeGiven = null;
        if ($method === 'cash' && array_key_exists('tendered_amount', $data) && $data['tendered_amount'] !== null) {
            $tendered = round((float) $data['tendered_amount'], 4);
            if ($tendered < $total - 0.0001) {
                throw new SalesRuleException(
                    "Tendered amount {$tendered} is less than sale total {$total}.",
                    'TENDER_BELOW_TOTAL',
                    ['total' => $total, 'tendered' => $tendered],
                );
            }
            $changeGiven = round($tendered - $total, 4);
        }

        $splitPayments = null;
        if ($method === 'split') {
            $splitPayments = $this->resolveSplitPayments($data['payments'] ?? [], $total, $client !== null);
        }

        $walkInName = $isWalkIn && isset($data['walk_in_name']) ? trim((string) $data['walk_in_name']) : null;
        $walkInPhone = $isWalkIn && isset($data['walk_in_phone']) ? trim((string) $data['walk_in_phone']) : null;

        try {
            return DB::transaction(function () use ($unit, $data, $seller, $client, $buyerUnit, $method, $cashAccount, $lines, $gross, $invoiceDiscount, $total, $requestId, $tendered, $changeGiven, $splitPayments, $walkInName, $walkInPhone): SalesOrder {
                $order = SalesOrder::create([
                    'operating_unit_id' => $unit->id,
                    'order_number' => $this->documentNumbers->next(DocumentNumberService::SALE),
                    'client_request_id' => $requestId,
                    'quotation_id' => $data['quotation_id'] ?? null,
                    'buyer_type' => match (true) {
                        $client !== null => 'client',
                        $buyerUnit !== null => 'internal_unit',
                        default => 'walk_in',
                    },
                    'client_id' => $client?->id,
                    'buyer_unit_id' => $buyerUnit?->id,
                    'walk_in_name' => $walkInName !== null && $walkInName !== '' ? $walkInName : null,
                    'walk_in_phone' => $walkInPhone !== null && $walkInPhone !== '' ? $walkInPhone : null,
                    'channel' => 'pos',
                    'status' => SalesOrderStatus::Open,
                    'payment_method' => $method,
                    'cash_account_id' => $cashAccount?->id,
                    'notes' => $data['notes'] ?? null,
                    'sold_by_user_id' => $seller->id,
                ]);

                foreach ($lines as $line) {
                    $order->lines()->create($line);
                }

                $order->gross_amount = $gross;
                $order->invoice_discount = $invoiceDiscount;
                $order->total_amount = $total;
                $order->save();

                if ($method === 'receivable' && $client !== null && $this->exceedsCreditLimit($client, $total)) {
                    return $this->escalate($order, $client, $total);
                }

                $this->finalize($order, $data['buyer_warehouse_id'] ?? null, $seller, $tendered, $changeGiven, $splitPayments);

                return $this->reload($order);
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry of the same cart won the race — return its sale.
            if ($requestId !== null && ($existing = $this->findByRequestId($requestId)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * A manager lets an over-limit receivable sale through: it completes
     * exactly as if it had passed the credit check at the counter.
     */
    public function approveCredit(CreditApprovalRequest $approval, User $decidedBy, ?string $notes = null): CreditApprovalRequest
    {
        return DB::transaction(function () use ($approval, $decidedBy, $notes): CreditApprovalRequest {
            $locked = $this->lockPendingApproval($approval);
            $order = SalesOrder::whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => 'approved',
                'decided_by_user_id' => $decidedBy->id,
                'decided_at' => now(),
                'notes' => $notes,
            ]);

            $order->status = SalesOrderStatus::Open;
            $order->save();

            $this->finalize($order, null, $decidedBy);

            return $locked->fresh(['salesOrder']);
        });
    }

    public function rejectCredit(CreditApprovalRequest $approval, User $decidedBy, ?string $notes = null): CreditApprovalRequest
    {
        return DB::transaction(function () use ($approval, $decidedBy, $notes): CreditApprovalRequest {
            $locked = $this->lockPendingApproval($approval);

            $locked->update([
                'status' => 'rejected',
                'decided_by_user_id' => $decidedBy->id,
                'decided_at' => now(),
                'notes' => $notes,
            ]);

            $order = SalesOrder::whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();
            $order->status = SalesOrderStatus::Rejected;
            $order->save();

            return $locked->fresh(['salesOrder']);
        });
    }

    /**
     * Money collected later against a receivable sale (SALE-06): the treasury
     * is debited, the client's receivable credited, and the balance drops.
     *
     * @param  array{
     *     cheque_no?: string|null,
     *     bank_name?: string|null,
     *     drawer_name?: string|null,
     *     due_date?: string|null,
     *     is_certified?: bool|null,
     * }|null  $chequeData
     */
    public function collectPayment(
        SalesOrder $order,
        float $amount,
        string $method,
        ?string $cashAccountId,
        User $receivedBy,
        ?float $tenderedAmount = null,
        ?array $chequeData = null,
    ): SalesOrder {
        if ($amount <= 0) {
            throw new SalesRuleException('A payment must be greater than zero.', 'INVALID_AMOUNT');
        }

        if (! in_array($method, ['cash', 'bank', 'cheque'], true)) {
            throw new SalesRuleException('A collection is received in cash, by bank, or by cheque.', 'PAYMENT_METHOD_REQUIRED');
        }

        if ($method === 'cheque' && empty($chequeData)) {
            throw new SalesRuleException('Cheque details are required for a cheque payment.', 'CHEQUE_DETAILS_REQUIRED');
        }

        $this->posDayGuard->ensureOpen($order->operatingUnit()->firstOrFail());

        $cashAccount = in_array($method, ['cash', 'bank'], true)
            ? $this->resolveTreasury($cashAccountId, $method)
            : null;

        $changeGiven = null;
        if ($method === 'cash' && $tenderedAmount !== null) {
            $tendered = round($tenderedAmount, 4);
            if ($tendered < $amount - 0.0001) {
                throw new SalesRuleException(
                    "Tendered amount {$tendered} is less than payment amount {$amount}.",
                    'TENDER_BELOW_TOTAL',
                    ['total' => $amount, 'amount' => $amount, 'tendered' => $tendered],
                );
            }
            $changeGiven = round($tendered - $amount, 4);
        }

        return DB::transaction(function () use ($order, $amount, $method, $cashAccount, $receivedBy, $tenderedAmount, $changeGiven, $chequeData): SalesOrder {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isReceivable() || ! $locked->status->isSold()) {
                throw new InvalidStateTransitionException(
                    "Sale {$locked->order_number} has no receivable to collect."
                );
            }

            $outstanding = $locked->outstanding();

            if ($amount > $outstanding + 0.0001) {
                throw new SalesRuleException(
                    "Payment of {$amount} exceeds the outstanding {$outstanding}.",
                    'PAYMENT_EXCEEDS_OUTSTANDING',
                );
            }

            if ($method === 'cheque') {
                $this->collectChequePayment($locked, $amount, $chequeData ?? [], $receivedBy);
            } else {
                $client = Client::with('account')->whereKey($locked->client_id)->lockForUpdate()->first();

                if ($client !== null) {
                    $client->current_balance = round((float) $client->current_balance - $amount, 4);
                    $client->save();
                }

                $receivableCode = SalesAccounts::receivableFor($client);

                $entry = $this->accountingService->postJournal(
                    "Collection on sale {$locked->order_number}",
                    [
                        ['account_code' => SalesAccounts::treasuryFor($cashAccount), 'debit' => $amount, 'operating_unit_id' => $locked->operating_unit_id],
                        ['account_code' => $receivableCode, 'credit' => $amount, 'operating_unit_id' => $locked->operating_unit_id, 'client_id' => $this->partyFor($client, $receivableCode)],
                    ],
                    'SalesOrder',
                    $locked->id,
                    $locked->operatingUnit?->company_id,
                );

                $this->recordPayment(
                    $locked,
                    $amount,
                    $method,
                    $cashAccount,
                    $receivedBy,
                    $entry->id,
                    tenderedAmount: $tenderedAmount,
                    changeGiven: $changeGiven,
                );
            }

            $locked->amount_paid = round((float) $locked->amount_paid + $amount, 4);
            $locked->status = $this->settledStatus($locked);
            $locked->save();

            return $this->reload($locked);
        });
    }

    /**
     * Re-derive open/completed after goods or money moved. Used by bundle
     * delivery once the last piece is handed over.
     */
    public function refreshStatus(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::Open || $order->status === SalesOrderStatus::Completed) {
            $order->status = $this->settledStatus($order);
            $order->save();
        }
    }

    /**
     * FR-INV-024 / TRS-05: Receive a customer cheque against a sale and record its payment.
     *
     * @param  array{
     *     cheque_no?: string|null,
     *     bank_name?: string|null,
     *     drawer_name?: string|null,
     *     due_date?: string|null,
     *     is_certified?: bool|null,
     * }  $chequeData
     */
    private function collectChequePayment(
        SalesOrder $order,
        float $amount,
        array $chequeData,
        User $receivedBy,
    ): SalePayment {
        $cheque = $this->chequeService->receive([
            'direction' => 'incoming',
            'client_id' => $order->client_id,
            'operating_unit_id' => $order->operating_unit_id,
            'company_id' => $order->operatingUnit?->company_id,
            'amount' => $amount,
            'currency' => 'LYD',
            'cheque_no' => $chequeData['cheque_no'] ?? '',
            'bank_name' => $chequeData['bank_name'] ?? '',
            'drawer_name' => $chequeData['drawer_name'] ?? null,
            'due_date' => $chequeData['due_date'] ?? null,
            'is_certified' => (bool) ($chequeData['is_certified'] ?? false),
        ], $receivedBy);

        $journalEntryId = ChequeEvent::where('cheque_id', $cheque->id)
            ->where('to_status', ChequeStatus::InPortfolio)
            ->value('journal_entry_id');

        return $this->recordPayment(
            $order,
            $amount,
            'cheque',
            null,
            $receivedBy,
            $journalEntryId,
            tenderedAmount: null,
            changeGiven: null,
            chequeId: $cheque->id,
        );
    }

    /**
     * FR-INV-024: Validate and prepare payment portions for a split sale.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array{
     *     method: string,
     *     amount: float,
     *     cashAccount: CashAccount|null,
     *     tendered_amount: float|null,
     *     change_given: float|null,
     *     cheque: array{cheque_no?: string|null, bank_name?: string|null, drawer_name?: string|null, due_date?: string|null, is_certified?: bool|null}|null,
     * }>
     */
    private function resolveSplitPayments(array $payments, float $total, bool $hasClient = true): array
    {
        $paymentsCount = count($payments);
        if ($paymentsCount < 2 || $paymentsCount > 4) {
            throw new SalesRuleException('A split payment requires between 2 and 4 payment portions.', 'SPLIT_PORTIONS_INVALID');
        }

        $cashCount = 0;
        $paidTotal = 0.0;
        $resolved = [];

        foreach ($payments as $portion) {
            $portionMethod = $portion['method'] ?? null;
            if (! in_array($portionMethod, ['cash', 'bank', 'cheque'], true)) {
                throw new SalesRuleException('A split payment portion must be cash, bank, or cheque.', 'PAYMENT_METHOD_REQUIRED');
            }

            if ($portionMethod === 'cheque' && ! $hasClient) {
                throw new SalesRuleException('A registered client is required for credit or cheque sales.', 'CLIENT_REQUIRED');
            }

            $portionAmount = round((float) ($portion['amount'] ?? 0), 4);
            if ($portionAmount <= 0) {
                throw new SalesRuleException('Every payment portion must be greater than zero.', 'INVALID_AMOUNT');
            }
            $paidTotal = round($paidTotal + $portionAmount, 4);

            $portionCashAccount = null;
            $portionTendered = null;
            $portionChange = null;

            if ($portionMethod === 'cash') {
                $cashCount++;
                if ($cashCount > 1) {
                    throw new SalesRuleException('A split sale can have at most one cash portion.', 'SPLIT_MULTIPLE_CASH');
                }
                $portionCashAccount = $this->resolveTreasury($portion['cash_account_id'] ?? null, 'cash');

                if (array_key_exists('tendered_amount', $portion) && $portion['tendered_amount'] !== null) {
                    $portionTendered = round((float) $portion['tendered_amount'], 4);
                    if ($portionTendered < $portionAmount - 0.0001) {
                        throw new SalesRuleException(
                            "Tendered amount {$portionTendered} is less than payment amount {$portionAmount}.",
                            'TENDER_BELOW_TOTAL',
                            ['total' => $portionAmount, 'tendered' => $portionTendered],
                        );
                    }
                    $portionChange = round($portionTendered - $portionAmount, 4);
                }
            } elseif ($portionMethod === 'bank') {
                $portionCashAccount = $this->resolveTreasury($portion['cash_account_id'] ?? null, 'bank');
            } elseif ($portionMethod === 'cheque') {
                if (empty($portion['cheque']) || ! is_array($portion['cheque'])) {
                    throw new SalesRuleException('Cheque details are required for a cheque payment.', 'CHEQUE_DETAILS_REQUIRED');
                }
            }

            $resolved[] = [
                'method' => $portionMethod,
                'amount' => $portionAmount,
                'cashAccount' => $portionCashAccount,
                'tendered_amount' => $portionTendered,
                'change_given' => $portionChange,
                'cheque' => $portion['cheque'] ?? null,
            ];
        }

        if (abs($paidTotal - $total) > 0.005) {
            throw new SalesRuleException(
                "Payment portions sum to {$paidTotal}, but sale total is {$total}.",
                'SPLIT_TOTAL_MISMATCH',
                ['total' => $total, 'paid' => $paidTotal],
            );
        }

        return $resolved;
    }

    /**
     * Move the stock and post the books for a sale that is allowed to happen.
     *
     * @param  array<int, array{
     *     method: string,
     *     amount: float,
     *     cashAccount: CashAccount|null,
     *     tendered_amount: float|null,
     *     change_given: float|null,
     *     cheque: array{cheque_no?: string|null, bank_name?: string|null, drawer_name?: string|null, due_date?: string|null, is_certified?: bool|null}|null,
     * }>|null  $splitPayments
     */
    private function finalize(
        SalesOrder $order,
        ?string $buyerWarehouseId,
        User $actor,
        ?float $tenderedAmount = null,
        ?float $changeGiven = null,
        ?array $splitPayments = null,
    ): void {
        $isInternal = $order->isInternal();
        $totalCost = 0.0;
        $costByAccount = [];
        $hasBundle = false;

        foreach ($order->lines()->with('inventoryItem')->get()->values() as $position => $line) {
            if ($line->isBundle()) {
                $hasBundle = true;

                continue;
            }

            [$cost, $portions] = $this->stockIssueService->issue(
                $line->inventory_item_id,
                (float) $line->quantity,
                $order->operating_unit_id,
                $line->stock_lot_id,
                'SalesOrder',
                $order->id,
                $isInternal ? 'internal_transfer_out' : 'sale_issue',
            );

            $line->unit_cost_actual = (float) $line->quantity > 0 ? round($cost / (float) $line->quantity, 4) : 0;

            if ($isInternal) {
                // At cost: the transfer's "price" is what the goods cost.
                $line->unit_price = $line->unit_cost_actual;

                $this->stockIssueService->land(
                    $portions,
                    $order->buyer_unit_id,
                    $buyerWarehouseId,
                    'TRF-'.$order->order_number.'-L'.($position + 1),
                    'SalesOrder',
                    $order->id,
                    'internal_transfer_in',
                );
            }

            $line->save();

            $totalCost += $cost;
            $inventoryItem = $line->inventoryItem;
            $orderUnit = $order->operatingUnit;
            if ($inventoryItem === null || $orderUnit === null) {
                throw new InvalidStateTransitionException(
                    'Sales order line is missing its inventory item or operating unit.',
                );
            }
            $purchasesAccount = $orderUnit->accountFor(InventoryEventType::Purchases);
            if ($purchasesAccount === null) {
                throw new InventoryAccountNotLinkedException($inventoryItem, $orderUnit, InventoryEventType::Purchases);
            }
            $account = $purchasesAccount->account_code;
            $costByAccount[$account] = round(($costByAccount[$account] ?? 0) + $cost, 4);
        }

        $order->total_cost = round($totalCost, 4);

        if ($isInternal) {
            $this->postInternalTransfer($order, $costByAccount);

            $order->gross_amount = $order->total_cost;
            $order->total_amount = $order->total_cost;
            $order->amount_paid = $order->total_cost;
            $order->fulfillment_status = SaleFulfillmentStatus::Delivered;
            $order->status = SalesOrderStatus::Completed;
            $order->save();

            return;
        }

        $client = $order->client_id !== null ? Client::with('account')->whereKey($order->client_id)->lockForUpdate()->first() : null;
        $total = (float) $order->total_amount;
        $lineDiscounts = round((float) $order->lines()->sum('discount_amount'), 4);
        $invoiceDiscount = round((float) $order->invoice_discount, 4);
        $totalDiscount = round($lineDiscounts + $invoiceDiscount, 4);
        $gross = round($total + $totalDiscount, 4);
        $cashAccount = $order->cash_account_id !== null ? CashAccount::with('account')->find($order->cash_account_id) : null;
        $orderUnit = $order->operatingUnit()->with('revenueAccount')->first();
        $revenueCode = SalesAccounts::revenueFor($orderUnit);
        $discountCode = $totalDiscount > 0 ? SalesAccounts::discountFor($orderUnit) : null;

        // Money (or the promise of it) against revenue, cost out of stock —
        // one balanced entry (SALE-07, FR-INV-014, FR-INV-015, BR-INV-017, FR-INV-024).
        if ($order->payment_method === 'split') {
            $journalLines = [];
            $chequeTotal = 0.0;

            foreach ($splitPayments ?? [] as $payment) {
                if (in_array($payment['method'], ['cash', 'bank'], true)) {
                    $journalLines[] = [
                        'account_code' => SalesAccounts::treasuryFor($payment['cashAccount']),
                        'debit' => $payment['amount'],
                        'operating_unit_id' => $order->operating_unit_id,
                    ];
                } elseif ($payment['method'] === 'cheque') {
                    $chequeTotal = round($chequeTotal + $payment['amount'], 4);
                }
            }

            if ($chequeTotal > 0) {
                $receivableCode = SalesAccounts::receivableFor($client);
                $journalLines[] = [
                    'account_code' => $receivableCode,
                    'debit' => $chequeTotal,
                    'operating_unit_id' => $order->operating_unit_id,
                    'client_id' => $this->partyFor($client, $receivableCode),
                ];
            }

            if ($discountCode !== null) {
                $journalLines[] = [
                    'account_code' => $discountCode,
                    'debit' => $totalDiscount,
                    'operating_unit_id' => $order->operating_unit_id,
                ];
            }

            $journalLines[] = [
                'account_code' => $revenueCode,
                'credit' => $gross,
                'operating_unit_id' => $order->operating_unit_id,
            ];
        } else {
            $debitAccount = $order->isReceivable()
                ? SalesAccounts::receivableFor($client)
                : SalesAccounts::treasuryFor($cashAccount);

            $journalLines = [
                [
                    'account_code' => $debitAccount,
                    'debit' => $total,
                    'operating_unit_id' => $order->operating_unit_id,
                    // Only the receivable is a client's account; a cash sale debits the treasury.
                    'client_id' => $order->isReceivable() ? $this->partyFor($client, $debitAccount) : null,
                ],
            ];

            if ($discountCode !== null) {
                $journalLines[] = [
                    'account_code' => $discountCode,
                    'debit' => $totalDiscount,
                    'operating_unit_id' => $order->operating_unit_id,
                ];
            }

            $journalLines[] = [
                'account_code' => $revenueCode,
                'credit' => $gross,
                'operating_unit_id' => $order->operating_unit_id,
            ];
        }

        if ($totalCost > 0) {
            $journalLines[] = ['account_code' => SalesAccounts::COGS, 'debit' => round($totalCost, 4), 'operating_unit_id' => $order->operating_unit_id];
            foreach ($costByAccount as $account => $amount) {
                $journalLines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
            }
        }

        $entry = $this->accountingService->postJournal(
            "Sale {$order->order_number}",
            $journalLines,
            'SalesOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );

        if ($order->payment_method === 'split') {
            if ($chequeTotal > 0 && $client !== null) {
                $client->current_balance = round((float) $client->current_balance + $chequeTotal, 4);
                $client->save();
            }

            foreach ($splitPayments ?? [] as $payment) {
                if ($payment['method'] === 'cheque') {
                    $this->collectChequePayment($order, $payment['amount'], $payment['cheque'] ?? [], $actor);
                } else {
                    $this->recordPayment(
                        $order,
                        $payment['amount'],
                        $payment['method'],
                        $payment['cashAccount'],
                        $actor,
                        $entry->id,
                        $payment['tendered_amount'] ?? null,
                        $payment['change_given'] ?? null,
                    );
                }
            }

            $order->amount_paid = $total;
        } elseif ($order->isReceivable()) {
            if ($client !== null) {
                $client->current_balance = round((float) $client->current_balance + $total, 4);
                $client->save();
            }
            $order->amount_paid = 0;
        } else {
            $this->recordPayment(
                $order,
                $total,
                $order->payment_method,
                $cashAccount,
                $actor,
                $entry->id,
                $tenderedAmount,
                $changeGiven,
            );
            $order->amount_paid = $total;
        }

        $order->fulfillment_status = $hasBundle ? SaleFulfillmentStatus::AwaitingDefinition : SaleFulfillmentStatus::Delivered;
        $order->status = $this->settledStatus($order);
        $order->save();
    }

    /**
     * @param  array<string, float>  $costByAccount
     */
    private function postInternalTransfer(SalesOrder $order, array $costByAccount): void
    {
        $costByAccount = array_filter($costByAccount, fn (float $amount): bool => $amount > 0);

        if ($costByAccount === []) {
            return;
        }

        $lines = [];

        foreach ($costByAccount as $account => $amount) {
            // At-cost transfer: no profit between units, only subledger movement.
            $lines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $order->buyer_unit_id];
            $lines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
        }

        $this->accountingService->postJournal(
            "Internal transfer {$order->order_number}",
            $lines,
            'SalesOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    private function escalate(SalesOrder $order, Client $client, float $total): SalesOrder
    {
        $order->status = SalesOrderStatus::PendingApproval;
        $order->fulfillment_status = null;
        $order->save();

        CreditApprovalRequest::create([
            'sales_order_id' => $order->id,
            'amount_over_limit' => round((float) $client->current_balance + $total - (float) $client->credit_limit, 4),
        ]);

        return $this->reload($order);
    }

    /**
     * Lock the client so two simultaneous sales cannot both pass on the same
     * remaining headroom.
     */
    private function exceedsCreditLimit(Client $client, float $total): bool
    {
        $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();

        return round((float) $locked->current_balance + $total, 4) > (float) $locked->credit_limit;
    }

    private function settledStatus(SalesOrder $order): SalesOrderStatus
    {
        $paid = $order->outstanding() <= 0.0001;
        $delivered = $order->fulfillment_status === SaleFulfillmentStatus::Delivered;

        return $paid && $delivered ? SalesOrderStatus::Completed : SalesOrderStatus::Open;
    }

    private function recordPayment(
        SalesOrder $order,
        float $amount,
        string $method,
        ?CashAccount $cashAccount,
        User $receivedBy,
        ?string $journalEntryId,
        ?float $tenderedAmount = null,
        ?float $changeGiven = null,
        ?string $chequeId = null,
    ): SalePayment {
        return SalePayment::create([
            'operating_unit_id' => $order->operating_unit_id,
            'sales_order_id' => $order->id,
            'client_id' => $order->client_id,
            'amount' => round($amount, 4),
            'method' => $method,
            'cash_account_id' => $cashAccount?->id,
            'received_at' => now(),
            'received_by_user_id' => $receivedBy->id,
            'journal_entry_id' => $journalEntryId,
            'tendered_amount' => $tenderedAmount !== null ? round($tenderedAmount, 4) : null,
            'change_given' => $changeGiven !== null ? round($changeGiven, 4) : null,
            'cheque_id' => $chequeId,
        ]);
    }

    /**
     * Resolve the sale's buyer: a registered client, another operating unit, or
     * an anonymous POS walk-in when neither is sent (FR-INV-003, decision #7).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Client|null, 1: OperatingUnit|null}
     */
    private function resolveBuyer(OperatingUnit $unit, array $data): array
    {
        $clientId = $data['client_id'] ?? null;
        $buyerUnitId = $data['buyer_unit_id'] ?? null;

        if ($clientId !== null && $buyerUnitId !== null) {
            throw new SalesRuleException(
                'Choose one buyer: a registered client or another operating unit.',
                'BUYER_REQUIRED',
            );
        }

        if ($clientId === null && $buyerUnitId === null) {
            return [null, null];
        }

        if ($buyerUnitId !== null) {
            if ($buyerUnitId === $unit->id) {
                throw new SalesRuleException('A unit cannot sell to itself.', 'SELF_TRANSFER');
            }

            return [null, OperatingUnit::findOrFail($buyerUnitId)];
        }

        // Unit-scoped: a client registered in another unit is not found here.
        $client = Client::with('account')->whereKey($clientId)->first();

        if ($client === null) {
            throw new SalesRuleException('The client is not registered in this operating unit.', 'CLIENT_NOT_FOUND');
        }

        if ($client->status?->value === 'blacklisted') {
            throw new SalesRuleException('This client is blacklisted and cannot buy.', 'CLIENT_BLOCKED');
        }

        return [$client, null];
    }

    /**
     * Walk-in customers must pay in full now by cash, bank, or a cash/bank
     * split; credit (receivable) and cheque sales require a registered client
     * (FR-INV-003, decision #7).
     */
    private function resolveMethod(?Client $client, ?string $method): string
    {
        if ($client === null) {
            if (in_array($method, ['receivable', 'cheque'], true)) {
                throw new SalesRuleException(
                    'A registered client is required for credit or cheque sales.',
                    'CLIENT_REQUIRED',
                );
            }

            if (! in_array($method, ['cash', 'bank', 'split'], true)) {
                throw new SalesRuleException('Choose how the customer pays: cash, bank or split.', 'PAYMENT_METHOD_REQUIRED');
            }

            return $method;
        }

        if (! in_array($method, self::METHODS, true)) {
            throw new SalesRuleException('Choose how the client pays: cash, bank or receivable.', 'PAYMENT_METHOD_REQUIRED');
        }

        if ($method === 'receivable' && $client->status?->value !== 'active') {
            throw new SalesRuleException('Only an active client can buy on credit.', 'CLIENT_BLOCKED');
        }

        return $method;
    }

    /**
     * The client id to tag a receivable line with, or null when the client's
     * linked account sits outside the receivables subtree (such a line stays
     * untagged rather than being refused).
     */
    private function partyFor(?Client $client, string $accountCode): ?string
    {
        if ($client === null) {
            return null;
        }

        $accountId = Account::where('account_code', $accountCode)->value('id');

        return $accountId !== null && ReceivableAccounts::contains($accountId) ? $client->id : null;
    }

    /**
     * The treasury money goes into: one of this unit's, of the matching kind,
     * and linked to the ledger.
     */
    private function resolveTreasury(?string $cashAccountId, string $method): CashAccount
    {
        $cashAccount = $cashAccountId !== null
            ? CashAccount::with('account')->whereKey($cashAccountId)->first()
            : null;

        if ($cashAccount === null) {
            throw new SalesRuleException(
                $method === 'bank' ? 'Choose the bank account the money goes into.' : 'Choose the treasury the cash goes into.',
                'TREASURY_REQUIRED',
            );
        }

        if ($cashAccount->kind !== $method) {
            throw new SalesRuleException(
                "'{$cashAccount->name}' is a {$cashAccount->kind} account, not {$method}.",
                'TREASURY_KIND_MISMATCH',
            );
        }

        SalesAccounts::treasuryFor($cashAccount);

        return $cashAccount;
    }

    /**
     * Check and fill sale lines the way checkout stores them: a bundle line
     * carries the bundle's name, an item line the item's. Quotations use the
     * same rules so a converted quotation never fails on its own lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function normalizeLines(array $lines, bool $isInternal = false): array
    {
        if ($lines === []) {
            throw new SalesRuleException('Add at least one item or bundle to sell.', 'EMPTY_SALE');
        }

        // validate() rebuilds wildcard arrays rule by rule, so keys can come
        // back out of order (a key only some lines carry puts those first).
        // The index is the cart order.
        ksort($lines);

        return array_map(function (array $line, int $position) use ($isInternal): array {
            $type = $line['line_type'] ?? (! empty($line['bundle_id']) && empty($line['inventory_item_id']) ? SalesOrderLine::TYPE_BUNDLE : SalesOrderLine::TYPE_ITEM);
            $quantity = (float) ($line['quantity'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discountAmount = round((float) ($line['discount_amount'] ?? 0), 4);

            if ($quantity <= 0 || $unitPrice < 0) {
                throw new SalesRuleException('Every line needs a quantity above zero and a price of zero or more.', 'INVALID_LINE');
            }

            if ($discountAmount < 0) {
                throw new SalesRuleException('Line discount cannot be negative.', 'DISCOUNT_INVALID', ['position' => $position]);
            }

            if ($isInternal && $discountAmount > 0) {
                throw new SalesRuleException('Internal transfers are priced at cost and cannot carry discounts.', 'DISCOUNT_INVALID', ['position' => $position]);
            }

            $lineGross = round($quantity * $unitPrice, 4);
            if ($discountAmount > $lineGross + 0.0001) {
                throw new SalesRuleException(
                    "Line discount {$discountAmount} exceeds line gross {$lineGross}.",
                    'DISCOUNT_INVALID',
                    ['position' => $position],
                );
            }

            if ($type === SalesOrderLine::TYPE_BUNDLE) {
                if ($isInternal) {
                    throw new SalesRuleException('Internal transfers carry inventory items only, not bundles.', 'INTERNAL_BUNDLE_NOT_ALLOWED');
                }

                $bundle = ! empty($line['bundle_id']) ? Bundle::find($line['bundle_id']) : null;

                if ($bundle === null) {
                    throw new SalesRuleException('The bundle does not exist or is not sold in this unit.', 'BUNDLE_NOT_FOUND');
                }

                return [
                    'position' => $position,
                    'line_type' => SalesOrderLine::TYPE_BUNDLE,
                    'bundle_id' => $bundle->id,
                    'description' => $line['description'] ?? $bundle->name,
                    'inventory_item_id' => null,
                    'stock_lot_id' => null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discountAmount,
                ];
            }

            $item = ! empty($line['inventory_item_id']) ? InventoryItem::find($line['inventory_item_id']) : null;

            if ($item === null) {
                throw new SalesRuleException('Every item line needs an existing inventory item.', 'ITEM_NOT_FOUND');
            }

            return [
                'position' => $position,
                'line_type' => SalesOrderLine::TYPE_ITEM,
                'inventory_item_id' => $item->id,
                'stock_lot_id' => $line['stock_lot_id'] ?? null,
                'bundle_id' => null,
                'description' => $line['description'] ?? $item->name,
                'quantity' => $quantity,
                'length_m' => $line['length_m'] ?? null,
                'width_m' => $line['width_m'] ?? null,
                'height_m' => $line['height_m'] ?? null,
                'unit_price' => $unitPrice,
                'discount_amount' => $discountAmount,
            ];
        }, array_values($lines), array_keys(array_values($lines)));
    }

    private function lockPendingApproval(CreditApprovalRequest $approval): CreditApprovalRequest
    {
        $locked = CreditApprovalRequest::whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending') {
            throw new InvalidStateTransitionException('This credit request has already been decided.');
        }

        return $locked;
    }

    private function findByRequestId(string $requestId): ?SalesOrder
    {
        $order = SalesOrder::where('client_request_id', $requestId)->first();

        return $order !== null ? $this->reload($order) : null;
    }

    private function reload(SalesOrder $order): SalesOrder
    {
        return $order->fresh([
            'lines.inventoryItem',
            'lines.stockLot',
            'lines.bundle',
            'client.entity',
            'buyerUnit',
            'cashAccount',
            'creditApprovalRequest',
            'payments.cashAccount',
            'payments.cheque',
            'payments.receivedBy:id,name',
        ]);
    }
}
