<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-03: item barcodes and the scan lookup (barcode, then item code, then lot number).
 */
beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Barcode Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'code' => $code, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->unit = $makeUnit('Showroom', 'STORE-01');
    $this->otherUnit = $makeUnit('Factory', 'FACT-01');
    seedUnitAccounts([$this->unit, $this->otherUnit]);

    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Main WH']);
    $this->foreignWarehouse = Warehouse::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Factory WH']);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
    $this->manager = $makeUser('inventory-manager', $this->unit->id);
    $this->cashier = $makeUser('pos-cashier', $this->unit->id);

    $this->sofa = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-1', 'barcode' => '6291234567890',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ]);
    $this->block = InventoryItem::create([
        'name' => 'Foam block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'piece',
    ]);

    $this->lot = fn (InventoryItem $item, string $lotNumber, ?Warehouse $warehouse = null) => StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        'lot_number' => $lotNumber, 'quantity' => 1, 'unit_cost' => 5, 'status' => 'available',
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('a barcode resolves to its item', function () {
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=6291234567890')
        ->assertOk()
        ->assertJsonPath('type', 'item')
        ->assertJsonPath('item.id', $this->sofa->id)
        ->assertJsonMissingPath('lot');
});

test('an item code resolves to its item', function () {
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=BLK-1')
        ->assertOk()
        ->assertJsonPath('type', 'item')
        ->assertJsonPath('item.id', $this->block->id);
});

test('a lot number resolves to the lot and its item', function () {
    $lot = ($this->lot)($this->block, '12-25-1');

    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=12-25-1')
        ->assertOk()
        ->assertJsonPath('type', 'lot')
        ->assertJsonPath('item.id', $this->block->id)
        ->assertJsonPath('lot.id', $lot->id)
        ->assertJsonPath('lot.warehouse.id', $this->warehouse->id);
});

test('a barcode wins over an item code, and an item code wins over a lot number', function () {
    // The API refuses this clash (see the cross-column tests below); the lookup
    // order is the fallback for rows written some other way.
    InventoryItem::create([
        'name' => 'Clash', 'code' => '6291234567890', 'item_type' => 'raw_material', 'unit_of_measure' => 'piece',
    ]);
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=6291234567890')
        ->assertOk()
        ->assertJsonPath('item.id', $this->sofa->id);

    // A lot numbered like an item code: the item code match comes first.
    ($this->lot)($this->sofa, 'BLK-1');
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=BLK-1')
        ->assertOk()
        ->assertJsonPath('type', 'item')
        ->assertJsonPath('item.id', $this->block->id);
});

test('matching is exact', function () {
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=SOFA')
        ->assertNotFound()
        ->assertJsonPath('code', 'CODE_NOT_FOUND');
});

test('a lot in another unit does not resolve', function () {
    ($this->lot)($this->block, 'LOT-FACTORY-1', $this->foreignWarehouse);

    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=LOT-FACTORY-1')
        ->assertNotFound()
        ->assertJsonPath('code', 'CODE_NOT_FOUND');
});

test('the lookup requires a code', function () {
    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('an item is created with a barcode', function () {
    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'CHR-1', 'barcode' => 'CHR-1-0001',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertCreated()->assertJsonPath('barcode', 'CHR-1-0001');

    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Stool', 'code' => 'STL-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertCreated()->assertJsonPath('barcode', null);
});

test('a barcode is unique across items', function () {
    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'CHR-1', 'barcode' => '6291234567890',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->block->id}", [
        'barcode' => '6291234567890',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');
});

test('an item keeps, changes or clears its own barcode', function () {
    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'name' => 'Sofa 3-seat', 'barcode' => '6291234567890',
    ])->assertOk()->assertJsonPath('barcode', '6291234567890');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'barcode' => 'SOFA-1-0001',
    ])->assertOk()->assertJsonPath('barcode', 'SOFA-1-0001');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'barcode' => null,
    ])->assertOk()->assertJsonPath('barcode', null);
});

test('a barcode holds printable ASCII only', function () {
    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'CHR-1', 'barcode' => 'كرسي-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');
});

test('a barcode may not equal another item\'s code', function () {
    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'CHR-1', 'barcode' => 'BLK-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'barcode' => 'BLK-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');

    // An item's barcode may repeat its own code.
    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->block->id}", [
        'barcode' => 'BLK-1',
    ])->assertOk()->assertJsonPath('barcode', 'BLK-1');
});

