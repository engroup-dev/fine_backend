<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->unitA = $makeUnit('Showroom A');
    $this->unitB = $makeUnit('Showroom B');
    linkRevenueAccount($this->unitA, '40204');

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
    $this->makeUser = $makeUser;
    $this->owner = $makeUser('owner', null);
    $this->cashier = $makeUser('pos-cashier', $this->unitA->id);

    $this->item = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-01', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Store WH', 'code' => 'WH-A']);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unitA->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    StockLot::create([
        'warehouse_id' => $warehouse->id, 'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-01', 'quantity' => 50, 'unit_cost' => 50, 'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->wholesale = ClientGroup::factory()->wholesale()->create(['code' => 'WHL']);
    $this->retail = ClientGroup::factory()->create(['code' => 'RTL', 'name' => 'Retail']);

    $this->api = fn (?User $user = null, ?OperatingUnit $unit = null) => $this->actingAs($user ?? $this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unitA)->id]);

    $this->makeClient = function (string $name, ?ClientGroup $group): Client {
        $entity = Entity::create(['entity_type' => 'organization', 'name' => $name]);

        return Client::create([
            'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
            'client_group_id' => $group?->id, 'credit_limit' => 100000, 'current_balance' => 0,
        ]);
    };

    $this->sellOnCredit = fn (Client $client, float $price) => ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $client->id,
        'payment_method' => 'receivable',
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => $price]],
    ])->assertCreated();
});

test('groups can be created, listed, edited and removed', function () {
    $group = ($this->api)()->postJson('/api/v1/client-groups', [
        'code' => 'CON', 'name' => 'Contractors', 'default_credit_limit' => 2500,
        'default_payment_terms_days' => 45, 'default_price_tier' => 'wholesale',
    ])->assertCreated()->json('data');

    expect($group['default_credit_limit'])->toEqual(2500)
        ->and($group['default_payment_terms_days'])->toBe(45)
        ->and($group['default_price_tier'])->toBe('wholesale');

    // Defaults fill in what the caller leaves out.
    $bare = ($this->api)()->postJson('/api/v1/client-groups', ['code' => 'HTL', 'name' => 'Hotels'])
        ->assertCreated()->json('data');
    expect($bare['default_credit_limit'])->toEqual(0)
        ->and($bare['default_payment_terms_days'])->toBe(30)
        ->and($bare['default_price_tier'])->toBe('retail');

    $codes = collect(($this->api)()->getJson('/api/v1/client-groups')->assertSuccessful()->json('data'))->pluck('code')->all();
    expect($codes)->toBe(['CON', 'HTL', 'RTL', 'WHL']);

    ($this->api)()->putJson("/api/v1/client-groups/{$group['id']}", [
        'name' => 'Building contractors', 'record_version' => $group['record_version'],
    ])->assertSuccessful()->assertJsonPath('data.name', 'Building contractors');

    ($this->api)()->putJson("/api/v1/client-groups/{$group['id']}", [
        'name' => 'Stale', 'record_version' => $group['record_version'],
    ])->assertStatus(409);

    ($this->api)()->deleteJson("/api/v1/client-groups/{$group['id']}")->assertSuccessful();
    expect(ClientGroup::find($group['id']))->toBeNull();

    // A removed group's code can be reused.
    ($this->api)()->postJson('/api/v1/client-groups', ['code' => 'CON', 'name' => 'Contractors again'])->assertCreated();
});

test('group codes are unique and the price tier must be retail or wholesale', function () {
    ($this->api)()->postJson('/api/v1/client-groups', ['code' => 'WHL', 'name' => 'Duplicate'])
        ->assertStatus(422)->assertJsonValidationErrors('code');

    ($this->api)()->postJson('/api/v1/client-groups', ['code' => 'VIP', 'name' => 'VIP', 'default_price_tier' => 'vip'])
        ->assertStatus(422)->assertJsonValidationErrors('default_price_tier');

    // Saving a group under its own code is fine.
    ($this->api)()->putJson("/api/v1/client-groups/{$this->wholesale->id}", [
        'code' => 'WHL', 'name' => 'Wholesale traders', 'record_version' => $this->wholesale->record_version,
    ])->assertSuccessful();
});

