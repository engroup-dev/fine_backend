<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryEventType;
use App\Exceptions\InventoryAccountNotLinkedException;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockLot;
use App\Models\User;
use App\Support\CompanyClock;
use App\Support\InventoryAccounts;
use App\Support\ReceivableAccounts;
use App\Support\SalesAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales returns (FR-INV-048, FR-INV-049, BR-INV-013, FR-IVT-032; owner
 * decision #8): a return is its own document raised against the original
 * sale, for some or all of its item lines.
 *
 * - BR-INV-013: a line can never be returned beyond what was sold, counting
 *   every earlier return of it.
 * - Restocked goods go back into the very lots the sale drew from, at those
 *   lots' cost (INV-06: every unit back in stock has its movement row).
 *   Damaged goods move no stock; their cost leaves COGS for waste.
 * - The customer gets cash/bank back (a negative SalePayment, so the register
 *   day nets it) or credit on their balance (FR-INV-049).
 * - One balanced journal: DR the unit's sales returns leaf (404xx) against
 *   the treasury or the client's receivable; cost reverses out of 51.
 */
final class SalesReturnService
{
    private const REFUND_METHODS = ['cash', 'bank', 'credit'];

    private const CONDITIONS = ['restock', 'damaged'];

    /** Reason StockIssueService::issue writes for a FIFO item line at checkout. */
    private const FIFO_ISSUE_REASON = 'sale_issue';

    /** Reason it writes for a line drawn from its own chosen lot (`'sale_issue'.'_specific_lot'`). */
    private const LOT_ISSUE_REASON = 'sale_issue_specific_lot';

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly PosDayGuard $posDayGuard,
        private readonly TreasuryBalanceService $treasuryBalances,
        private readonly SaleCheckoutService $checkoutService,
        private readonly PosShiftService $posShifts,
    ) {}

    /**
     * What each line of a sale can still be returned (BR-INV-013).
     *
     * @return list<array{
     *     sales_order_line_id: string,
     *     line_type: string,
     *     description: string|null,
     *     inventory_item_id: string|null,
     *     item_code: string|null,
     *     sold_qty: float,
     *     returned_qty: float,
     *     remaining_qty: float,
     *     net_unit_amount: float,
     *     returnable: bool,
     * }>
     */
    public function returnable(SalesOrder $order): array
    {
        $lines = $order->lines()->with('inventoryItem')->get();
        $returned = $this->returnedByLine($lines->pluck('id')->all());
        $netUnits = $this->netUnitAmounts($order, $lines);

        return $lines->map(function (SalesOrderLine $line) use ($returned, $netUnits): array {
            $sold = round((float) $line->quantity, 4);
            $returnedQty = round((float) ($returned[$line->id]['quantity'] ?? 0), 4);

            return [
                'sales_order_line_id' => $line->id,
                'line_type' => $line->line_type,
                'description' => $line->description ?? $line->inventoryItem?->name,
                'inventory_item_id' => $line->inventory_item_id,
                'item_code' => $line->isBundle() ? null : $line->inventoryItem?->code,
                'sold_qty' => $sold,
                'returned_qty' => $returnedQty,
                'remaining_qty' => max(0.0, round($sold - $returnedQty, 4)),
                'net_unit_amount' => round($netUnits[$line->id] ?? 0.0, 4),
                'returnable' => ! $line->isBundle() && $line->inventory_item_id !== null,
            ];
        })->values()->all();
    }

    /**
     * Record a return against a sale: stock back, money or credit back, one
     * journal — all or nothing.
     *
     * @param  array{
     *     lines?: array<int, array{sales_order_line_id?: string, quantity?: float|int|string, condition?: string}>,
     *     refund_method?: string,
     *     cash_account_id?: string|null,
     *     reason?: string|null,
     * }  $data
     *
     * @throws SalesRuleException
     */
    public function create(SalesOrder $order, array $data, User $user): SalesReturn
    {
        $requested = array_values($data['lines'] ?? []);

        if ($requested === []) {
            throw new SalesRuleException('Choose at least one line to return.', 'RETURN_EMPTY');
        }

        $refundMethod = (string) ($data['refund_method'] ?? '');

        if (! in_array($refundMethod, self::REFUND_METHODS, true)) {
            throw new SalesRuleException('Choose how the customer is refunded: cash, bank or credit.', 'RETURN_REFUND_METHOD_INVALID');
        }

        $unit = $order->operatingUnit()->with('revenueAccount')->firstOrFail();

        $this->posDayGuard->ensureOpen($unit);

        $cashAccount = $refundMethod === 'credit'
            ? null
            : $this->resolveTreasury($data['cash_account_id'] ?? null, $refundMethod);

        // FR-SLREP-008: with shifts on, a cash refund leaves only a drawer with an open shift.
        if ($refundMethod === 'cash') {
            $this->posShifts->ensureShiftFor($unit, $cashAccount);
        }

        return DB::transaction(function () use ($order, $unit, $requested, $refundMethod, $cashAccount, $data, $user): SalesReturn {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isInternal() || ! $locked->status->isSold()) {
                throw new SalesRuleException(
                    "Sale {$locked->order_number} is not a completed customer sale and cannot be returned.",
                    'RETURN_SALE_NOT_COMPLETED',
                );
            }

            if ($refundMethod === 'credit' && $locked->client_id === null) {
                throw new SalesRuleException(
                    'Only a registered client can be refunded as credit on their balance.',
                    'RETURN_REFUND_METHOD_INVALID',
                );
            }

            $orderLines = $locked->lines()->with('inventoryItem')->get()->keyBy('id');
            $returned = $this->returnedByLine($orderLines->keys()->all());
            $netUnits = $this->netUnitAmounts($locked, $orderLines->values());

            $prepared = $this->prepareLines($requested, $orderLines, $returned, $netUnits);
            $amount = round(array_sum(array_column($prepared, 'amount')), 4);

            if ($cashAccount !== null) {
                $this->ensureRefundable($locked, $cashAccount, $amount);
            }

            $today = CompanyClock::today($unit);

            $salesReturn = SalesReturn::create([
                'operating_unit_id' => $locked->operating_unit_id,
                'sales_order_id' => $locked->id,
                'client_id' => $locked->client_id,
                'number' => $this->documentNumbers->next(DocumentNumberService::SALES_RETURN, (int) substr($today, 0, 4)),
                'return_date' => $today,
                'refund_method' => $refundMethod,
                'cash_account_id' => $cashAccount?->id,
                'amount' => $amount,
                'total_cost' => 0,
                'reason' => $data['reason'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            $restockCost = 0.0;
            $restockByAccount = [];
            $damagedCost = 0.0;
            $totalCost = 0.0;
            $firstItem = null;

            foreach ($prepared as $entry) {
                /** @var SalesOrderLine $line */
                $line = $entry['line'];
                $firstItem ??= $line->inventoryItem;

                if ($entry['condition'] === 'restock') {
                    $lineCost = $this->restock($locked, $line, $entry['quantity'], $salesReturn);
                    $unitCost = round($lineCost / $entry['quantity'], 4);
                } else {
                    $unitCost = round((float) $line->unit_cost_actual, 4);
                    $lineCost = round($unitCost * $entry['quantity'], 4);
                }

                $totalCost += $lineCost;

                if ($entry['condition'] === 'restock') {
                    $restockCost += $lineCost;
                    // INV-11: the goods go back into their lot's account —
                    // by id, so an item archived since the sale keeps its type.
                    $account = InventoryAccounts::forItemId($line->inventory_item_id);
                    $restockByAccount[$account] = round(($restockByAccount[$account] ?? 0) + $lineCost, 4);
                } else {
                    $damagedCost += $lineCost;
                }

                SalesReturnLine::create([
                    'sales_return_id' => $salesReturn->id,
                    'sales_order_line_id' => $line->id,
                    'quantity' => $entry['quantity'],
                    'amount' => $entry['amount'],
                    'condition' => $entry['condition'],
                    'unit_cost' => $unitCost,
                ]);
            }

            $client = $locked->client_id !== null
                ? Client::with(['account', 'entity'])->whereKey($locked->client_id)->lockForUpdate()->first()
                : null;

            // Nothing to post when a return moves no value (a fully discounted,
            // zero-cost line): ACC-01 has no lines to balance, so no entry.
            $entry = $amount <= 0 && $restockCost <= 0 && $damagedCost <= 0 ? null : $this->postJournal(
                $locked,
                $unit,
                $salesReturn,
                $client,
                $cashAccount,
                $amount,
                round($restockCost, 4),
                $restockByAccount,
                round($damagedCost, 4),
                $firstItem,
                $user,
                $today,
            );

            $salesReturn->total_cost = round($totalCost, 4);
            $salesReturn->journal_entry_id = $entry?->id;
            $salesReturn->save();

            if ($cashAccount !== null && $amount > 0) {
                // FR-INV-049: the refund is a negative payment on the original sale,
                // so the register day's expected cash nets it automatically.
                SalePayment::create([
                    'operating_unit_id' => $locked->operating_unit_id,
                    'sales_order_id' => $locked->id,
                    'sales_return_id' => $salesReturn->id,
                    'client_id' => $locked->client_id,
                    'amount' => -$amount,
                    'method' => $refundMethod,
                    'cash_account_id' => $cashAccount->id,
                    'received_at' => now(),
                    'received_by_user_id' => $user->id,
                    'journal_entry_id' => $entry?->id,
                    // Only the refund row is stamped; the original sale keeps its own shift (or none).
                    'pos_shift_id' => $this->posShifts->shiftForPayment($locked, $refundMethod, $cashAccount, stampOrder: false),
                ]);

                $locked->amount_paid = round((float) $locked->amount_paid - $amount, 4);
            } elseif ($client !== null) {
                $client->current_balance = round((float) $client->current_balance - $amount, 4);
                $client->save();
            }

            $locked->returned_amount = round((float) $locked->returned_amount + $amount, 4);
            $locked->save();
            $this->checkoutService->refreshStatus($locked);

            return $salesReturn->load([
                'lines.orderLine.inventoryItem',
                'order:id,order_number,client_id,walk_in_name,buyer_type,total_amount,returned_amount,amount_paid',
                'cashAccount',
                'createdBy:id,name',
            ]);
        });
    }

    /**
     * Check every requested line against the sale (BR-INV-013) and price it.
     *
     * @param  array<int, array<string, mixed>>  $requested
     * @param  Collection<string, SalesOrderLine>  $orderLines
     * @param  array<string, array{quantity: float, amount: float}>  $returned
     * @param  array<string, float>  $netUnits
     * @return list<array{line: SalesOrderLine, quantity: float, condition: string, amount: float}>
     */
    private function prepareLines(array $requested, Collection $orderLines, array $returned, array $netUnits): array
    {
        $claimed = [];
        $prepared = [];

        foreach ($requested as $row) {
            $lineId = (string) ($row['sales_order_line_id'] ?? '');
            $quantity = round((float) ($row['quantity'] ?? 0), 4);
            $condition = (string) ($row['condition'] ?? 'restock');

            /** @var SalesOrderLine|null $line */
            $line = $orderLines->get($lineId);

            if ($line === null || $line->isBundle() || $line->inventory_item_id === null) {
                throw new SalesRuleException(
                    'Only item lines of this sale can be returned.',
                    'RETURN_LINE_NOT_RETURNABLE',
                    ['sales_order_line_id' => $lineId],
                );
            }

            if ($quantity <= 0) {
                throw new SalesRuleException('A returned quantity must be greater than zero.', 'INVALID_QUANTITY', ['sales_order_line_id' => $lineId]);
            }

            if (! in_array($condition, self::CONDITIONS, true)) {
                throw new SalesRuleException('A returned line is either restocked or damaged.', 'RETURN_CONDITION_INVALID', ['sales_order_line_id' => $lineId]);
            }

            $sold = round((float) $line->quantity, 4);
            $alreadyReturned = round((float) ($returned[$lineId]['quantity'] ?? 0) + ($claimed[$lineId] ?? 0), 4);
            $remaining = max(0.0, round($sold - $alreadyReturned, 4));

            if ($quantity > $remaining + 0.0001) {
                throw new SalesRuleException(
                    "Returned quantity {$quantity} exceeds the {$remaining} still returnable on this line.",
                    'RETURN_QUANTITY_EXCEEDS_SOLD',
                    ['sales_order_line_id' => $lineId, 'remaining' => $remaining],
                );
            }

            $claimed[$lineId] = round(($claimed[$lineId] ?? 0) + $quantity, 4);

            // The last units of a line take exactly what is left of its net, so
            // all returns of a line together never drift past what was paid for it.
            $lineNet = round(($netUnits[$lineId] ?? 0.0) * $sold, 4);
            $amountSoFar = round((float) ($returned[$lineId]['amount'] ?? 0) + array_sum(array_map(
                fn (array $p): float => $p['line']->id === $lineId ? $p['amount'] : 0.0,
                $prepared,
            )), 4);

            $amount = abs($claimed[$lineId] + (float) ($returned[$lineId]['quantity'] ?? 0) - $sold) < 0.0001
                ? max(0.0, round($lineNet - $amountSoFar, 4))
                : round(($netUnits[$lineId] ?? 0.0) * $quantity, 4);

            $prepared[] = ['line' => $line, 'quantity' => $quantity, 'condition' => $condition, 'amount' => $amount];
        }

        return $prepared;
    }

    /**
     * Each line's net per unit: its own discount off, and its pro-rata share
     * of the invoice discount (BR-INV-017) — what the customer paid for it.
     *
     * @param  Collection<int, SalesOrderLine>  $lines
     * @return array<string, float>
     */
    private function netUnitAmounts(SalesOrder $order, Collection $lines): array
    {
        $subtotal = (float) $lines->sum(fn (SalesOrderLine $line): float => $line->lineTotal());
        $invoiceDiscount = (float) $order->invoice_discount;
        $result = [];

        foreach ($lines as $line) {
            $lineNet = $line->lineTotal();
            $share = $subtotal > 0 ? $invoiceDiscount * $lineNet / $subtotal : 0.0;
            $quantity = (float) $line->quantity;
            $result[$line->id] = $quantity > 0 ? ($lineNet - $share) / $quantity : 0.0;
        }

        return $result;
    }

    /**
     * Quantities and amounts already returned per order line.
     *
     * @param  array<int, string>  $lineIds
     * @return array<string, array{quantity: float, amount: float}>
     */
    private function returnedByLine(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return SalesReturnLine::query()
            ->whereIn('sales_order_line_id', $lineIds)
            ->selectRaw('sales_order_line_id, SUM(quantity) as qty, SUM(amount) as amt')
            ->groupBy('sales_order_line_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->sales_order_line_id => [
                    'quantity' => round((float) $row->qty, 4),
                    'amount' => round((float) $row->amt, 4),
                ],
            ])
            ->all();
    }

    /**
     * Put a restocked quantity back into the lots this line drew from, most
     * recently drawn first, never past what each lot gave net of earlier
     * returns (INV-06). Returns the actual cost of what came back, at each
     * lot's own cost.
     *
     * Sale movements carry the order, not the line, so a line's draws are
     * found by how it was issued: a line tied to its own lot drew only that
     * lot (reason sale_issue_specific_lot); a FIFO line drew by item (reason
     * sale_issue). Lines of this order issued the same way pool those draws.
     * Every restore of the pool fills the same lots in the same order, so
     * what earlier returns already put back is the pool's restocked quantity
     * laid over that order — no restore movement has to be tied to a line.
     *
     * @throws SalesRuleException
     */
    private function restock(SalesOrder $order, SalesOrderLine $line, float $quantity, SalesReturn $salesReturn): float
    {
        $lotId = $line->stock_lot_id;

        $draws = InventoryMovement::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'inventory_movements.stock_lot_id')
            ->where('inventory_movements.reference_document_type', 'SalesOrder')
            ->where('inventory_movements.reference_id', $order->id)
            ->where('inventory_movements.movement_type', 'sale')
            ->where('stock_lots.inventory_item_id', $line->inventory_item_id)
            ->when(
                $lotId !== null,
                fn ($query) => $query->where('inventory_movements.reason', self::LOT_ISSUE_REASON)
                    ->where('inventory_movements.stock_lot_id', $lotId),
                fn ($query) => $query->where('inventory_movements.reason', self::FIFO_ISSUE_REASON),
            )
            ->groupBy('inventory_movements.stock_lot_id')
            ->selectRaw('inventory_movements.stock_lot_id as lot_id, -SUM(inventory_movements.quantity_delta) as drawn, MAX(inventory_movements.created_at) as last_drawn_at, MAX(stock_lots.created_at) as lot_created_at')
            ->orderByDesc('last_drawn_at')
            ->orderByDesc('lot_created_at')
            ->orderByDesc('lot_id')
            ->get();

        $poolLineIds = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->where('inventory_item_id', $line->inventory_item_id)
            ->when(
                $lotId !== null,
                fn ($query) => $query->where('stock_lot_id', $lotId),
                fn ($query) => $query->whereNull('stock_lot_id'),
            )
            ->pluck('id')
            ->all();

        // Earlier restocks of the pool, this return's earlier lines included.
        $alreadyRestored = round((float) SalesReturnLine::query()
            ->whereIn('sales_order_line_id', $poolLineIds)
            ->where('condition', 'restock')
            ->sum('quantity'), 4);

        $remaining = $quantity;
        $cost = 0.0;

        foreach ($draws as $draw) {
            if ($remaining <= 0.00001) {
                break;
            }

            $drawn = round((float) $draw->drawn, 4);
            $taken = min($drawn, $alreadyRestored);
            $alreadyRestored = round($alreadyRestored - $taken, 4);
            $open = round($drawn - $taken, 4);

            if ($open <= 0) {
                continue;
            }

            $put = min($open, $remaining);

            $lot = StockLot::withoutGlobalScopes()->with('inventoryItem')->whereKey($draw->lot_id)->lockForUpdate()->firstOrFail();
            $lot->quantity = round((float) $lot->quantity + $put, 4);

            if ($lot->status === 'consumed') {
                $lot->status = 'available';
            }

            $lot->save();

            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $lot->id,
                'to_warehouse_id' => $lot->warehouse_id,
                'sku' => $lot->inventoryItem?->code ?? 'ITEM',
                'movement_type' => 'sale_return',
                'quantity_delta' => $put,
                'unit_cost' => (float) $lot->unit_cost,
                'reason' => 'sales_return',
                'reference_document_type' => 'SalesReturn',
                'reference_id' => $salesReturn->id,
            ]);

            $cost += $put * (float) $lot->unit_cost;
            $remaining = round($remaining - $put, 4);
        }

        if ($remaining > 0.0001) {
            throw new SalesRuleException(
                'The stock this line was sold from cannot be found to take the goods back.',
                'RETURN_STOCK_SOURCE_NOT_FOUND',
                ['sales_order_line_id' => $line->id],
            );
        }

        return round($cost, 4);
    }

    /**
     * One balanced entry for the whole return. Restocked goods go back into
     * their item type's account (INV-11); damaged goods to the unit's waste
     * account.
     *
     * @param  array<string, float>  $restockByAccount
     *
     * @throws SalesRuleException|InventoryAccountNotLinkedException
     */
    private function postJournal(
        SalesOrder $order,
        OperatingUnit $unit,
        SalesReturn $salesReturn,
        ?Client $client,
        ?CashAccount $cashAccount,
        float $amount,
        float $restockCost,
        array $restockByAccount,
        float $damagedCost,
        ?InventoryItem $item,
        User $user,
        string $today,
    ): JournalEntry {
        $unitId = $order->operating_unit_id;
        $lines = [];

        if ($amount > 0) {
            $lines[] = ['account_code' => SalesAccounts::returnsFor($unit), 'debit' => $amount, 'operating_unit_id' => $unitId];

            if ($cashAccount !== null) {
                $lines[] = ['account_code' => SalesAccounts::treasuryFor($cashAccount), 'credit' => $amount, 'operating_unit_id' => $unitId];
            } else {
                $receivableCode = SalesAccounts::receivableFor($client);
                $lines[] = [
                    'account_code' => $receivableCode,
                    'credit' => $amount,
                    'operating_unit_id' => $unitId,
                    'client_id' => $this->partyFor($client, $receivableCode),
                ];
            }
        }

        if ($restockCost > 0) {
            foreach ($restockByAccount as $account => $accountCost) {
                if ($accountCost > 0) {
                    $lines[] = ['account_code' => (string) $account, 'debit' => $accountCost, 'operating_unit_id' => $unitId];
                }
            }
            $lines[] = ['account_code' => SalesAccounts::COGS, 'credit' => $restockCost, 'operating_unit_id' => $unitId];
        }

        if ($damagedCost > 0) {
            $lines[] = ['account_code' => $this->unitAccount($unit, InventoryEventType::Waste, $item), 'debit' => $damagedCost, 'operating_unit_id' => $unitId];
            $lines[] = ['account_code' => SalesAccounts::COGS, 'credit' => $damagedCost, 'operating_unit_id' => $unitId];
        }

        return $this->accountingService->postJournal(
            "Sales return {$salesReturn->number} on sale {$order->order_number}",
            $lines,
            'SalesReturn',
            $salesReturn->id,
            $unit->company_id,
            userId: $user->id,
            entryDate: $today,
        );
    }

    /**
     * @throws InventoryAccountNotLinkedException
     */
    private function unitAccount(OperatingUnit $unit, InventoryEventType $event, ?InventoryItem $item): string
    {
        $account = $unit->accountFor($event);

        if ($account === null) {
            throw new InventoryAccountNotLinkedException($item ?? new InventoryItem(['name' => '-', 'code' => '-']), $unit, $event);
        }

        return $account->account_code;
    }

    /**
     * A refund cannot pay out more than the sale actually took in, nor more
     * than the treasury holds (TRS-01 live ledger balance).
     *
     * @throws SalesRuleException
     */
    private function ensureRefundable(SalesOrder $order, CashAccount $cashAccount, float $amount): void
    {
        $paid = round((float) $order->amount_paid, 4);

        if ($amount > $paid + 0.0001) {
            throw new SalesRuleException(
                "A cash or bank refund of {$amount} exceeds the {$paid} received on this sale; refund the rest as credit.",
                'RETURN_REFUND_METHOD_INVALID',
                ['amount_paid' => $paid],
            );
        }

        CashAccount::whereKey($cashAccount->id)->lockForUpdate()->first();
        $available = round((float) ($this->treasuryBalances->balanceFor($cashAccount)['ledger_balance'] ?? 0.0), 4);

        if ($available < $amount) {
            throw new SalesRuleException(
                "Insufficient balance in treasury {$cashAccount->name}. Available: {$available}, required: {$amount}.",
                'TREASURY_INSUFFICIENT_BALANCE',
                ['available' => $available],
            );
        }
    }

    /**
     * The treasury a refund leaves from: of the refund's kind and linked to
     * the ledger (same refusals as a checkout's treasury).
     *
     * @throws SalesRuleException
     */
    private function resolveTreasury(?string $cashAccountId, string $method): CashAccount
    {
        $cashAccount = $cashAccountId !== null
            ? CashAccount::with('account')->whereKey($cashAccountId)->first()
            : null;

        if ($cashAccount === null) {
            throw new SalesRuleException(
                $method === 'bank' ? 'Choose the bank account the refund leaves from.' : 'Choose the treasury the refund leaves from.',
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

    private function partyFor(?Client $client, string $accountCode): ?string
    {
        if ($client === null) {
            return null;
        }

        $accountId = Account::where('account_code', $accountCode)->value('id');

        return $accountId !== null && ReceivableAccounts::contains($accountId) ? $client->id : null;
    }
}
