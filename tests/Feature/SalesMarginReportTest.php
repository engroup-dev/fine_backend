<?php

declare(strict_types=1);

use App\Enums\SaleFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture',
        'code' => 'FURN-01',
        'unit_type' => 'manufactory',
    ]);

    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom',
        'code' => 'STORE-01',
        'unit_type' => 'showroom',
    ]);

    $this->warehouseA = Warehouse::create([
        'operating_unit_id' => $this->unitA->id,
        'name' => 'WH-A',
        'code' => 'WH-A',
    ]);

    $this->warehouseB = Warehouse::create([
        'operating_unit_id' => $this->unitB->id,
        'name' => 'WH-B',
        'code' => 'WH-B',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Furniture Manager', 'slug' => 'furniture-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitA->id]);

    $this->unauthorizedUser = User::factory()->create(['must_change_password' => false]);
    $guestRole = Role::create(['name' => 'Guest', 'slug' => 'guest-role']);
    UserRole::create(['user_id' => $this->unauthorizedUser->id, 'role_id' => $guestRole->id, 'operating_unit_id' => $this->unitA->id]);

    $this->category = ItemCategory::create([
        'name' => 'مراتب إسفنج',
        'code' => 'CAT-MATTRESS',
    ]);

    $this->itemA = InventoryItem::create([
        'name' => 'مرتبة فاخرة',
        'code' => 'MATT-01',
        'item_type' => 'furniture_finished_good',
        'category_id' => $this->category->id,
        'unit_of_measure' => 'each',
        'selling_price' => 200,
    ]);

    $this->itemB = InventoryItem::create([
        'name' => 'وسادة طبية',
        'code' => 'PIL-01',
        'item_type' => 'furniture_finished_good',
        'category_id' => $this->category->id,
        'unit_of_measure' => 'each',
        'selling_price' => 50,
    ]);

    $this->lotA = StockLot::create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-MATT-01',
        'quantity' => 10,
        'unit_cost' => 120.0,
        'status' => 'available',
    ]);

    $this->lotB = StockLot::create([
        'inventory_item_id' => $this->itemB->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-PIL-01',
        'quantity' => 20,
        'unit_cost' => 30.0,
        'status' => 'available',
    ]);

    $this->entity = Entity::create(['name' => 'شركة طرابلس للمفروشات', 'entity_type' => 'organization']);
    $this->client = Client::create([
        'operating_unit_id' => $this->unitA->id,
        'entity_id' => $this->entity->id,
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
    $this->asManager = fn () => $this->actingAs($this->manager);
});

test('it returns gross margin grouped by item sorted by margin descending', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0001',
        'client_id' => $this->client->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Open,
        'fulfillment_status' => SaleFulfillmentStatus::Delivered,
        'channel' => 'pos',
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 2,
        'unit_price' => 200,
        'position' => 1,
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $this->lotB->id,
        'quantity' => 5,
        'unit_price' => 50,
        'position' => 2,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?group_by=item&company_wide=1')
        ->assertOk()
        ->json();

    expect($response['group_by'])->toBe('item')
        ->and($response['lines_missing_cost'])->toBe(0)
        ->and($response['rows'])->toHaveCount(2)
        // Row 1: Item A has margin (400 - 240) = 160
        ->and($response['rows'][0]['key'])->toBe($this->itemA->id)
        ->and($response['rows'][0]['label'])->toBe('مرتبة فاخرة')
        ->and((float) $response['rows'][0]['quantity'])->toEqual(2.0)
        ->and((float) $response['rows'][0]['revenue'])->toEqual(400.0)
        ->and((float) $response['rows'][0]['cost'])->toEqual(240.0)
        ->and((float) $response['rows'][0]['margin'])->toEqual(160.0)
        ->and((float) $response['rows'][0]['margin_pct'])->toEqual(40.0)
        // Row 2: Item B has margin (250 - 150) = 100
        ->and($response['rows'][1]['key'])->toBe($this->itemB->id)
        ->and($response['rows'][1]['label'])->toBe('وسادة طبية')
        ->and((float) $response['rows'][1]['quantity'])->toEqual(5.0)
        ->and((float) $response['rows'][1]['revenue'])->toEqual(250.0)
        ->and((float) $response['rows'][1]['cost'])->toEqual(150.0)
        ->and((float) $response['rows'][1]['margin'])->toEqual(100.0)
        ->and((float) $response['rows'][1]['margin_pct'])->toEqual(40.0)
        // Totals
        ->and((float) $response['totals']['quantity'])->toEqual(7.0)
        ->and((float) $response['totals']['revenue'])->toEqual(650.0)
        ->and((float) $response['totals']['cost'])->toEqual(390.0)
        ->and((float) $response['totals']['margin'])->toEqual(260.0)
        ->and((float) $response['totals']['margin_pct'])->toEqual(40.0);
});

