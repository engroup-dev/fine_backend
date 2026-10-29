<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderBlock;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\StockLotService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-04: stagnant stock report and transfer lineage.
 */
beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Stagnant Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Factory', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'code' => $code, 'unit_type' => 'foam_manufactory', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->unit = $makeUnit('Foam Factory', 'FOAM-01');
    $this->otherUnit = $makeUnit('Other Factory', 'FOAM-02');
    seedUnitAccounts([$this->unit, $this->otherUnit]);

    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Main WH']);
    $this->backWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Back WH']);
    $this->foreignWarehouse = Warehouse::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Other WH']);

    $user = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'inventory-manager'], ['name' => 'Inventory Manager']);
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);
    $this->manager = $user;

    $this->fabrics = ItemCategory::create(['name' => 'Fabrics', 'code' => 'FAB']);
    $this->fabric = InventoryItem::create(['name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter', 'category_id' => $this->fabrics->id]);
    $this->glue = InventoryItem::create(['name' => 'Glue', 'code' => 'GLU-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);

    /** A lot that entered stock `$daysAgo` days ago through an intake movement. */
    $this->lotReceived = function (InventoryItem $item, float $quantity, float $unitCost, int $daysAgo, ?Warehouse $warehouse = null, array $attributes = []): StockLot {
        $warehouse ??= $this->warehouse;
        $this->travelTo(now()->subDays($daysAgo));

        $lot = StockLot::create(array_merge([
            'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'lot_number' => 'LOT-'.$item->code.'-'.uniqid(), 'quantity' => $quantity, 'unit_cost' => $unitCost, 'status' => 'available',
        ], $attributes));

        InventoryMovement::create([
            'operating_unit_id' => $warehouse->operating_unit_id, 'stock_lot_id' => $lot->id,
            'to_warehouse_id' => $warehouse->id, 'sku' => $item->code, 'movement_type' => 'intake',
            'quantity_delta' => $quantity, 'unit_cost' => $unitCost, 'reason' => 'stock_intake',
        ]);

        $this->travelBack();

        return $lot;
    };

    /** Record a movement on a lot `$daysAgo` days ago. */
    $this->movement = function (StockLot $lot, string $type, float $delta, int $daysAgo, string $reason = 'test'): void {
        $this->travelTo(now()->subDays($daysAgo));
        InventoryMovement::create([
            'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $lot->id,
            'from_warehouse_id' => $lot->warehouse_id, 'sku' => 'X', 'movement_type' => $type,
            'quantity_delta' => $delta, 'unit_cost' => 0, 'reason' => $reason,
        ]);
        $this->travelBack();
    };

    $this->report = fn (array $query = []) => $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/stagnant-stock?'.http_build_query($query));
});

test('lots idle past the cutoff are listed; recently moved ones are not', function () {
    $old = ($this->lotReceived)($this->fabric, 10, 5, 120);
    $recentlySold = ($this->lotReceived)($this->glue, 10, 5, 120);
    ($this->movement)($recentlySold, 'sale', -2, 10);
    ($this->lotReceived)($this->glue, 4, 5, 30);

    $response = ($this->report)()->assertOk();

    expect($response->json('summary.days'))->toBe(90)
        ->and(array_column($response->json('rows'), 'stock_lot_id'))->toBe([$old->id])
        ->and($response->json('rows.0.days_idle'))->toBe(120);

    $shorter = ($this->report)(['days' => 20])->assertOk();
    expect($shorter->json('summary.lots'))->toBe(2)
        ->and(array_column($shorter->json('rows'), 'stock_lot_id'))->not->toContain($recentlySold->id);
});

test('a lot with no movement falls back to when it was received', function () {
    $this->travelTo(now()->subDays(100));
    $lot = StockLot::create([
        'inventory_item_id' => $this->fabric->id, 'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-NOMOVE', 'quantity' => 3, 'unit_cost' => 2, 'status' => 'available',
    ]);
    $this->travelBack();

    expect($lot->fresh()->received_at)->not->toBeNull();

    $response = ($this->report)()->assertOk();
    expect($response->json('rows.0.stock_lot_id'))->toBe($lot->id)
        ->and($response->json('rows.0.last_movement_at'))->toBeNull()
        ->and($response->json('rows.0.days_idle'))->toBe(100);
});

