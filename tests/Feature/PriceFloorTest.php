<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Pricing Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Store',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->otherUnit, '40203');

    $this->cashierRole = Role::create(['name' => 'POS Cashier', 'slug' => 'pos-cashier']);
    $this->storeManagerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);

    $this->cashier = User::factory()->create([
        'name' => 'Ali Cashier',
        'email' => 'cashier@fine.local',
        'phone' => '0911111111',
        'password' => Hash::make('cashier-secret'),
        'must_change_password' => false,
    ]);
    UserRole::create([
        'user_id' => $this->cashier->id,
        'role_id' => $this->cashierRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->manager = User::factory()->create([
        'name' => 'Salem Manager',
        'email' => 'manager@fine.local',
        'phone' => '0922222222',
        'password' => Hash::make('manager-secret'),
        'must_change_password' => false,
    ]);
    UserRole::create([
        'user_id' => $this->manager->id,
        'role_id' => $this->storeManagerRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom WH',
        'code' => 'WH-PRC',
    ]);

    Warehouse::create([
        'operating_unit_id' => $this->otherUnit->id,
        'name' => 'Furniture WH',
        'code' => 'WH-FURN-PRC',
    ]);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::SalesReturns, '40404'],
        [InventoryEventType::GrantedDiscount, '40704'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom Drawer',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Noor Co']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->item = InventoryItem::create([
        'name' => 'Mattress Queen',
        'code' => 'MAT-QN-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
        'cost_price' => 60,
        'min_price' => 80,
        'wholesale_price' => 90,
        'selling_price' => 110,
        'price_basis' => 'unit',
    ]);

    $this->lot = StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-MAT-01',
        'quantity' => 30,
        'unit_cost' => 60,
        'status' => 'available',
    ]);

    $this->apiAs = fn (User $user, ?OperatingUnit $unit = null) => $this->actingAs($user)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unit)->id]);
});

test('inventory items store and update min_price, expose minPriceFor and costFor on unit and m3 basis, and return min_price and cost_price in POS picker', function (): void {
    $created = ($this->apiAs)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Foam Sheet D30',
        'code' => 'FOAM-D30-MIN',
        'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'piece',
        'length_m' => 2.0,
        'width_m' => 1.0,
        'height_m' => 0.1,
        'cost_price' => 300,
        'selling_price' => 500,
        'wholesale_price' => 450,
        'min_price' => 400,
        'price_basis' => 'm3',
    ])->assertCreated();

    $created->assertJsonPath('min_price', fn ($v): bool => (float) $v === 400.0)
        ->assertJsonPath('cost_price', fn ($v): bool => (float) $v === 300.0);

    $m3Item = InventoryItem::findOrFail($created->json('id'));
    // Catalog volume = 0.2 m3: minPriceFor(1) = 400 * 0.2 = 80, costFor(1) = 300 * 0.2 = 60
    expect($m3Item->minPriceFor(1))->toBe(80.0)
        ->and($m3Item->costFor(1))->toBe(60.0)
        ->and($m3Item->minPriceFor(2, 2.0, 0.5, 0.1))->toBe(80.0)
        ->and($m3Item->costFor(2, 2.0, 0.5, 0.1))->toBe(60.0);

    ($this->apiAs)($this->manager)->putJson("/api/v1/inventory-items/{$m3Item->id}", [
        'min_price' => 420,
    ])->assertOk()
        ->assertJsonPath('min_price', fn ($v): bool => (float) $v === 420.0);

    $picker = ($this->apiAs)($this->cashier)->getJson('/api/v1/inventory-items?with_stock=1&search=MAT-QN-01')
        ->assertOk();

    $picker->assertJsonPath('data.0.min_price', fn ($v): bool => (float) $v === 80.0)
        ->assertJsonPath('data.0.cost_price', fn ($v): bool => (float) $v === 60.0);
});

test('retail and wholesale tiers store per-unit list_price and wholesale falls back to retail when wholesale_price is null', function (): void {
    $retailOnlyItem = InventoryItem::create([
        'name' => 'Retail Only Pillow',
        'code' => 'PIL-RET-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
        'selling_price' => 45,
        'wholesale_price' => null,
    ]);
    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $retailOnlyItem->id,
        'lot_number' => 'LOT-PIL-01',
        'quantity' => 10,
        'unit_cost' => 20,
        'status' => 'available',
    ]);

    // Retail tier (default) stores selling_price as list_price
    $retailSale = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 105],
        ],
    ])->assertCreated();

    $retailSale->assertJsonPath('price_tier', 'retail')
        ->assertJsonPath('lines.0.list_price', fn ($v): bool => (float) $v === 110.0)
        ->assertJsonPath('lines.0.unit_price', fn ($v): bool => (float) $v === 105.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 210.0);

    // Wholesale tier stores wholesale_price on item with wholesale_price, and falls back to selling_price when null
    $wholesaleSale = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_tier' => 'wholesale',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 95],
            ['inventory_item_id' => $retailOnlyItem->id, 'quantity' => 1, 'unit_price' => 40],
        ],
    ])->assertCreated();

    $wholesaleSale->assertJsonPath('price_tier', 'wholesale')
        ->assertJsonPath('lines.0.list_price', fn ($v): bool => (float) $v === 90.0)
        ->assertJsonPath('lines.1.list_price', fn ($v): bool => (float) $v === 45.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 230.0);
});

