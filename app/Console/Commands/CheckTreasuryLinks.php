<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashAccount;
use App\Support\TreasuryAccounts;
use Illuminate\Console\Command;

/**
 * CR 8: banks belong under 1212 and cash boxes outside it. New links are
 * validated; this lists the existing treasuries that are unlinked or linked
 * in the other branch, so the owner can fix them. It never changes anything.
 */
final class CheckTreasuryLinks extends Command
{
    protected $signature = 'ledger:check-treasury-links';

    protected $description = 'List treasuries that are unlinked or linked outside their cash/bank branch (read-only)';

    public function handle(): int
    {
        $rows = [];

        foreach (CashAccount::with('account')->orderBy('kind')->orderBy('name')->get() as $treasury) {
            $kindLabel = $treasury->kind === CashAccount::KIND_BANK ? 'bank' : 'cash';

            if ($treasury->account_id === null) {
                $rows[] = [$treasury->name, $kindLabel, '—', 'not linked to a ledger account'];

                continue;
            }

            if (! TreasuryAccounts::accountMatchesKind($treasury->account_id, $treasury->kind)) {
                $rows[] = [
                    $treasury->name,
                    $kindLabel,
                    (string) $treasury->account?->account_code,
                    $treasury->kind === CashAccount::KIND_BANK
                        ? 'a bank should be under '.TreasuryAccounts::BANK_ROOT
                        : 'a cash box should not be under '.TreasuryAccounts::BANK_ROOT,
                ];
            }
        }

        if ($rows === []) {
            $this->info('All treasuries are linked in the right branch.');

            return self::SUCCESS;
        }

        $this->warn('Some treasuries need attention (nothing was changed):');
        $this->table(['Treasury', 'Kind', 'Account', 'Problem'], $rows);

        return self::SUCCESS;
    }
}
