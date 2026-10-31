<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\EntityContact;
use App\Support\DebtorStatementFilters;
use App\Support\ReceivableAccounts;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-client receivables built from the general ledger: every journal line on
 * the 122 subtree, grouped by journal_lines.client_id. Lines with no client
 * form the "unassigned" section so the report reconciles with the subtree.
 *
 * Query count is fixed regardless of client count: grouped opening balances,
 * period movements, debit lines for aging, and one client meta load (with
 * entity and contacts eager loaded).
 */
final class ReceivablesLedgerService
{
    private const float TOLERANCE = 0.0001;

    private const int DEFAULT_PAYMENT_TERMS_DAYS = 30;

    private const string UNNAMED_CLIENT = 'عميل غير مسمى';

    /** Grouping key for lines with no client_id (client ids are uuids, never empty). */
    private const string UNASSIGNED = '';

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     generated_at: string,
     *     debtors: list<array<string, mixed>>,
     *     unassigned: array<string, mixed>|null,
     *     totals: array{opening: float, debit: float, credit: float, closing: float, debtors_count: int, aging: array<string, float>, overdue: float},
     * }
     */
    public function debtorStatements(DebtorStatementFilters $filters): array
    {
        $subtreeIds = ReceivableAccounts::subtreeIds();
        $asOf = Carbon::parse($filters->to)->startOfDay();

        // A client group narrows the ledger reads to its clients in SQL (and,
        // like picking clients, leaves out the unassigned bucket).
        $partyIds = $filters->clientIds;
        $includeUnassigned = $filters->includeUnassigned && $filters->clientGroupId === null;
        if ($filters->clientGroupId !== null) {
            $groupClientIds = Client::withoutGlobalScopes()
                ->where('client_group_id', $filters->clientGroupId)
                ->pluck('id')
                ->all();
            $partyIds = $partyIds === []
                ? $groupClientIds
                : array_values(array_intersect($partyIds, $groupClientIds));

            if ($partyIds === []) {
                return $this->emptyDebtorStatements($filters);
            }
        }

        $partyFilter = function (Builder $query) use ($partyIds, $includeUnassigned): void {
            if ($partyIds !== []) {
                $query->where(function (Builder $inner) use ($partyIds, $includeUnassigned): void {
                    $inner->whereIn('journal_lines.client_id', $partyIds);
                    if ($includeUnassigned) {
                        $inner->orWhereNull('journal_lines.client_id');
                    }
                });
            } elseif (! $includeUnassigned) {
                $query->whereNotNull('journal_lines.client_id');
            }
        };

        $openingQuery = $this->receivableLines($subtreeIds, $filters->operatingUnitId)
            ->whereDate('journal_entries.entry_date', '<', $filters->from)
            ->selectRaw('journal_lines.client_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.client_id');
        $partyFilter($openingQuery);
        $openings = $openingQuery->get()->keyBy(fn (object $row): string => $row->client_id ?? self::UNASSIGNED);

        $movementQuery = $this->receivableLines($subtreeIds, $filters->operatingUnitId)
            ->whereDate('journal_entries.entry_date', '>=', $filters->from)
            ->whereDate('journal_entries.entry_date', '<=', $filters->to)
            ->select([
                'journal_lines.client_id',
                'journal_lines.journal_entry_id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.memo',
                'journal_entries.entry_date',
                'journal_entries.reference',
                'journal_entries.description',
                'journal_entries.source_document_type',
                'journal_entries.source_document_id',
            ]);
        $partyFilter($movementQuery);
        $movements = $this->inLedgerOrder($movementQuery)->get()
            ->groupBy(fn (object $row): string => $row->client_id ?? self::UNASSIGNED);

        $debitQuery = $this->agingDebits($subtreeIds, $filters->operatingUnitId, $filters->to);
        $partyFilter($debitQuery);
        $debits = $debitQuery->get()->groupBy(fn (object $row): string => $row->client_id ?? self::UNASSIGNED);

        $keys = $openings->keys()->merge($movements->keys())->unique()->values();
        $clientIds = $keys->reject(fn (string $key): bool => $key === self::UNASSIGNED)->values()->all();

        $clients = $clientIds === [] ? collect() : Client::withoutGlobalScopes()
            ->with(['entity.contacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        $debtors = [];

        foreach ($clientIds as $clientId) {
            $client = $clients->get($clientId);

            if ($client === null || ! $this->matchesClientFilters($client, $filters)) {
                continue;
            }

            $paymentTerms = (int) ($client->payment_terms_days ?? self::DEFAULT_PAYMENT_TERMS_DAYS);
            $section = $this->section(
                $openings->get($clientId),
                $movements->get($clientId, collect()),
                $debits->get($clientId, collect()),
                $asOf,
                $paymentTerms,
            );

            $isActive = $section['lines'] !== [] || $section['closing_balance'] > self::TOLERANCE;
            if (! $isActive) {
                continue;
            }

            if ($filters->minBalance !== null && $section['closing_balance'] < $filters->minBalance) {
                continue;
            }

            $debtors[] = ['client' => $this->clientMeta($client, $paymentTerms)] + $section;
        }

        usort($debtors, fn (array $a, array $b): int => [$b['closing_balance'], $a['client']['name']]
            <=> [$a['closing_balance'], $b['client']['name']]);

        $unassigned = null;
        if ($includeUnassigned && $keys->contains(self::UNASSIGNED)) {
            $section = $this->section(
                $openings->get(self::UNASSIGNED),
                $movements->get(self::UNASSIGNED, collect()),
                $debits->get(self::UNASSIGNED, collect()),
                $asOf,
                self::DEFAULT_PAYMENT_TERMS_DAYS,
            );

            if ($section['lines'] !== [] || abs($section['closing_balance']) > self::TOLERANCE) {
                $unassigned = $section;
            }
        }

        return [
            'period' => ['from' => $filters->from, 'to' => $filters->to],
            'generated_at' => now()->toIso8601String(),
            'debtors' => $debtors,
            'unassigned' => $unassigned,
            'totals' => $this->totals($debtors),
        ];
    }

    /**
     * The report for a filter that matches no client (e.g. an empty group).
     *
     * @return array{period: array{from: string, to: string}, generated_at: string, debtors: list<array<string, mixed>>, unassigned: null, totals: array{opening: float, debit: float, credit: float, closing: float, debtors_count: int, aging: array<string, float>, overdue: float}}
     */
    private function emptyDebtorStatements(DebtorStatementFilters $filters): array
    {
        return [
            'period' => ['from' => $filters->from, 'to' => $filters->to],
            'generated_at' => now()->toIso8601String(),
            'debtors' => [],
            'unassigned' => null,
            'totals' => $this->totals([]),
        ];
    }

    /**
     * All-time receivable totals, aging and overdue per client as of a date.
     * Overdue uses each client's payment terms, as in debtorStatements.
     * Untagged lines (no client_id) are not included.
     *
     * @param  list<string>|null  $clientIds  null means every client
     * @return array<string, array{total_debit: float, total_credit: float, net_balance: float, aging: array{current: float, days_31_60: float, days_61_90: float, over_90: float}, overdue_amount: float}>
     */
    public function balances(string $asOf, ?string $operatingUnitId, ?array $clientIds = null): array
    {
        if ($clientIds === []) {
            return [];
        }

        $subtreeIds = ReceivableAccounts::subtreeIds();
        $asOfDate = Carbon::parse($asOf)->startOfDay();

        $partyFilter = function (Builder $query) use ($clientIds): void {
            $query->whereNotNull('journal_lines.client_id');
            if ($clientIds !== null) {
                $query->whereIn('journal_lines.client_id', $clientIds);
            }
        };

        $totalsQuery = $this->receivableLines($subtreeIds, $operatingUnitId)
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->selectRaw('journal_lines.client_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.client_id');
        $partyFilter($totalsQuery);

        $debitQuery = $this->agingDebits($subtreeIds, $operatingUnitId, $asOf);
        $partyFilter($debitQuery);
        $debits = $debitQuery->get()->groupBy('client_id');

        $totals = $totalsQuery->get();

        $paymentTerms = $totals->isEmpty() ? collect() : Client::withoutGlobalScopes()
            ->whereIn('id', $totals->pluck('client_id')->all())
            ->pluck('payment_terms_days', 'id');

        $balances = [];

        foreach ($totals as $row) {
            $totalDebit = round((float) $row->debit, 4);
            $totalCredit = round((float) $row->credit, 4);
            $terms = (int) ($paymentTerms->get($row->client_id) ?? self::DEFAULT_PAYMENT_TERMS_DAYS);
            [$aging, $overdue] = $this->age($debits->get($row->client_id, collect()), $totalCredit, $asOfDate, $terms);

            $balances[$row->client_id] = [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_balance' => round($totalDebit - $totalCredit, 4),
                'aging' => $aging,
                'overdue_amount' => $overdue,
            ];
        }

        return $balances;
    }

    /**
     * Date (Y-m-d) of a client's earliest receivable line, or null when it has none.
     */
    public function firstEntryDate(string $clientId, ?string $operatingUnitId): ?string
    {
        $date = $this->receivableLines(ReceivableAccounts::subtreeIds(), $operatingUnitId)
            ->where('journal_lines.client_id', $clientId)
            ->min('journal_entries.entry_date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    /**
     * Journal lines on the receivables subtree joined to their (live) entries.
     *
     * @param  array<int, string>  $subtreeIds
     */
    private function receivableLines(array $subtreeIds, ?string $operatingUnitId): Builder
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $subtreeIds)
            ->whereNull('journal_entries.deleted_at');

        if ($operatingUnitId !== null) {
            $query->where('journal_lines.operating_unit_id', $operatingUnitId);
        }

        return $query;
    }

    /**
     * Debit lines up to a date, oldest first per client, for FIFO aging.
     *
     * @param  array<int, string>  $subtreeIds
     */
    private function agingDebits(array $subtreeIds, ?string $operatingUnitId, string $to): Builder
    {
        $query = $this->receivableLines($subtreeIds, $operatingUnitId)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->where('journal_lines.debit', '>', 0)
            ->select(['journal_lines.client_id', 'journal_lines.debit', 'journal_entries.entry_date']);

        return $this->inLedgerOrder($query);
    }

    /**
     * Posting order: entry date, then creation time, then the sequential reference
     * (which breaks ties for entries posted within the same second).
     */
    private function inLedgerOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('journal_lines.client_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.created_at')
            ->orderBy('journal_entries.reference');
    }

    /**
     * One statement section: opening, period lines with running balance, closing and aging.
     *
     * @param  Collection<int, object>  $movements
     * @param  Collection<int, object>  $debits
     * @return array{opening_balance: float, lines: list<array<string, mixed>>, total_debit: float, total_credit: float, closing_balance: float, aging: array<string, float>, overdue_amount: float}
     */
    private function section(?object $opening, Collection $movements, Collection $debits, Carbon $asOf, int $paymentTerms): array
    {
        $openingCredit = (float) ($opening->credit ?? 0);
        $openingBalance = round((float) ($opening->debit ?? 0) - $openingCredit, 4);

        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $lines = [];

        foreach ($movements as $row) {
            $debit = round((float) $row->debit, 4);
            $credit = round((float) $row->credit, 4);
            $running = round($running + $debit - $credit, 4);
            $totalDebit += $debit;
            $totalCredit += $credit;

            $lines[] = [
                'date' => substr((string) $row->entry_date, 0, 10),
                'reference' => $row->reference,
                'description' => $row->memo ?? $row->description,
                'source_type' => $row->source_document_type,
                'source_id' => $row->source_document_id,
                'journal_entry_id' => $row->journal_entry_id,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        }

        $totalDebit = round($totalDebit, 4);
        $totalCredit = round($totalCredit, 4);
        [$aging, $overdue] = $this->age($debits, $openingCredit + $totalCredit, $asOf, $paymentTerms);

        return [
            'opening_balance' => $openingBalance,
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => round($openingBalance + $totalDebit - $totalCredit, 4),
            'aging' => $aging,
            'overdue_amount' => $overdue,
        ];
    }

    /**
     * FIFO aging: all credits up to the as-of date settle the oldest debits first;
     * what is left of each debit is bucketed by its age in days at the as-of date.
     * Overdue is the unpaid part of debits older than the payment terms.
     *
     * @param  Collection<int, object>  $debits  ordered oldest first
     * @return array{0: array{current: float, days_31_60: float, days_61_90: float, over_90: float}, 1: float}
     */
    private function age(Collection $debits, float $totalCredit, Carbon $asOf, int $paymentTerms): array
    {
        $aging = ['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0];
        $overdue = 0.0;
        $remainingCredit = round($totalCredit, 4);

        foreach ($debits as $row) {
            $amount = round((float) $row->debit, 4);

            if ($remainingCredit >= $amount) {
                $remainingCredit = round($remainingCredit - $amount, 4);

                continue;
            }

            $unpaid = round($amount - $remainingCredit, 4);
            $remainingCredit = 0.0;

            $days = (int) Carbon::parse(substr((string) $row->entry_date, 0, 10))->diffInDays($asOf);

            $bucket = match (true) {
                $days <= 30 => 'current',
                $days <= 60 => 'days_31_60',
                $days <= 90 => 'days_61_90',
                default => 'over_90',
            };
            $aging[$bucket] += $unpaid;

            if ($days > $paymentTerms) {
                $overdue += $unpaid;
            }
        }

        return [array_map(fn (float $value): float => round($value, 4), $aging), round($overdue, 4)];
    }

    private function matchesClientFilters(Client $client, DebtorStatementFilters $filters): bool
    {
        if ($filters->statuses !== [] && ! in_array($client->status?->value ?? 'active', $filters->statuses, true)) {
            return false;
        }

        if ($filters->clientGroupId !== null && $client->client_group_id !== $filters->clientGroupId) {
            return false;
        }

        if ($filters->city !== null && trim($filters->city) !== '') {
            $wanted = mb_strtolower(trim($filters->city));
            $contacts = $client->entity?->contacts ?? collect();

            return $contacts->contains(fn (EntityContact $contact): bool => $contact->city !== null
                && mb_strtolower(trim($contact->city)) === $wanted);
        }

        return true;
    }

    /**
     * @return array{id: string, name: string, phone: string, city: string, status: string, credit_limit: float, payment_terms_days: int}
     */
    private function clientMeta(Client $client, int $paymentTerms): array
    {
        $contacts = $client->entity?->contacts ?? collect();
        $contact = $contacts->firstWhere('is_primary', true) ?? $contacts->first();

        return [
            'id' => $client->id,
            'name' => $client->entity?->name ?? self::UNNAMED_CLIENT,
            'phone' => $contact?->phone ?? '',
            'city' => $contact?->city ?? '',
            'status' => $client->status?->value ?? 'active',
            'credit_limit' => round((float) ($client->credit_limit ?? 0), 4),
            'payment_terms_days' => $paymentTerms,
        ];
    }

    /**
     * Totals over included debtors only; the unassigned section is reported separately.
     *
     * @param  list<array<string, mixed>>  $debtors
     * @return array{opening: float, debit: float, credit: float, closing: float, debtors_count: int, aging: array<string, float>, overdue: float}
     */
    private function totals(array $debtors): array
    {
        $aging = ['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0];
        foreach ($debtors as $debtor) {
            foreach ($aging as $bucket => $sum) {
                $aging[$bucket] = $sum + $debtor['aging'][$bucket];
            }
        }

        $sum = fn (string $key): float => round(array_sum(array_column($debtors, $key)), 4);

        return [
            'opening' => $sum('opening_balance'),
            'debit' => $sum('total_debit'),
            'credit' => $sum('total_credit'),
            'closing' => $sum('closing_balance'),
            'debtors_count' => count($debtors),
            'aging' => array_map(fn (float $value): float => round($value, 4), $aging),
            'overdue' => $sum('overdue_amount'),
        ];
    }
}
