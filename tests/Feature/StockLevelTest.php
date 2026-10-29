<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InternalRestockRequest;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\ItemStockLevel;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-02: reorder levels (FR-STK-007, BR-STK-004) and shelf locations (FR-STK-004).
 */
beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Levels Co', 'default_currency' => 'LYD']);
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
    $this->backWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Back WH']);
    $this->foreignWarehouse = Warehouse::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Factory WH']);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
    $this->owner = $makeUser('owner', null);
    $this->manager = $makeUser('inventory-manager', $this->unit->id);

    $this->fabrics = ItemCategory::create(['name' => 'Fabrics', 'code' => 'FAB']);
    $this->fabric = InventoryItem::create(['name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter', 'category_id' => $this->fabrics->id]);
    $this->glue = InventoryItem::create(['name' => 'Glue', 'code' => 'GLU-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);
    $this->sofa = InventoryItem::create(['name' => 'Sofa', 'code' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece']);

    $this->lot = fn (InventoryItem $item, float $quantity, string $status = 'available', ?Warehouse $warehouse = null) => StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        'lot_number' => 'LOT-'.$item->code.'-'.uniqid(), 'quantity' => $quantity, 'unit_cost' => 5, 'status' => $status,
    ]);

    $this->level = fn (InventoryItem $item, float $min, float $max, ?string $bin = null, ?Warehouse $warehouse = null) => ItemStockLevel::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        'min_qty' => $min, 'max_qty' => $max, 'bin_location' => $bin,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('levels are set per item per warehouse', function () {
    $response = ($this->as)($this->manager)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'min_qty' => 10,
        'max_qty' => 50,
        'reorder_qty' => 40,
        'bin_location' => ' A-01-03 ',
    ])->assertCreated();

    expect((float) $response->json('min_qty'))->toBe(10.0)
        ->and((float) $response->json('max_qty'))->toBe(50.0)
        ->and((float) $response->json('reorder_qty'))->toBe(40.0)
        ->and($response->json('bin_location'))->toBe('A-01-03');

    $list = ($this->as)($this->manager)->getJson('/api/v1/item-stock-levels?inventory_item_id='.$this->fabric->id)->assertOk();
    expect($list->json())->toHaveCount(1);

    ($this->as)($this->manager)->putJson('/api/v1/item-stock-levels/'.$response->json('id'), [
        'max_qty' => 60,
        'record_version' => $response->json('record_version'),
    ])->assertOk()->assertJsonPath('bin_location', 'A-01-03');

    expect((float) ItemStockLevel::sole()->max_qty)->toBe(60.0);
});

test('BR-STK-004: the maximum cannot be less than the minimum', function () {
    ($this->as)($this->manager)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'min_qty' => 20,
        'max_qty' => 10,
    ])->assertStatus(422)->assertJsonPath('code', 'STOCK_LEVEL_INVALID');

    expect(ItemStockLevel::count())->toBe(0);

    $level = ($this->level)($this->fabric, 5, 10);

    ($this->as)($this->manager)->putJson("/api/v1/item-stock-levels/{$level->id}", [
        'min_qty' => 15,
        'record_version' => $level->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'STOCK_LEVEL_INVALID');

    expect((float) $level->fresh()->min_qty)->toBe(5.0);
});

test('an item has one level row per warehouse', function () {
    ($this->level)($this->fabric, 5, 10);

    ($this->as)($this->manager)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'min_qty' => 1,
        'max_qty' => 2,
    ])->assertStatus(422)->assertJsonPath('code', 'STOCK_LEVEL_EXISTS');

    ($this->as)($this->manager)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->backWarehouse->id,
        'min_qty' => 1,
        'max_qty' => 2,
    ])->assertCreated();
});

test('levels cannot be set on another unit\'s warehouse', function () {
    ($this->as)($this->manager)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->foreignWarehouse->id,
        'min_qty' => 1,
        'max_qty' => 2,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
});

test('the reorder report suggests max minus on hand once on hand reaches the minimum', function () {
    ($this->level)($this->fabric, 10, 50);
    ($this->lot)($this->fabric, 4);
    ($this->lot)($this->fabric, 3.5);
    ($this->lot)($this->fabric, 100, 'reserved');
    ($this->lot)($this->fabric, 6, 'quarantined');
    ($this->lot)($this->fabric, 0, 'consumed');
    ($this->lot)($this->fabric, 30, 'available', $this->backWarehouse);

    ($this->level)($this->glue, 10, 40);
    ($this->lot)($this->glue, 10); // exactly at the minimum

    ($this->level)($this->sofa, 2, 8);
    ($this->lot)($this->sofa, 5); // above the minimum

    $report = ($this->as)($this->manager)->getJson('/api/v1/inventory/reorder-report')->assertOk()->json();
    $rows = collect($report['rows'])->keyBy('inventory_item_id');

    expect($report['rows'])->toHaveCount(3)
        ->and($report['summary'])->toBe(['rows' => 3, 'needs_reorder_count' => 2])
        ->and($rows[$this->fabric->id]['on_hand'])->toEqual(7.5)
        ->and($rows[$this->fabric->id]['needs_reorder'])->toBeTrue()
        ->and($rows[$this->fabric->id]['suggested_qty'])->toEqual(42.5)
        ->and($rows[$this->glue->id]['needs_reorder'])->toBeTrue()
        ->and($rows[$this->glue->id]['suggested_qty'])->toEqual(30)
        ->and($rows[$this->sofa->id]['on_hand'])->toEqual(5)
        ->and($rows[$this->sofa->id]['needs_reorder'])->toBeFalse()
        ->and($rows[$this->sofa->id]['suggested_qty'])->toEqual(0)
        ->and($report['rows'][2]['inventory_item_id'])->toBe($this->sofa->id);
});

