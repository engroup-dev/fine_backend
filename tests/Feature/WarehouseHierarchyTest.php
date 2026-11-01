<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Factory',
        'code' => 'FOAM-01',
        'unit_type' => 'foam_manufactory',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->asUser = fn () => $this->actingAs($this->user)->withHeader('X-Operating-Unit-ID', $this->unit->id);
});

test('creating a sub-warehouse inherits the parent operating unit; a body unit is refused', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
        'code' => 'OTHER-01',
        'unit_type' => 'foam_manufactory',
    ]);

    // W6-06: operating_unit_id is never accepted in the body.
    ($this->asUser)()->postJson('/api/v1/warehouses', [
        'name' => 'Shelf A1',
        'parent_id' => $this->warehouse->id,
        'location_type' => 'SHELF',
        'operating_unit_id' => $otherUnit->id,
    ])->assertStatus(422)->assertJsonValidationErrors('operating_unit_id');

    $response = ($this->asUser)()->postJson('/api/v1/warehouses', [
        'name' => 'Shelf A1',
        'parent_id' => $this->warehouse->id,
        'location_type' => 'SHELF',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('operating_unit_id', $this->warehouse->operating_unit_id);
    $response->assertJsonPath('parent_id', $this->warehouse->id);
    $response->assertJsonPath('location_type', 'SHELF');
});

test('index returns only top-level warehouses with children embedded', function () {
    $child = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
        'location_type' => 'SHELF',
    ]);

    $response = ($this->asUser)()->getJson('/api/v1/warehouses');

    $response->assertOk();
    $ids = collect($response->json())->pluck('id');
    expect($ids)->toContain($this->warehouse->id);
    expect($ids)->not->toContain($child->id);

    $parentRow = collect($response->json())->firstWhere('id', $this->warehouse->id);
    expect(collect($parentRow['children'])->pluck('id'))->toContain($child->id);
});

test('a sub-warehouse cannot itself have sub-warehouses', function () {
    $child = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
        'location_type' => 'SHELF',
    ]);

    $response = ($this->asUser)()->postJson('/api/v1/warehouses', [
        'name' => 'Grandchild',
        'parent_id' => $child->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'NESTED_SUB_WAREHOUSE_NOT_ALLOWED');
});

test('deleting a warehouse with a sub-warehouse is blocked', function () {
    // A second top-level warehouse so the parent isn't also the unit's last
    // one — this test isolates the WAREHOUSE_HAS_CHILDREN guard specifically.
    Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Overflow Warehouse']);

    $child = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
    ]);

    $response = ($this->asUser)()->deleteJson("/api/v1/warehouses/{$this->warehouse->id}");

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'WAREHOUSE_HAS_CHILDREN');

    // Deleting the child first, then the parent, succeeds.
    ($this->asUser)()->deleteJson("/api/v1/warehouses/{$child->id}")->assertOk();
    ($this->asUser)()->deleteJson("/api/v1/warehouses/{$this->warehouse->id}")->assertOk();
});

test('the last-warehouse guard only counts top-level warehouses, not sub-warehouses', function () {
    Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
    ]);

    // $this->warehouse is the unit's only top-level warehouse; it still can't
    // be deleted even though the raw warehouses-row count for the unit is 2 —
    // (it's also blocked earlier by WAREHOUSE_HAS_CHILDREN, so delete the
    // child out of the way first to isolate the guard under test).
    $child = Warehouse::withoutGlobalScopes()->where('parent_id', $this->warehouse->id)->firstOrFail();
    ($this->asUser)()->deleteJson("/api/v1/warehouses/{$child->id}")->assertOk();

    $response = ($this->asUser)()->deleteJson("/api/v1/warehouses/{$this->warehouse->id}");
    $response->assertStatus(422);
    $response->assertJsonPath('code', 'CANNOT_DELETE_LAST_WAREHOUSE');
});

test('deleting a sub-warehouse never trips the last-warehouse guard', function () {
    $child = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
    ]);

    // $this->warehouse is the unit's only top-level warehouse, but deleting
    // its child sub-warehouse must never be blocked by that rule.
    ($this->asUser)()->deleteJson("/api/v1/warehouses/{$child->id}")->assertOk();
});

test('a sub-warehouse with stock lots cannot be deleted', function () {
    $child = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'parent_id' => $this->warehouse->id,
        'name' => 'Shelf A1',
    ]);

    $item = InventoryItem::create([
        'name' => 'Cotton Filler',
        'code' => 'FILL-COT',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    StockLot::factory()->create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $child->id,
        'status' => 'available',
    ]);

    $response = ($this->asUser)()->deleteJson("/api/v1/warehouses/{$child->id}");
    $response->assertStatus(422);
    $response->assertJsonPath('code', 'WAREHOUSE_NOT_EMPTY');
});
