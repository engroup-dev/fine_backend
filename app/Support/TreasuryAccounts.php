<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;

/**
 * The one place a treasury or bank account is turned into its ledger code.
 * Every operation that moves cash — sales, collections, payroll, settlements,
 * overhead, fixed assets, cash stock intake — names the treasury it uses and
 * posts to the leaf account behind it, never to the 12/121 headers.
 */
final class TreasuryAccounts
{
    /**
     * CR 8: banks live under 1212 (حسابات المصارف) and nothing else does. Cash
     * boxes sit under 1211 (الخزائن النقدية), and custody boxes under 1214.
     */
    public const BANK_ROOT = '1212';

    /**
     * Whether a ledger account suits the treasury kind: a bank must be in the
     * bank branch, and a cash box must not be.
     */
    public static function accountMatchesKind(string $accountId, string $kind): bool
    {
        $inBankBranch = in_array($accountId, AccountTree::subtreeIds(self::BANK_ROOT), true);

        return $kind === CashAccount::KIND_BANK ? $inBankBranch : ! $inBankBranch;
    }

    /**
     * A treasury that is not linked to the chart cannot move money — posting it
     * to a header account would hide it from every cash report.
     *
     * @throws SalesRuleException
     */
    public static function codeFor(CashAccount $cashAccount): string
    {
        $code = $cashAccount->account?->account_code;

        if ($code === null) {
            throw new SalesRuleException(
                "Treasury '{$cashAccount->name}' is not linked to a ledger account yet.",
                'TREASURY_NOT_LINKED',
            );
        }

        return $code;
    }

    /**
     * Resolve a treasury picked by id on a request.
     *
     * @throws SalesRuleException
     */
    public static function codeForId(?string $cashAccountId): string
    {
        $cashAccount = $cashAccountId !== null
            ? CashAccount::with('account')->find($cashAccountId)
            : null;

        if ($cashAccount === null) {
            throw new SalesRuleException('Choose the treasury the money comes from or goes into.', 'TREASURY_REQUIRED');
        }

        return self::codeFor($cashAccount);
    }
}
