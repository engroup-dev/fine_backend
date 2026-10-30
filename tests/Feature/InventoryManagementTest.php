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
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

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
        'code' => 'WH-MAIN-01',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    $this->role = Role::create([
        'name' => 'Inventory Manager',
        'slug' => 'inventory_manager',
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('can create and list inventory items', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'name' => 'Polyol Standard',
            'code' => 'RAW-POLY-01',
            'item_type' => 'raw_material',
            'unit_of_measure' => 'kg',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('code', 'RAW-POLY-01');

    $listResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory-items');

    $listResponse->assertStatus(200)
        ->assertJsonFragment(['code' => 'RAW-POLY-01']);
});

test('can create stock lots and filter available blocks for cutter selection', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 30Density',
        'code' => 'BLOCK-30D',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $lot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-FB-101',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 500.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    expect((float) $lot->volume_m3)->toBe(4.0);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/stock-lots/available-for-cutting?min_volume_m3=3.0&grade=standard');

    $response->assertStatus(200)
        ->assertJsonFragment(['lot_number' => 'LOT-FB-101']);
});

test('can retrieve inventory valuation summary and company rollup', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block Super',
        'code' => 'BLOCK-SUPER',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-VAL-01',
        'quantity' => 1.0,
        'unit_cost' => 1000.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $valResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/valuation');

    $valResponse->assertStatus(200);
    expect((float) $valResponse->json('stock_lot_valuation'))->toBe(1000.0);

    $rollupResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/valuation/rollup');

    $rollupResponse->assertStatus(200);
    expect((float) $rollupResponse->json('company_total_valuation'))->toBe(1000.0);
});

test('cutter operator can process cut remnant by restocking remnant block with new dimensions (Option C)', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 30D',
        'code' => 'BLOCK-30D-CUT',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $parentLot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-CUT-PARENT-01',
        'bin_location' => 'CUT-3',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 600.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);
    $fill = InventoryItem::create([
        'name' => 'Byproduct Fill', 'code' => 'FILL-CUT-01', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-lots/{$parentLot->id}/process-cut-remnant", [
            'remnant_action' => 'restock_remnant',
            'remnant_dimensions' => [
                'length_m' => 1.2,
                'width_m' => 2.0,
                'height_m' => 1.0,
            ],
            'byproduct_weight_kg' => 5.5,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('parent_lot.status', 'consumed')
        ->assertJsonPath('remnant_lot.length_m', '1.200')
        ->assertJsonPath('remnant_lot.volume_m3', '2.4000')
        ->assertJsonPath('remnant_lot.bin_location', 'CUT-3');

    $parentLot->refresh();
    expect($parentLot->status)->toBe('consumed');

    // W4-06: the 2.4 m³ remnant takes 2.4/4 of the 600; the fill lot takes
    // the other 240 at a per-kg price. Nothing is carried twice.
    expect((float) $response->json('remnant_lot.unit_cost'))->toBe(360.0)
        ->and($response->json('byproduct_lot.inventory_item_id'))->toBe($fill->id)
        ->and((float) $response->json('byproduct_lot.quantity'))->toBe(5.5)
        ->and(round((float) $response->json('byproduct_lot.unit_cost') * 5.5, 2))->toBe(240.0)
        ->and((float) $parentLot->quantity)->toBe(0.0);
});

test('cutter operator can process cut remnant by converting remaining block to byproduct fill (Option C)', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 25D',
        'code' => 'BLOCK-25D-BYPROD',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $parentLot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-CUT-PARENT-02',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 450.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);
    InventoryItem::create([
        'name' => 'Byproduct Fill', 'code' => 'FILL-CUT-02', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-lots/{$parentLot->id}/process-cut-remnant", [
            'remnant_action' => 'convert_to_byproduct',
            'byproduct_weight_kg' => 25.0,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('parent_lot.status', 'consumed')
        ->assertJsonPath('remnant_lot', null);

    $parentLot->refresh();
    expect($parentLot->status)->toBe('consumed')
        // W4-06: with no remnant the whole 450 rides on the 25 kg of fill.
        ->and((float) $response->json('byproduct_lot.unit_cost'))->toBe(18.0);
});

test('can update an inventory item', function () {
    $item = InventoryItem::create([
        'name' => 'Original Item',
        'code' => 'ITEM-ORIG-01',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'primary_uom' => 'barrel',
        'secondary_uom' => 'kg',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$item->id}", [
            'name' => 'Updated Item Name',
            'code' => 'ITEM-ORIG-01',
            'unit_of_measure' => 'liter',
            'primary_uom' => 'drum',
            'secondary_uom' => 'liter',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('name', 'Updated Item Name')
        ->assertJsonPath('unit_of_measure', 'liter')
        ->assertJsonPath('primary_uom', 'drum')
        ->assertJsonPath('secondary_uom', 'liter');

    $item->refresh();
    expect($item->name)->toBe('Updated Item Name')
        ->and($item->unit_of_measure)->toBe('liter')
        ->and($item->primary_uom)->toBe('drum');
});

test('updating inventory item with duplicate SKU is rejected', function () {
    $item1 = InventoryItem::create([
        'name' => 'First Item',
        'code' => 'ITEM-SKU-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $item2 = InventoryItem::create([
        'name' => 'Second Item',
        'code' => 'ITEM-SKU-2',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$item2->id}", [
            'code' => 'ITEM-SKU-1',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});