test('an item with a level but no stock suggests the full maximum', function () {
    ($this->level)($this->glue, 0, 25);

    $row = ($this->as)($this->manager)->getJson('/api/v1/inventory/reorder-report')->assertOk()->json('rows.0');

    expect($row['on_hand'])->toEqual(0)
        ->and($row['needs_reorder'])->toBeTrue()
        ->and($row['suggested_qty'])->toEqual(25);
});

test('a level row with no maximum only records the bin and stays out of the report', function () {
    ($this->level)($this->glue, 0, 0, 'B-02');

    $report = ($this->as)($this->manager)->getJson('/api/v1/inventory/reorder-report')->assertOk()->json();

    expect($report['rows'])->toBe([]);
});

test('the reorder report filters by warehouse and category and stays in the unit', function () {
    ($this->level)($this->fabric, 10, 50);
    ($this->level)($this->glue, 10, 50);
    ($this->level)($this->glue, 10, 50, null, $this->backWarehouse);
    ($this->level)($this->glue, 10, 50, null, $this->foreignWarehouse);

    $all = ($this->as)($this->manager)->getJson('/api/v1/inventory/reorder-report')->assertOk()->json('rows');
    expect($all)->toHaveCount(3)
        ->and(collect($all)->pluck('warehouse_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->warehouse->id, $this->backWarehouse->id])->sort()->values()->all());

    $byWarehouse = ($this->as)($this->manager)
        ->getJson('/api/v1/inventory/reorder-report?warehouse_id='.$this->backWarehouse->id)->assertOk()->json('rows');
    expect($byWarehouse)->toHaveCount(1)
        ->and($byWarehouse[0]['inventory_item_id'])->toBe($this->glue->id);

    $byCategory = ($this->as)($this->manager)
        ->getJson('/api/v1/inventory/reorder-report?category_id='.$this->fabrics->id)->assertOk()->json('rows');
    expect($byCategory)->toHaveCount(1)
        ->and($byCategory[0]['inventory_item_id'])->toBe($this->fabric->id);

    ($this->as)($this->manager)
        ->getJson('/api/v1/inventory/reorder-report?warehouse_id='.$this->foreignWarehouse->id)
        ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
});

test('intake defaults the lot bin from the level row', function () {
    ($this->level)($this->fabric, 0, 0, 'A-01');

    $defaulted = ($this->as)($this->manager)->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'unit_cost' => 5,
        'source' => 'opening_balance',
    ])->assertCreated();

    expect($defaulted->json('bin_location'))->toBe('A-01');

    $given = ($this->as)($this->manager)->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'bin_location' => 'C-09',
        'quantity' => 2,
        'unit_cost' => 5,
        'source' => 'opening_balance',
    ])->assertCreated();

    expect($given->json('bin_location'))->toBe('C-09');

    $noLevel = ($this->as)($this->manager)->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->backWarehouse->id,
        'quantity' => 2,
        'unit_cost' => 5,
        'source' => 'opening_balance',
    ])->assertCreated();

    expect($noLevel->json('bin_location'))->toBeNull();
});

test('a warehouse transfer gives the new lots the destination bin', function () {
    ($this->level)($this->fabric, 0, 0, 'BACK-7', $this->backWarehouse);
    $source = ($this->lot)($this->fabric, 10);
    $source->update(['bin_location' => 'MAIN-1']);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-BIN',
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->backWarehouse->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->fabric->id, 'quantity' => 4]);

    ($this->as)($this->manager)->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete")->assertOk();

    $landed = StockLot::where('warehouse_id', $this->backWarehouse->id)->sole();

    expect($landed->bin_location)->toBe('BACK-7')
        ->and($source->fresh()->bin_location)->toBe('MAIN-1');
});

test('a lot bin is editable and searchable', function () {
    $lot = ($this->lot)($this->fabric, 3);
    ($this->lot)($this->glue, 3);

    ($this->as)($this->manager)->putJson("/api/v1/stock-lots/{$lot->id}", [
        'bin_location' => 'R2-S4',
        'record_version' => $lot->record_version,
    ])->assertOk()->assertJsonPath('bin_location', 'R2-S4');

    $found = ($this->as)($this->manager)->getJson('/api/v1/stock-lots?bin_location=r2')->assertOk()->json('data');

    expect($found)->toHaveCount(1)
        ->and($found[0]['id'])->toBe($lot->id);
});

