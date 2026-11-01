<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
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

test('can store and query stock lots by dynamic JSON attributes', function () {
    $category = ItemCategory::create([
        'name' => 'Foam Blocks',
        'code' => 'CAT-FOAM-01',
    ]);

    $item = InventoryItem::create([
        'category_id' => $category->id,
        'name' => 'Block 35 Pressure',
        'code' => 'BLOCK-35P',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
        'primary_uom' => 'block',
        'secondary_uom' => 'm3',
    ]);

    // High Pressure Lot (35 kPa)
    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-HIGH-35P',
        // INV-02: a foam block lot is exactly one block.
        'quantity' => 1.0,
        'container_quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 800.0,
        'status' => 'available',
        'attribute_values' => [
            'pressure_kpa' => 35.0,
            'density_kg_m3' => 30.0,
        ],
    ]);

    // Low Pressure Lot (20 kPa)
    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-LOW-20P',
        // INV-02: a foam block lot is exactly one block.
        'quantity' => 1.0,
        'container_quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 500.0,
        'status' => 'available',
        'attribute_values' => [
            'pressure_kpa' => 20.0,
            'density_kg_m3' => 20.0,
        ],
    ]);

    // Filter lots with pressure_kpa >= 30
    $filterResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/stock-lots?attrs[pressure_kpa][gte]=30');

    $filterResponse->assertStatus(200)
        ->assertJsonFragment(['lot_number' => 'LOT-HIGH-35P'])
        ->assertJsonMissing(['lot_number' => 'LOT-LOW-20P']);
});

test('can define and update inventory item with container capacity and dual UOMs', function () {
    // 1. Create item with barrel holding 200 liters
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'category_id' => itemCategoryId(),
            'name' => 'Polyol Special Chemical',
            'code' => 'POLY-SPEC-200',
            'item_type' => 'raw_material',
            'unit_of_measure' => 'liter',
            'primary_uom' => 'barrel',
            'secondary_uom' => 'liter',
            'container_capacity' => 200.0,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Polyol Special Chemical')
        ->assertJsonPath('code', 'POLY-SPEC-200')
        ->assertJsonPath('primary_uom', 'barrel')
        ->assertJsonPath('secondary_uom', 'liter');

    $itemId = $response->json('id');
    $item = InventoryItem::findOrFail($itemId);
    expect((float) $item->container_capacity)->toBe(200.0)
        ->and($item->primary_uom)->toBe('barrel')
        ->and($item->secondary_uom)->toBe('liter')
        ->and($item->tracksContainers())->toBeTrue();

    // 2. Update item to change container capacity to 220 liters
    $updateResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$itemId}", [
            'container_capacity' => 220.0,
        ]);

    $updateResponse->assertStatus(200);

    $item->refresh();
    expect((float) $item->container_capacity)->toBe(220.0);

    // 3. Clear container capacity (nullable)
    $clearResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$itemId}", [
            'container_capacity' => null,
        ]);

    $clearResponse->assertStatus(200);
    $item->refresh();
    expect($item->container_capacity)->toBeNull()
        ->and($item->tracksContainers())->toBeFalse();
});

test('item category supports item_type and auto-defaults into inventory items', function () {
    // 1. Create a category with a designated item_type
    $catResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/item-categories', [
            'name' => 'Raw Chemicals',
            'code' => 'CAT-CHEM-RAW',
            'item_type' => 'raw_material',
            'description' => 'Chemical raw materials',
        ]);

    $catResponse->assertStatus(201)
        ->assertJsonPath('item_type', 'raw_material');

    $categoryId = $catResponse->json('id');

    // 2. Create inventory item referencing this category without specifying item_type
    $itemResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'category_id' => $categoryId,
            'name' => 'TDI Chemical',
            'code' => 'CHEM-TDI-001',
            'unit_of_measure' => 'kg',
        ]);

    $itemResponse->assertStatus(201)
        ->assertJsonPath('item_type', 'raw_material')
        ->assertJsonPath('category.item_type', 'raw_material');

    // 3. Update category item_type
    $updateCatResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/item-categories/{$categoryId}", [
            'item_type' => 'barrel',
        ]);

    $updateCatResponse->assertStatus(200)
        ->assertJsonPath('item_type', 'barrel');
});
