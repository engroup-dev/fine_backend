<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W5-00: re-parent 1214 (العهد المالية) and 1215 (سلف الموظفين) from 121 (the
 * treasury node) to 12 on existing databases. Codes are unchanged and the rows
 * are updated in place, so their postings and any sub-accounts stay put.
 * Treasury checks walk the 121 subtree by parent, so vouchers and cheques may
 * now use these accounts and the dashboard no longer counts them as cash.
 *
 * ChartOfAccountsSeeder lists the new parent for fresh databases. This
 * migration moves only these two rows instead of re-running the seeder, which
 * would also prune unused hand-made accounts (orphaning the posted children of
 * a pruned header) and reset renamed catalog accounts (see W4-01's
 * 2026_10_29_120100_add_stock_count_surplus_account). A 1214 or 1215 that is
 * no longer directly under 121 was placed by hand and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        // Per chart: 121 and 12 of that chart.
        Account::query()->where('account_code', '121')->get()->each(function (Account $treasuryNode): void {
            $currentAssets = Account::query()
                ->where('chart_of_accounts_id', $treasuryNode->chart_of_accounts_id)
                ->where('account_code', '12')
                ->value('id');

            if ($currentAssets === null) {
                return;
            }

            Account::query()
                ->where('chart_of_accounts_id', $treasuryNode->chart_of_accounts_id)
                ->whereIn('account_code', ['1214', '1215'])
                ->where('parent_account_id', $treasuryNode->id)
                ->update(['parent_account_id' => $currentAssets]);
        });
    }

    public function down(): void
    {
        // The re-parented accounts may carry postings made while outside the treasury node.
    }
};
