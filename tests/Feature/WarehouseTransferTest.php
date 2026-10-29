<?php

declare(strict_types=1);

use App\Exceptions\LedgerRuleException;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\StockLotService;
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

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Warehouse A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Warehouse B']);

    $this->itemA = InventoryItem::create([
        'name' => 'Cotton Filler',
        'code' => 'FILL-COT',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);
    $this->itemB = InventoryItem::create([
        'name' => 'Foam Sheet',
        'code' => 'SHEET-STD',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'm3',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->asUser = fn () => $this->actingAs($this->user)->withHeader('X-Operating-Unit-ID', $this->unit->id);
    $this->draftPayload = fn (array $overrides = []) => array_merge(['transfer_number' => 'XFER-'.uniqid()], $overrides);
});

test('a draft with multiple lines can be created and moves no stock', function () {
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 50,
        'status' => 'available',
    ]);

    $response = ($this->asUser)()->postJson('/api/v1/warehouse-transfers', ($this->draftPayload)([
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'lines' => [
            ['inventory_item_id' => $this->itemA->id, 'quantity' => 10],
            ['inventory_item_id' => $this->itemB->id, 'quantity' => 5],
        ],
    ]));

    $response->assertCreated();
    $response->assertJsonPath('status', 'draft');
    expect($response->json('lines'))->toHaveCount(2);

    // Nothing has physically moved yet.
    $lot = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((float) $lot->quantity)->toBe(50.0);
    expect($lot->warehouse_id)->toBe($this->warehouseA->id);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('editing a draft replaces its lines', function () {
    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-EDIT',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 10]);

    $response = ($this->asUser)()->putJson("/api/v1/warehouse-transfers/{$transfer->id}", [
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'lines' => [
            ['inventory_item_id' => $this->itemB->id, 'quantity' => 3],
        ],
    ]);

    $response->assertOk();
    expect($response->json('lines'))->toHaveCount(1);
    expect($response->json('lines.0.inventory_item_id'))->toBe($this->itemB->id);
});

test('completing a draft with a full-quantity single line moves the lot', function () {
    $lot = StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 50,
        'unit_cost' => 4,
        'status' => 'available',
    ]);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-FULL',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 50]);

    $response = ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete");

    $response->assertOk();
    $response->assertJsonPath('status', 'completed');
    expect($response->json('completed_at'))->not->toBeNull();

    $lot->refresh();
    expect((float) $lot->quantity)->toBe(0.0);
    expect($lot->status)->toBe('consumed');

    $moved = StockLot::where('warehouse_id', $this->warehouseB->id)->sole();
    expect((float) $moved->quantity)->toBe(50.0);

    $this->assertDatabaseHas('inventory_movements', [
        'reference_document_type' => 'WarehouseTransfer',
        'reference_id' => $transfer->id,
        'from_warehouse_id' => $this->warehouseA->id,
        'quantity_delta' => -50,
    ]);
    $this->assertDatabaseHas('inventory_movements', [
        'reference_document_type' => 'WarehouseTransfer',
        'reference_id' => $transfer->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'quantity_delta' => 50,
    ]);
});

test('completing a multi-line draft draws FIFO per line and moves every item', function () {
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 20,
        'status' => 'available',
    ]);
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemB->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 8,
        'status' => 'available',
    ]);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-MULTI',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 5]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemB->id, 'quantity' => 3]);

    ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete")->assertOk();

    $movedA = StockLot::where('warehouse_id', $this->warehouseB->id)->where('inventory_item_id', $this->itemA->id)->sole();
    $movedB = StockLot::where('warehouse_id', $this->warehouseB->id)->where('inventory_item_id', $this->itemB->id)->sole();
    expect((float) $movedA->quantity)->toBe(5.0);
    expect((float) $movedB->quantity)->toBe(3.0);
});

test('insufficient stock on a later line rolls back everything the completion already moved', function () {
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 20,
        'status' => 'available',
    ]);
    // itemB has no stock at all in warehouse A.

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-ROLLBACK',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 5]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemB->id, 'quantity' => 3]);

    $response = ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete");

    $response->assertStatus(422);

    $transfer->refresh();
    expect($transfer->status->value)->toBe('draft');

    // Line A must not have partially moved despite succeeding before line B failed.
    $lotA = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((float) $lotA->quantity)->toBe(20.0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('draft creation is rejected when the destination warehouse belongs to another operating unit', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
        'code' => 'OTHER-01',
        'unit_type' => 'foam_manufactory',
    ]);
    $otherWarehouse = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other Warehouse']);

    $response = ($this->asUser)()->postJson('/api/v1/warehouse-transfers', ($this->draftPayload)([
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $otherWarehouse->id,
        'lines' => [['inventory_item_id' => $this->itemA->id, 'quantity' => 5]],
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['to_warehouse_id']);
});

