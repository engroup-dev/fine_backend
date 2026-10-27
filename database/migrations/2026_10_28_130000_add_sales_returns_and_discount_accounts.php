<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W3-05 / FR-INV-014, FR-INV-015, BR-INV-017 (decisions #8, #9):
 *
 * Provisions sales returns leaves 40403–40404 under 404 and the granted
 * discount header 407 with leaves 40701–40704 under 4 via ChartOfAccountsSeeder,
 * then points every operating unit on revenue account 4020N (N=1..4) to 4040N
 * for SalesReturns and 4070N for GrantedDiscount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        (new ChartOfAccountsSeeder)->run();

        if (! Schema::hasTable('operating_units') || ! Schema::hasTable('operating_unit_accounts')) {
            return;
        }

        OperatingUnit::withoutGlobalScopes()
            ->with('revenueAccount')
            ->whereNotNull('revenue_account_id')
            ->get()
            ->each(function (OperatingUnit $unit): void {
                $revenueCode = $unit->revenueAccount?->account_code;

                if ($revenueCode === null || preg_match('/^4020([1-4])$/', (string) $revenueCode, $matches) !== 1) {
                    return;
                }

                $suffix = $matches[1];

                foreach ([
                    InventoryEventType::SalesReturns->value => '4040'.$suffix,
                    InventoryEventType::GrantedDiscount->value => '4070'.$suffix,
                ] as $event => $code) {
                    $accountId = Account::query()->where('account_code', $code)->value('id');

                    if ($accountId !== null) {
                        OperatingUnitAccount::query()->updateOrCreate(
                            ['operating_unit_id' => $unit->id, 'event_type' => $event],
                            ['account_id' => $accountId],
                        );
                    }
                }
            });
    }

    public function down(): void
    {
        // Accounts and event mappings provisioned here may already carry postings.
    }
};