test('a group with clients in any unit cannot be removed', function () {
    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Far Away Co']);
    Client::create(['entity_id' => $entity->id, 'operating_unit_id' => $this->unitB->id, 'client_group_id' => $this->wholesale->id]);

    ($this->api)()->deleteJson("/api/v1/client-groups/{$this->wholesale->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CLIENT_GROUP_IN_USE')
        ->assertJsonPath('clients_count', 1);

    expect($this->wholesale->fresh())->not->toBeNull();
});

test('a cashier reads groups but cannot change them', function () {
    ($this->api)($this->cashier)->getJson('/api/v1/client-groups')->assertSuccessful()->assertJsonCount(2, 'data');

    ($this->api)($this->cashier)->postJson('/api/v1/client-groups', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    ($this->api)($this->cashier)->putJson("/api/v1/client-groups/{$this->retail->id}", [
        'name' => 'X', 'record_version' => $this->retail->record_version,
    ])->assertForbidden();
    ($this->api)($this->cashier)->deleteJson("/api/v1/client-groups/{$this->retail->id}")->assertForbidden();
});

test('company-wide managers change groups; unit-scoped managers and other roles cannot', function () {
    foreach (['store-manager', 'accounting-manager', 'unit_manager', 'manager'] as $index => $slug) {
        $manager = ($this->makeUser)($slug, null);

        $group = ($this->api)($manager)->postJson('/api/v1/client-groups', ['code' => "G{$index}", 'name' => $slug])
            ->assertCreated()->json('data');
        ($this->api)($manager)->putJson("/api/v1/client-groups/{$group['id']}", [
            'name' => "{$slug} edited", 'record_version' => $group['record_version'],
        ])->assertSuccessful()->assertJsonPath('data.name', "{$slug} edited");
        ($this->api)($manager)->deleteJson("/api/v1/client-groups/{$group['id']}")->assertSuccessful();
    }

    // A manager limited to one unit reads groups but may not change them.
    $unitManager = ($this->makeUser)('store-manager', $this->unitA->id);
    ($this->api)($unitManager)->getJson('/api/v1/client-groups')->assertSuccessful();
    ($this->api)($unitManager)->postJson('/api/v1/client-groups', ['code' => 'U1', 'name' => 'Unit'])
        ->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');
    ($this->api)($unitManager)->putJson("/api/v1/client-groups/{$this->retail->id}", [
        'name' => 'X', 'record_version' => $this->retail->record_version,
    ])->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');
    ($this->api)($unitManager)->deleteJson("/api/v1/client-groups/{$this->retail->id}")
        ->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');

    // A unit-scoped manager who also holds a non-write role company-wide is
    // still refused: the company-wide assignment itself must be a write role.
    foreach (['procurement-manager', 'pos-cashier'] as $otherSlug) {
        $mixed = ($this->makeUser)('store-manager', $this->unitA->id);
        UserRole::create([
            'user_id' => $mixed->id,
            'role_id' => Role::firstOrCreate(['slug' => $otherSlug], ['name' => $otherSlug])->id,
            'operating_unit_id' => null,
        ]);
        ($this->api)($mixed)->getJson('/api/v1/client-groups')->assertSuccessful();
        ($this->api)($mixed)->postJson('/api/v1/client-groups', ['code' => 'M1', 'name' => 'Mixed'])
            ->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');
        ($this->api)($mixed)->putJson("/api/v1/client-groups/{$this->retail->id}", [
            'name' => 'X', 'record_version' => $this->retail->record_version,
        ])->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');
        ($this->api)($mixed)->deleteJson("/api/v1/client-groups/{$this->retail->id}")
            ->assertForbidden()->assertJsonPath('code', 'CLIENT_GROUP_FORBIDDEN');
    }

    // A procurement manager works with clients but is not on the write list.
    $buyer = ($this->makeUser)('procurement-manager', null);
    ($this->api)($buyer)->getJson('/api/v1/client-groups')->assertSuccessful();
    ($this->api)($buyer)->postJson('/api/v1/client-groups', ['code' => 'P1', 'name' => 'P'])->assertForbidden();

    expect(ClientGroup::pluck('code')->sort()->values()->all())->toBe(['RTL', 'WHL']);
});

test('editing group defaults leaves existing clients as they are', function () {
    $member = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Member', 'client_group_id' => $this->wholesale->id,
    ])->assertCreated()->json('data');

    ($this->api)()->putJson("/api/v1/client-groups/{$this->wholesale->id}", [
        'default_credit_limit' => 9999, 'default_payment_terms_days' => 15,
        'record_version' => $this->wholesale->record_version,
    ])->assertSuccessful();

    $client = Client::findOrFail($member['id']);
    expect((float) $client->credit_limit)->toBe(5000.0)
        ->and($client->payment_terms_days)->toBe(60);

    // Clients registered after the edit take the new defaults.
    $late = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Late member', 'client_group_id' => $this->wholesale->id,
    ])->assertCreated()->json('data');
    expect((float) $late['credit_limit'])->toBe(9999.0)
        ->and($late['payment_terms_days'])->toBe(15);
});

