<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Enums\ClientStatus;
use App\Enums\EmployeeStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * W6-04: `all=1` on the server-paged lists the desktop exports to CSV — every
 * row the same filters and unit scope match, in the list's own shape, capped
 * at fine.export_all_limit — and a unique order on the lists the desktop now
 * walks page by page.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    RolePermissions::grantDefaults($role);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->api = fn () => $this->actingAs($this->owner)->withHeader('X-Operating-Unit-ID', $this->unit->id);

    $this->supplier = Supplier::create(['operating_unit_id' => $this->unit->id, 'name' => 'Benghazi Imports', 'default_currency' => 'USD']);

    $this->makeSale = fn (SalesOrderStatus $status, ?OperatingUnit $unit = null): SalesOrder => SalesOrder::factory()->create([
        'operating_unit_id' => ($unit ?? $this->unit)->id,
        'client_id' => Client::factory()->create(['operating_unit_id' => ($unit ?? $this->unit)->id])->id,
        'status' => $status,
    ]);

    $this->makePurchaseOrder = fn (string $kind): PurchaseOrder => PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id, 'supplier_id' => $this->supplier->id,
        'currency' => $kind === 'local' ? 'LYD' : 'USD', 'kind' => $kind,
        'negotiated_price' => 50, 'quantity' => 10, 'status' => PurchaseOrderStatus::Draft,
    ]);

    $this->makeCheque = fn (string $chequeNo): Cheque => Cheque::create([
        'company_id' => $this->company->id, 'operating_unit_id' => $this->unit->id,
        'cheque_number_internal' => 'CHQ-2026-'.$chequeNo, 'direction' => 'incoming',
        'cheque_no' => $chequeNo, 'bank_name' => 'Aman Bank', 'amount' => 100, 'currency' => 'LYD',
        'due_date' => '2026-11-01', 'received_date' => '2026-10-18', 'status' => ChequeStatus::InPortfolio,
    ]);
});

test('sales all=1 returns every filtered row in the unit as one page of the paginator shape', function (): void {
    foreach (range(1, 3) as $i) {
        ($this->makeSale)(SalesOrderStatus::Open);
    }
    ($this->makeSale)(SalesOrderStatus::Completed);
    ($this->makeSale)(SalesOrderStatus::Open, $this->otherUnit);

    ($this->api)()->getJson('/api/v1/sales?status=open&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 3)->assertJsonPath('last_page', 3);

    ($this->api)()->getJson('/api/v1/sales?status=open&per_page=1&all=1')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('total', 3)
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('last_page', 1)
        ->assertJsonStructure(['data' => [['id', 'order_number', 'lines_count', 'client']], 'per_page', 'from', 'to', 'links']);
});

test('quotations all=1 keeps the status filter', function (): void {
    $quoted = ['operating_unit_id' => $this->unit->id, 'client_id' => Client::factory()->create(['operating_unit_id' => $this->unit->id])->id];
    Quotation::factory()->count(2)->create($quoted);
    Quotation::factory()->create([...$quoted, 'status' => Quotation::STATUS_CANCELLED]);
    Quotation::factory()->expired()->create($quoted);

    ($this->api)()->getJson('/api/v1/quotations?status=open&per_page=1&all=1')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2)->assertJsonPath('last_page', 1);
});

test('purchase orders all=1 lifts the per_page limit and keeps the kind filter', function (): void {
    foreach (range(1, 3) as $i) {
        ($this->makePurchaseOrder)('foreign');
    }
    ($this->makePurchaseOrder)('local');

    ($this->api)()->getJson('/api/v1/purchase-orders?kind=foreign&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data');

    $rows = ($this->api)()->getJson('/api/v1/purchase-orders?kind=foreign&per_page=1&all=1')
        ->assertOk()->assertJsonCount(3, 'data')->json('data');

    expect(collect($rows)->pluck('kind')->unique()->all())->toBe(['foreign']);
});

