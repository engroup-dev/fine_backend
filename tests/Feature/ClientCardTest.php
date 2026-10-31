<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientCreditLimitChange;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\TreasuryVoucher;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

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

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Waha Traders']);
    $this->client = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 1000, 'current_balance' => 0,
    ]);

    $this->api = fn (?OperatingUnit $unit = null) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unitA)->id]);

    $this->sell = fn (array $overrides = []) => ($this->api)()->postJson('/api/v1/sales', array_filter([
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100]],
        ...$overrides,
    ], fn ($value) => $value !== null));
});

test('addresses can be added, listed, edited and removed with one default', function () {
    $first = ($this->api)()->postJson("/api/v1/clients/{$this->client->id}/addresses", [
        'label' => 'Main store', 'city' => 'Tripoli', 'address' => 'Gargaresh St. 12', 'phone' => '0911111111',
    ])->assertCreated()->json('data');

    expect($first['is_default'])->toBeTrue()
        ->and($first['snapshot'])->toBe('Main store - Tripoli - Gargaresh St. 12 - 0911111111');

    $second = ($this->api)()->postJson("/api/v1/clients/{$this->client->id}/addresses", [
        'label' => 'Warehouse', 'city' => 'Misrata', 'address' => 'Industrial zone', 'is_default' => true,
    ])->assertCreated()->json('data');

    expect($second['is_default'])->toBeTrue()
        ->and(ClientAddress::find($first['id'])->is_default)->toBeFalse();

    $list = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/addresses")->assertSuccessful()->json('data');
    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe($second['id']);

    $firstRow = ClientAddress::find($first['id']);
    $updated = ($this->api)()->putJson("/api/v1/clients/{$this->client->id}/addresses/{$first['id']}", [
        'address' => 'Gargaresh St. 40',
        'is_default' => true,
        'record_version' => $firstRow->record_version,
    ])->assertSuccessful()->json('data');

    expect($updated['address'])->toBe('Gargaresh St. 40')
        ->and($updated['is_default'])->toBeTrue()
        ->and(ClientAddress::find($second['id'])->is_default)->toBeFalse();

    // Removing the default promotes the remaining address.
    ($this->api)()->deleteJson("/api/v1/clients/{$this->client->id}/addresses/{$first['id']}")->assertSuccessful();

    expect(ClientAddress::find($first['id']))->toBeNull()
        ->and(ClientAddress::find($second['id'])->is_default)->toBeTrue();
});

test('the default address cannot be switched off directly', function () {
    $default = ClientAddress::factory()->default()->create(['client_id' => $this->client->id]);
    $other = ClientAddress::factory()->create(['client_id' => $this->client->id]);

    ($this->api)()->putJson("/api/v1/clients/{$this->client->id}/addresses/{$default->id}", [
        'is_default' => false, 'record_version' => $default->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'ADDRESS_DEFAULT_REQUIRED');

    expect($default->fresh()->is_default)->toBeTrue();

    // Saying false on an address that is not the default is a plain save.
    ($this->api)()->putJson("/api/v1/clients/{$this->client->id}/addresses/{$other->id}", [
        'label' => 'Back office', 'is_default' => false, 'record_version' => $other->record_version,
    ])->assertSuccessful()->assertJsonPath('data.is_default', false);

    expect($default->fresh()->is_default)->toBeTrue();
});

test('an address edit with a stale record version is refused', function () {
    $address = ClientAddress::factory()->default()->create(['client_id' => $this->client->id]);

    ($this->api)()->putJson("/api/v1/clients/{$this->client->id}/addresses/{$address->id}", [
        'label' => 'Renamed', 'record_version' => $address->record_version + 5,
    ])->assertStatus(409);

    expect($address->fresh()->label)->not->toBe('Renamed');
});

test('addresses of a client in another unit are not reachable', function () {
    $address = ClientAddress::factory()->create(['client_id' => $this->client->id]);

    ($this->api)($this->unitB)->getJson("/api/v1/clients/{$this->client->id}/addresses")->assertNotFound();
    ($this->api)($this->unitB)->deleteJson("/api/v1/clients/{$this->client->id}/addresses/{$address->id}")->assertNotFound();

    $otherEntity = Entity::create(['entity_type' => 'organization', 'name' => 'Other Co']);
    $other = Client::create(['entity_id' => $otherEntity->id, 'operating_unit_id' => $this->unitA->id]);

    ($this->api)()->deleteJson("/api/v1/clients/{$other->id}/addresses/{$address->id}")->assertNotFound();
    expect($address->fresh())->not->toBeNull();
});

test('a sale keeps a snapshot of its delivery address and the delivery note prints it', function () {
    $address = ClientAddress::factory()->default()->create([
        'client_id' => $this->client->id, 'label' => 'Main store', 'city' => 'Tripoli',
        'address' => 'Gargaresh St. 12', 'phone' => '0911111111',
    ]);

    $sale = ($this->sell)(['delivery_address_id' => $address->id])->assertCreated()->json();

    expect($sale['delivery_address_id'])->toBe($address->id)
        ->and($sale['delivery_address'])->toBe('Main store - Tripoli - Gargaresh St. 12 - 0911111111');

    // Later edits to the address book never rewrite the sale.
    $address->update(['address' => 'Moved away']);

    $note = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}/delivery-note")->assertSuccessful()->json();

    expect($note['delivery_address'])->toBe('Main store - Tripoli - Gargaresh St. 12 - 0911111111')
        ->and(SalesOrder::find($sale['id'])->delivery_address)->toBe('Main store - Tripoli - Gargaresh St. 12 - 0911111111');
});