test('it groups by category', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0002',
        'client_id' => $this->client->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
        'fulfillment_status' => SaleFulfillmentStatus::Delivered,
        'channel' => 'pos',
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
        'position' => 1,
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $this->lotB->id,
        'quantity' => 2,
        'unit_price' => 50,
        'position' => 2,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?group_by=category&company_wide=1')
        ->assertOk()
        ->json();

    expect($response['group_by'])->toBe('category')
        ->and($response['rows'])->toHaveCount(1)
        ->and($response['rows'][0]['key'])->toBe($this->category->id)
        ->and($response['rows'][0]['label'])->toBe('مراتب إسفنج')
        ->and((float) $response['rows'][0]['quantity'])->toEqual(3.0)
        ->and((float) $response['rows'][0]['revenue'])->toEqual(300.0)
        ->and((float) $response['rows'][0]['cost'])->toEqual(180.0)
        ->and((float) $response['rows'][0]['margin'])->toEqual(120.0)
        ->and((float) $response['rows'][0]['margin_pct'])->toEqual(40.0);
});

test('it groups by client', function (): void {
    $entity2 = Entity::create(['name' => 'عميل بنغازي', 'entity_type' => 'individual']);
    $client2 = Client::create(['operating_unit_id' => $this->unitA->id, 'entity_id' => $entity2->id]);

    $order1 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0003',
        'client_id' => $this->client->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
        'channel' => 'pos',
    ]);
    SalesOrderLine::create([
        'sales_order_id' => $order1->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    $order2 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0004',
        'client_id' => $client2->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
        'channel' => 'pos',
    ]);
    SalesOrderLine::create([
        'sales_order_id' => $order2->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $this->lotB->id,
        'quantity' => 4,
        'unit_price' => 50,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?group_by=client&company_wide=1')
        ->assertOk()
        ->json();

    expect($response['group_by'])->toBe('client')
        ->and($response['rows'])->toHaveCount(2);

    $keys = array_column($response['rows'], 'key');
    expect($keys)->toContain($this->client->id)
        ->and($keys)->toContain($client2->id);
});

test('it groups per sale', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0005',
        'client_id' => $this->client->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
        'channel' => 'pos',
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?group_by=sale&company_wide=1')
        ->assertOk()
        ->json();

    expect($response['group_by'])->toBe('sale')
        ->and($response['rows'])->toHaveCount(1)
        ->and($response['rows'][0]['key'])->toBe($order->id)
        ->and($response['rows'][0]['label'])->toBe('SO-2026-0005')
        ->and((float) $response['rows'][0]['revenue'])->toEqual(200.0)
        ->and((float) $response['rows'][0]['cost'])->toEqual(120.0)
        ->and((float) $response['rows'][0]['margin'])->toEqual(80.0);
});