test('selling below min_price is refused with PRICE_BELOW_FLOOR and line context, with nothing written', function (): void {
    $response = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 75],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'PRICE_BELOW_FLOOR')
        ->assertJsonPath('lines.0.position', 0)
        ->assertJsonPath('lines.0.item_id', $this->item->id)
        ->assertJsonPath('lines.0.item_name', 'Mattress Queen')
        ->assertJsonPath('lines.0.net_price', fn ($v): bool => (float) $v === 75.0)
        ->assertJsonPath('lines.0.floor', fn ($v): bool => (float) $v === 80.0)
        ->assertJsonPath('lines.0.reason', 'min_price');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(30.0);
});

test('selling below cost_price is refused with reason cost', function (): void {
    $costlyItem = InventoryItem::create([
        'name' => 'Imported Spring Unit',
        'code' => 'SPR-IMP-01',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'piece',
        'cost_price' => 120,
        'min_price' => 100,
        'selling_price' => 150,
    ]);
    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $costlyItem->id,
        'lot_number' => 'LOT-SPR-01',
        'quantity' => 10,
        'unit_cost' => 120,
        'status' => 'available',
    ]);

    $response = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $costlyItem->id, 'quantity' => 1, 'unit_price' => 110],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'PRICE_BELOW_FLOOR')
        ->assertJsonPath('lines.0.item_id', $costlyItem->id)
        ->assertJsonPath('lines.0.net_price', fn ($v): bool => (float) $v === 110.0)
        ->assertJsonPath('lines.0.floor', fn ($v): bool => (float) $v === 120.0)
        ->assertJsonPath('lines.0.reason', 'cost');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('a line discount or pro-rata invoice discount pushing net unit price below floor is refused', function (): void {
    // Line discount: qty 2 * 90 = 180, line discount 30 => net 150 => net unit price 75 < floor 80
    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 90, 'discount_amount' => 30],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_BELOW_FLOOR')
        ->assertJsonPath('lines.0.net_price', fn ($v): bool => (float) $v === 75.0)
        ->assertJsonPath('lines.0.floor', fn ($v): bool => (float) $v === 80.0);

    // Pro-rata invoice discount across two lines:
    // Line 0 (item): qty 1 * 85 = 85 (floor 80)
    // Line 1 (item): qty 1 * 115 = 115 (floor 80)
    // Subtotal = 200. Invoice discount = 20.
    // Line 0 share = 20 * (85 / 200) = 8.5 => Line 0 net unit = 76.5 < 80!
    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 20,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 85],
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 115],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_BELOW_FLOOR')
        ->assertJsonPath('lines.0.position', 0)
        ->assertJsonPath('lines.0.net_price', fn ($v): bool => (float) $v === 76.5)
        ->assertJsonPath('lines.0.floor', fn ($v): bool => (float) $v === 80.0);

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('a valid manager override passes, stores approver and reason, returns approver name, and logs to audit', function (): void {
    $response = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => '0922222222',
            'password' => 'manager-secret',
            'reason' => 'Clearance display piece',
        ],
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertCreated();

    $response->assertJsonPath('price_override_by_user_id', $this->manager->id)
        ->assertJsonPath('price_override_reason', 'Clearance display piece')
        ->assertJsonPath('price_override_by.id', $this->manager->id)
        ->assertJsonPath('price_override_by.name', 'Salem Manager');

    $saleId = $response->json('id');
    $audit = AuditLog::where('table_name', 'sales_orders')
        ->where('record_id', $saleId)
        ->where('action', 'created')
        ->firstOrFail();

    expect($audit->new_values['price_override_by_user_id'])->toBe($this->manager->id)
        ->and($audit->new_values['price_override_reason'])->toBe('Clearance display piece');
});

