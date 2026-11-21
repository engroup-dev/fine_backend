<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Support\LedgerAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions two leaf accounts on existing databases:
 * - 34 (أرصدة افتتاحية) under 3: credited when a treasury's opening balance is posted.
 * - 46 (فوائد وإيرادات بنكية) under 4: bank interest added during a bank reconciliation.
 *
 * ChartOfAccountsSeeder lists both for fresh databases. As with account 45, a
 * code that already means something else is never repurposed: the migration
 * stops and the owner decides.
 */
return new class extends Migration
{
    /**
     * @var list<array{code: string, name: string, type: string, parent: string}>
     */
    private const ACCOUNTS = [
        ['code' => LedgerAccounts::OPENING_BALANCES, 'name' => 'أرصدة افتتاحية', 'type' => 'equity', 'parent' => '3'],
        ['code' => LedgerAccounts::BANK_INTEREST, 'name' => 'فوائد وإيرادات بنكية', 'type' => 'revenue', 'parent' => '4'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        foreach (self::ACCOUNTS as $wanted) {
            Account::query()->where('account_code', $wanted['code'])->get()->each(function (Account $existing) use ($wanted): void {
                $isOurs = $existing->name === $wanted['name']
                    && $existing->type === $wanted['type']
                    && Account::query()->where('parent_account_id', $existing->id)->doesntExist();

                if (! $isOurs) {
                    throw new RuntimeException(
                        "Account {$wanted['code']} already exists as \"{$existing->name}\" ({$existing->type}); \"{$wanted['name']}\" needs that code. Renumber it, then migrate again."
                    );
                }
            });
        }

        foreach (self::ACCOUNTS as $wanted) {
            Account::query()->where('account_code', $wanted['parent'])->get()->each(function (Account $parent) use ($wanted): void {
                Account::query()->firstOrCreate(
                    ['chart_of_accounts_id' => $parent->chart_of_accounts_id, 'account_code' => $wanted['code']],
                    [
                        'name' => $wanted['name'],
                        'type' => $wanted['type'],
                        'currency' => $parent->currency,
                        'parent_account_id' => $parent->id,
                    ],
                );
            });
        }
    }

    public function down(): void
    {
        // The accounts provisioned here may already carry postings.
    }
};