test('transfer and reservation-split movements do not count as real movement', function () {
    $transferred = ($this->lotReceived)($this->fabric, 10, 5, 150);
    ($this->movement)($transferred, 'transfer', -2, 5, 'internal_warehouse_transfer');

    $reservedFrom = ($this->lotReceived)($this->glue, 10, 5, 150);
    ($this->movement)($reservedFrom, 'transfer', -1, 3, 'reserve_for_sale');

    $adjusted = ($this->lotReceived)($this->glue, 10, 5, 150);
    ($this->movement)($adjusted, 'adjustment', -1, 3);

    $ids = array_column(($this->report)()->assertOk()->json('rows'), 'stock_lot_id');

    expect($ids)->toContain($transferred->id)
        ->toContain($reservedFrom->id)
        ->not->toContain($adjusted->id);
});

test('available, reserved and quarantined lots with stock count, labelled by status', function () {
    $available = ($this->lotReceived)($this->fabric, 10, 5, 200);
    $reserved = ($this->lotReceived)($this->fabric, 10, 5, 200, null, ['status' => 'reserved']);
    $quarantined = ($this->lotReceived)($this->fabric, 10, 5, 200, null, ['status' => 'quarantined']);
    ($this->lotReceived)($this->fabric, 0, 5, 200, null, ['status' => 'consumed']);
    ($this->lotReceived)($this->fabric, 0, 5, 200);

    // A block on a cutter work order is in production, not idle stock.
    $onCutter = ($this->lotReceived)($this->fabric, 1, 5, 200, null, ['status' => 'reserved']);
    $order = CutterWorkOrder::create([
        'operating_unit_id' => $this->unit->id, 'order_number' => 'CWO-STAGNANT', 'status' => 'in_production',
    ]);
    CutterWorkOrderBlock::create([
        'cutter_work_order_id' => $order->id, 'stock_lot_id' => $onCutter->id, 'unit_cost_snapshot' => 5,
    ]);

    $rows = collect(($this->report)()->assertOk()->json('rows'))->pluck('status', 'stock_lot_id')->all();

    expect($rows)->toEqual([
        $available->id => 'available',
        $reserved->id => 'reserved',
        $quarantined->id => 'quarantined',
    ]);
});

test('blocks of an open production batch are left out until it closes', function () {
    $blockItem = InventoryItem::create(['name' => 'Foam Block', 'code' => 'BLK-OPEN', 'item_type' => 'foam_block', 'unit_of_measure' => 'piece']);
    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id, 'operation_number' => 78, 'bun_width_m' => 2.0, 'status' => 'graded', 'material_cost' => 100,
    ]);
    $block = ($this->lotReceived)($blockItem, 1, 40, 150, null, [
        'production_batch_id' => $batch->id, 'sequence_in_batch' => 1,
        'length_m' => 2, 'width_m' => 2, 'height_m' => 1,
    ]);

    // Work in process: no value posted yet, so not stagnant stock.
    expect(($this->report)()->assertOk()->json('summary.lots'))->toBe(0);

    $batch->update(['status' => 'closed']);

    expect(array_column(($this->report)()->assertOk()->json('rows'), 'stock_lot_id'))->toBe([$block->id]);
});

test('rows are valued at quantity times lot cost and totalled', function () {
    ($this->lotReceived)($this->fabric, 10, 5.5, 200);
    $output = ($this->lotReceived)($this->glue, 4, 12.25, 100);
    // Foam output registers its movement at cost 0; the value comes from the lot.
    InventoryMovement::where('stock_lot_id', $output->id)->update(['movement_type' => 'production_output', 'unit_cost' => 0]);

    $response = ($this->report)()->assertOk();

    expect($response->json('rows.0.value'))->toEqual(55)
        ->and($response->json('rows.0.days_idle'))->toBe(200)
        ->and($response->json('rows.1.stock_lot_id'))->toBe($output->id)
        ->and($response->json('rows.1.value'))->toEqual(49)
        ->and($response->json('summary.total_value'))->toEqual(104);
});

test('the report filters by warehouse and category and stays in the unit', function () {
    $main = ($this->lotReceived)($this->fabric, 1, 1, 200);
    $back = ($this->lotReceived)($this->glue, 1, 1, 200, $this->backWarehouse);
    ($this->lotReceived)($this->fabric, 1, 1, 200, $this->foreignWarehouse);

    expect(array_column(($this->report)()->json('rows'), 'stock_lot_id'))->toEqualCanonicalizing([$main->id, $back->id])
        ->and(array_column(($this->report)(['warehouse_id' => $this->backWarehouse->id])->json('rows'), 'stock_lot_id'))->toBe([$back->id])
        ->and(array_column(($this->report)(['category_id' => $this->fabrics->id])->json('rows'), 'stock_lot_id'))->toBe([$main->id]);

    ($this->report)(['warehouse_id' => $this->foreignWarehouse->id])->assertUnprocessable();
    ($this->report)(['days' => 0])->assertUnprocessable();
});

