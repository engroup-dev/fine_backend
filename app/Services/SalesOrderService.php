<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\InternalRestockRequest;
use App\Models\OperatingUnit;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Internal restock between units. Selling itself lives in SaleCheckoutService
 * (the POS); this service keeps the store-asks-a-plant restock flow.
 */
class SalesOrderService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly StockIssueService $stockIssueService,
    ) {}

    /**
     * Fulfill an approved restock request. Fulfillment is the physical
     * transfer: stock leaves the source unit and lands in the requesting unit
     * at cost — each lot keeping its own size — with the journal moving value
     * between the two unit subledgers (SALE-08).
     */
    public function fulfillRestock(InternalRestockRequest $request): InternalRestockRequest
    {
        return DB::transaction(function () use ($request): InternalRestockRequest {
            $locked = InternalRestockRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new InvalidStateTransitionException(
                    "Restock request {$locked->request_number} is {$locked->status}; only an approved request can be fulfilled."
                );
            }

            // Fails early (before any draw) when the store has nowhere to put it.
            $this->stockIssueService->receivingWarehouse($locked->requesting_unit_id);

            $costByAccount = [];
            $totalCost = 0.0;

            foreach ($locked->lines()->with('inventoryItem')->orderBy('created_at')->get()->values() as $position => $line) {
                [$cost, $portions] = $this->stockIssueService->drawFromUnit(
                    $line->inventory_item_id,
                    (float) $line->quantity,
                    $locked->source_unit_id,
                    'InternalRestockRequest',
                    $locked->id,
                    'restock_transfer_out',
                );

                $this->stockIssueService->land(
                    $portions,
                    $locked->requesting_unit_id,
                    null,
                    'RST-'.$locked->request_number.'-L'.($position + 1),
                    'InternalRestockRequest',
                    $locked->id,
                    'restock_transfer_in',
                );

                $totalCost += $cost;
                // INV-11: same account on both sides — only the unit changes.
                $account = InventoryAccounts::forItem(
                    $line->inventoryItem ?? throw new InvalidStateTransitionException('Restock line is missing its inventory item.'),
                );
                $costByAccount[$account] = round(($costByAccount[$account] ?? 0) + $cost, 4);
            }

            $locked->status = 'fulfilled';
            $locked->save();

            if ($totalCost > 0) {
                $journalLines = [];
                foreach ($costByAccount as $account => $amount) {
                    // Same account, two units: value moves between subledgers
                    // while the company total stays put — at-cost transfer.
                    $journalLines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $locked->requesting_unit_id];
                    $journalLines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $locked->source_unit_id];
                }

                $this->accountingService->postJournal(
                    "Restock transfer {$locked->request_number}",
                    $journalLines,
                    'InternalRestockRequest',
                    $locked->id,
                    OperatingUnit::find($locked->source_unit_id)?->company_id,
                );
            }

            return $locked->fresh(['lines']);
        });
    }
}
