<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentRequestStatus;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Client;
use App\Models\EntityContact;
use App\Models\PayableSettlement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\DebtorStatementFilters;
use Carbon\Carbon;

final class PartnerReportService
{
    public function __construct(private readonly ReceivablesLedgerService $receivables) {}

    /**
     * Cross-client balances and aging summary, read from the receivables ledger.
     * Every client in scope is listed, including clients with no ledger lines.
     * A client group narrows the list to that group's clients (W5-06).
     *
     * @return array<string, mixed>
     */
    public function clientBalances(?string $asOf = null, ?string $operatingUnitId = null, ?string $clientGroupId = null): array
    {
        $asOf ??= now()->toDateString();

        $query = Client::query()->with([
            'entity.contacts' => fn ($contacts) => $contacts->orderBy('created_at')->orderBy('id'),
            'operatingUnit',
        ]);
        if ($operatingUnitId !== null) {
            $query->where('operating_unit_id', $operatingUnitId);
        }
        if ($clientGroupId !== null) {
            $query->where('client_group_id', $clientGroupId);
        }

        $clients = $query->get();
        // Company-wide reads every client, so skip the (possibly huge) id list.
        $balances = $this->receivables->balances(
            $asOf,
            $operatingUnitId,
            $operatingUnitId === null && $clientGroupId === null ? null : $clients->modelKeys(),
        );

        $rows = [];
        $totalReceivables = 0.0;
        $totalCreditsOrAdvances = 0.0;
        $overdueTotal = 0.0;

        foreach ($clients as $client) {
            $balance = $balances[$client->id] ?? [
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'net_balance' => 0.0,
                'aging' => ['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0],
                'overdue_amount' => 0.0,
            ];
            $netBalance = $balance['net_balance'];

            if ($netBalance > 0.0001) {
                $position = 'debit';
                $totalReceivables += $netBalance;
                $overdueTotal += $balance['overdue_amount'];
            } elseif ($netBalance < -0.0001) {
                $position = 'credit';
                $totalCreditsOrAdvances += abs($netBalance);
            } else {
                $position = 'settled';
            }

            $contact = $this->primaryContact($client);

            $rows[] = [
                'id' => $client->id,
                'name' => $client->entity?->name ?? 'عميل غير مسمى',
                'phone' => $contact?->phone ?? '',
                'city' => $contact?->city ?? '',
                'operating_unit_id' => $client->operating_unit_id,
                'operating_unit_name' => $client->operatingUnit?->name ?? '—',
                'credit_limit' => round((float) ($client->credit_limit ?? 0), 4),
                'payment_terms_days' => (int) ($client->payment_terms_days ?? 30),
                'total_debit' => $balance['total_debit'],
                'total_credit' => $balance['total_credit'],
                'net_balance' => $netBalance,
                'balance_type' => $position,
                'aging' => $balance['aging'],
                'overdue_amount' => $balance['overdue_amount'],
                'status' => $client->status?->value ?? 'active',
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net_balance'] <=> $a['net_balance']);

        return [
            'type' => 'clients',
            'as_of' => $asOf,
            'operating_unit_id' => $operatingUnitId,
            'client_group_id' => $clientGroupId,
            'summary' => [
                'total_receivables' => round($totalReceivables, 4),
                'total_credits_or_advances' => round($totalCreditsOrAdvances, 4),
                'net_exposure' => round($totalReceivables - $totalCreditsOrAdvances, 4),
                'overdue_total' => round($overdueTotal, 4),
                'total_count' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Cross-supplier balances and aging summary.
     *
     * @return array<string, mixed>
     */
    public function supplierBalances(?string $asOf = null, ?string $operatingUnitId = null): array
    {
        $asOf ??= now()->toDateString();
        $asOfEnd = Carbon::parse($asOf)->endOfDay();

        $query = Supplier::query()->with(['operatingUnit', 'account']);
        if ($operatingUnitId !== null) {
            $query->where('operating_unit_id', $operatingUnitId);
        }

        $suppliers = $query->get();

        $rows = [];
        $totalPayables = 0.0;
        $totalAdvances = 0.0;
        $overdueTotal = 0.0;

        foreach ($suppliers as $supplier) {
            $pos = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', '!=', PurchaseOrderStatus::Draft)
                ->where('created_at', '<=', $asOfEnd)
                ->with(['items', 'paymentRequests'])
                ->get();

            $totalPurchases = 0.0;
            $totalPayments = 0.0;

            foreach ($pos as $po) {
                $cost = (float) $po->totalCost();
                $totalPurchases += $cost;

                if ($po->kind === PurchaseOrderKind::Local) {
                    if (in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                        $totalPayments += $cost;
                    }
                } else {
                    // Foreign PO: sum paid payment requests
                    $paidReqs = $po->paymentRequests->where('status', PaymentRequestStatus::Paid);
                    $totalPayments += (float) $paidReqs->sum('amount_requested');
                }
            }

            // Also check for settlements on supplier's sub-account if mapped
            if ($supplier->account?->account_code) {
                $settlements = PayableSettlement::query()
                    ->where('account_code', $supplier->account->account_code)
                    ->where('settled_at', '<=', $asOf)
                    ->sum('amount');
                $totalPayments += (float) $settlements;
            }

            $totalPurchases = round($totalPurchases, 4);
            $totalPayments = round($totalPayments, 4);
            $netBalance = round($totalPurchases - $totalPayments, 4);

            $aging = [
                'current' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'over_90' => 0.0,
            ];

            $remainingPayments = $totalPayments;
            $sortedPos = $pos->sortBy('created_at');

            foreach ($sortedPos as $po) {
                $poCost = (float) $po->totalCost();
                if ($remainingPayments >= $poCost) {
                    $remainingPayments -= $poCost;

                    continue;
                }

                $unpaid = $poCost - $remainingPayments;
                $remainingPayments = 0.0;

                $poDate = Carbon::parse($po->created_at);
                $days = $poDate->diffInDays(Carbon::parse($asOf), false);

                if ($days <= 30) {
                    $aging['current'] += $unpaid;
                } elseif ($days <= 60) {
                    $aging['days_31_60'] += $unpaid;
                } elseif ($days <= 90) {
                    $aging['days_61_90'] += $unpaid;
                } else {
                    $aging['over_90'] += $unpaid;
                }
            }

            foreach ($aging as $key => $val) {
                $aging[$key] = round($val, 4);
            }

            $supplierOverdue = round($aging['days_31_60'] + $aging['days_61_90'] + $aging['over_90'], 4);

            if ($netBalance > 0.0001) {
                $position = 'credit'; // Fine owes Supplier (payable)
                $totalPayables += $netBalance;
                $overdueTotal += $supplierOverdue;
            } elseif ($netBalance < -0.0001) {
                $position = 'debit'; // Supplier owes Fine (advance)
                $totalAdvances += abs($netBalance);
            } else {
                $position = 'settled';
            }

            $rows[] = [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact ?? '',
                'address' => $supplier->address ?? '',
                'operating_unit_id' => $supplier->operating_unit_id,
                'operating_unit_name' => $supplier->operatingUnit?->name ?? '—',
                'default_currency' => $supplier->default_currency ?? 'LYD',
                'total_purchases' => $totalPurchases,
                'total_payments' => $totalPayments,
                'net_balance' => $netBalance,
                'balance_type' => $position,
                'aging' => $aging,
                'overdue_amount' => $supplierOverdue,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net_balance'] <=> $a['net_balance']);

        return [
            'type' => 'suppliers',
            'as_of' => $asOf,
            'operating_unit_id' => $operatingUnitId,
            'summary' => [
                'total_payables' => round($totalPayables, 4),
                'total_advances' => round($totalAdvances, 4),
                'net_exposure' => round($totalPayables - $totalAdvances, 4),
                'overdue_total' => round($overdueTotal, 4),
                'total_count' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Itemized Statement of Account for a single Client, read from the receivables ledger.
     * A missing `from` starts at the client's first ledger line (or today); a missing `to` is today.
     *
     * @return array<string, mixed>
     */
    public function clientStatement(string $clientId, ?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $clientQuery = Client::query()->with([
            'entity.contacts' => fn ($contacts) => $contacts->orderBy('created_at')->orderBy('id'),
            'operatingUnit',
        ])->whereKey($clientId);
        if ($operatingUnitId !== null) {
            $clientQuery->where('operating_unit_id', $operatingUnitId);
        }

        $client = $clientQuery->firstOrFail();

        $today = now()->toDateString();
        $periodFrom = $from !== null
            ? Carbon::parse($from)->toDateString()
            : ($this->receivables->firstEntryDate($client->id, $operatingUnitId) ?? $today);
        $periodTo = $to !== null ? Carbon::parse($to)->toDateString() : $today;

        $report = $this->receivables->debtorStatements(new DebtorStatementFilters(
            from: $periodFrom,
            to: $periodTo,
            operatingUnitId: $operatingUnitId,
            clientIds: [$client->id],
            includeUnassigned: false,
        ));

        $section = $report['debtors'][0] ?? null;

        if ($section === null) {
            // No movement in the period and no positive balance: the balance carried
            // through the period (zero, or an overpayment) is both opening and closing.
            $carried = $this->receivables->balances($periodTo, $operatingUnitId, [$client->id])[$client->id]['net_balance'] ?? 0.0;
            $section = ['opening_balance' => $carried, 'closing_balance' => $carried, 'total_debit' => 0.0, 'total_credit' => 0.0, 'lines' => []];
        }

        $transactions = array_map(fn (array $line): array => $this->statementTransaction($line), $section['lines']);
        $contact = $this->primaryContact($client);

        return [
            'party' => [
                'id' => $client->id,
                'name' => $client->entity?->name ?? 'عميل غير مسمى',
                'phone' => $contact?->phone ?? '',
                'address' => $contact?->address ?? '',
                'type' => 'client',
                'currency' => 'LYD',
                'operating_unit_id' => $client->operating_unit_id,
                'operating_unit_name' => $client->operatingUnit?->name ?? '—',
                'credit_limit' => round((float) ($client->credit_limit ?? 0), 4),
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'opening_balance' => $section['opening_balance'],
            'closing_balance' => $section['closing_balance'],
            'total_debit' => $section['total_debit'],
            'total_credit' => $section['total_credit'],
            'net_change' => round($section['total_debit'] - $section['total_credit'], 4),
            'transactions' => $transactions,
        ];
    }

    /**
     * Maps a receivables ledger line to the partner statement transaction shape.
     *
     * @param  array<string, mixed>  $line
     * @return array{id: string, date: string, document_type: string, document_type_label: string, document_number: string|null, memo: string|null, debit: float, credit: float, running_balance: float}
     */
    private function statementTransaction(array $line): array
    {
        $fromSale = $line['source_type'] === 'SalesOrder';

        [$type, $label] = match (true) {
            $fromSale && $line['debit'] > 0 => ['sale_order', 'طلب مبيعات آجل'],
            $fromSale && $line['credit'] > 0 => ['sale_payment', 'سداد دفعة'],
            default => ['journal_entry', 'قيد يومية'],
        };

        return [
            'id' => $line['journal_entry_id'],
            'date' => $line['date'],
            'document_type' => $type,
            'document_type_label' => $label,
            'document_number' => $line['reference'],
            'memo' => $line['description'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'running_balance' => $line['running_balance'],
        ];
    }

    /**
     * The client's primary contact, else its oldest one (same source as the receivables ledger).
     */
    private function primaryContact(Client $client): ?EntityContact
    {
        $contacts = $client->entity?->contacts ?? collect();

        return $contacts->firstWhere('is_primary', true) ?? $contacts->first();
    }

    /**
     * Itemized Statement of Account for a single Supplier.
     *
     * @return array<string, mixed>
     */
    public function supplierStatement(string $supplierId, ?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $supplierQuery = Supplier::query()->with(['operatingUnit', 'account'])->whereKey($supplierId);
        if ($operatingUnitId !== null) {
            $supplierQuery->where('operating_unit_id', $operatingUnitId);
        }

        $supplier = $supplierQuery->firstOrFail();

        $fromCarbon = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $toCarbon = $to !== null ? Carbon::parse($to)->endOfDay() : null;

        // Opening balance calculation
        $openingPurchases = 0.0;
        $openingPayments = 0.0;

        if ($fromCarbon !== null) {
            $prePos = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', '!=', PurchaseOrderStatus::Draft)
                ->where('created_at', '<', $fromCarbon)
                ->with(['items', 'paymentRequests'])
                ->get();

            foreach ($prePos as $po) {
                $cost = (float) $po->totalCost();
                $openingPurchases += $cost;

                if ($po->kind === PurchaseOrderKind::Local) {
                    if (in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                        $openingPayments += $cost;
                    }
                } else {
                    $paidReqs = $po->paymentRequests->where('status', PaymentRequestStatus::Paid);
                    $openingPayments += (float) $paidReqs->sum('amount_requested');
                }
            }

            if ($supplier->account?->account_code) {
                $openingPayments += (float) PayableSettlement::query()
                    ->where('account_code', $supplier->account->account_code)
                    ->where('settled_at', '<', $from)
                    ->sum('amount');
            }
        }

        $openingBalance = round($openingPurchases - $openingPayments, 4);

        // Period transactions
        $posQuery = PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', PurchaseOrderStatus::Draft)
            ->with(['items', 'paymentRequests']);

        if ($fromCarbon !== null) {
            $posQuery->where('created_at', '>=', $fromCarbon);
        }
        if ($toCarbon !== null) {
            $posQuery->where('created_at', '<=', $toCarbon);
        }

        $transactions = [];

        foreach ($posQuery->get() as $po) {
            $cost = round((float) $po->totalCost(), 4);
            $transactions[] = [
                'id' => $po->id,
                'date' => Carbon::parse($po->created_at)->toDateTimeString(),
                'timestamp' => Carbon::parse($po->created_at)->timestamp,
                'document_type' => 'purchase_order',
                'document_type_label' => $po->kind === PurchaseOrderKind::Local ? 'أمر شراء محلي' : 'أمر شراء خارجي',
                'document_number' => "PO-{$po->id}",
                'memo' => "شراء توريد — الحالة: {$po->status->value}",
                'debit' => 0.0,
                'credit' => $cost, // Credit increases liability to supplier
            ];

            // Local PO payment
            if ($po->kind === PurchaseOrderKind::Local && in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                $transactions[] = [
                    'id' => "pay-{$po->id}",
                    'date' => Carbon::parse($po->updated_at)->toDateTimeString(),
                    'timestamp' => Carbon::parse($po->updated_at)->timestamp + 1,
                    'document_type' => 'po_payment',
                    'document_type_label' => 'سداد أمر شراء',
                    'document_number' => "PO-{$po->id}",
                    'memo' => 'سداد نقدي/مصرفي لأمر الشراء المحلي',
                    'debit' => $cost, // Debit decreases liability to supplier
                    'credit' => 0.0,
                ];
            } else {
                // Foreign PO payments
                foreach ($po->paymentRequests as $req) {
                    if ($req->status === PaymentRequestStatus::Paid) {
                        $reqAmount = round((float) $req->amount_requested, 4);
                        $transactions[] = [
                            'id' => $req->id,
                            'date' => Carbon::parse($req->updated_at)->toDateTimeString(),
                            'timestamp' => Carbon::parse($req->updated_at)->timestamp + 1,
                            'document_type' => 'po_payment',
                            'document_type_label' => 'حوالة / سداد مالي للمورد',
                            'document_number' => $req->invoice_ref ?? "REQ-{$req->id}",
                            'memo' => "دفعة للمورد عبر المسار: {$req->route->value}",
                            'debit' => $reqAmount,
                            'credit' => 0.0,
                        ];
                    }
                }
            }
        }

        // Also add settlements
        if ($supplier->account?->account_code) {
            $settleQuery = PayableSettlement::query()
                ->where('account_code', $supplier->account->account_code);
            if ($from !== null) {
                $settleQuery->where('settled_at', '>=', $from);
            }
            if ($to !== null) {
                $settleQuery->where('settled_at', '<=', $to);
            }

            foreach ($settleQuery->get() as $settle) {
                $settleAmount = round((float) $settle->amount, 4);
                $transactions[] = [
                    'id' => $settle->id,
                    'date' => Carbon::parse($settle->settled_at)->toDateTimeString(),
                    'timestamp' => Carbon::parse($settle->settled_at)->timestamp,
                    'document_type' => 'payable_settlement',
                    'document_type_label' => 'تسوية التزامات مورد',
                    'document_number' => $settle->reference ?? "SETTLE-{$settle->id}",
                    'memo' => "تسوية حساب ذمم الدائنين: {$settle->account_code}",
                    'debit' => $settleAmount,
                    'credit' => 0.0,
                ];
            }
        }

        // Sort chronologically
        usort($transactions, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($transactions as &$tx) {
            $totalDebit += $tx['debit'];
            $totalCredit += $tx['credit'];
            $running = round($running + $tx['credit'] - $tx['debit'], 4);
            $tx['running_balance'] = $running;
            unset($tx['timestamp']);
        }
        unset($tx);

        return [
            'party' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact ?? '',
                'address' => $supplier->address ?? '',
                'type' => 'supplier',
                'currency' => $supplier->default_currency ?? 'LYD',
                'operating_unit_id' => $supplier->operating_unit_id,
                'operating_unit_name' => $supplier->operatingUnit?->name ?? '—',
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $running,
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'net_change' => round($totalCredit - $totalDebit, 4),
            'transactions' => $transactions,
        ];
    }
}
