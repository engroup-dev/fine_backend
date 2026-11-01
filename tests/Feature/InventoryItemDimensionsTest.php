<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
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

test('creating an inventory item with dimensions computes volume_m3', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'category_id' => itemCategoryId(),
            'name' => 'قالب 14-12',
            'code' => 'FOAM-1412',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
            'length_m' => 1.00,
            'width_m' => 2.00,
            'height_m' => 2.40,
        ]);

    $response->assertStatus(201);
    expect((float) InventoryItem::find($response->json('id'))->volume_m3)->toBe(4.8);
});

test('creating an inventory item with only some dimensions leaves volume_m3 null', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'category_id' => itemCategoryId(),
            'name' => 'قالب غير مكتمل القياس',
            'code' => 'FOAM-PARTIAL',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
            'length_m' => 1.00,
        ]);

    $response->assertStatus(201)->assertJsonPath('volume_m3', null);
});

test('rejects a negative dimension', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'category_id' => itemCategoryId(),
            'name' => 'قالب خاطئ',
            'code' => 'FOAM-NEG',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
            'width_m' => -1,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['width_m']);
});

test('updating an inventory item dimensions recompute volume_m3', function () {
    $item = InventoryItem::create([
        'name' => 'قالب 14-12',
        'code' => 'FOAM-1412',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
        'length_m' => 1.00,
        'width_m' => 2.00,
        'height_m' => 2.40,
    ]);

    expect((float) $item->fresh()->volume_m3)->toBe(4.8);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$item->id}", [
            'height_m' => 1.20,
        ]);

    $response->assertStatus(200);
    expect((float) $item->fresh()->volume_m3)->toBe(2.4);
});