test('a delivery address of another client or on a walk-in sale is refused', function () {
    $otherEntity = Entity::create(['entity_type' => 'organization', 'name' => 'Other Co']);
    $other = Client::create(['entity_id' => $otherEntity->id, 'operating_unit_id' => $this->unitA->id]);
    $foreign = ClientAddress::factory()->create(['client_id' => $other->id]);

    ($this->sell)(['delivery_address_id' => $foreign->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'DELIVERY_ADDRESS_INVALID');

    $own = ClientAddress::factory()->create(['client_id' => $this->client->id]);

    ($this->api)()->postJson('/api/v1/sales', [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'delivery_address_id' => $own->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100]],
    ])->assertStatus(422)->assertJsonPath('code', 'DELIVERY_ADDRESS_INVALID');

    expect(SalesOrder::count())->toBe(0);
});

test('a credit limit change is logged with old, new, reason and who changed it', function () {
    $client = $this->client->fresh();

    $updated = ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'credit_limit' => 2500,
        'credit_limit_reason' => 'Good payment record',
        'record_version' => $client->record_version,
    ])->assertSuccessful()->json('data');

    // A save that does not move the limit writes nothing.
    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'credit_limit' => 2500,
        'payment_terms_days' => 45,
        'record_version' => $updated['record_version'],
    ])->assertSuccessful();

    $change = ClientCreditLimitChange::sole();

    expect((float) $change->old_limit)->toBe(1000.0)
        ->and((float) $change->new_limit)->toBe(2500.0)
        ->and($change->reason)->toBe('Good payment record')
        ->and($change->changed_by)->toBe($this->user->id);

    $history = ($this->api)()->getJson("/api/v1/clients/{$client->id}/credit-limit-changes")
        ->assertSuccessful()->json('data');

    expect($history)->toHaveCount(1)
        ->and($history[0]['old_limit'])->toEqual(1000)
        ->and($history[0]['new_limit'])->toEqual(2500)
        ->and($history[0]['changed_by']['name'])->toBe($this->user->name);
});

test('the summary shows ledger aging, overdue, the last payment and open sales', function () {
    $this->travelTo(now()->subDays(45));
    $old = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null])->assertCreated()->json();
    $this->travelBack();

    $recent = ($this->sell)([
        'payment_method' => 'receivable', 'cash_account_id' => null,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100]],
    ])->assertCreated()->json();

    // A cash sale is paid in full: not open, and not a collection.
    ($this->sell)()->assertCreated();

    ($this->api)()->postJson("/api/v1/sales/{$recent['id']}/payments", [
        'amount' => 50, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    $summary = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');

    // Ledger FIFO: the 50 collected settles the oldest debit first.
    expect($summary['balance'])->toEqual(250)
        ->and($summary['aging']['current'])->toEqual(200)
        ->and($summary['aging']['days_31_60'])->toEqual(50)
        ->and($summary['overdue_amount'])->toEqual(50)
        ->and($summary['last_payment']['amount'])->toEqual(50)
        ->and($summary['last_payment']['method'])->toBe('cash')
        ->and($summary['last_payment']['source'])->toBe('sale_payment')
        ->and($summary['last_payment']['reference'])->toBe($recent['order_number'])
        ->and($summary['open_sales_count'])->toBe(2)
        ->and($summary['open_sales_outstanding'])->toEqual(250);

    [$first, $second] = $summary['open_sales'];

    expect($first['id'])->toBe($old['id'])
        ->and($first['outstanding'])->toEqual(100)
        ->and($first['days_overdue'])->toBe(15)
        ->and($second['id'])->toBe($recent['id'])
        ->and($second['outstanding'])->toEqual(150)
        ->and($second['days_overdue'])->toBe(0);
});

