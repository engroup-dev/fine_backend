<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Store Manager', 'slug' => 'store_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);
});

test('an inventory item can be created with cost, purchase, selling, and wholesale prices', function () {
    $response = ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => itemCategoryId(),
        'name' => 'Deluxe Pillow',
        'code' => 'PIL-DELUXE-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'each',
        'cost_price' => 25.5,
        'purchase_price' => 22.0,
        'selling_price' => 45.0,
        'wholesale_price' => 35.0,
        'price_basis' => 'unit',
    ])->assertCreated();

    $item = InventoryItem::where('code', 'PIL-DELUXE-01')->firstOrFail();
    expect((float) $item->cost_price)->toEqual(25.5)
        ->and((float) $item->purchase_price)->toEqual(22.0)
        ->and((float) $item->selling_price)->toEqual(45.0)
        ->and((float) $item->wholesale_price)->toEqual(35.0);

    expect($item->priceFor(2))->toEqual(90.0)
        ->and($item->wholesalePriceFor(2))->toEqual(70.0);
});

test('inventory item prices can be updated', function () {
    $item = InventoryItem::create([
        'name' => 'Standard Foam Sheet',
        'code' => 'FOAM-STD-01',
        'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each',
        'length_m' => 2.0,
        'width_m' => 1.0,
        'height_m' => 0.1,
        'cost_price' => 15.0,
        'purchase_price' => 12.0,
        'selling_price' => 30.0,
        'wholesale_price' => 25.0,
        'price_basis' => 'unit',
    ]);

    ($this->api)()->putJson("/api/v1/inventory-items/{$item->id}", [
        'cost_price' => 18.0,
        'wholesale_price' => 28.0,
    ])->assertOk();

    $fresh = $item->fresh();
    expect((float) $fresh->cost_price)->toEqual(18.0)
        ->and((float) $fresh->wholesale_price)->toEqual(28.0)
        ->and((float) $fresh->purchase_price)->toEqual(12.0)
        ->and((float) $fresh->selling_price)->toEqual(30.0);
});

test('m3 price basis calculates wholesale price by volume', function () {
    $item = InventoryItem::create([
        'name' => 'Bulk Foam Block',
        'code' => 'BLK-D30-01',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
        'length_m' => 2.0,
        'width_m' => 1.5,
        'height_m' => 1.0, // Volume = 3.0 m3
        'selling_price' => 200.0,
        'wholesale_price' => 180.0,
        'price_basis' => 'm3',
    ]);

    expect($item->wholesalePriceFor(1))->toEqual(540.0) // 180 * 3.0 * 1
        ->and($item->priceFor(1))->toEqual(600.0); // 200 * 3.0 * 1
});