test('wrong password or inactive manager is refused with PRICE_OVERRIDE_INVALID', function (): void {
    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'manager@fine.local',
            'password' => 'wrong-password',
            'reason' => 'Discount request',
        ],
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_OVERRIDE_INVALID');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('approver equal to seller or approver without approve-price-override permission is refused with PRICE_OVERRIDE_NOT_ALLOWED', function (): void {
    // Approver is the seller themselves
    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'cashier@fine.local',
            'password' => 'cashier-secret',
            'reason' => 'Self approval attempt',
        ],
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_OVERRIDE_NOT_ALLOWED');

    // Approver is another pos-cashier (lacks approve-price-override)
    $otherCashier = User::factory()->create([
        'email' => 'cashier2@fine.local',
        'password' => Hash::make('cashier2-secret'),
        'must_change_password' => false,
    ]);
    UserRole::create([
        'user_id' => $otherCashier->id,
        'role_id' => $this->cashierRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'cashier2@fine.local',
            'password' => 'cashier2-secret',
            'reason' => 'Peer cashier approval attempt',
        ],
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_OVERRIDE_NOT_ALLOWED');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('a store-manager seller selling below the floor passes without price_override and is recorded', function (): void {
    $response = ($this->apiAs)($this->manager)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertCreated();

    $response->assertJsonPath('price_override_by_user_id', $this->manager->id)
        ->assertJsonPath('price_override_reason', 'approved by seller')
        ->assertJsonPath('price_override_by.name', 'Salem Manager');
});

test('the 6th wrong override attempt within a minute returns PRICE_OVERRIDE_THROTTLED with retry_after', function (): void {
    $payload = [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'manager@fine.local',
            'password' => 'bad-pass',
            'reason' => 'Guessing password',
        ],
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ];

    for ($i = 1; $i <= 5; $i++) {
        ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_OVERRIDE_INVALID');
    }

    $throttled = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', $payload)
        ->assertStatus(422);

    $throttled->assertJsonPath('code', 'PRICE_OVERRIDE_THROTTLED')
        ->assertJsonPath('retry_after', fn ($v): bool => is_int($v) && $v > 0);
});

test('bundle lines, internal transfers, and items with no min_price and no cost_price are unchecked', function (): void {
    // 1. Bundle line has list_price null and is not checked by PriceFloorGuard
    $bundle = Bundle::create(['name' => 'Living Room Bundle']);

    $bundleSale = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['line_type' => 'bundle', 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 10],
        ],
    ])->assertCreated();

    $bundleSale->assertJsonPath('lines.0.list_price', null)
        ->assertJsonPath('price_override_by_user_id', null);

    // 2. Internal transfer is not checked even when item has min_price 80 and cost_price 60
    ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->otherUnit->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 10],
        ],
    ])->assertCreated()
        ->assertJsonPath('price_override_by_user_id', null);

    // 3. Item with no min_price and no cost_price (or 0) never triggers floor even when price_override is sent
    $unflooredItem = InventoryItem::create([
        'name' => 'Freebie Cushion',
        'code' => 'CSH-FREE-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);
    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $unflooredItem->id,
        'lot_number' => 'LOT-CSH-01',
        'quantity' => 10,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    $unflooredSale = ($this->apiAs)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'invalid@fine.local',
            'password' => 'ignored-when-above-floor',
            'reason' => 'Ignored override',
        ],
        'lines' => [
            ['inventory_item_id' => $unflooredItem->id, 'quantity' => 1, 'unit_price' => 5],
        ],
    ])->assertCreated();

    $unflooredSale->assertJsonPath('price_override_by_user_id', null)
        ->assertJsonPath('price_override_reason', null);
});

test('quotation conversion preserves price_tier unless overridden and enforces the price floor', function (): void {
    $quoteResponse = ($this->apiAs)($this->cashier)->postJson('/api/v1/quotations', [
        'client_id' => $this->client->id,
        'price_tier' => 'wholesale',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 70],
        ],
    ])->assertCreated();

    $quoteResponse->assertJsonPath('price_tier', 'wholesale');
    $quotationId = $quoteResponse->json('id');

    // Converting below floor without override is refused with PRICE_BELOW_FLOOR
    ($this->apiAs)($this->cashier)->postJson("/api/v1/quotations/{$quotationId}/convert", [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_BELOW_FLOOR');

    // Converting with valid manager override succeeds and keeps quotation's wholesale tier
    $converted = ($this->apiAs)($this->cashier)->postJson("/api/v1/quotations/{$quotationId}/convert", [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'price_override' => [
            'login' => 'manager@fine.local',
            'password' => 'manager-secret',
            'reason' => 'Honoring quoted special price',
        ],
    ])->assertCreated();

    $converted->assertJsonPath('price_tier', 'wholesale')
        ->assertJsonPath('lines.0.list_price', fn ($v): bool => (float) $v === 90.0)
        ->assertJsonPath('price_override_by_user_id', $this->manager->id)
        ->assertJsonPath('price_override_reason', 'Honoring quoted special price');
});

test('permission migration grants approve-price-override to an existing store-manager role', function (): void {
    $permId = Permission::where('slug', 'approve-price-override')->value('id');
    if ($permId !== null) {
        $this->storeManagerRole->permissions()->detach($permId);
    }

    expect($this->storeManagerRole->permissions()->pluck('slug'))->not->toContain('approve-price-override');

    $migration = require database_path('migrations/2026_10_28_140100_seed_approve_price_override_permission.php');
    $migration->up();

    expect($this->storeManagerRole->permissions()->pluck('slug'))->toContain('approve-price-override');
});