test('clients, suppliers and employees all=1 return the same filtered rows as the plain list', function (): void {
    Client::factory()->count(2)->create(['operating_unit_id' => $this->unit->id]);
    Client::factory()->suspended()->create(['operating_unit_id' => $this->unit->id]);
    Employee::factory()->count(2)->create(['operating_unit_id' => $this->unit->id]);
    Employee::factory()->inactive()->create(['operating_unit_id' => $this->unit->id]);
    Supplier::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Tripoli Trading', 'default_currency' => 'LYD']);

    foreach ([
        '/api/v1/clients?status='.ClientStatus::Active->value => 2,
        '/api/v1/employees?status='.EmployeeStatus::Active->value => 2,
        '/api/v1/suppliers?operating_unit_id='.$this->unit->id => 1,
    ] as $url => $expected) {
        $plain = ($this->api)()->getJson($url)->assertOk()->assertJsonCount($expected, 'data')->json('data');
        $all = ($this->api)()->getJson($url.'&all=1')->assertOk()->assertJsonCount($expected, 'data')->json('data');

        expect($all)->toBe($plain);
    }
});

test('cheques all=1 keeps the search filter and the resource paginator shape', function (): void {
    ($this->makeCheque)('A-1');
    ($this->makeCheque)('A-2');
    ($this->makeCheque)('A-3');
    ($this->makeCheque)('B-1');

    ($this->api)()->getJson('/api/v1/cheques?search=A-&per_page=1&all=1')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1);
});

