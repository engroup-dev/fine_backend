<?php

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Seed the standard per-unit chart-of-accounts mapping for one or more
 * operating units. Idempotent: re-running is a no-op because the unique
 * index on (operating_unit_id, event_type) blocks duplicates.
 *
 * Mirrors `SystemBootstrapSeeder::seedPerUnitInventoryAccounts()` so tests
 * reflect what a fresh `migrate:fresh --seed` install looks like. Override
 * individual events via the third argument when a test cares about a
 * specific code (e.g. `['purchases' => '1134']`).
 *
 * @param  OperatingUnit|array<int, OperatingUnit>  $units
 * @param  array<string, string>  $overrides  event value => account_code
 */
function seedUnitAccounts(OperatingUnit|array $units, array $overrides = []): void
{
    $units = is_array($units) ? $units : [$units];

    $defaults = [
        InventoryEventType::Opening->value => '111',
        InventoryEventType::Ending->value => '111',
        InventoryEventType::Purchases->value => '111',
        InventoryEventType::PurchaseReturns->value => '111',
        InventoryEventType::Cogs->value => '51',
        InventoryEventType::Waste->value => '52',
        InventoryEventType::TransportIn->value => '406',
    ];

    foreach ($units as $unit) {
        $merged = array_merge($defaults, $overrides);
        foreach ($merged as $event => $code) {
            if (OperatingUnitAccount::where('operating_unit_id', $unit->id)
                ->where('event_type', $event)->exists()) {
                continue;
            }
            $account = Account::where('account_code', $code)->first();
            if ($account === null) {
                continue;
            }
            OperatingUnitAccount::create([
                'operating_unit_id' => $unit->id,
                'event_type' => $event,
                'account_id' => $account->id,
            ]);
        }
    }
}

/**
 * Link a unit to its sales revenue leaf — a sale from an unlinked unit is
 * refused with UNIT_REVENUE_NOT_LINKED.
 */
function linkRevenueAccount(OperatingUnit $unit, string $code): void
{
    $unit->update(['revenue_account_id' => Account::where('account_code', $code)->value('id')]);
}

/**
 * A treasury linked to its ledger leaf — every cash operation names one and
 * posts to that leaf, never to the 12 header.
 */
function linkedTreasury(OperatingUnit $unit, string $code = '121101', string $kind = 'cash'): CashAccount
{
    return CashAccount::create([
        'operating_unit_id' => $unit->id,
        'name' => "Treasury {$code}",
        'kind' => $kind,
        'account_id' => Account::where('account_code', $code)->value('id'),
        'currency' => 'LYD',
        'balance' => 0,
    ]);
}

/**
 * The id of a linked treasury for service-level fixtures that only need some
 * treasury to move cash through. Created on the first unit on first use.
 */
function treasuryId(string $code = '121101'): string
{
    $accountId = Account::where('account_code', $code)->value('id');

    return CashAccount::firstOrCreate(
        ['account_id' => $accountId],
        [
            'operating_unit_id' => OperatingUnit::withoutGlobalScopes()->value('id'),
            'name' => "Treasury {$code}",
            'kind' => 'cash',
            'currency' => 'LYD',
            'balance' => 0,
        ],
    )->id;
}

/** The ledger leaf a direct PO payment fixture pays from. */
function cashLeafId(string $code = '121101'): string
{
    return Account::where('account_code', $code)->value('id');
}

/**
 * W6-06: an item's category is required, so fixtures that create items
 * through the API name this one.
 */
function itemCategoryId(): string
{
    return ItemCategory::firstOrCreate(['code' => 'CAT-TEST'], ['name' => 'Test category'])->id;
}
