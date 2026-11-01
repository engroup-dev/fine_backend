<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Support\CurrentUnitContext;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Two units, a user scoped to each, and one company-wide Owner.
 * Every record below is created with no unit context active, so nothing is
 * implicitly scoped during setup.
 */
beforeEach(function () {
    app(CurrentUnitContext::class)->clear();

    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => $name,
        'code' => $code,
        'unit_type' => 'manufactory',
    ]);

    $this->unitA = $makeUnit('Foam Plant A', 'FOAM-A');
    $this->unitB = $makeUnit('Foam Plant B', 'FOAM-B');

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'WH A', 'code' => 'WH-A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'WH B', 'code' => 'WH-B']);

    $role = Role::create(['name' => 'Unit Manager', 'slug' => 'unit_manager']);

    $this->userA = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->userA->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unitA->id]);

    // Company-wide role: no unit of its own, so nothing should be filtered for them.
    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    // Bulk material: these lots exist to test scoping, and carry quantities > 1,
    // which INV-02 would reject on a serialized foam block.
    $this->item = InventoryItem::create([
        'name' => 'Bulk Filler', 'code' => 'FILL-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'code' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    // One record of each kind in unit B — the unit userA must never reach.
    $this->batchB = ProductionBatch::create([
        'operating_unit_id' => $this->unitB->id,
        'operation_number' => 500,
        'bun_width_m' => 2.4,
        'status' => 'graded',
        'material_cost' => 1000.0,
    ]);

    $this->category = ItemCategory::create(['name' => 'Universal Category', 'code' => 'CAT-U']);

    $this->lotB = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-B-1',
        'quantity' => 5,
        'unit_cost' => 10,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $entityEmpB = Entity::create(['name' => 'Worker Unit B', 'entity_type' => 'individual', 'is_active' => true]);
    $this->employeeB = Employee::create([
        'entity_id' => $entityEmpB->id,
        'operating_unit_id' => $this->unitB->id,
        'job_title' => 'Cutter Operator',
        'labor_role' => 'operator',
        'pay_type' => 'hourly',
        'hourly_rate' => 15.00,
        'hire_date' => '2026-02-01',
        'status' => 'active',
    ]);

    $entityClientB = Entity::create(['name' => 'Client Unit B', 'entity_type' => 'organization', 'is_active' => true]);
    $this->clientB = Client::create([
        'entity_id' => $entityClientB->id,
        'operating_unit_id' => $this->unitB->id,
        'credit_limit' => 30000.00,
        'current_balance' => 1200.00,
        'payment_terms_days' => 30,
        'status' => 'active',
    ]);

    app(CurrentUnitContext::class)->clear();

    $this->supplierB = Supplier::create([
        'operating_unit_id' => $this->unitB->id,
        'name' => 'Supplier Unit B',
        'default_currency' => 'USD',
    ]);

    $this->purchaseOrderB = PurchaseOrder::create([
        'operating_unit_id' => $this->unitB->id,
        'supplier_id' => $this->supplierB->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => PurchaseOrderStatus::Draft,
    ]);

    $this->cashAccountB = CashAccount::create([
        'operating_unit_id' => $this->unitB->id,
        'name' => 'Cash Unit B',
        'currency' => 'LYD',
        'balance' => 5000,
    ]);

    app(CurrentUnitContext::class)->clear();

    $this->asA = fn () => $this->actingAs($this->userA)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id]);
});

test('a unit id from another database is rejected with a recoverable code', function () {
    // A client holding a unit id from a reseeded database would otherwise retry
    // the same bad header forever. The code lets it drop the stale value.
    $this->actingAs($this->userA)
        ->withHeaders(['X-Operating-Unit-ID' => '019ff000-0000-7000-8000-00000000dead'])
        ->getJson('/api/v1/production-batches')
        ->assertStatus(400)
        ->assertJsonPath('code', 'INVALID_OPERATING_UNIT');
});