test('a cash refund on a return is not reported as the last payment', function () {
    $sale = ($this->sell)([
        'payment_method' => 'receivable', 'cash_account_id' => null,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 3, 'unit_price' => 100]],
    ])->assertCreated()->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 150, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    $this->travel(1)->minutes();

    $line = SalesOrder::findOrFail($sale['id'])->lines()->firstOrFail();
    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    expect(SalePayment::where('sales_order_id', $sale['id'])->where('amount', '<', 0)->exists())->toBeTrue();

    $summary = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');

    expect($summary['last_payment']['amount'])->toEqual(150)
        ->and($summary['last_payment']['source'])->toBe('sale_payment')
        ->and($summary['last_payment']['reference'])->toBe($sale['order_number']);
});

test('a newer posted receipt voucher is the last payment and a cancelled one is ignored', function () {
    $this->travelTo(now()->subDays(5));
    $sale = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null])->assertCreated()->json();
    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 30, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();
    $this->travelBack();

    $voucher = fn (string $date) => ($this->api)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->drawer->id,
        'client_id' => $this->client->id,
        'amount' => 40,
        'voucher_date' => $date,
        'description' => 'Collection on account',
        'operating_unit_id' => $this->unitA->id,
    ])->assertCreated()->json('data');

    $posted = $voucher(now()->subDay()->toDateString());
    $cancelled = $voucher(now()->toDateString());

    ($this->api)()->postJson("/api/v1/treasury-vouchers/{$cancelled['id']}/cancel", ['reason' => 'Entered twice'])
        ->assertSuccessful();

    $summary = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');
    $postedRow = TreasuryVoucher::findOrFail($posted['id']);

    expect($summary['last_payment']['source'])->toBe('receipt_voucher')
        ->and($summary['last_payment']['reference'])->toBe($postedRow->voucher_number)
        ->and($summary['last_payment']['amount'])->toEqual((float) $postedRow->amount_lyd)
        ->and($summary['last_payment']['date'])->toBe(now()->subDay()->toDateString())
        ->and($summary['last_payment']['method'])->toBe('voucher');
});

test('a cheque collection that bounced or went back to the drawer is not the last payment', function (string $action) {
    $sale = ($this->sell)([
        'payment_method' => 'receivable', 'cash_account_id' => null,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 3, 'unit_price' => 100]],
    ])->assertCreated()->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 50, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    $this->travel(1)->minutes();

    $chequeId = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 120,
        'method' => 'cheque',
        'cheque' => [
            'cheque_no' => 'CHQ-CARD-1',
            'bank_name' => 'Aman Bank',
            'drawer_name' => 'Al-Waha Traders',
            'due_date' => now()->addDays(20)->toDateString(),
        ],
    ])->assertSuccessful()->json('payments.1.cheque_id');

    expect($chequeId)->not->toBeNull();

    // While the cheque is in the portfolio it is the latest collection.
    $before = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');
    expect($before['last_payment']['method'])->toBe('cheque')
        ->and($before['last_payment']['amount'])->toEqual(120);

    $payload = $action === 'bounce' ? ['reason' => 'Insufficient funds'] : ['note' => 'Drawer asked for it back'];
    ($this->api)()->postJson("/api/v1/cheques/{$chequeId}/{$action}", $payload)->assertSuccessful();

    $after = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');

    expect($after['last_payment']['method'])->toBe('cash')
        ->and($after['last_payment']['amount'])->toEqual(50)
        ->and($after['last_payment']['reference'])->toBe($sale['order_number']);
})->with(['bounce', 'return']);

test('the summary still lists open sales from the unit a client moved away from', function () {
    $sale = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null])->assertCreated()->json();

    $client = $this->client->fresh();
    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'operating_unit_id' => $this->unitB->id,
        'record_version' => $client->record_version,
    ])->assertSuccessful();

    ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertNotFound();

    $summary = ($this->api)($this->unitB)->getJson("/api/v1/clients/{$this->client->id}/summary")
        ->assertSuccessful()->json('data');

    expect($summary['balance'])->toEqual(100)
        ->and($summary['open_sales_count'])->toBe(1)
        ->and($summary['open_sales'][0]['id'])->toBe($sale['id'])
        ->and($summary['open_sales_outstanding'])->toEqual(100);
});

test('a client with no history has an empty summary', function () {
    $summary = ($this->api)()->getJson("/api/v1/clients/{$this->client->id}/summary")->assertSuccessful()->json('data');

    expect($summary['balance'])->toEqual(0)
        ->and($summary['overdue_amount'])->toEqual(0)
        ->and($summary['last_payment'])->toBeNull()
        ->and($summary['open_sales'])->toBe([]);

    ($this->api)($this->unitB)->getJson("/api/v1/clients/{$this->client->id}/summary")->assertNotFound();
});
