<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Test Company',
        'default_currency' => 'LYD',
        'transfer_pricing_mode' => 'at_cost',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Test Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->user = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'must_change_password' => false,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('authenticated user can list work orders', function () {
    WorkOrder::create([
        'id' => (string) Str::uuid(),
        'operating_unit_id' => $this->unit->id,
        'product_sku' => 'SKU-TEST-1',
        'quantity' => 10,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/work-orders');

    $response->assertStatus(200)
        ->assertJsonFragment(['product_sku' => 'SKU-TEST-1']);
});

test('authenticated user can filter work orders by status', function () {
    WorkOrder::create([
        'id' => (string) Str::uuid(),
        'operating_unit_id' => $this->unit->id,
        'product_sku' => 'OPEN-ITEM',
        'quantity' => 10,
        'status' => 'open',
    ]);

    WorkOrder::create([
        'id' => (string) Str::uuid(),
        'operating_unit_id' => $this->unit->id,
        'product_sku' => 'COMPLETED-ITEM',
        'quantity' => 5,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/work-orders?status=open');

    $response->assertStatus(200)
        ->assertJsonFragment(['product_sku' => 'OPEN-ITEM'])
        ->assertJsonMissing(['product_sku' => 'COMPLETED-ITEM']);
});

test('authenticated user can create a work order with explicit fields', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/work-orders', [
            'product_sku' => 'SKU-NEW-100',
            'quantity' => 50.5,
            'status' => 'draft',
        ]);

    $response->assertStatus(201)
        ->assertJsonFragment(['product_sku' => 'SKU-NEW-100', 'status' => 'draft']);

    $this->assertDatabaseHas('work_orders', [
        'product_sku' => 'SKU-NEW-100',
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('the work-order complete back door is gone and writes no movement (W4-01)', function () {
    $order = WorkOrder::create([
        'id' => (string) Str::uuid(),
        'operating_unit_id' => $this->unit->id,
        'product_sku' => 'WIDGET-FINISHED',
        'quantity' => 50,
        'status' => 'open',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/work-orders/{$order->id}/complete", [
            'consumedSku' => 'RAW-MATERIAL-Y',
            'consumedQty' => 100,
        ])
        ->assertNotFound();

    expect($order->fresh()->status)->toBe('open');
    $this->assertDatabaseCount('inventory_movements', 0);
});
