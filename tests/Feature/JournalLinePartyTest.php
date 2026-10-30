<?php

declare(strict_types=1);

use App\Exceptions\LedgerRuleException;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\SaleCheckoutService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    linkRevenueAccount($this->unit, '40203');
    $entity = Entity::create(['name' => 'شركة البناء الراقي', 'entity_type' => 'organization', 'phone' => '0910000001']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 50000.0,
        'current_balance' => 0.0,
    ]);

    $this->parent = Account::where('account_code', '122')->firstOrFail();
    $this->child = Account::create([
        'chart_of_accounts_id' => $this->parent->chart_of_accounts_id,
        'account_code' => '122001',
        'name' => 'Client sub-account',
        'type' => $this->parent->type,
        'section' => $this->parent->section,
        'currency' => $this->parent->currency,
        'parent_account_id' => $this->parent->id,
    ]);
});

function postSale(string $debitCode, ?string $clientId, string $creditCode = '40201', ?string $creditClient = null): JournalEntry
{
    return app(AccountingService::class)->postJournal('Sale', [
        ['account_code' => $debitCode, 'debit' => 100, 'client_id' => $clientId],
        ['account_code' => $creditCode, 'credit' => 100, 'client_id' => $creditClient],
    ], isManual: true);
}

test('stores client_id on a receivable line', function () {
    postSale('122', $this->client->id);

    $line = JournalLine::where('account_id', $this->parent->id)->firstOrFail();
    expect($line->client_id)->toBe($this->client->id);
});

test('refuses client_id on a non-receivable account', function () {
    try {
        postSale('122', null, '40201', $this->client->id);
        $this->fail('Expected SalesRuleException');
    } catch (SalesRuleException $e) {
        expect($e->errorCode)->toBe('PARTY_ON_NON_RECEIVABLE');
    }

    expect(JournalEntry::count())->toBe(0);
});

test('refuses mismatched sub-account', function () {
    $this->client->update(['account_id' => $this->child->id]);

    try {
        postSale('122', $this->client->id);
        $this->fail('Expected SalesRuleException');
    } catch (SalesRuleException $e) {
        expect($e->errorCode)->toBe('PARTY_ACCOUNT_MISMATCH');
    }

    expect(JournalEntry::count())->toBe(0);
});

test('accepts descendant of 122', function () {
    $this->client->update(['account_id' => $this->child->id]);

    postSale('122001', $this->client->id);

    expect(JournalLine::where('account_id', $this->child->id)->value('client_id'))->toBe($this->client->id);
});

function sellOnTerms(string $method, ?string $cashAccountId = null): SalesOrder
{
    $warehouse = Warehouse::create(['operating_unit_id' => test()->unit->id, 'name' => 'WH', 'code' => 'WH-1']);
    $item = InventoryItem::create([
        'name' => 'Foam', 'code' => 'F-1', 'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each', 'selling_price' => 100,
    ]);
    StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
        'lot_number' => 'L-1', 'quantity' => 10, 'unit_cost' => 40, 'status' => 'available',
    ]);
    $seller = User::factory()->create(['must_change_password' => false]);

    return app(SaleCheckoutService::class)->checkout(test()->unit, [
        'client_id' => test()->client->id,
        'payment_method' => $method,
        'cash_account_id' => $cashAccountId,
        'lines' => [['inventory_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 100]],
    ], $seller);
}

test('credit sale tags receivable line with client on its own sub-account', function () {
    seedUnitAccounts($this->unit);

    $order = sellOnTerms('receivable');

    $tagged = JournalLine::whereNotNull('client_id')->get();
    expect($tagged)->toHaveCount(1)
        ->and($tagged->first()->client_id)->toBe($this->client->id)
        ->and((float) $tagged->first()->debit)->toBe(200.0)
        // The client had no sub-account, so the sale gave it one instead of
        // posting to the 122 header.
        ->and($tagged->first()->account_id)->toBe($this->client->fresh()->account_id)
        ->and($tagged->first()->account->parent_account_id)->toBe($this->parent->id)
        ->and($order->status->isSold())->toBeTrue();
});

test('cash sale to named client posts no client-tagged line', function () {
    seedUnitAccounts($this->unit);
    $cash = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    sellOnTerms('cash', $cash->id);

    expect(JournalLine::count())->toBeGreaterThan(0)
        ->and(JournalLine::whereNotNull('client_id')->count())->toBe(0);
});

test('collection tags receivable credit line with client', function () {
    seedUnitAccounts($this->unit);
    $cash = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);
    $order = sellOnTerms('receivable');
    $collector = User::factory()->create(['must_change_password' => false]);

    app(SaleCheckoutService::class)->collectPayment($order, 50.0, 'cash', $cash->id, $collector);

    $collection = JournalLine::whereNotNull('client_id')->where('credit', '>', 0)->get();
    expect($collection)->toHaveCount(1)
        ->and((float) $collection->first()->credit)->toBe(50.0)
        ->and($collection->first()->client_id)->toBe($this->client->id)
        ->and(JournalLine::whereNotNull('client_id')->count())->toBe(2);
});

