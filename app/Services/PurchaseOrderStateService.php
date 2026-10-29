<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Account;
use App\Models\BankHold;
use App\Models\CashAccount;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\LandedCostLine;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\InventoryAccounts;
use App\Support\LedgerAccounts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PurchaseOrderStateService
{
    /**
     * Defensive defaults — config('fine.fx.tolerance_lyd') wins when present.
     * Tolerance band for the LYD-grounded variance between actual settled and
     * booked expectation. Deviations within this band do not require an
     * `extra_allocation_note`.
     */
    public const FX_TOLERANCE_LYD = 0.01;

    /**
     * Defensive default — config('fine.fx.hard_cap_percent') wins when present.
     * Hard cap, expressed as a percent of settled, above which the variance
     * surface turns red and a confirm chip is required before submit. Beyond
     * the tolerance but within the hard cap, the variance is amber and the
     * note alone suffices.
     */
    public const FX_HARD_CAP_PERCENT = 5.0;

    public static function fxToleranceLyd(): float
    {
        return (float) config('fine.fx.tolerance_lyd', self::FX_TOLERANCE_LYD);
    }

    public static function fxHardCapPercent(): float
    {
        return (float) config('fine.fx.hard_cap_percent', self::FX_HARD_CAP_PERCENT);
    }

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly StockLotService $stockLotService,
    ) {}

    /**
     * Single source of truth for the FX values that actually move through
     * the books. Given the two optional inputs (`fxRateUsed` and
     * `exactAmountUsedLyd`), returns both derived values consistently:
     *
     *   - If LYD was supplied, the rate is derived (LYD is the truth — what
     *     the bank statement shows).
     *   - If only the rate was supplied, settled is derived.
     *   - If both are supplied, settled wins and the rate is reconciled to
     *     it (the bank's number, not the operator's typing).
     *
     * This is what `payment_requests.fx_rate_used` is persisted as from
     * Phase 1 onward — the effective realized rate, not the user-entered one.
     *
     * @return array{effective_settled: float, effective_rate: float}
     */
    public function deriveEffectiveValues(
        PaymentRequest $paymentRequest,
        ?float $fxRateUsed,
        ?float $exactAmountUsedLyd,
    ): array {
        $amount = (float) $paymentRequest->amount_requested;

        if ($exactAmountUsedLyd !== null && $exactAmountUsedLyd > 0) {
            $settled = round($exactAmountUsedLyd, 4);
            $rate = $amount > 0 ? round($settled / $amount, 6) : (float) ($fxRateUsed ?? 0);

            return ['effective_settled' => $settled, 'effective_rate' => $rate];
        }

        if ($fxRateUsed !== null && $fxRateUsed > 0) {
            $rate = (float) $fxRateUsed;
            $settled = round($amount * $rate, 4);

            return ['effective_settled' => $settled, 'effective_rate' => $rate];
        }

        throw new InvalidArgumentException('Either fx_rate_used or exact_amount_used_lyd is required.');
    }

    /**
     * Variance between actual settled LYD and what the booked FX snapshot
     * predicted. Returns null when there is no booked snapshot to compare
     * against (the completion journal then treats booked = settled).
     */
    public function varianceVsBooked(
        PurchaseOrder $order,
        float $effectiveSettled,
        string $functionalCurrency,
    ): ?float {
        $bookedRate = $this->bookedFxRate($order, $functionalCurrency);
        if ($bookedRate === null) {
            return null;
        }

        $expected = round((float) $order->totalCost() * $bookedRate, 4);

        return round($effectiveSettled - $expected, 4);
    }

    /**
     * Wave 5 (local flow): approve a draft purchase order.
     *
     * Local PO state machine:
     *   draft → approved → (received) → paid → closed
     *
     * Approval is the manager's sign-off that the purchase is legitimate.
     * It does NOT post any journal — the journal happens at the receive
     * event when goods arrive.
     */
    public function approve(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->kind !== PurchaseOrderKind::Local) {
            throw new InvalidArgumentException(
                'approve() is only valid for local purchase orders. Foreign orders use selectPaymentRoute().'
            );
        }

        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new InvalidStateTransitionException(
                    'Order must be in draft status to be approved.'
                );
            }

            $order->status = PurchaseOrderStatus::Approved;
            $order->save();

            return $order->refresh();
        });
    }

    public function transitionToPendingPayment(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new InvalidStateTransitionException('Order must be in draft status to transition to pending payment.');
            }

            $amountRequested = $order->totalCost();

            PaymentRequest::create([
                'operating_unit_id' => $order->operating_unit_id,
                'purchase_order_id' => $order->id,
                'route' => PaymentRoute::Bank,
                'amount_requested' => $amountRequested,
                'status' => PaymentRequestStatus::Pending,
            ]);

            $order->update([
                'status' => PurchaseOrderStatus::PendingPayment,
            ]);

            return $order->refresh();
        });
    }

    public function selectPaymentRoute(
        PurchaseOrder $order,
        PaymentRoute $route,
        float $amountRequested,
        ?float $heldAmountLyd = null,
        ?string $invoiceRef = null
    ): PurchaseOrder {
        return DB::transaction(function () use ($order, $route, $amountRequested, $heldAmountLyd, $invoiceRef) {
            if ($order->status !== PurchaseOrderStatus::PendingPayment) {
                throw new InvalidStateTransitionException('Order must be in pending_payment status to select payment route.');
            }

            $paymentRequest = $order->paymentRequests()->where('status', PaymentRequestStatus::Pending)->first();

            if (! $paymentRequest) {
                $paymentRequest = PaymentRequest::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'purchase_order_id' => $order->id,
                    'route' => $route,
                    'amount_requested' => $amountRequested,
                    'status' => PaymentRequestStatus::Pending,
                ]);
            } else {
                // FX-09: once a hold exists on this request, the route is
                // locked. Switching from Bank to Market would leave the
                // BankHold row stranded while the request is relabeled, and
                // executePayment would still apply the bank-branch math
                // because the guard reads `route === Bank && bankHold`.
                if ($paymentRequest->bankHold && $paymentRequest->route !== $route) {
                    throw new InvalidArgumentException(
                        'Cannot change payment route after a bank hold is established.',
                    );
                }

                $paymentRequest->update([
                    'route' => $route,
                    'invoice_ref' => $invoiceRef,
                    'amount_requested' => $amountRequested,
                ]);
            }

            if ($route === PaymentRoute::Bank) {
                if ($heldAmountLyd === null || $heldAmountLyd <= 0) {
                    throw new InvalidArgumentException('Held amount in LYD is required for bank payment route.');
                }

                // FX-01: updateOrCreate keyed on the request id so a repeated
                // select_route call overwrites the held amount rather than
                // producing a second orphan row.
                BankHold::updateOrCreate(
                    ['payment_request_id' => $paymentRequest->id],
                    [
                        'held_amount_lyd' => $heldAmountLyd,
                        'exact_amount_used' => 0,
                        'released_amount' => 0,
                    ],
                );

                $order->update(['status' => PurchaseOrderStatus::AwaitingBankApproval]);
            } else {
                $order->update(['status' => PurchaseOrderStatus::AwaitingTransfer]);
            }

            return $order->refresh();
        });
    }

    public function executePayment(
        PaymentRequest $paymentRequest,
        ?float $fxRateUsed = null,
        ?float $exactAmountUsedLyd = null,
        ?string $bankReference = null,
        ?string $extraAllocationNote = null,
        ?string $paymentSourceAccountId = null,
    ): PaymentRequest {
        return DB::transaction(function () use ($paymentRequest, $fxRateUsed, $exactAmountUsedLyd, $bankReference, $extraAllocationNote, $paymentSourceAccountId) {
            if ($paymentRequest->status !== PaymentRequestStatus::Pending) {
                throw new InvalidStateTransitionException('Payment request is not pending.');
            }

            // FX-02 / FX-24 / FX-15: derive the single source of truth. The
            // persisted `fx_rate_used` is the *effective* realized rate —
            // derived from the LYD amount when LYD is supplied, from the
            // operator-entered rate otherwise. Both inputs are now accepted
            // on Bank and Market routes; the LYD amount is what actually
            // moves the books.
            ['effective_settled' => $settled, 'effective_rate' => $effectiveRate] =
                $this->deriveEffectiveValues($paymentRequest, $fxRateUsed, $exactAmountUsedLyd);

            $paymentRequest->update([
                'fx_rate_used' => $effectiveRate,
                'extra_allocation_note' => $extraAllocationNote,
                'status' => PaymentRequestStatus::Paid,
            ]);

            if ($paymentRequest->route === PaymentRoute::Bank && $paymentRequest->bankHold) {
                $held = (float) $paymentRequest->bankHold->held_amount_lyd;
                $released = max(0, $held - $settled);

                $paymentRequest->bankHold->update([
                    'exact_amount_used' => $settled,
                    'released_amount' => $released,
                    'bank_reference' => $bankReference,
                ]);
            }

            $order = $paymentRequest->purchaseOrder;

            // Money actually left the company: cash out, advance on the
            // supplier until the goods complete (the completion journal
            // clears 1500 with the same settled amount, so it nets exactly).
            $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';

            $supplierAccountCode = '15';
            if ($order?->supplier_id !== null) {
                $supplier = Supplier::with('account')->find($order->supplier_id);
                if ($supplier?->account?->account_code) {
                    $supplierAccountCode = $supplier->account->account_code;
                }
            }

            // The treasury or bank leaf the money leaves from. There is no
            // fallback: posting to the 12 header would hide the payment from
            // every cash report.
            $creditAccountCode = null;
            if (empty($paymentSourceAccountId) && $settled > 0) {
                throw new InvalidArgumentException('Choose the treasury or bank account the payment leaves from.');
            }
            if (! empty($paymentSourceAccountId)) {
                $linked = Account::query()->whereKey($paymentSourceAccountId)->first();
                if ($linked === null) {
                    throw new InvalidArgumentException('Linked payment source account does not exist.');
                }
                $creditAccountCode = (string) $linked->account_code;

                // Persist on the order for audit / replay.
                $order->payment_source_account_id = $linked->id;
                $order->save();
            }

            $supplierLine = [
                'account_code' => $supplierAccountCode, // Advances to Suppliers (or linked supplier account)
                'debit' => $settled,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => $order->supplier?->name,
            ];

            $creditLine = [
                'account_code' => $creditAccountCode,
                'credit' => $settled,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => $paymentRequest->route->value.' route',
            ];

            if ($order->currency !== $functionalCurrency) {
                $supplierLine['currency'] = $order->currency;
                $supplierLine['original_amount'] = (float) $order->totalCost();

                $sourceAccountId = $paymentSourceAccountId ?: $order->payment_source_account_id;
                if (! empty($sourceAccountId)) {
                    $cashAccountCurrency = CashAccount::query()
                        ->where('account_id', $sourceAccountId)
                        ->value('currency');

                    if ($cashAccountCurrency !== null && strtoupper($cashAccountCurrency) === strtoupper($order->currency)) {
                        $creditLine['currency'] = $order->currency;
                        $creditLine['original_amount'] = (float) $order->totalCost();
                    }
                }
            }

            if ($settled > 0) {
                $this->accountingService->postJournal(
                    "Import payment executed — {$order->supplier?->name}",
                    [$supplierLine, $creditLine],
                    'PaymentRequest',
                    $paymentRequest->id,
                    $order->operatingUnit?->company_id,
                );
            }

            $order->update(['status' => PurchaseOrderStatus::Paid]);

            return $paymentRequest->refresh();
        });
    }

    /**
     * What actually left the bank in functional currency: the bank's exact
     * amount when a hold was settled, otherwise the contract value at the
     * executed rate. Both the payment and the completion journals use this
     * one number, so the advance always clears to zero.
     */
    private function settledLyd(PurchaseOrder $order, string $functionalCurrency): float
    {
        $supplierCostFc = $order->totalCost();

        if ($order->currency === $functionalCurrency) {
            return $supplierCostFc;
        }

        $paid = $order->paymentRequests()
            ->where('status', PaymentRequestStatus::Paid)
            ->whereNotNull('fx_rate_used')
            ->latest('updated_at')
            ->first();

        if ($paid === null) {
            throw new InvalidArgumentException(
                'Order has no executed payment with an FX rate; cannot value the purchase for posting.'
            );
        }

        $exactUsed = (float) ($paid->bankHold?->exact_amount_used ?? 0);

        if ($exactUsed > 0) {
            return round($exactUsed, 4);
        }

        return round($supplierCostFc * (float) $paid->fx_rate_used, 4);
    }

    public function confirmShipment(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::Paid) {
                throw new InvalidStateTransitionException('Order must be paid before confirming shipment.');
            }

            $order->update(['status' => PurchaseOrderStatus::InTransit]);

            return $order->refresh();
        });
    }

    public function arriveAtPort(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::InTransit) {
                throw new InvalidStateTransitionException('Order must be in_transit before arriving at port.');
            }

            $order->update(['status' => PurchaseOrderStatus::AtPort]);

            return $order->refresh();
        });
    }

    public function transportToWarehouse(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::AtPort) {
                throw new InvalidStateTransitionException('Order must be at_port before transporting to warehouse.');
            }

            $order->update(['status' => PurchaseOrderStatus::InTransitToWarehouse]);

            return $order->refresh();
        });
    }

    /**
     * The truck has arrived at the destination warehouse but the goods have
     * not yet been counted / inspected. Records which warehouse they're at
     * so the operator doesn't have to re-pick on the receive step.
     */
    public function arriveAtWarehouse(PurchaseOrder $order, string $warehouseId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $warehouseId) {
            if ($order->status !== PurchaseOrderStatus::InTransitToWarehouse) {
                throw new InvalidStateTransitionException('Order must be in_transit_to_warehouse before arriving at the warehouse.');
            }

            $order->update([
                'status' => PurchaseOrderStatus::AtWarehouse,
                'arrived_warehouse_id' => $warehouseId,
            ]);

            return $order->refresh();
        });
    }

    public function receiveGoods(
        PurchaseOrder $order,
        string $warehouseId,
        float $receivedQty,
        ?string $notes = null
    ): GoodsReceipt {
        return DB::transaction(function () use ($order, $warehouseId, $receivedQty, $notes) {
            // Accept the modern at_warehouse state, plus the legacy
            // awaiting_receipt state for orders that predate the
            // arrived_at_warehouse split.
            if (! in_array($order->status, [
                PurchaseOrderStatus::AtWarehouse,
                PurchaseOrderStatus::AwaitingReceipt,
            ], true)) {
                throw new InvalidStateTransitionException('Order must be at_warehouse (or awaiting_receipt) before receiving goods.');
            }

            if ($receivedQty > (float) $order->quantity) {
                throw new InvalidArgumentException('Received quantity cannot exceed ordered quantity.');
            }

            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'received_qty' => $receivedQty,
                'condition_notes' => $notes,
            ]);

            $order->update(['status' => PurchaseOrderStatus::Received]);

            return $receipt;
        });
    }

    /**
     * Wave 5 (local flow): atomic per-line batch receive.
     *
     * Atomically updates each line's received_quantity on the supplied
     * items, transitions the order to `received` when every item is
     * fully received, and posts the inventory journal entry. The whole
     * operation runs inside one DB transaction so a failure on any item
     * rolls back the entire receive — partial receipts are committed
     * only when ALL items validate and update cleanly.
     *
     * @param  array<int, array{id: string, received_quantity: float|int|string}>  $lines
     */
    public function receiveItems(PurchaseOrder $order, array $lines): PurchaseOrder
    {
        if ($order->kind !== PurchaseOrderKind::Local) {
            throw new InvalidArgumentException(
                'Per-line batch receive is only supported for local purchase orders.'
            );
        }

        if ($order->status !== PurchaseOrderStatus::Approved) {
            throw new InvalidStateTransitionException(
                'Order must be in approved status before receiving goods.'
            );
        }

        $itemsById = $order->items()->get()->keyBy('id');

        $normalised = [];
        foreach ($lines as $line) {
            $id = (string) ($line['id'] ?? '');
            $qty = (float) ($line['received_quantity'] ?? 0);
            if (! $itemsById->has($id)) {
                throw new InvalidArgumentException("Line item {$id} does not belong to this order.");
            }
            $item = $itemsById[$id];
            $already = (float) ($item->received_quantity ?? 0);
            if ($qty < $already) {
                throw new InvalidArgumentException(
                    "Line item {$id} received_quantity ({$qty}) is less than the previously-recorded {$already}."
                );
            }
            if ($qty > (float) $item->quantity) {
                throw new InvalidArgumentException(
                    "Line item {$id} received_quantity ({$qty}) exceeds ordered quantity ({$item->quantity})."
                );
            }
            $normalised[] = ['item' => $item, 'qty' => $qty];
        }

        return DB::transaction(function () use ($order, $normalised) {
            $fullyReceived = true;

            foreach ($normalised as $row) {
                /** @var PurchaseOrderItem $item */
                $item = $row['item'];
                $item->received_quantity = $row['qty'];
                $item->save();

                if ((float) $row['qty'] < (float) $item->quantity) {
                    $fullyReceived = false;
                }
            }

            if ($fullyReceived) {
                $order->status = PurchaseOrderStatus::Received;
                $order->save();
            }

            return $order->refresh();
        });
    }

    /**
     * Wave 5 (local flow): record a payment on a received local order.
     * Posts a DR supplier advance / CR chosen payment source journal,
     * persists the source account on the order, then transitions to `paid`;
     * auto-closes when fully received + paid. Routes the line items as
     * StockLots into the destination warehouse and posts an inventory DR /
     * supplier-advance CR journal to clear the advance and value the stock.
     */
    public function payLocal(PurchaseOrder $order, string $paymentSourceAccountId): PurchaseOrder
    {
        if ($order->kind !== PurchaseOrderKind::Local) {
            throw new InvalidArgumentException('payLocal is only for local purchase orders.');
        }

        if ($order->status !== PurchaseOrderStatus::Received) {
            throw new InvalidStateTransitionException(
                'Order must be received before it can be paid.'
            );
        }

        $source = Account::query()->whereKey($paymentSourceAccountId)->first();
        if ($source === null) {
            throw new InvalidArgumentException('Linked payment source account does not exist.');
        }

        // Resolve the supplier advance code: linked sub-account if present,
        // otherwise the universal '15' Advances to Suppliers.
        $supplierAccountCode = '15';
        if ($order->supplier_id !== null) {
            $supplier = Supplier::with('account')->find($order->supplier_id);
            if ($supplier?->account?->account_code) {
                $supplierAccountCode = $supplier->account->account_code;
            }
        }

        $totalLyd = (float) $order->totalCost();

        return DB::transaction(function () use ($order, $source, $supplierAccountCode, $totalLyd) {
            if ($totalLyd > 0) {
                $this->accountingService->postJournal(
                    "Local PO paid — {$order->supplier?->name}",
                    [
                        [
                            'account_code' => $supplierAccountCode,
                            'debit' => $totalLyd,
                            'operating_unit_id' => $order->operating_unit_id,
                            'memo' => $order->supplier?->name,
                        ],
                        [
                            'account_code' => (string) $source->account_code,
                            'credit' => $totalLyd,
                            'operating_unit_id' => $order->operating_unit_id,
                            'memo' => $source->name,
                        ],
                    ],
                    'PurchaseOrder',
                    $order->id,
                    $order->operatingUnit?->company_id,
                );
            }

            // Materialise the line items into the destination warehouse.
            // Also posts an inventory DR / supplier-advance CR journal so
            // the books carry the inventory value AND the supplier advance
            // nets back to zero. Inventory DR uses the order's operating
            // unit `purchases` account mapping.
            $this->routeGoodsIntoWarehouse($order);

            $order->payment_source_account_id = $source->id;
            $order->status = PurchaseOrderStatus::Paid;
            $order->save();

            // Auto-close: every line item received AND paid → archive.
            if ($order->isFullyReceived()) {
                $order->status = PurchaseOrderStatus::Closed;
                $order->save();
            }

            return $order->refresh();
        });
    }

    public function completeOrder(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== PurchaseOrderStatus::Received) {
                throw new InvalidStateTransitionException('Order must be in received status to complete.');
            }

            if (! $order->goodsReceipt) {
                throw new InvalidArgumentException('Goods receipt must exist to complete order.');
            }

            $unconfirmedLines = $order->landedCostLines()->where('is_confirmed', false)->count();
            if ($unconfirmedLines > 0) {
                throw new InvalidArgumentException('All landed cost lines must be confirmed before completing order.');
            }

            // ACC-02/ACC-10: the purchase enters the ledger here, on Complete,
            // or not at all. Posting before the status flip keeps the guard
            // fatal — a completion that cannot post is refused, not skipped.
            $this->postCompletionJournal($order);

            // Materialise the line items into the destination warehouse.
            // `postCompletionJournal()` already posted the inventory DR on
            // each item type's account (INV-11).
            $this->routeGoodsIntoWarehouse($order);

            $order->update(['status' => PurchaseOrderStatus::Complete]);

            return $order->refresh();
        });
    }

    /**
     * Phase 08 §8.4, PurchaseOrder.Complete — the purchase reaches the ledger.
     *
     *   DR  inventory (INV-11: item type's account, 111 for raw materials)
     *                                     (supplier cost at booked rate + landed costs)
     *   DR  5300 FX Loss                  (realized > booked)
     *   CR  2100 Accounts Payable         (supplier cost at realized rate)
     *   CR  4200 FX Gain                  (realized < booked)
     *   CR  2300 Landed Cost Clearing     (one line per confirmed cost component)
     *
     * Inventory carries the transaction-date (booked) value of the goods plus
     * every confirmed landed cost; the credit clears the 1500 advance that
     * executePayment posted, with exactly the settled amount, so the advance
     * always nets to zero; the difference between booked and settled — rate
     * movement and bank spread alike — is the FX gain/loss (phase-02 §2.8,
     * ACC-09). Landed cost lines of type `supplier_price` are skipped: the
     * order itself is the supplier-price component, and counting a mirror
     * line again would double the inventory value.
     */
    private function postCompletionJournal(PurchaseOrder $order): void
    {
        $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';

        $supplierCostFc = $order->totalCost();
        $realizedRate = $this->realizedFxRate($order, $functionalCurrency);
        $settled = $this->settledLyd($order, $functionalCurrency);
        $bookedRate = $this->bookedFxRate($order, $functionalCurrency);

        $bookedCost = $bookedRate !== null ? round($supplierCostFc * $bookedRate, 4) : $settled;
        $fxDifference = round($settled - $bookedCost, 4);

        $landedCostLines = $order->landedCostLines()
            ->where('is_confirmed', true)
            ->where('type', '!=', LandedCostType::SupplierPrice)
            ->get();

        $landedCostTotal = 0.0;
        $clearingLines = [];

        foreach ($landedCostLines as $line) {
            $amount = $this->landedCostInFunctionalCurrency($line, $order, $functionalCurrency, $realizedRate);

            if ($amount <= 0.0) {
                continue;
            }

            $landedCostTotal = round($landedCostTotal + $amount, 4);
            $clearingLine = [
                'account_code' => '23', // Landed Cost Clearing
                'credit' => $amount,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => $line->type->value,
            ];

            if ($line->currency !== null && $line->currency !== '' && strtoupper($line->currency) !== strtoupper($functionalCurrency)) {
                $clearingLine['currency'] = $line->currency;
                $clearingLine['original_amount'] = (float) $line->amount;
            }

            $clearingLines[] = $clearingLine;
        }

        $receivedQty = (float) $order->goodsReceipt->received_qty;
        $inventoryValue = round($bookedCost + $landedCostTotal, 4);
        $perUnit = $receivedQty > 0.0 ? round($inventoryValue / $receivedQty, 4) : 0.0;

        $lines = [];
        foreach ($this->inventoryValueByAccount($order, $inventoryValue) as $accountCode => $amount) {
            $lines[] = [
                'account_code' => (string) $accountCode,
                'debit' => $amount,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "{$receivedQty} received at {$perUnit}/unit landed",
            ];
        }

        if ($fxDifference > 0) {
            $lines[] = [
                'account_code' => LedgerAccounts::FX_LOSS, // FX Loss
                'debit' => $fxDifference,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "booked {$bookedCost}, settled {$settled}",
            ];
        }

        $supplierAccountCode = '15';
        if ($order->supplier_id !== null) {
            $supplier = Supplier::with('account')->find($order->supplier_id);
            if ($supplier?->account?->account_code) {
                $supplierAccountCode = $supplier->account->account_code;
            }
        }

        $supplierLine = [
            'account_code' => $supplierAccountCode, // Advances to Suppliers (or linked supplier account) — cleared
            'credit' => $settled,
            'operating_unit_id' => $order->operating_unit_id,
            'memo' => $order->supplier?->name,
        ];

        if ($order->currency !== $functionalCurrency) {
            $supplierLine['currency'] = $order->currency;
            $supplierLine['original_amount'] = $supplierCostFc;
        }

        $lines[] = $supplierLine;

        if ($fxDifference < 0) {
            $lines[] = [
                'account_code' => LedgerAccounts::FX_GAIN, // FX Gain
                'credit' => -$fxDifference,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "booked {$bookedCost}, settled {$settled}",
            ];
        }

        $this->accountingService->postJournal(
            "Import order from {$order->supplier?->name} completed",
            array_merge($lines, $clearingLines),
            'PurchaseOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    /**
     * INV-11: split the landed inventory value over the item-type accounts
     * the lots will sit in. The foreign flow stocks every unit at the same
     * landed value, so each account's share follows the quantity of its
     * items; the last account absorbs the rounding so the split sums back
     * to the value exactly. An order of raw materials only gets one 111 line.
     *
     * @return array<string, float>
     */
    private function inventoryValueByAccount(PurchaseOrder $order, float $inventoryValue): array
    {
        $quantityByAccount = [];

        foreach ($order->items()->get() as $item) {
            $account = InventoryAccounts::forItemId($item->inventory_item_id);
            $quantityByAccount[$account] = ($quantityByAccount[$account] ?? 0.0) + (float) $item->quantity;
        }

        $totalQuantity = array_sum($quantityByAccount);

        if ($quantityByAccount === [] || $totalQuantity <= 0.0) {
            return ['111' => $inventoryValue];
        }

        $split = [];
        $allocated = 0.0;
        $accounts = array_keys($quantityByAccount);
        $last = end($accounts);

        foreach ($quantityByAccount as $account => $quantity) {
            $amount = $account === $last
                ? round($inventoryValue - $allocated, 4)
                : round($inventoryValue * ($quantity / $totalQuantity), 4);
            $allocated = round($allocated + $amount, 4);

            if ($amount > 0) {
                $split[(string) $account] = $amount;
            }
        }

        return $split;
    }

    /**
     * The rate treasury actually settled at. An order cannot reach Received
     * without an executed payment, so a missing rate means the books cannot
     * be made whole — refuse rather than guess.
     */
    private function realizedFxRate(PurchaseOrder $order, string $functionalCurrency): float
    {
        if ($order->currency === $functionalCurrency) {
            return 1.0;
        }

        $rate = $order->paymentRequests()
            ->where('status', PaymentRequestStatus::Paid)
            ->whereNotNull('fx_rate_used')
            ->latest('updated_at')
            ->value('fx_rate_used');

        if ($rate === null) {
            throw new InvalidArgumentException(
                'Order has no executed payment with an FX rate; cannot value the purchase for posting.'
            );
        }

        return (float) $rate;
    }

    /**
     * The booked estimate: the snapshot taken at order creation, falling back
     * to the rate history for orders that predate the snapshot column. Null
     * means no estimate ever existed — the caller treats booked = realized.
     */
    private function bookedFxRate(PurchaseOrder $order, string $functionalCurrency): ?float
    {
        if ($order->currency === $functionalCurrency) {
            return 1.0;
        }

        if ($order->booked_fx_rate !== null) {
            return (float) $order->booked_fx_rate;
        }

        $historical = FxRate::query()
            ->where('from_currency', $order->currency)
            ->where('to_currency', $functionalCurrency)
            ->where('captured_at', '<=', $order->created_at)
            ->latest('captured_at')
            ->value('rate');

        return $historical !== null ? (float) $historical : null;
    }

    private function landedCostInFunctionalCurrency(
        LandedCostLine $line,
        PurchaseOrder $order,
        string $functionalCurrency,
        float $realizedRate,
    ): float {
        $amount = round((float) $line->amount, 4);

        if ($line->currency === $functionalCurrency) {
            return $amount;
        }

        if ($line->currency === $order->currency) {
            return round($amount * $realizedRate, 4);
        }

        throw new InvalidArgumentException(
            "Landed cost line in {$line->currency} cannot be converted to {$functionalCurrency}; no rate is available."
        );
    }

    /**
     * Materialise every line item as a StockLot in the destination warehouse.
     * Called by `completeOrder` (foreign) and `payLocal` (local) at the final
     * accounting step, inside the same transaction that posts the inventory
     * journal. Each line's value is debited to its item type's account
     * (INV-11, InventoryAccounts).
     *
     * For local flow: one StockLot per line item with `received_quantity > 0`,
     * `unit_cost = line.unit_price`. Partial receipts only stock the
     * received qty.
     *
     * For foreign flow: one StockLot per line item, qty = ordered quantity
     * (the goods receipt is a single-row summary, not per-item; we assume
     * all ordered items arrived — partial-import handling is out of scope
     * here), `unit_cost` = per-unit landed value.
     *
     * For local flow we also post an inventory DR / supplier-advance CR
     * journal that clears the 15 advance created by `payLocal`'s earlier
     * leg and values the stock. Foreign flow already posts the equivalent
     * journal in `postCompletionJournal`, so we skip it there.
     */
    private function routeGoodsIntoWarehouse(PurchaseOrder $order): void
    {
        $warehouseId = $order->resolveDestinationWarehouseId();
        if ($warehouseId === null) {
            throw new InvalidArgumentException(
                'Destination warehouse must be set before the order can be completed. Pick one on create or via the foreign arriveAtWarehouse step.',
            );
        }

        $warehouse = Warehouse::query()->whereKey($warehouseId)->first();
        if ($warehouse === null) {
            throw new InvalidArgumentException("Destination warehouse {$warehouseId} does not exist.");
        }

        if ((string) $warehouse->operating_unit_id !== (string) $order->operating_unit_id) {
            throw new InvalidArgumentException(
                "Destination warehouse {$warehouse->name} does not belong to the order's operating unit.",
            );
        }

        $items = $order->items()->with('inventoryItem')->get();
        if ($items->isEmpty()) {
            return;
        }

        if ($order->kind === PurchaseOrderKind::Local) {
            $perAccountDebits = [];
            $totalInventoryLyd = 0.0;

            foreach ($items as $item) {
                $received = (float) ($item->received_quantity ?? 0);
                if ($received <= 0) {
                    continue;
                }

                $lineTotal = round($received * (float) $item->unit_price, 4);
                $totalInventoryLyd = round($totalInventoryLyd + $lineTotal, 4);

                $inventoryItem = $item->inventoryItem;
                if ($inventoryItem === null) {
                    throw new InvalidArgumentException(
                        "Line item {$item->id} is missing its inventory item.",
                    );
                }

                // INV-11: the lot's value enters through its item type's account.
                $inventoryAccountCode = InventoryAccounts::forItem($inventoryItem);

                // Force string keys — PHP coerces numeric strings to ints
                // when used as array keys, which breaks the journal
                // resolver (account_code must be a string).
                $perAccountDebits[(string) $inventoryAccountCode] = round(
                    ((float) ($perAccountDebits[(string) $inventoryAccountCode] ?? 0)) + $lineTotal,
                    4,
                );

                $this->stockLotService->intake([
                    'inventory_item_id' => $item->inventory_item_id,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => $this->generatePoLotNumber($order, $item),
                    'quantity' => $received,
                    'unit_cost' => (float) $item->unit_price,
                    'source' => 'import_receipt',
                    'purchase_order_id' => $order->id,
                ]);
            }

            if ($totalInventoryLyd > 0 && ! empty($perAccountDebits)) {
                $supplierAccountCode = '15';
                if ($order->supplier_id !== null) {
                    $supplier = Supplier::with('account')->find($order->supplier_id);
                    if ($supplier?->account?->account_code) {
                        $supplierAccountCode = $supplier->account->account_code;
                    }
                }

                $lines = [];
                foreach ($perAccountDebits as $accountCode => $amount) {
                    $lines[] = [
                        'account_code' => (string) $accountCode,
                        'debit' => (float) $amount,
                        'operating_unit_id' => $order->operating_unit_id,
                        'memo' => 'Inventory value on local PO receipt',
                    ];
                }
                $lines[] = [
                    'account_code' => (string) $supplierAccountCode,
                    'credit' => (float) $totalInventoryLyd,
                    'operating_unit_id' => $order->operating_unit_id,
                    'memo' => 'Clear supplier advance on local PO receipt',
                ];

                $this->accountingService->postJournal(
                    "Local PO receipt — {$order->supplier?->name}",
                    $lines,
                    'PurchaseOrder',
                    $order->id,
                    $order->operatingUnit?->company_id,
                );
            }

            return;
        }

        // Foreign flow: per-unit landed value already posted by
        // postCompletionJournal(); here we just materialise the lots.
        $perUnit = $this->computeLandedValuePerUnit($order);
        foreach ($items as $item) {
            $this->stockLotService->intake([
                'inventory_item_id' => $item->inventory_item_id,
                'warehouse_id' => $warehouseId,
                'lot_number' => $this->generatePoLotNumber($order, $item),
                'quantity' => (float) $item->quantity,
                'unit_cost' => $perUnit,
                'source' => 'import_receipt',
                'purchase_order_id' => $order->id,
            ]);
        }
    }

    /**
     * Foreign flow per-unit landed value. Mirrors the computation in
     * `postCompletionJournal()` so the StockLots match the inventory DR
     * already posted.
     */
    private function computeLandedValuePerUnit(PurchaseOrder $order): float
    {
        $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';
        $supplierCostFc = $order->totalCost();
        $realizedRate = $this->realizedFxRate($order, $functionalCurrency);
        $settled = $this->settledLyd($order, $functionalCurrency);
        $bookedRate = $this->bookedFxRate($order, $functionalCurrency);

        $bookedCost = $bookedRate !== null ? round($supplierCostFc * $bookedRate, 4) : $settled;
        unset($realizedRate);

        $landedCostLines = $order->landedCostLines()
            ->where('is_confirmed', true)
            ->where('type', '!=', LandedCostType::SupplierPrice)
            ->get();

        $landedCostTotal = 0.0;
        foreach ($landedCostLines as $line) {
            $amount = $this->landedCostInFunctionalCurrency($line, $order, $functionalCurrency, $this->realizedFxRate($order, $functionalCurrency));
            if ($amount > 0.0) {
                $landedCostTotal = round($landedCostTotal + $amount, 4);
            }
        }

        $totalInventoryValue = round($bookedCost + $landedCostTotal, 4);
        $receivedQty = (float) ($order->goodsReceipt?->received_qty ?? 0);

        return $receivedQty > 0.0 ? round($totalInventoryValue / $receivedQty, 4) : 0.0;
    }

    private function generatePoLotNumber(PurchaseOrder $order, PurchaseOrderItem $item): string
    {
        return 'PO-'.substr((string) $order->id, 0, 8).'-'.substr((string) $item->id, 0, 8);
    }
}