test('cheques and treasury vouchers clamp per_page to 100 so the paged list cannot sidestep the export cap', function (): void {
    foreach (['/api/v1/cheques', '/api/v1/treasury-vouchers'] as $url) {
        ($this->api)()->getJson($url.'?per_page=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);

        ($this->api)()->getJson($url.'?per_page=0')
            ->assertOk()->assertJsonPath('meta.per_page', 1);

        ($this->api)()->getJson($url)
            ->assertOk()->assertJsonPath('meta.per_page', 25);
    }
});

test('a zero or negative per_page on sales and quotations still pages one row at a time', function (): void {
    foreach (range(1, 3) as $i) {
        ($this->makeSale)(SalesOrderStatus::Open);
    }
    $quoted = ['operating_unit_id' => $this->unit->id, 'client_id' => Client::factory()->create(['operating_unit_id' => $this->unit->id])->id];
    Quotation::factory()->count(3)->create($quoted);

    foreach (['/api/v1/sales', '/api/v1/quotations'] as $url) {
        foreach (['-1', '0'] as $perPage) {
            ($this->api)()->getJson($url.'?per_page='.$perPage)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('per_page', 1)->assertJsonPath('total', 3);
        }
    }
});

test('treasury vouchers all=1 keeps the search filter', function (): void {
    $treasury = linkedTreasury($this->unit, '121101');

    foreach (['Rent March', 'Rent April', 'Scrap sale'] as $description) {
        ($this->api)()->postJson('/api/v1/treasury-vouchers', [
            'kind' => 'receipt', 'cash_account_id' => $treasury->id, 'counter_account_code' => '42',
            'amount' => 100, 'voucher_date' => '2026-10-15', 'description' => $description,
            'operating_unit_id' => $this->unit->id,
        ])->assertCreated();
    }

    ($this->api)()->getJson('/api/v1/treasury-vouchers?search=Rent&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);

    ($this->api)()->getJson('/api/v1/treasury-vouchers?search=Rent&per_page=1&all=1')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 1);
});

test('all=1 past the export cap is refused with EXPORT_TOO_LARGE on every list, not cut short', function (): void {
    foreach (range(1, 3) as $i) {
        ($this->makeSale)(SalesOrderStatus::Open);
        ($this->makePurchaseOrder)('foreign');
        ($this->makeCheque)("C-{$i}");
    }
    Quotation::factory()->count(3)->create([
        'operating_unit_id' => $this->unit->id,
        'client_id' => Client::factory()->create(['operating_unit_id' => $this->unit->id])->id,
    ]);
    Employee::factory()->count(3)->create(['operating_unit_id' => $this->unit->id]);
    Supplier::create(['operating_unit_id' => $this->unit->id, 'name' => 'Misrata Metals', 'default_currency' => 'LYD']);
    Supplier::create(['operating_unit_id' => $this->unit->id, 'name' => 'Zawiya Fabrics', 'default_currency' => 'LYD']);
    $treasury = linkedTreasury($this->unit, '121101');
    foreach (range(1, 3) as $i) {
        ($this->api)()->postJson('/api/v1/treasury-vouchers', [
            'kind' => 'receipt', 'cash_account_id' => $treasury->id, 'counter_account_code' => '42',
            'amount' => 10, 'voucher_date' => '2026-10-15', 'description' => "Receipt {$i}",
            'operating_unit_id' => $this->unit->id,
        ])->assertCreated();
    }

    $lists = ['sales', 'quotations', 'purchase-orders', 'clients', 'suppliers', 'employees', 'cheques', 'treasury-vouchers'];

    // Each list holds at least three rows (the sales add three clients).
    config(['fine.export_all_limit' => 2]);
    foreach ($lists as $list) {
        ($this->api)()->getJson("/api/v1/{$list}?all=1")
            ->assertStatus(422)
            ->assertJsonPath('code', 'EXPORT_TOO_LARGE')
            ->assertJsonPath('limit', 2);
    }

    // The cap counts the filtered rows, so narrowing the filters gets under it.
    ($this->api)()->getJson('/api/v1/sales?all=1')->assertStatus(422)->assertJsonPath('total', 3);
    ($this->api)()->getJson('/api/v1/cheques?all=1&search=C-1')->assertOk()->assertJsonCount(1, 'data');

    config(['fine.export_all_limit' => 50]);
    foreach ($lists as $list) {
        ($this->api)()->getJson("/api/v1/{$list}?all=1")->assertOk();
    }
});

/**
 * Walks a paged list one page at a time and returns the ids in page order.
 *
 * @return array<int, string>
 */
function walkPages(Closure $api, string $url, int $perPage): array
{
    $ids = [];
    $page = 1;

    do {
        $response = $api()->getJson("{$url}?per_page={$perPage}&page={$page}")->assertOk();
        $ids = [...$ids, ...collect($response->json('data'))->pluck('id')->all()];
        $page++;
    } while ($page <= $response->json('last_page'));

    return $ids;
}

test('cutter orders, material requests and transfers page in a unique order when created_at ties', function (): void {
    // The clock is frozen, so every row below shares one created_at.
    $item = InventoryItem::create(['name' => 'Foam Block', 'code' => 'FB-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'each']);
    $from = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Main', 'code' => 'WH-A']);
    $to = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Back', 'code' => 'WH-B']);

    $created = [
        '/api/v1/cutter-work-orders' => CutterWorkOrder::factory()->count(5)->create(['operating_unit_id' => $this->unit->id]),
        '/api/v1/material-requests' => collect(range(1, 5))->map(fn () => MaterialRequest::create([
            'fulfilling_module' => MaterialRequest::MODULE_CUTTER, 'inventory_item_id' => $item->id,
            'quantity' => 1, 'status' => MaterialRequest::STATUS_PENDING, 'operating_unit_id' => $this->unit->id,
        ])),
        '/api/v1/warehouse-transfers' => collect(range(1, 5))->map(fn (int $i) => WarehouseTransfer::create([
            'operating_unit_id' => $this->unit->id, 'transfer_number' => "XFER-{$i}",
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
        ])),
    ];

    foreach ($created as $url => $rows) {
        $expected = $rows->pluck('id')->sortDesc(SORT_STRING)->values()->all();

        expect(walkPages($this->api, $url, 2))->toBe($expected);
    }
});

test('inventory items page in a unique order when names tie', function (): void {
    // Inserted with descending ids, so insertion order is the reverse of the
    // id order and only the id tie-break can produce the expected order.
    $ids = collect(range(5, 1))->map(fn (int $i) => InventoryItem::forceCreate([
        'id' => sprintf('00000000-0000-4000-8000-%012d', $i),
        'name' => 'Foam Block', 'code' => "FB-{$i}", 'item_type' => 'foam_block', 'unit_of_measure' => 'each',
    ])->id);
    $first = InventoryItem::create(['name' => 'Adhesive', 'code' => 'AD-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'each'])->id;

    $expected = [$first, ...$ids->sort(SORT_STRING)->values()->all()];

    expect(walkPages($this->api, '/api/v1/inventory-items', 1))->toBe($expected)
        ->and(walkPages($this->api, '/api/v1/inventory-items', 2))->toBe($expected);
});