test('a unit-scoped user cannot read another unit production batch', function () {
    ($this->asA)()->getJson("/api/v1/production-batches/{$this->batchB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit production batch', function () {
    ($this->asA)()->putJson("/api/v1/production-batches/{$this->batchB->id}", [
        'status' => 'closed',
        'record_version' => $this->batchB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->batchB->fresh()->status->value)->toBe('graded');
});

test('a unit-scoped user cannot delete another unit production batch', function () {
    ($this->asA)()->deleteJson("/api/v1/production-batches/{$this->batchB->id}")->assertNotFound();
});

test('item categories are universal and readable from any unit', function () {
    ($this->asA)()->getJson("/api/v1/item-categories/{$this->category->id}")->assertOk();
});

test('a unit-scoped user cannot read another unit stock lot', function () {
    // Stock lots carry no operating_unit_id; they are scoped through their warehouse.
    ($this->asA)()->getJson("/api/v1/stock-lots/{$this->lotB->id}")->assertNotFound();
});

test('listings exclude other units', function () {
    ($this->asA)()->getJson('/api/v1/production-batches')->assertStatus(200)->assertJsonPath('total', 0);
    ($this->asA)()->getJson('/api/v1/stock-lots')->assertStatus(200)->assertJsonPath('total', 0);
});

test('item categories are listed for every unit', function () {
    // item-categories returns a plain collection rather than a paginator
    // W6-06: the general "عام" category is always there besides this one.
    ($this->asA)()->getJson('/api/v1/item-categories')
        ->assertStatus(200)
        ->assertJsonCount(2)
        ->assertJsonFragment(['code' => 'CAT-U'])
        ->assertJsonFragment(['code' => 'GEN']);
});

test('bundles are universal and listed for every unit', function () {
    app(CurrentUnitContext::class)->clear();
    $bundle = Bundle::create(['name' => 'Universal Bundle']);

    ($this->asA)()->getJson("/api/v1/bundles/{$bundle->id}")->assertOk();
    ($this->asA)()->getJson('/api/v1/bundles')->assertOk()->assertJsonFragment(['name' => 'Universal Bundle']);
});

test('a company-wide owner still sees across every unit', function () {
    // The whole point of the scope being conditional: Owner must not be filtered.
    $this->actingAs($this->owner)->getJson('/api/v1/production-batches')
        ->assertStatus(200)->assertJsonPath('total', 1);

    $this->actingAs($this->owner)->getJson("/api/v1/production-batches/{$this->batchB->id}")
        ->assertStatus(200)->assertJsonPath('operation_number', 500);

    $this->actingAs($this->owner)->getJson("/api/v1/stock-lots/{$this->lotB->id}")
        ->assertStatus(200)->assertJsonPath('lot_number', 'LOT-B-1');
});

test('an owner scoped to a unit by header is filtered to it', function () {
    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/production-batches/{$this->batchB->id}")
        ->assertNotFound();
});

test('a stock lot cannot be created into another unit warehouse', function () {
    // exists: issues a raw query and ignores global scopes, so this needs the
    // scope-aware rule to be rejected at all.
    ($this->asA)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-CROSS-1',
        'quantity' => 1,
        'unit_cost' => 10,
        'source' => 'opening_balance',
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    app(CurrentUnitContext::class)->clear();
    expect(StockLot::where('lot_number', 'LOT-CROSS-1')->exists())->toBeFalse();
});

test('a stock lot cannot be moved into another unit warehouse', function () {
    $lotA = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-A-MOVE',
        'quantity' => 1,
        'unit_cost' => 10,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    ($this->asA)()->putJson("/api/v1/stock-lots/{$lotA->id}", [
        'warehouse_id' => $this->warehouseB->id,
        'record_version' => $lotA->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
});

test('blocks cannot be registered into another unit warehouse', function () {
    $batchA = ProductionBatch::create([
        'operating_unit_id' => $this->unitA->id,
        'operation_number' => 600,
        'bun_width_m' => 2.4,
        'status' => 'graded',
    ]);

    ($this->asA)()->postJson("/api/v1/production-batches/{$batchA->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id,
            'warehouse_id' => $this->warehouseB->id,
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('groups.0.warehouse_id');
});

test('a stock lot cannot be attached to another unit production batch', function () {
    // The bare POST /stock-lots that accepted production_batch_id is gone; batch
    // lots are created only by block registration on the batch itself.
    ($this->asA)()->postJson('/api/v1/stock-lots', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-CROSS-2',
        'quantity' => 1,
        'unit_cost' => 10,
        'production_batch_id' => $this->batchB->id,
    ])->assertStatus(405);

    app(CurrentUnitContext::class)->clear();
    expect(StockLot::where('lot_number', 'LOT-CROSS-2')->exists())->toBeFalse();
});

test('an inventory item can use any category', function () {
    ($this->asA)()->postJson('/api/v1/inventory-items', [
        'name' => 'Universal Item',
        'code' => 'UNIV-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'category_id' => $this->category->id,
    ])->assertStatus(201);
});

test('an inventory item cannot use a missing category', function () {
    ($this->asA)()->postJson('/api/v1/inventory-items', [
        'name' => 'Bad Item',
        'code' => 'BAD-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'category_id' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonValidationErrors('category_id');
});

test('an owner is not blocked by the scope-aware existence rule', function () {
    // With no unit in context the scopes no-op, so any real warehouse is valid.
    // Only the warehouse rule is under test; posting may still need accounts.
    $this->actingAs($this->owner)->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-OWNER-1',
        'quantity' => 1,
        'unit_cost' => 10,
        'source' => 'opening_balance',
    ])->assertJsonMissingValidationErrors('warehouse_id');
});

test('operation numbers stay globally unique across units', function () {
    // The expected-number query must ignore unit scoping, or two units would both
    // be told "next is 1" and collide on the unique index.
    ($this->asA)()->postJson('/api/v1/production-batches', [
        'operation_number' => 500, // already used by unit B
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('operation_number');
});

test('a unit-scoped user cannot read another unit employee', function () {
    ($this->asA)()->getJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit employee', function () {
    ($this->asA)()->putJson("/api/v1/employees/{$this->employeeB->id}", [
        'job_title' => 'Senior Cutter Operator',
        'record_version' => $this->employeeB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->employeeB->fresh()->job_title)->toBe('Cutter Operator');
});

test('a unit-scoped user cannot delete another unit employee', function () {
    ($this->asA)()->deleteJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->employeeB->fresh()->trashed())->toBeFalse();
});

test('a unit-scoped user cannot read another unit client', function () {
    ($this->asA)()->getJson("/api/v1/clients/{$this->clientB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit client', function () {
    ($this->asA)()->putJson("/api/v1/clients/{$this->clientB->id}", [
        'credit_limit' => 99999.00,
        'record_version' => $this->clientB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect((float) $this->clientB->fresh()->credit_limit)->toBe(30000.0);
});

test('a unit-scoped user cannot delete another unit client', function () {
    ($this->asA)()->deleteJson("/api/v1/clients/{$this->clientB->id}")->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect(Client::withoutGlobalScopes()->whereKey($this->clientB->id)->exists())->toBeTrue();
});

test('employee and client listings exclude other units', function () {
    ($this->asA)()->getJson('/api/v1/employees')->assertStatus(200)->assertJsonCount(0, 'data');
    ($this->asA)()->getJson('/api/v1/clients')->assertStatus(200)->assertJsonCount(0, 'data');
});

test('a company-wide owner can read employees and clients across all units', function () {
    $this->actingAs($this->owner)->getJson("/api/v1/employees/{$this->employeeB->id}")
        ->assertStatus(200)->assertJsonPath('data.id', $this->employeeB->id);

    $this->actingAs($this->owner)->getJson("/api/v1/clients/{$this->clientB->id}")
        ->assertStatus(200)->assertJsonPath('data.id', $this->clientB->id);
});

test('the listing unit filter is ignored for unit-scoped callers', function () {
    // ?operating_unit_id= exists so company-wide admins can drill into one
    // unit; in unit-scoped hands it must not become a scope bypass.
    ($this->asA)()->getJson("/api/v1/clients?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(0, 'data');

    ($this->asA)()->getJson("/api/v1/employees?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(0, 'data');
});

test('a company-wide owner can drill a listing into one unit', function () {
    $this->actingAs($this->owner)->getJson("/api/v1/clients?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->clientB->id);

    $this->actingAs($this->owner)->getJson("/api/v1/employees?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->employeeB->id);

    // Drilling into unit A finds nothing — B's rows do not tag along.
    $this->actingAs($this->owner)->getJson("/api/v1/clients?operating_unit_id={$this->unitA->id}")
        ->assertStatus(200)->assertJsonCount(0, 'data');
});

test('an owner pinned to a unit by header can still drill into another unit', function () {
    // The desktop pins a unit even for the owner (AppShell auto-select), so the
    // drill-down must lift the ambient scope for company-wide callers.
    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/clients?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->clientB->id);
});

test('the unit drill-down does not resurface soft-deleted employees', function () {
    // Lifting every global scope (the old withoutGlobalScopes) also dropped
    // SoftDeletes; only the unit scope may be lifted.
    app(CurrentUnitContext::class)->clear();
    $this->employeeB->delete();

    $this->actingAs($this->owner)->getJson("/api/v1/employees?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->assertJsonCount(0, 'data');
});

test('the report unit filter cannot read another unit ledger', function () {
    app(CurrentUnitContext::class)->clear();
    app(AccountingService::class)->postJournal('Unit B opening', [
        ['account_code' => '111', 'debit' => 750.0, 'operating_unit_id' => $this->unitB->id],
        ['account_code' => '31', 'credit' => 750.0, 'operating_unit_id' => $this->unitB->id],
    ]);

    // A unit-A caller asking for unit B's trial balance gets their own (empty) books.
    $tb = ($this->asA)()->getJson("/api/v1/reports/trial-balance?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->json();
    expect((float) $tb['total_debit'])->toBe(0.0);

    // The same filter in company-wide hands reads unit B for real.
    $tb = $this->actingAs($this->owner)->getJson("/api/v1/reports/trial-balance?operating_unit_id={$this->unitB->id}")
        ->assertStatus(200)->json();
    expect((float) $tb['total_debit'])->toBe(750.0);
});

test('a unit-scoped user cannot read or modify another unit supplier', function () {
    ($this->asA)()->getJson("/api/v1/suppliers/{$this->supplierB->id}")->assertNotFound();
    ($this->asA)()->putJson("/api/v1/suppliers/{$this->supplierB->id}", ['name' => 'Hacked'])->assertNotFound();
});

test('a unit-scoped user cannot read another unit import order', function () {
    ($this->asA)()->getJson("/api/v1/purchase-orders/{$this->purchaseOrderB->id}")->assertNotFound();
});

test('cash accounts are company-wide and visible from any unit', function () {
    $res = ($this->asA)()->getJson('/api/v1/cash-accounts');
    $res->assertOk();
    expect(collect($res->json('data'))->pluck('id'))->toContain($this->cashAccountB->id);
});

test('stock lot quantity cannot be directly modified via update (INV-06 invariant)', function () {
    $lotA = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-A-INV06',
        'quantity' => 10,
        'unit_cost' => 5,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    ($this->asA)()->putJson("/api/v1/stock-lots/{$lotA->id}", [
        'quantity' => 999,
        'record_version' => $lotA->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('quantity');
});