function ownerUser(): User
{
    $owner = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => Role::create(['name' => 'Owner', 'slug' => 'owner'])->id,
        'operating_unit_id' => null,
    ]);

    return $owner;
}

test('manual entry accepts client_id on 122', function () {
    $this->actingAs(ownerUser())->postJson('/api/v1/journal-entries', [
        'description' => 'Opening balance',
        'lines' => [
            ['account_code' => '122', 'debit' => 100, 'client_id' => $this->client->id],
            ['account_code' => '40201', 'credit' => 100],
        ],
    ])->assertCreated();

    expect(JournalLine::where('account_id', $this->parent->id)->value('client_id'))->toBe($this->client->id);
});

test('manual entry with client_id on 41 returns 422 PARTY_ON_NON_RECEIVABLE', function () {
    $this->actingAs(ownerUser())->postJson('/api/v1/journal-entries', [
        'description' => 'Bad party',
        'lines' => [
            ['account_code' => '122', 'debit' => 100],
            ['account_code' => '40201', 'credit' => 100, 'client_id' => $this->client->id],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'PARTY_ON_NON_RECEIVABLE');

    expect(JournalEntry::count())->toBe(0);
});

test('credit sale and collection for a client linked to a non-receivable account still post, untagged', function () {
    seedUnitAccounts($this->unit);
    $other = Account::where('account_code', '123')->firstOrFail();
    $this->client->update(['account_id' => $other->id]);
    $cash = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $order = sellOnTerms('receivable');
    app(SaleCheckoutService::class)->collectPayment($order, 50.0, 'cash', $cash->id, User::factory()->create(['must_change_password' => false]));

    $lines = JournalLine::where('account_id', $other->id)->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('client_id')->filter()->count())->toBe(0);
});

test('a client linked to the employee advances account (1215) can no longer sell on terms', function () {
    // W5-00: 1215 lines need an employee, and sales never carry one, so the
    // misconfiguration is refused at posting time rather than posting untagged.
    seedUnitAccounts($this->unit);
    $this->client->update(['account_id' => Account::where('account_code', '1215')->value('id')]);

    expect(fn () => sellOnTerms('receivable'))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_REQUIRED');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('manual entry checks the sub-account of a client from another unit', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Foam Unit', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $entity = Entity::create(['name' => 'Foam client', 'entity_type' => 'organization', 'phone' => '0910000009']);
    $foreign = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $otherUnit->id,
        'credit_limit' => 1.0, 'current_balance' => 0.0, 'account_id' => $this->child->id,
    ]);

    $this->actingAs(ownerUser())
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/journal-entries', [
            'description' => 'Wrong account',
            'lines' => [
                ['account_code' => '122', 'debit' => 100, 'client_id' => $foreign->id],
                ['account_code' => '40201', 'credit' => 100],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'PARTY_ACCOUNT_MISMATCH');

    expect(JournalEntry::count())->toBe(0);
});
