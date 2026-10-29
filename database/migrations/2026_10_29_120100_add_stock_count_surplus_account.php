<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W4-01 (decision #13): provisions leaf account 45 (أرباح فروقات الجرد) under
 * 4 (revenue) on existing databases. Approved stock count surpluses are
 * credited there. ChartOfAccountsSeeder lists 45 for fresh databases; this
 * migration adds only that one row instead of re-running the seeder, which
 * would also prune unused hand-made accounts and reset renamed catalog ones.
 *
 * Account codes can be created by hand, so a 45 that already means something
 * else (another name or type, or a header) is never repurposed: the migration
 * stops and the owner decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        Account::query()->where('account_code', '45')->get()->each(function (Account $existing): void {
            $isOurs = $existing->name === 'أرباح فروقات الجرد'
                && $existing->type === 'revenue'
                && Account::query()->where('parent_account_id', $existing->id)->doesntExist();

            if (! $isOurs) {
                throw new RuntimeException(
                    "Account 45 already exists as \"{$existing->name}\" ({$existing->type}); the stock count surplus account needs that code. Renumber it, then migrate again."
                );
            }
        });

        // One 45 under each chart's revenue root (4).
        Account::query()->where('account_code', '4')->get()->each(function (Account $revenue): void {
            Account::query()->firstOrCreate(
                ['chart_of_accounts_id' => $revenue->chart_of_accounts_id, 'account_code' => '45'],
                [
                    'name' => 'أرباح فروقات الجرد',
                    'type' => 'revenue',
                    'currency' => $revenue->currency,
                    'parent_account_id' => $revenue->id,
                ],
            );
        });
    }

    public function down(): void
    {
        // The account provisioned here may already carry postings.
    }
};