test('a group removed while a client is being registered is refused, not dropped', function () {
    // The removal lands after validation, inside the registration transaction.
    Entity::creating(fn () => $this->retail->delete());

    ($this->api)()->postJson('/api/v1/clients', ['name' => 'Racer', 'client_group_id' => $this->retail->id])
        ->assertStatus(422)->assertJsonValidationErrors('client_group_id');

    expect(Client::count())->toBe(0)
        ->and(Entity::where('name', 'Racer')->exists())->toBeFalse();
});

test('a group removed while a client is being moved to it is refused', function () {
    $client = ($this->makeClient)('Mover', $this->retail);

    $fired = false;
    Client::retrieved(function () use (&$fired) {
        if (! $fired) {
            $fired = true;
            $this->wholesale->delete();
        }
    });

    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'client_group_id' => $this->wholesale->id, 'record_version' => $client->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('client_group_id');

    expect($client->fresh()->client_group_id)->toBe($this->retail->id);
});

test('the database keeps live group codes unique', function () {
    expect(fn () => DB::table('client_groups')->insert([
        'id' => (string) Str::uuid(), 'code' => 'WHL', 'name' => 'Sneaky duplicate',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // A removed group's code may be taken again.
    $this->retail->delete();
    ClientGroup::factory()->create(['code' => 'RTL']);
    expect(ClientGroup::where('code', 'RTL')->count())->toBe(1);
});

test('a new client takes the group defaults for what the caller leaves out', function () {
    $client = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Big Buyer', 'client_group_id' => $this->wholesale->id,
    ])->assertCreated()->json('data');

    expect((float) $client['credit_limit'])->toBe(5000.0)
        ->and($client['payment_terms_days'])->toBe(60)
        ->and($client['client_group_id'])->toBe($this->wholesale->id)
        ->and($client['client_group']['default_price_tier'])->toBe('wholesale');

    $explicit = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Careful Buyer', 'client_group_id' => $this->wholesale->id, 'credit_limit' => 750,
    ])->assertCreated()->json('data');

    expect((float) $explicit['credit_limit'])->toBe(750.0)
        ->and($explicit['payment_terms_days'])->toBe(60);

    // Without a group the old defaults stand.
    $plain = ($this->api)()->postJson('/api/v1/clients', ['name' => 'Walk Up'])->assertCreated()->json('data');
    expect((float) $plain['credit_limit'])->toBe(0.0)
        ->and($plain['payment_terms_days'])->toBe(30)
        ->and($plain['client_group_id'])->toBeNull()
        ->and($plain['client_group'])->toBeNull();
});

test('a removed group cannot be assigned', function () {
    $this->retail->delete();

    ($this->api)()->postJson('/api/v1/clients', ['name' => 'Late', 'client_group_id' => $this->retail->id])
        ->assertStatus(422)->assertJsonValidationErrors('client_group_id');
});

test('moving a client to another group keeps its own limit and terms', function () {
    $client = ($this->makeClient)('Mover', $this->retail);

    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'client_group_id' => $this->wholesale->id, 'record_version' => $client->record_version,
    ])->assertSuccessful()
        ->assertJsonPath('data.client_group_id', $this->wholesale->id)
        ->assertJsonPath('data.client_group.code', 'WHL');

    $client->refresh();
    expect((float) $client->credit_limit)->toBe(100000.0)
        ->and($client->payment_terms_days)->toBe(30);

    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'client_group_id' => null, 'record_version' => $client->record_version,
    ])->assertSuccessful()->assertJsonPath('data.client_group_id', null);
});

test('the client list filters by group', function () {
    $wholesaler = ($this->makeClient)('Wholesaler', $this->wholesale);
    ($this->makeClient)('Shopper', $this->retail);
    ($this->makeClient)('Loner', null);

    $ids = collect(($this->api)()->getJson("/api/v1/clients?client_group_id={$this->wholesale->id}")
        ->assertSuccessful()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$wholesaler->id]);
    expect(($this->api)()->getJson('/api/v1/clients')->json('data'))->toHaveCount(3);
});