test('a warehouse transfer keeps the lot lineage and age', function () {
    $blockItem = InventoryItem::create(['name' => 'Foam Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'piece']);
    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id, 'operation_number' => 77, 'bun_width_m' => 2.0, 'status' => 'closed', 'material_cost' => 100,
    ]);
    $origin = ($this->lotReceived)($blockItem, 1, 40, 150, null, [
        'production_batch_id' => $batch->id, 'sequence_in_batch' => 3, 'pressure' => 25,
        'length_m' => 2, 'width_m' => 2, 'height_m' => 1,
    ]);
    $piece = ($this->lotReceived)($this->fabric, 6, 3, 120, null, [
        'source_stock_lot_id' => $origin->id,
        'source_block_length_m' => 2, 'source_block_width_m' => 2, 'source_block_height_m' => 1,
        'length_m' => 1, 'width_m' => 1, 'height_m' => 0.1,
    ]);

    $service = app(StockLotService::class);
    $movedBlock = $service->transferBetweenWarehouses($blockItem->id, $this->warehouse->id, $this->backWarehouse->id, 1)['lots'][0];
    $movedPiece = $service->transferBetweenWarehouses($this->fabric->id, $this->warehouse->id, $this->backWarehouse->id, 4)['lots'][0];

    expect($movedBlock->lot_number)->toEndWith('-XFER')
        // A lot with no source of its own still links back to the lot it came from.
        ->and($movedBlock->source_stock_lot_id)->toBe($origin->id)
        ->and($movedBlock->production_batch_id)->toBe($batch->id)
        ->and($movedBlock->pressure)->toBe(25)
        ->and($movedBlock->received_at->toDateTimeString())->toBe($origin->received_at->toDateTimeString())
        // A cut piece points at the piece it split from; the origin block is one step on.
        ->and($movedPiece->source_stock_lot_id)->toBe($piece->id)
        ->and($movedPiece->sourceBlock->source_stock_lot_id)->toBe($origin->id)
        ->and((float) $movedPiece->source_block_length_m)->toBe(2.0)
        ->and((float) $movedPiece->source_block_height_m)->toBe(1.0)
        ->and((float) $movedPiece->height_m)->toBe(0.1)
        ->and($movedPiece->received_at->toDateTimeString())->toBe($piece->received_at->toDateTimeString());

    // The batch still counts one block with that sequence.
    expect($batch->blocks()->count())->toBe(1);

    // Moving stock does not make it fresh: the moved lots stay stagnant at their age.
    $rows = collect(($this->report)(['warehouse_id' => $this->backWarehouse->id])->assertOk()->json('rows'))->keyBy('stock_lot_id');
    expect($rows[$movedBlock->id]['days_idle'])->toBe(150)
        ->and($rows[$movedPiece->id]['days_idle'])->toBe(120);
});

test('a transfer passes on the idle-since of its source, not its receipt date', function () {
    $roll = ($this->lotReceived)($this->fabric, 10, 5, 120);
    ($this->movement)($roll, 'sale', -4, 10);
    $roll->update(['quantity' => 6]);
    $soldAt = InventoryMovement::where('stock_lot_id', $roll->id)->where('movement_type', 'sale')->value('created_at');

    $moved = app(StockLotService::class)
        ->transferBetweenWarehouses($this->fabric->id, $this->warehouse->id, $this->backWarehouse->id, 6)['lots'][0];

    expect($roll->fresh()->status)->toBe('consumed')
        ->and($moved->received_at->toDateTimeString())->toBe($soldAt->toDateTimeString());

    // Sold from 10 days ago: not stagnant at 90 days, before or after the move.
    expect(($this->report)()->assertOk()->json('summary.lots'))->toBe(0);

    $rows = ($this->report)(['days' => 5])->assertOk()->json('rows');
    expect(array_column($rows, 'stock_lot_id'))->toBe([$moved->id])
        ->and($rows[0]['days_idle'])->toBe(10);
});
