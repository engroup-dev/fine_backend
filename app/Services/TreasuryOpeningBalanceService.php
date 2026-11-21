<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalEntryType;
use App\Exceptions\LedgerRuleException;
use App\Models\CashAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\LedgerAccounts;
use App\Support\TreasuryAccounts;

/**
 * TRS-01: a treasury's balance is its ledger balance, so the opening figure
 * typed when a treasury is created has to reach the ledger. It posts once,
 * against equity 34 (أرصدة افتتاحية): debit the treasury for a positive
 * figure, credit it for a negative one. Until it posts, the treasury shows 0,
 * transfers out of it fail on balance and its first bank reconciliation can
 * never reach a zero difference.
 */
final class TreasuryOpeningBalanceService
{
    public const SOURCE_TYPE = 'CashAccountOpening';

    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function hasOpening(CashAccount $cashAccount): bool
    {
        return JournalEntry::query()
            ->where('source_document_type', self::SOURCE_TYPE)
            ->where('source_document_id', $cashAccount->id)
            ->exists();
    }

    /**
     * Post the treasury's opening balance. Returns null when there is nothing
     * to post (a zero figure, or the opening was already posted).
     *
     * @throws LedgerRuleException
     */
    public function post(CashAccount $cashAccount, ?float $fxRate = null, ?string $entryDate = null, ?User $user = null): ?JournalEntry
    {
        $amount = round((float) $cashAccount->balance, 4);

        if ($amount === 0.0 || $this->hasOpening($cashAccount)) {
            return null;
        }

        $cashAccount->loadMissing('account');
        $treasuryCode = TreasuryAccounts::codeFor($cashAccount);

        $currency = strtoupper(trim((string) $cashAccount->currency));
        $isForeign = $currency !== '' && $currency !== 'LYD';

        if ($isForeign && ($fxRate === null || $fxRate <= 0)) {
            throw new LedgerRuleException(
                "An exchange rate greater than zero is required for the {$currency} opening balance of '{$cashAccount->name}'.",
                'FX_RATE_REQUIRED',
            );
        }

        $original = abs($amount);
        $amountLyd = $isForeign ? round($original * (float) $fxRate, 4) : $original;
        $side = $amount > 0 ? 'debit' : 'credit';
        $otherSide = $amount > 0 ? 'credit' : 'debit';
        $memo = "رصيد افتتاحي — {$cashAccount->name}";

        $treasuryLine = [
            'account_code' => $treasuryCode,
            'operating_unit_id' => $cashAccount->operating_unit_id,
            'memo' => $memo,
            $side => $amountLyd,
        ];
        if ($isForeign) {
            $treasuryLine['currency'] = $currency;
            $treasuryLine['original_amount'] = $original;
            $treasuryLine['fx_rate'] = $fxRate;
        }

        return $this->accountingService->postJournal(
            description: $memo,
            lines: [
                $treasuryLine,
                [
                    'account_code' => LedgerAccounts::OPENING_BALANCES,
                    'operating_unit_id' => $cashAccount->operating_unit_id,
                    'memo' => $memo,
                    $otherSide => $amountLyd,
                ],
            ],
            sourceType: self::SOURCE_TYPE,
            sourceId: $cashAccount->id,
            userId: $user?->id,
            entryDate: $entryDate,
            entryType: JournalEntryType::Opening,
        );
    }
}