test('a company-wide role without a unit header cannot point a transfer at another unit', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
        'code' => 'OTHER-02',
        'unit_type' => 'foam_manufactory',
    ]);
    $otherWarehouse = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other Warehouse']);
    $otherWarehouse2 = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other Warehouse 2']);

    $lot = StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 20,
        'status' => 'available',
    ]);

    $owner = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => Role::create(['name' => 'Owner', 'slug' => 'owner'])->id,
        'operating_unit_id' => null,
    ]);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-CROSS',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 5]);
    $lines = [['inventory_item_id' => $this->itemA->id, 'quantity' => 5]];

    // Without a unit header ExistsInCurrentUnit lets another unit's warehouses through.
    $this->actingAs($owner)->putJson("/api/v1/warehouse-transfers/{$transfer->id}", [
        'to_warehouse_id' => $otherWarehouse->id,
        'lines' => $lines,
    ])->assertStatus(422)->assertJsonPath('code', 'TRANSFER_CROSS_UNIT');

    // Both warehouses moved to the other unit: unit A's transfer would move unit B's stock.
    $this->actingAs($owner)->putJson("/api/v1/warehouse-transfers/{$transfer->id}", [
        'from_warehouse_id' => $otherWarehouse->id,
        'to_warehouse_id' => $otherWarehouse2->id,
        'lines' => $lines,
    ])->assertStatus(422)->assertJsonPath('code', 'TRANSFER_CROSS_UNIT');

    expect($transfer->fresh()->from_warehouse_id)->toBe($this->warehouseA->id)
        ->and($transfer->fresh()->to_warehouse_id)->toBe($this->warehouseB->id);

    // A draft saved across units before the check existed cannot complete either.
    $transfer->update(['to_warehouse_id' => $otherWarehouse->id]);

    $this->actingAs($owner)->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'TRANSFER_CROSS_UNIT');

    expect((float) $lot->fresh()->quantity)->toBe(20.0)
        ->and($transfer->fresh()->status->value)->toBe('draft');
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(StockLot::where('warehouse_id', $otherWarehouse->id)->exists())->toBeFalse();
});

test('the stock service refuses a move between warehouses of different units', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
        'code' => 'OTHER-03',
        'unit_type' => 'foam_manufactory',
    ]);
    $otherWarehouse = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other Warehouse']);
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 20,
        'status' => 'available',
    ]);

    expect(fn () => app(StockLotService::class)->transferBetweenWarehouses($this->itemA->id, $this->warehouseA->id, $otherWarehouse->id, 5))
        ->toThrow(LedgerRuleException::class);
});

test('a completed transfer cannot be edited, completed again, or cancelled', function () {
    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-DONE',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 5]);
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 10,
        'status' => 'available',
    ]);

    ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete")->assertOk();

    $editResponse = ($this->asUser)()->putJson("/api/v1/warehouse-transfers/{$transfer->id}", [
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'lines' => [['inventory_item_id' => $this->itemA->id, 'quantity' => 1]],
    ]);
    $editResponse->assertStatus(422);
    $editResponse->assertJsonPath('code', 'TRANSFER_NOT_DRAFT');

    $completeAgain = ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete");
    $completeAgain->assertStatus(422);
    $completeAgain->assertJsonPath('code', 'TRANSFER_NOT_DRAFT');

    $cancelResponse = ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/cancel");
    $cancelResponse->assertStatus(422);
    $cancelResponse->assertJsonPath('code', 'TRANSFER_NOT_DRAFT');
});

test('cancelling a draft marks it cancelled without touching stock', function () {
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 10,
        'status' => 'available',
    ]);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-CANCEL',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 5]);

    $response = ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/cancel");

    $response->assertOk();
    $response->assertJsonPath('status', 'cancelled');

    $lot = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((float) $lot->quantity)->toBe(10.0);
});

test('index only lists transfers for the current operating unit', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
        'code' => 'OTHER-02',
        'unit_type' => 'foam_manufactory',
    ]);
    $otherWarehouseA = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other A']);
    $otherWarehouseB = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other B']);

    WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-MINE',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    WarehouseTransfer::create([
        'operating_unit_id' => $otherUnit->id,
        'transfer_number' => 'XFER-OTHER',
        'from_warehouse_id' => $otherWarehouseA->id,
        'to_warehouse_id' => $otherWarehouseB->id,
    ]);

    $response = ($this->asUser)()->getJson('/api/v1/warehouse-transfers');

    $response->assertOk();
    $numbers = collect($response->json('data'))->pluck('transfer_number');
    expect($numbers)->toContain('XFER-MINE');
    expect($numbers)->not->toContain('XFER-OTHER');
});

test('a completed transfer full movement history is retrievable via the generic document endpoint', function () {
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouseA->id,
        'quantity' => 10,
        'status' => 'available',
    ]);

    $transfer = WarehouseTransfer::create([
        'operating_unit_id' => $this->unit->id,
        'transfer_number' => 'XFER-HISTORY',
        'from_warehouse_id' => $this->warehouseA->id,
        'to_warehouse_id' => $this->warehouseB->id,
    ]);
    $transfer->lines()->create(['inventory_item_id' => $this->itemA->id, 'quantity' => 4]);

    ($this->asUser)()->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete")->assertOk();

    $response = ($this->asUser)()->getJson("/api/v1/inventory-movements/for-document/WarehouseTransfer/{$transfer->id}");

    $response->assertOk();
    expect($response->json())->toHaveCount(2);
});