test('the dashboard counts low stock company-wide', function () {
    ($this->level)($this->fabric, 10, 50);
    ($this->lot)($this->fabric, 4);
    ($this->level)($this->glue, 10, 50, null, $this->foreignWarehouse);
    ($this->level)($this->sofa, 1, 5);
    ($this->lot)($this->sofa, 3);

    $kpis = $this->actingAs($this->owner)->getJson('/api/v1/dashboard/kpis')->assertOk()->json();

    expect($kpis['low_stock_count'])->toBe(2);
});

test('the count sheet shows each lot bin', function () {
    $lot = ($this->lot)($this->fabric, 3);
    $lot->update(['bin_location' => 'A-04']);

    $count = ($this->as)($this->manager)
        ->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->warehouse->id])
        ->assertCreated()->json();

    expect($count['lines'][0]['bin_location'])->toBe('A-04');

    $sheet = ($this->as)($this->manager)->getJson("/api/v1/stock-counts/{$count['id']}/sheet")->assertOk()->json();

    expect($sheet['rows'][0]['bin_location'])->toBe('A-04');
});

test('another unit\'s level row is not found', function () {
    $foreign = ($this->level)($this->fabric, 1, 5, 'F-1', $this->foreignWarehouse);

    ($this->as)($this->manager)->getJson("/api/v1/item-stock-levels/{$foreign->id}")->assertNotFound();
    ($this->as)($this->manager)->putJson("/api/v1/item-stock-levels/{$foreign->id}", [
        'max_qty' => 9,
        'record_version' => $foreign->record_version,
    ])->assertNotFound();
    ($this->as)($this->manager)->deleteJson("/api/v1/item-stock-levels/{$foreign->id}")->assertNotFound();

    expect(ItemStockLevel::withoutGlobalScopes()->whereKey($foreign->id)->value('max_qty'))->toEqual(5);
});

test('a view-only role can read levels but not write them or a lot bin', function () {
    // The role keeps view-inventory; edit-inventory is revoked.
    $viewer = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Store manager', 'slug' => 'store-manager']);
    $role->permissions()->detach(Permission::where('slug', 'edit-inventory')->value('id'));
    UserRole::create(['user_id' => $viewer->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $level = ($this->level)($this->fabric, 1, 5);
    $lot = ($this->lot)($this->fabric, 3);

    ($this->as)($viewer)->getJson('/api/v1/item-stock-levels')->assertOk();

    ($this->as)($viewer)->postJson('/api/v1/item-stock-levels', [
        'inventory_item_id' => $this->glue->id,
        'warehouse_id' => $this->warehouse->id,
        'max_qty' => 5,
    ])->assertForbidden();
    ($this->as)($viewer)->putJson("/api/v1/item-stock-levels/{$level->id}", [
        'max_qty' => 9,
        'record_version' => $level->record_version,
    ])->assertForbidden();
    ($this->as)($viewer)->deleteJson("/api/v1/item-stock-levels/{$level->id}")->assertForbidden();
    ($this->as)($viewer)->putJson("/api/v1/stock-lots/{$lot->id}", [
        'bin_location' => 'X-1',
        'record_version' => $lot->record_version,
    ])->assertForbidden();

    expect(ItemStockLevel::count())->toBe(1)
        ->and($lot->fresh()->bin_location)->toBeNull();
});

test('a stale record version cannot overwrite a level', function () {
    $level = ($this->level)($this->fabric, 1, 5);
    $stale = $level->record_version;

    ($this->as)($this->manager)->putJson("/api/v1/item-stock-levels/{$level->id}", [
        'max_qty' => 8,
        'record_version' => $stale,
    ])->assertOk();

    ($this->as)($this->manager)->putJson("/api/v1/item-stock-levels/{$level->id}", [
        'max_qty' => 9,
        'record_version' => $stale,
    ])->assertStatus(409)->assertJsonPath('code', 'OPTIMISTIC_LOCK_CONFLICT');

    expect((float) $level->fresh()->max_qty)->toBe(8.0);
});

test('an internal restock lands in the receiving warehouse bin', function () {
    ($this->level)($this->fabric, 0, 0, 'IN-5');
    ($this->lot)($this->fabric, 10, 'available', $this->foreignWarehouse);

    $request = InternalRestockRequest::create([
        'request_number' => 'RST-BIN-1',
        'requesting_unit_id' => $this->unit->id,
        'source_unit_id' => $this->otherUnit->id,
        'status' => 'approved',
    ]);
    $request->lines()->create(['inventory_item_id' => $this->fabric->id, 'quantity' => 4]);

    app(SalesOrderService::class)->fulfillRestock($request);

    $landed = StockLot::withoutGlobalScopes()->where('warehouse_id', $this->warehouse->id)->sole();

    expect((float) $landed->quantity)->toBe(4.0)
        ->and($landed->bin_location)->toBe('IN-5');
});