test('it filters by from and to dates', function (): void {
    $orderOld = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-OLD',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);
    $orderOld->forceFill(['created_at' => '2026-07-15 10:00:00'])->save();

    SalesOrderLine::create([
        'sales_order_id' => $orderOld->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    $orderNew = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-NEW',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);
    $orderNew->forceFill(['created_at' => '2026-08-20 10:00:00'])->save();

    SalesOrderLine::create([
        'sales_order_id' => $orderNew->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $this->lotB->id,
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?from=2026-08-01&to=2026-08-31&company_wide=1')
        ->assertOk()
        ->json();

    expect($response['rows'])->toHaveCount(1)
        ->and($response['rows'][0]['key'])->toBe($this->itemB->id);
});

test('it scopes results to the operating unit', function (): void {
    $orderA = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-UNIT-A',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);
    SalesOrderLine::create([
        'sales_order_id' => $orderA->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    $lotUnitB = StockLot::create([
        'inventory_item_id' => $this->itemB->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-B-01',
        'quantity' => 10,
        'unit_cost' => 30.0,
        'status' => 'available',
    ]);

    $orderB = SalesOrder::create([
        'operating_unit_id' => $this->unitB->id,
        'order_number' => 'SO-UNIT-B',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);
    SalesOrderLine::create([
        'sales_order_id' => $orderB->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $lotUnitB->id,
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    // Manager of Unit A sees only Unit A
    $responseManager = ($this->asManager)()->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/sales-margin')
        ->assertOk()
        ->json();

    expect($responseManager['rows'])->toHaveCount(1)
        ->and($responseManager['rows'][0]['key'])->toBe($this->itemA->id);

    // Owner company_wide sees both Unit A and Unit B
    $responseOwner = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?company_wide=1')
        ->assertOk()
        ->json();

    expect($responseOwner['rows'])->toHaveCount(2);
});

test('it flags lines missing cost and reports cost as zero without dropping revenue', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-NO-COST',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);

    // Line with cost
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    // Line with no lot / missing cost
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => null,
        'quantity' => 2,
        'unit_price' => 50,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?company_wide=1')
        ->assertOk()
        ->json();

    expect($response['lines_missing_cost'])->toBe(1);

    $rowB = collect($response['rows'])->firstWhere('key', $this->itemB->id);
    expect((float) $rowB['revenue'])->toEqual(100.0)
        ->and((float) $rowB['cost'])->toEqual(0.0)
        ->and((float) $rowB['margin'])->toEqual(100.0)
        ->and((float) $rowB['margin_pct'])->toEqual(100.0);
});

test('margin_pct is null on zero revenue', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-FREE',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);

    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 0,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?company_wide=1')
        ->assertOk()
        ->json();

    expect((float) $response['rows'][0]['revenue'])->toEqual(0.0)
        ->and($response['rows'][0]['margin_pct'])->toBeNull()
        ->and((float) $response['totals']['revenue'])->toEqual(0.0)
        ->and($response['totals']['margin_pct'])->toBeNull();
});

test('an unauthorized role is denied access with 403', function (): void {
    $this->actingAs($this->unauthorizedUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/sales-margin')
        ->assertStatus(403);
});

test('it excludes draft, pending approval, rejected orders, and internal transfers', function (): void {
    foreach ([SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval, SalesOrderStatus::Rejected] as $status) {
        $order = SalesOrder::create([
            'operating_unit_id' => $this->unitA->id,
            'order_number' => 'SO-'.$status->value,
            'buyer_type' => 'client',
            'status' => $status,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_item_id' => $this->itemA->id,
            'stock_lot_id' => $this->lotA->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]);
    }

    $internalOrder = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'buyer_unit_id' => $this->unitB->id,
        'order_number' => 'SO-INTERNAL',
        'buyer_type' => 'internal_unit',
        'status' => SalesOrderStatus::Completed,
    ]);
    SalesOrderLine::create([
        'sales_order_id' => $internalOrder->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 1,
        'unit_price' => 200,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?company_wide=1')
        ->assertOk()
        ->json();

    expect($response['rows'])->toBeEmpty()
        ->and((float) $response['totals']['revenue'])->toEqual(0.0);
});

test('it costs a line from its recorded actual cost, not the lot cost, and does not flag lot-less lines that carry one', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-ACTUAL',
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Completed,
    ]);

    // Lot cost is 120, but checkout recorded 100 from the stock actually issued.
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 2,
        'unit_price' => 200,
        'unit_cost_actual' => 100,
    ]);

    // No single lot (as on bundle lines), but the line carries its own actual cost.
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => null,
        'quantity' => 1,
        'unit_price' => 80,
        'unit_cost_actual' => 50,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?company_wide=1')
        ->assertOk()
        ->json();

    $rowA = collect($response['rows'])->firstWhere('key', $this->itemA->id);
    $rowB = collect($response['rows'])->firstWhere('key', $this->itemB->id);

    expect((float) $rowA['cost'])->toEqual(200.0)
        ->and((float) $rowB['cost'])->toEqual(50.0)
        ->and($response['lines_missing_cost'])->toBe(0);
});

test('discounted sales count their net revenue: line discount plus a share of the invoice discount', function (): void {
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-2026-0099',
        'client_id' => $this->client->id,
        'buyer_type' => 'client',
        'status' => SalesOrderStatus::Open,
        'fulfillment_status' => SaleFulfillmentStatus::Delivered,
        'channel' => 'pos',
    ]);
    // Money columns are not mass-assignable; checkout sets them explicitly too.
    $order->forceFill(['invoice_discount' => 30])->save();

    // Item A: 2 × 200 = 400, less a 100 line discount = 300 net.
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemA->id,
        'stock_lot_id' => $this->lotA->id,
        'quantity' => 2,
        'unit_price' => 200,
        'discount_amount' => 100,
        'position' => 1,
    ]);

    // Item B: 2 × 50 = 100 net. The 30 invoice discount splits 300:100 → 22.5 and 7.5.
    SalesOrderLine::create([
        'sales_order_id' => $order->id,
        'inventory_item_id' => $this->itemB->id,
        'stock_lot_id' => $this->lotB->id,
        'quantity' => 2,
        'unit_price' => 50,
        'position' => 2,
    ]);

    $response = ($this->asOwner)()->getJson('/api/v1/reports/sales-margin?group_by=item&company_wide=1')
        ->assertOk()
        ->json();

    $rows = collect($response['rows'])->keyBy('key');

    expect((float) $rows[$this->itemA->id]['revenue'])->toBe(277.5)
        ->and((float) $rows[$this->itemB->id]['revenue'])->toBe(92.5)
        ->and((float) $response['totals']['revenue'])->toBe(370.0);
});
