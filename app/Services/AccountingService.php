<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalEntryType;
use App\Exceptions\HeaderAccountPostingException;
use App\Exceptions\LedgerRuleException;
use App\Exceptions\MissingAccountException;
use App\Exceptions\SalesRuleException;
use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Scopes\OperatingUnitScope;
use App\Support\CompanyClock;
use App\Support\EmployeeAdvanceAccounts;
use App\Support\LedgerAccounts;
use App\Support\ReceivableAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccountingService
{
    /**
     * Rounding tolerance for the balance check.
     *
     * Amounts are stored to 4dp and apportionment divides across many blocks, so
     * an exact float comparison would reject entries that are correct to the
     * stored precision.
     */
    private const BALANCE_TOLERANCE = 0.0001;

    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PeriodGuard $periodGuard,
    ) {}

    /**
     * Post a balanced double-entry journal.
     *
     * ACC-01 is enforced here rather than in a model observer so an unbalanced
     * entry never reaches the database at all — a half-written journal is worse
     * than a rejected one, because it silently breaks the trial balance and
     * nothing downstream would notice.
     *
     * @param  array<int, array{account_code: string, debit?: float, credit?: float, operating_unit_id?: string|null, memo?: string|null, client_id?: string|null, employee_id?: string|null, currency?: string|null, original_amount?: float|null, fx_rate?: float|null}>  $lines
     */
    public function postJournal(
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $companyId = null,
        bool $isManual = false,
        ?string $userId = null,
        ?string $entryDate = null,
        ?JournalEntryType $entryType = null,
    ): JournalEntry {
        $entryType ??= $isManual ? JournalEntryType::Manual : JournalEntryType::Automatic;
        $isManual = $isManual || $entryType->isManualFamily();

        if ($lines === []) {
            throw new InvalidArgumentException('A journal entry needs at least one line.');
        }

        $companyId ??= Company::query()->value('id');

        if ($companyId === null) {
            throw new InvalidArgumentException('No company exists to post a journal against.');
        }

        $defaultCurrency = strtoupper(trim((string) (Company::query()->where('id', $companyId)->value('default_currency') ?? 'LYD')));

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $normalizedLines = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Journal amounts cannot be negative; use the opposite side instead.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line is either a debit or a credit, never both.');
            }

            $rawCurrency = isset($line['currency']) && is_string($line['currency']) && trim($line['currency']) !== ''
                ? strtoupper(trim($line['currency']))
                : null;

            if ($rawCurrency === null || $rawCurrency === $defaultCurrency) {
                $line['currency'] = null;
                $line['original_amount'] = null;
                $line['fx_rate'] = null;
            } else {
                $originalAmount = isset($line['original_amount']) && is_numeric($line['original_amount'])
                    ? round((float) $line['original_amount'], 4)
                    : null;

                if ($originalAmount === null || $originalAmount <= 0) {
                    throw new InvalidArgumentException('Original amount must be greater than zero.');
                }

                $lyd = $debit > 0 ? $debit : $credit;

                if (! isset($line['fx_rate']) || $line['fx_rate'] === null || $line['fx_rate'] === '') {
                    $fxRate = round($lyd / $originalAmount, 8);
                } else {
                    $fxRate = round((float) $line['fx_rate'], 8);
                    if (abs(round($originalAmount * $fxRate, 4) - $lyd) > 0.01) {
                        throw new LedgerRuleException(
                            "ACC-15: Amount mismatch between {$rawCurrency} {$originalAmount} at rate {$fxRate} and {$lyd} {$defaultCurrency}.",
                            'FX_AMOUNT_MISMATCH',
                            [
                                'currency' => $rawCurrency,
                                'original_amount' => $originalAmount,
                                'fx_rate' => $fxRate,
                                'amount' => $lyd,
                            ],
                        );
                    }
                }

                $line['currency'] = $rawCurrency;
                $line['original_amount'] = $originalAmount;
                $line['fx_rate'] = $fxRate;
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
            $normalizedLines[] = $line;
        }

        $lines = $normalizedLines;

        $totalDebit = round($totalDebit, 4);
        $totalCredit = round($totalCredit, 4);

        if (abs($totalDebit - $totalCredit) > self::BALANCE_TOLERANCE) {
            throw new UnbalancedJournalException(
                "Journal does not balance: debits {$totalDebit} against credits {$totalCredit} for \"{$description}\"."
            );
        }

        if ($totalDebit === 0.0) {
            throw new InvalidArgumentException('A journal entry with no value has nothing to record.');
        }

        $accounts = [];

        foreach ($lines as $line) {
            $accounts[$line['account_code']] ??= $this->resolvePostableAccount($line['account_code'], $isManual);
        }

        $this->validatePartyLines($lines, $accounts, $isManual);

        $entryDate ??= CompanyClock::today();

        return DB::transaction(fn (): JournalEntry => $this->writeEntry(
            [
                'company_id' => $companyId,
                'entry_date' => $entryDate,
                'description' => $description,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'is_manual' => $isManual,
                'entry_type' => $entryType,
                'created_by_user_id' => $userId,
            ],
            array_map(fn (array $line): array => [...$line, 'account_id' => $accounts[$line['account_code']]->id], $lines),
        ));
    }

    /**
     * ACC-13: correct a posted entry by posting its mirror, linked to it. The
     * original never changes, and the unique reversal_of_id index makes a
     * second reversal impossible even under a race.
     *
     * The mirror re-uses the accounts the original already posted to, so it
     * skips the header and party checks: undoing an accepted balance can't
     * create a new problem, even if an account has since gained children.
     */
    public function reverseEntry(JournalEntry $original, ?string $entryDate, string $reason, ?string $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $entryDate, $reason, $userId): JournalEntry {
            $locked = JournalEntry::with('lines')->lockForUpdate()->findOrFail($original->getKey());

            if ($locked->entry_type === JournalEntryType::Closing) {
                throw new LedgerRuleException('A closing entry is undone by reopening its fiscal year, not by reversal.', 'CLOSING_NOT_REVERSIBLE');
            }

            if ($locked->entry_type === JournalEntryType::Reversal) {
                throw new LedgerRuleException('A reversal cannot itself be reversed; post a new entry instead.', 'REVERSAL_NOT_REVERSIBLE');
            }

            if (JournalEntry::withTrashed()->where('reversal_of_id', $locked->id)->exists()) {
                throw new LedgerRuleException("Entry {$locked->reference} has already been reversed.", 'ALREADY_REVERSED');
            }

            $entryDate ??= CompanyClock::today();

            if (Carbon::parse($entryDate)->lt($locked->entry_date->copy()->startOfDay())) {
                throw new LedgerRuleException(
                    'A reversal cannot be dated before the entry it reverses.',
                    'REVERSAL_DATE_BEFORE_ORIGINAL',
                    ['original_date' => $locked->entry_date->toDateString()],
                );
            }

            return $this->writeEntry(
                [
                    'company_id' => $locked->company_id,
                    'entry_date' => $entryDate,
                    // The full reason lives in reversal_reason; description and memo are 255 chars.
                    'description' => Str::limit("عكس القيد {$locked->reference}: {$reason}", 250),
                    // Same source, so the document's audit path shows both entries.
                    'source_document_type' => $locked->source_document_type,
                    'source_document_id' => $locked->source_document_id,
                    'is_manual' => true,
                    'entry_type' => JournalEntryType::Reversal,
                    'reversal_of_id' => $locked->id,
                    'reversal_reason' => $reason,
                    'created_by_user_id' => $userId,
                ],
                $locked->lines->map(fn (JournalLine $line): array => [
                    'account_id' => $line->account_id,
                    'operating_unit_id' => $line->operating_unit_id,
                    'client_id' => $line->client_id,
                    'employee_id' => $line->employee_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'currency' => $line->currency,
                    'original_amount' => $line->original_amount !== null ? (float) $line->original_amount : null,
                    'fx_rate' => $line->fx_rate !== null ? (float) $line->fx_rate : null,
                    'memo' => Str::limit('عكس: '.($line->memo ?? $locked->description), 250),
                ])->all(),
            );
        });
    }

    /**
     * ACC-12: post the balanced closing entry that moves P&L balances to 33.
     *
     * Resolves accounts without header/frozen checks for P&L lines (legacy lines
     * may sit on accounts that later gained children or became frozen), while
     * retained earnings (33) is checked with the strict postable check.
     *
     * @param  array<int, array{account_code: string, debit?: float, credit?: float, operating_unit_id?: string|null, memo?: string|null, client_id?: string|null, employee_id?: string|null, currency?: string|null, original_amount?: float|null, fx_rate?: float|null}>  $lines
     *
     * @throws LedgerRuleException RETAINED_EARNINGS_NOT_POSTABLE
     * @throws UnbalancedJournalException
     * @throws InvalidArgumentException
     */
    public function postClosingEntry(FiscalYear $year, array $lines, ?string $userId = null): JournalEntry
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $normalizedLines = [];

        foreach ($lines as $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Journal line amounts must be positive.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line cannot have both a debit and a credit.');
            }

            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
            $normalizedLines[] = $line;
        }

        $lines = $normalizedLines;

        $totalDebit = round($totalDebit, 4);
        $totalCredit = round($totalCredit, 4);

        if (abs($totalDebit - $totalCredit) > self::BALANCE_TOLERANCE) {
            throw new UnbalancedJournalException(
                "Journal does not balance: debits {$totalDebit} against credits {$totalCredit} for closing of \"{$year->name}\"."
            );
        }

        if ($totalDebit === 0.0) {
            throw new InvalidArgumentException('A journal entry with no value has nothing to record.');
        }

        $accounts = [];
        $retainedCode = LedgerAccounts::RETAINED_EARNINGS;

        foreach ($lines as $line) {
            $code = (string) $line['account_code'];
            if (! isset($accounts[$code])) {
                if ($code === $retainedCode) {
                    $account = Account::where('account_code', $retainedCode)->first();
                    if ($account === null || $account->isFrozen() || Account::query()->where('parent_account_id', $account->id)->exists()) {
                        throw new LedgerRuleException(
                            "Account {$retainedCode} cannot accept closing entries.",
                            'RETAINED_EARNINGS_NOT_POSTABLE',
                            ['account_code' => $retainedCode],
                        );
                    }
                    $accounts[$code] = $account;
                } else {
                    $accounts[$code] = $this->resolveAccount($code);
                }
            }
        }

        return DB::transaction(fn (): JournalEntry => $this->writeEntry(
            [
                'company_id' => $year->company_id,
                'entry_date' => $year->end_date->toDateString(),
                'description' => "إقفال السنة المالية {$year->name}",
                'source_document_type' => 'FiscalYear',
                'source_document_id' => $year->id,
                'is_manual' => false,
                'entry_type' => JournalEntryType::Closing,
                'created_by_user_id' => $userId,
            ],
            array_map(fn (array $line): array => [
                ...$line,
                'account_id' => $accounts[(string) $line['account_code']]->id,
            ], $lines),
            closingYearId: $year->id,
        ));
    }

    /**
     * ACC-12: reverse a closing entry when reopening its fiscal year.
     *
     * Mirror of the closing entry (swap debit/credit, same accounts/units, entry_type Reversal).
     * Dated the year end_date, through writeEntry() with closingYearId set.
     */
    public function reverseClosingEntry(JournalEntry $closing, FiscalYear $year, string $reason, ?string $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($closing, $year, $reason, $userId): JournalEntry {
            $locked = JournalEntry::with('lines')->lockForUpdate()->findOrFail($closing->getKey());

            if (JournalEntry::withTrashed()->where('reversal_of_id', $locked->id)->exists()) {
                throw new LedgerRuleException("Entry {$locked->reference} has already been reversed.", 'ALREADY_REVERSED');
            }

            return $this->writeEntry(
                [
                    'company_id' => $locked->company_id,
                    'entry_date' => $year->end_date->toDateString(),
                    'description' => Str::limit("عكس قيد الإقفال {$locked->reference}: {$reason}", 250),
                    'source_document_type' => 'FiscalYear',
                    'source_document_id' => $year->id,
                    'is_manual' => false,
                    'entry_type' => JournalEntryType::Reversal,
                    'reversal_of_id' => $locked->id,
                    'reversal_reason' => $reason,
                    'created_by_user_id' => $userId,
                ],
                $locked->lines->map(fn (JournalLine $line): array => [
                    'account_id' => $line->account_id,
                    'operating_unit_id' => $line->operating_unit_id,
                    'client_id' => $line->client_id,
                    'employee_id' => $line->employee_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'currency' => $line->currency,
                    'original_amount' => $line->original_amount !== null ? (float) $line->original_amount : null,
                    'fx_rate' => $line->fx_rate !== null ? (float) $line->fx_rate : null,
                    'memo' => Str::limit('عكس: '.($line->memo ?? $locked->description), 250),
                ])->all(),
                closingYearId: $year->id,
            );
        });
    }

    /**
     * The one place entries and lines are written. Callers validate first and
     * run this inside their transaction. The period guard (ACC-11) runs here,
     * before any row exists, so manual entries, automatic postings and
     * reversals are all held to the same locks.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines  each with a resolved account_id
     * @param  string|null  $closingYearId  set only for a closing entry or its reversal (ACC-12)
     */
    private function writeEntry(array $attributes, array $lines, ?string $closingYearId = null): JournalEntry
    {
        $this->periodGuard->ensurePostable((string) $attributes['entry_date'], (string) $attributes['company_id'], $closingYearId);

        $entry = JournalEntry::create([
            ...$attributes,
            'reference' => $this->nextReference($attributes['entry_date']),
        ]);

        foreach ($lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'operating_unit_id' => $line['operating_unit_id'] ?? null,
                'debit' => round((float) ($line['debit'] ?? 0), 4),
                'credit' => round((float) ($line['credit'] ?? 0), 4),
                'currency' => $line['currency'] ?? null,
                'original_amount' => $line['original_amount'] ?? null,
                'fx_rate' => $line['fx_rate'] ?? null,
                'client_id' => $line['client_id'] ?? null,
                'employee_id' => $line['employee_id'] ?? null,
                'memo' => $line['memo'] ?? null,
            ]);
        }

        return $entry->fresh(['lines.account']);
    }

    /**
     * All-time trial balance, optionally for one unit. Kept for existing
     * callers; the dated three-column report lives in TrialBalanceService.
     *
     * If this ever fails to balance, something bypassed postJournal — which is
     * exactly what it is here to reveal.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(?string $operatingUnitId = null): array
    {
        return app(TrialBalanceService::class)->build(operatingUnitId: $operatingUnitId);
    }

    /**
     * A client party may only sit on the receivables subtree, and on the
     * sub-account the client is bound to (when it has one). Checked before any
     * write so a bad party never leaves a half-posted entry.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, Account>  $accounts  the accounts postJournal already resolved, keyed by code
     */
    private function validatePartyLines(array $lines, array $accounts, bool $isManual): void
    {
        foreach ($lines as $line) {
            $clientId = $line['client_id'] ?? null;

            if ($clientId === null) {
                continue;
            }

            $account = $accounts[$line['account_code']];

            if (! ReceivableAccounts::contains($account->id)) {
                throw new SalesRuleException(
                    "Account {$account->account_code} is not a receivable account, so it cannot carry a client.",
                    'PARTY_ON_NON_RECEIVABLE',
                );
            }

            // A client of another unit must still be checked, so no unit scope here.
            $clientAccountId = Client::withoutGlobalScopes()->whereKey($clientId)->value('account_id');

            if ($clientAccountId !== null && $clientAccountId !== $account->id) {
                throw new SalesRuleException(
                    "The client is bound to a different receivable sub-account than {$account->account_code}.",
                    'PARTY_ACCOUNT_MISMATCH',
                );
            }
        }

        $this->validateEmployeePartyLines($lines, $accounts, $isManual);
    }

    /**
     * W5-00: the employee party belongs to the employee advances subtree (1215)
     * and is required there, so every advance balance can be traced to a person.
     * It is refused on any other account. Checked before any write.
     *
     * One exception: 1215 was a plain postable leaf before W5-00, so it may hold
     * untagged balances. A manual entry may credit such a balance with no
     * employee, up to what is left of it, so it can be reclassified to a person
     * or written off; the untagged balance can only fall to zero, never go
     * negative or grow.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, Account>  $accounts  the accounts postJournal already resolved, keyed by code
     */
    private function validateEmployeePartyLines(array $lines, array $accounts, bool $isManual): void
    {
        $advanceIds = EmployeeAdvanceAccounts::subtreeIds();

        /** @var array<string, array{account: Account, unit: string|null, credit: float}> $untaggedCredits */
        $untaggedCredits = [];

        foreach ($lines as $line) {
            $employeeId = $line['employee_id'] ?? null;
            $account = $accounts[$line['account_code']];
            $isAdvance = in_array($account->id, $advanceIds, true);

            if ($employeeId === null) {
                if (! $isAdvance) {
                    continue;
                }

                $credit = round((float) ($line['credit'] ?? 0), 4);

                if ($isManual && $credit > 0) {
                    $unitId = $line['operating_unit_id'] ?? null;
                    $key = $account->id.'|'.($unitId ?? '');
                    $untaggedCredits[$key] ??= ['account' => $account, 'unit' => $unitId, 'credit' => 0.0];
                    $untaggedCredits[$key]['credit'] += $credit;

                    continue;
                }

                throw new LedgerRuleException(
                    "Account {$account->account_code} ({$account->name}) needs the employee the advance belongs to.",
                    'PARTY_EMPLOYEE_REQUIRED',
                    ['account_code' => $account->account_code],
                );
            }

            if (! $isAdvance) {
                throw new LedgerRuleException(
                    "Account {$account->account_code} is not an employee advances account, so it cannot carry an employee.",
                    'PARTY_ON_NON_EMPLOYEE_ACCOUNT',
                    ['account_code' => $account->account_code],
                );
            }

            // Only the unit scope is lifted: the line's unit is checked against the
            // employee's own below. Deleted employees are loaded so a repayment
            // from a former employee can still settle their advance.
            $employee = Employee::withoutGlobalScope(OperatingUnitScope::class)
                ->withTrashed()
                ->select(['id', 'operating_unit_id', 'deleted_at'])
                ->find($employeeId);

            if ($employee === null) {
                throw new LedgerRuleException('The employee on the journal line does not exist.', 'EMPLOYEE_NOT_FOUND');
            }

            if ($employee->trashed() && (float) ($line['debit'] ?? 0) > 0) {
                throw new LedgerRuleException(
                    'The employee was deleted, so no new advance can be charged to them; only repayments may post.',
                    'PARTY_EMPLOYEE_DELETED',
                );
            }

            // The advance belongs to the employee's unit, where payroll recovers it.
            $lineUnitId = $line['operating_unit_id'] ?? null;
            if ($lineUnitId !== null && $lineUnitId !== $employee->operating_unit_id) {
                throw new LedgerRuleException(
                    "The employee belongs to another operating unit than the line on account {$account->account_code}.",
                    'PARTY_EMPLOYEE_UNIT_MISMATCH',
                    ['account_code' => $account->account_code],
                );
            }
        }

        foreach ($untaggedCredits as ['account' => $account, 'unit' => $unitId, 'credit' => $credit]) {
            $untagged = round((float) JournalLine::query()
                ->posted()
                ->where('account_id', $account->id)
                ->whereNull('employee_id')
                ->when(
                    $unitId === null,
                    fn ($query) => $query->whereNull('operating_unit_id'),
                    fn ($query) => $query->where('operating_unit_id', $unitId),
                )
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
                ->value('balance'), 4);

            if ($credit - $untagged > self::BALANCE_TOLERANCE) {
                throw new LedgerRuleException(
                    "Account {$account->account_code} ({$account->name}) needs the employee the advance belongs to; "
                    ."an untagged credit may only clear the {$untagged} posted there before advances named the employee.",
                    'PARTY_EMPLOYEE_REQUIRED',
                    ['account_code' => $account->account_code, 'untagged_balance' => $untagged],
                );
            }
        }
    }

    /**
     * Postings land on leaf accounts only. Manual entries may still name the
     * receivables root, which debtor statements read for clients that were
     * never given a sub-account.
     */
    private function resolvePostableAccount(string $code, bool $isManual): Account
    {
        $account = $this->resolveAccount($code);

        if ($account->isFrozen()) {
            throw new LedgerRuleException(
                "Account {$account->account_code} ({$account->name}) is frozen and takes no new postings.",
                'ACCOUNT_FROZEN',
                ['account_code' => $account->account_code],
            );
        }

        if ($isManual && $code === ReceivableAccounts::ROOT_CODE) {
            return $account;
        }

        if (Account::query()->where('parent_account_id', $account->id)->exists()) {
            throw new HeaderAccountPostingException($account);
        }

        return $account;
    }

    private function resolveAccount(string $code): Account
    {
        $account = Account::where('account_code', $code)->first();

        if ($account === null) {
            throw new MissingAccountException(
                "The chart of accounts has no account {$code}. Seed the chart of accounts before posting."
            );
        }

        return $account;
    }

    /**
     * The sequence year follows the entry date, not the posting date — a
     * backdated correction must count against its own year or two of them
     * would collide on the same reference.
     */
    private function nextReference(string $entryDate): string
    {
        return $this->documentNumbers->next(
            DocumentNumberService::JOURNAL_ENTRY,
            (int) Carbon::parse($entryDate)->format('Y'),
        );
    }
}