test('an item code may not equal another item\'s barcode', function () {
    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => '6291234567890',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->block->id}", [
        'code' => '6291234567890',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});

test('a barcode may not equal a lot number, in any unit', function () {
    ($this->lot)($this->block, 'LOT-FACTORY-1', $this->foreignWarehouse);

    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'CHR-1', 'barcode' => 'LOT-FACTORY-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'barcode' => 'LOT-FACTORY-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('barcode');
});

test('an item can still be edited when a later lot number matches its barcode', function () {
    ($this->lot)($this->block, '6291234567890');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'name' => 'Sofa 3-seat', 'code' => 'SOFA-1', 'barcode' => '6291234567890',
    ])->assertOk()->assertJsonPath('name', 'Sofa 3-seat');
});

test('an item code may not equal a lot number, in any unit', function () {
    ($this->lot)($this->block, 'LOT-FACTORY-1', $this->foreignWarehouse);

    ($this->as)($this->manager)->postJson('/api/v1/inventory-items', [
        'name' => 'Chair', 'code' => 'LOT-FACTORY-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'code' => 'LOT-FACTORY-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect($this->sofa->fresh()->code)->toBe('SOFA-1');
});

test('an item can still be edited when a later lot number matches its code', function () {
    ($this->lot)($this->sofa, 'BLK-1');

    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->block->id}", [
        'name' => 'Foam block 2', 'code' => 'BLK-1',
    ])->assertOk()->assertJsonPath('name', 'Foam block 2');
});

test('an update that omits the barcode keeps it', function () {
    ($this->as)($this->manager)->putJson("/api/v1/inventory-items/{$this->sofa->id}", [
        'name' => 'Sofa 3-seat',
    ])->assertOk()->assertJsonPath('barcode', '6291234567890');

    expect($this->sofa->fresh()->barcode)->toBe('6291234567890');
});

test('a soft-deleted item\'s barcode does not resolve', function () {
    $this->sofa->delete();

    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=6291234567890')
        ->assertNotFound()
        ->assertJsonPath('code', 'CODE_NOT_FOUND');
});

test('a lot whose item is soft-deleted does not resolve', function () {
    ($this->lot)($this->block, '12-25-1');
    $this->block->delete();

    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=12-25-1')
        ->assertNotFound()
        ->assertJsonPath('code', 'CODE_NOT_FOUND');
});

test('a sold lot still resolves, with its status', function () {
    $lot = ($this->lot)($this->block, '12-25-1');
    $lot->update(['status' => 'sold']);

    ($this->as)($this->cashier)->getJson('/api/v1/inventory/lookup?code=12-25-1')
        ->assertOk()
        ->assertJsonPath('type', 'lot')
        ->assertJsonPath('lot.id', $lot->id)
        ->assertJsonPath('lot.status', 'sold');
});

test('the lookup refuses an unauthenticated request', function () {
    $this->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/lookup?code=BLK-1')
        ->assertUnauthorized();
});

test('the lookup refuses a user without inventory access', function () {
    // A role outside the inventory group.
    $outsider = User::factory()->create(['must_change_password' => false]);
    $hrRole = Role::firstOrCreate(['slug' => 'hr-manager'], ['name' => 'hr-manager']);
    UserRole::create(['user_id' => $outsider->id, 'role_id' => $hrRole->id, 'operating_unit_id' => $this->unit->id]);

    ($this->as)($outsider)->getJson('/api/v1/inventory/lookup?code=BLK-1')->assertForbidden();

    // A role inside the group whose view-inventory and edit-inventory are revoked.
    $revoked = User::factory()->create(['must_change_password' => false]);
    $assembler = Role::firstOrCreate(['slug' => 'assembler'], ['name' => 'assembler']);
    $assembler->permissions()->detach(Permission::whereIn('slug', ['view-inventory', 'edit-inventory'])->pluck('id'));
    UserRole::create(['user_id' => $revoked->id, 'role_id' => $assembler->id, 'operating_unit_id' => $this->unit->id]);

    ($this->as)($revoked)->getJson('/api/v1/inventory/lookup?code=BLK-1')
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCESS_DENIED');
});