test('debtor statements and balances filter by group', function () {
    $wholesaler = ($this->makeClient)('Wholesaler', $this->wholesale);
    $shopper = ($this->makeClient)('Shopper', $this->retail);
    ($this->sellOnCredit)($wholesaler, 300);
    ($this->sellOnCredit)($shopper, 120);

    // A receivable line with no client: the unassigned bucket.
    app(AccountingService::class)->postJournal('Untagged', [
        ['account_code' => '122', 'debit' => 45, 'credit' => 0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '40201', 'debit' => 0, 'credit' => 45, 'operating_unit_id' => $this->unitA->id],
    ], isManual: true);

    $today = now()->toDateString();
    $withoutGroup = ($this->api)()->getJson("/api/v1/reports/debtor-statements?from={$today}&to={$today}&include_unassigned=1")
        ->assertSuccessful()->json();
    expect($withoutGroup['unassigned'])->not->toBeNull()
        ->and((float) $withoutGroup['unassigned']['closing_balance'])->toBe(45.0);

    $statements = ($this->api)()->getJson("/api/v1/reports/debtor-statements?from={$today}&to={$today}&client_group_id={$this->wholesale->id}&include_unassigned=1")
        ->assertSuccessful()->json();

    expect(collect($statements['debtors'])->pluck('client.id')->all())->toBe([$wholesaler->id])
        ->and((float) $statements['totals']['closing'])->toBe(300.0)
        ->and($statements['unassigned'])->toBeNull();

    $balances = ($this->api)()->getJson("/api/v1/reports/partner-balances?type=clients&client_group_id={$this->retail->id}")
        ->assertSuccessful()->json();

    expect(collect($balances['rows'])->pluck('id')->all())->toBe([$shopper->id])
        ->and((float) $balances['summary']['total_receivables'])->toBe(120.0)
        ->and($balances['client_group_id'])->toBe($this->retail->id);

    // A group with no clients reports nothing, even company-wide.
    $empty = ClientGroup::factory()->create(['code' => 'EMP']);
    $none = $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/debtor-statements?from={$today}&to={$today}&company_wide=1&include_unassigned=1&client_group_id={$empty->id}")
        ->assertSuccessful()->json();
    expect($none['debtors'])->toBe([])
        ->and($none['unassigned'])->toBeNull()
        ->and((float) $none['totals']['closing'])->toBe(0.0);

    // Company-wide (no unit) with a group still narrows to that group.
    $companyWide = $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/partner-balances?type=clients&company_wide=1&client_group_id={$this->wholesale->id}")
        ->assertSuccessful()->json();
    expect(collect($companyWide['rows'])->pluck('id')->all())->toBe([$wholesaler->id])
        ->and((float) $companyWide['summary']['total_receivables'])->toBe(300.0);
});

test('the margin report groups by client group and filters by it', function () {
    $wholesaler = ($this->makeClient)('Wholesaler', $this->wholesale);
    $otherWholesaler = ($this->makeClient)('Other wholesaler', $this->wholesale);
    $loner = ($this->makeClient)('Loner', null);
    ($this->sellOnCredit)($wholesaler, 300);
    ($this->sellOnCredit)($otherWholesaler, 200);
    ($this->sellOnCredit)($loner, 90);
    ($this->api)()->postJson('/api/v1/sales', [
        'payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 80]],
    ])->assertCreated();

    $report = ($this->api)()->getJson('/api/v1/reports/sales-margin?group_by=client_group')->assertSuccessful()->json();
    $rows = collect($report['rows'])->keyBy('key');

    expect($report['group_by'])->toBe('client_group')
        ->and($rows)->toHaveCount(2)
        ->and($rows[$this->wholesale->id]['label'])->toBe('Wholesale')
        ->and((float) $rows[$this->wholesale->id]['revenue'])->toBe(500.0)
        ->and((float) $rows[$this->wholesale->id]['cost'])->toBe(100.0)
        ->and((float) $rows['no_group']['revenue'])->toBe(170.0);

    $filtered = ($this->api)()->getJson("/api/v1/reports/sales-margin?group_by=client&client_group_id={$this->wholesale->id}")
        ->assertSuccessful()->json();

    expect(collect($filtered['rows'])->pluck('key')->sort()->values()->all())
        ->toBe(collect([$wholesaler->id, $otherWholesaler->id])->sort()->values()->all())
        ->and((float) $filtered['totals']['revenue'])->toBe(500.0);

    ($this->api)()->getJson('/api/v1/reports/sales-margin?group_by=region')->assertStatus(422);
});
