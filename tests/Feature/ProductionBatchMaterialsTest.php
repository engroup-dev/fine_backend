<?php

declare(strict_types=1);

use App\Enums\JournalEntryType;
use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMaterial;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\ProductionBatchService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * W4-05 (decision #11): chemicals are issued to a foam batch FIFO from the
 * unit's lots; the issue posts DR 1121 / CR 111 and the batch costs what was
 * issued.
 */
beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);
    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Foam WH', 'code' => 'WH-F']);

    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'SHOW-01', 'unit_type' => 'store',
    ]);
    $this->otherWarehouse = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Show WH', 'code' => 'WH-S']);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'code' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);
    $this->emptyBarrel = InventoryItem::create([
        'name' => 'Empty barrel 200L', 'code' => 'BARREL-200-EMPTY', 'item_type' => 'raw_material', 'unit_of_measure' => 'piece',
    ]);
    $this->polyol = InventoryItem::create([
        'name' => 'Polyol', 'code' => 'CHEM-POLYOL', 'item_type' => 'raw_material', 'unit_of_measure' => 'liter',
        'container_capacity' => 200, 'empty_container_item_id' => $this->emptyBarrel->id,
    ]);
    $this->amine = InventoryItem::create([
        'name' => 'Amine', 'code' => 'CHEM-AMINE', 'item_type' => 'raw_material', 'unit_of_measure' => 'liter',
    ]);

    $this->lot = function (InventoryItem $item, float $quantity, float $unitCost, string $lotNumber, int $daysAgo, ?Warehouse $warehouse = null, ?float $containers = null): StockLot {
        $lot = StockLot::create([
            'inventory_item_id' => $item->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'lot_number' => $lotNumber,
            'quantity' => $quantity,
            'container_quantity' => $containers ?? 1,
            'unit_cost' => $unitCost,
            'status' => 'available',
        ]);
        DB::table('stock_lots')->where('id', $lot->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $lot->fresh();
    };

    $this->batch = fn (string $status = 'planned', float $materialCost = 0.0) => ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.0,
        'status' => $status,
        'material_cost' => $materialCost,
    ]);

    $this->api = fn () => $this->actingAs($this->user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->issue = fn (ProductionBatch $batch, array $lines) => ($this->api)()
        ->postJson("/api/v1/production-batches/{$batch->id}/materials", ['lines' => $lines]);

    $this->balance = function (string $code): float {
        $accountId = Account::where('account_code', $code)->value('id');
        $row = JournalLine::where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS net')->first();

        return round((float) $row->net, 4);
    };
});

test('a line draws FIFO across two lots at each lot\'s actual cost', function () {
    $older = ($this->lot)($this->amine, 100, 3.0, 'AMINE-OLD', 10);
    $newer = ($this->lot)($this->amine, 500, 4.0, 'AMINE-NEW', 2);
    ($this->lot)($this->amine, 1000, 1.0, 'AMINE-ELSEWHERE', 20, $this->otherWarehouse);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 150]])
        ->assertStatus(201)
        ->assertJsonPath('material_cost', '500.0000')
        ->assertJsonCount(2, 'materials');

    $rows = ProductionBatchMaterial::where('production_batch_id', $batch->id)->get()->keyBy('stock_lot_id');
    expect($rows)->toHaveCount(2)
        ->and((float) $rows[$older->id]->quantity)->toBe(100.0)
        ->and((float) $rows[$older->id]->total_cost)->toBe(300.0)
        ->and((float) $rows[$newer->id]->quantity)->toBe(50.0)
        ->and((float) $rows[$newer->id]->total_cost)->toBe(200.0)
        ->and($rows[$older->id]->issued_by)->toBe($this->user->id);

    expect($older->fresh()->status)->toBe('consumed')
        ->and((float) $older->fresh()->quantity)->toBe(0.0)
        ->and((float) $newer->fresh()->quantity)->toBe(450.0);

    $movements = InventoryMovement::where('reference_document_type', 'ProductionBatch')
        ->where('reference_id', $batch->id)->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->unique()->all())->toBe(['consumption'])
        ->and((float) $movements->sum('quantity_delta'))->toBe(-150.0)
        ->and($rows[$older->id]->inventory_movement_id)->not->toBeNull();

    expect((float) $batch->fresh()->material_cost)->toBe(500.0);
});

test('drawing a barrel empty returns the empty barrel to stock', function () {
    $lot = ($this->lot)($this->polyol, 400, 3.0, 'POLYOL-1', 5, null, 2);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->polyol->id, 'quantity' => 250]])->assertStatus(201);

    expect((float) $lot->fresh()->quantity)->toBe(150.0)
        ->and((float) $lot->fresh()->container_quantity)->toBe(1.0);

    $empties = StockLot::where('inventory_item_id', $this->emptyBarrel->id)->get();
    expect($empties)->toHaveCount(1)
        ->and((float) $empties->first()->quantity)->toBe(1.0)
        ->and((float) $empties->first()->unit_cost)->toBe(0.0);

    expect(InventoryMovement::where('stock_lot_id', $empties->first()->id)->where('movement_type', 'byproduct_yield')->exists())->toBeTrue();
});

test('an issue the unit cannot cover is refused and moves nothing', function () {
    $lot = ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    ($this->lot)($this->amine, 1000, 1.0, 'AMINE-ELSEWHERE', 20, $this->otherWarehouse);
    $polyol = ($this->lot)($this->polyol, 400, 3.0, 'POLYOL-1', 5, null, 2);
    $batch = ($this->batch)();

    ($this->issue)($batch, [
        ['inventory_item_id' => $this->polyol->id, 'quantity' => 100],
        ['inventory_item_id' => $this->amine->id, 'quantity' => 60],
        ['inventory_item_id' => $this->amine->id, 'quantity' => 60],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortfalls.0.code', 'CHEM-AMINE');

    expect((float) $lot->fresh()->quantity)->toBe(100.0)
        ->and((float) $polyol->fresh()->quantity)->toBe(400.0)
        ->and(ProductionBatchMaterial::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and((float) $batch->fresh()->material_cost)->toBe(0.0);
});

test('each issued lot posts DR 1121 / CR 111 at its actual cost', function () {
    ($this->lot)($this->amine, 100, 3.0, 'AMINE-OLD', 10);
    ($this->lot)($this->amine, 500, 4.0, 'AMINE-NEW', 2);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 150]])->assertStatus(201);

    $entries = JournalEntry::with('lines.account')
        ->where('source_document_type', ProductionBatchService::MATERIAL_SOURCE_TYPE)
        ->get();
    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        $row = ProductionBatchMaterial::findOrFail($entry->source_document_id);
        $debit = $entry->lines->firstWhere('debit', '>', 0);
        $credit = $entry->lines->firstWhere('credit', '>', 0);

        expect($debit->account->account_code)->toBe('1121')
            ->and($credit->account->account_code)->toBe('111')
            ->and((float) $debit->debit)->toBe((float) $row->total_cost)
            ->and((float) $credit->credit)->toBe((float) $row->total_cost)
            ->and($debit->operating_unit_id)->toBe($this->unit->id);
    }

    expect(($this->balance)('1121'))->toBe(500.0)
        ->and(($this->balance)('111'))->toBe(-500.0);
});

test('the issued cost flows into block unit costs at close and 1121 clears', function () {
    ($this->lot)($this->amine, 100, 3.0, 'AMINE-OLD', 10);
    ($this->lot)($this->amine, 500, 4.0, 'AMINE-NEW', 2);
    $batch = ($this->batch)('ready_for_grading');

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 150]])->assertStatus(201);

    // Volumes 2 × 2 × 1 = 4 and 3 × 2 × 1 = 6: a 40 / 60 split of 500.
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ['kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 1.0, 'pressure' => 30,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id],
            ['kind' => 'block', 'count' => 1, 'length_m' => 3.0, 'height_m' => 1.0, 'pressure' => 30,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id],
        ],
    ])->assertStatus(201);

    foreach (['graded', 'closed'] as $status) {
        ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => $status])->assertStatus(200);
    }

    $costs = $batch->blocks()->orderBy('sequence_in_batch')->pluck('unit_cost')->map(fn ($c) => (float) $c)->all();
    expect($costs)->toBe([200.0, 300.0]);

    expect(($this->balance)('1121'))->toBe(0.0)
        ->and(($this->balance)('1131'))->toBe(500.0)
        ->and(($this->balance)('111'))->toBe(-500.0);
});

test('a batch whose issued chemicals carry no cost is refused at close with its own code', function () {
    ($this->lot)($this->amine, 100, 0.0, 'AMINE-OPENING', 5);
    $batch = ($this->batch)('graded');

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 10]])
        ->assertStatus(201)
        ->assertJsonPath('material_cost', '0.0000');

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'closed'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'MATERIAL_COST_ZERO_FROM_ISSUES');

    expect($batch->fresh()->status->value)->toBe('graded');
});

test('removing a line restores its lot and reverses its entry', function () {
    $older = ($this->lot)($this->amine, 100, 3.0, 'AMINE-OLD', 10);
    $newer = ($this->lot)($this->amine, 500, 4.0, 'AMINE-NEW', 2);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 150]])->assertStatus(201);
    $rowOld = ProductionBatchMaterial::where('stock_lot_id', $older->id)->firstOrFail();
    $rowNew = ProductionBatchMaterial::where('stock_lot_id', $newer->id)->firstOrFail();

    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}/materials/{$rowNew->id}")
        ->assertStatus(200)
        ->assertJsonPath('material_cost', '300.0000')
        ->assertJsonCount(1, 'materials');

    expect((float) $newer->fresh()->quantity)->toBe(500.0)
        ->and(ProductionBatchMaterial::find($rowNew->id))->toBeNull()
        ->and(ProductionBatchMaterial::withTrashed()->find($rowNew->id))->not->toBeNull();

    $original = JournalEntry::where('source_document_id', $rowNew->id)->whereNull('reversal_of_id')->firstOrFail();
    expect(JournalEntry::where('reversal_of_id', $original->id)->where('entry_type', JournalEntryType::Reversal)->exists())->toBeTrue();

    // The fully drawn lot comes back to life.
    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}/materials/{$rowOld->id}")->assertStatus(200);
    expect($older->fresh()->status)->toBe('available')
        ->and((float) $older->fresh()->quantity)->toBe(100.0)
        ->and((float) $batch->fresh()->material_cost)->toBe(0.0)
        ->and(($this->balance)('1121'))->toBe(0.0)
        ->and(($this->balance)('111'))->toBe(0.0);

    $returned = InventoryMovement::where('reason', 'foam_material_returned')->get();
    expect($returned)->toHaveCount(2)
        ->and((float) $returned->sum('quantity_delta'))->toBe(150.0);
});

test('removing a line that emptied a barrel takes the empty barrel back', function () {
    $lot = ($this->lot)($this->polyol, 400, 3.0, 'POLYOL-1', 5, null, 2);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->polyol->id, 'quantity' => 250]])->assertStatus(201);
    $row = ProductionBatchMaterial::firstOrFail();

    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}/materials/{$row->id}")->assertStatus(200);

    expect((float) $lot->fresh()->quantity)->toBe(400.0)
        ->and((float) $lot->fresh()->container_quantity)->toBe(2.0);

    $empty = StockLot::where('inventory_item_id', $this->emptyBarrel->id)->firstOrFail();
    expect((float) $empty->quantity)->toBe(0.0)
        ->and($empty->status)->toBe('consumed');
});

test('the material cost cannot be typed once chemicals are issued', function () {
    ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 10]])->assertStatus(201);
    $fresh = $batch->fresh();

    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'material_cost' => 999,
        'record_version' => $fresh->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'MATERIAL_COST_FROM_ISSUES');

    expect((float) $batch->fresh()->material_cost)->toBe(30.0);

    // A whole-form save resending the computed value is not an override.
    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'material_cost' => 30,
        'bun_width_m' => 2.2,
        'record_version' => $fresh->record_version,
    ])->assertStatus(200);
});

test('a batch with no issued chemicals keeps the manual material cost', function () {
    $batch = ($this->batch)();

    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'material_cost' => 700,
        'record_version' => $batch->record_version,
    ])->assertStatus(200)->assertJsonPath('material_cost', '700.0000');
});

test('a closed batch refuses issues and removals', function () {
    ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    $batch = ($this->batch)('graded');
    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 10]])->assertStatus(201);
    $row = ProductionBatchMaterial::firstOrFail();

    $batch->fresh()->forceFill(['status' => 'closed'])->save();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 10]])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}/materials/{$row->id}")
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    expect(ProductionBatchMaterial::count())->toBe(1);
});

test('a batch with issued chemicals cannot be deleted until they are removed', function () {
    $lot = ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    $batch = ($this->batch)();
    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 40]])->assertStatus(201);

    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}")
        ->assertStatus(422)->assertJsonPath('code', 'BATCH_HAS_MATERIALS');

    expect(ProductionBatch::find($batch->id))->not->toBeNull()
        ->and((float) $lot->fresh()->quantity)->toBe(60.0)
        ->and(($this->balance)('1121'))->toBe(120.0);

    $row = ProductionBatchMaterial::firstOrFail();
    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}/materials/{$row->id}")->assertStatus(200);

    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}")->assertStatus(200);

    expect(ProductionBatch::find($batch->id))->toBeNull()
        ->and((float) $lot->fresh()->quantity)->toBe(100.0)
        ->and(($this->balance)('1121'))->toBe(0.0);
});

test('only raw materials can be issued; a foam block is refused and moves nothing', function () {
    $block = ($this->lot)($this->blockItem, 1, 250.0, 'BLOCK-LOT-1', 3);
    $amine = ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    $batch = ($this->batch)();

    ($this->issue)($batch, [
        ['inventory_item_id' => $this->amine->id, 'quantity' => 10],
        ['inventory_item_id' => $this->blockItem->id, 'quantity' => 0.5],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'MATERIAL_NOT_RAW')
        ->assertJsonPath('items.0.code', 'BLOCK-1');

    expect((float) $block->fresh()->quantity)->toBe(1.0)
        ->and((float) $amine->fresh()->quantity)->toBe(100.0)
        ->and(ProductionBatchMaterial::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

test('a quantity that rounds to zero is refused with a 422', function () {
    ($this->lot)($this->amine, 100, 3.0, 'AMINE-1', 5);
    $batch = ($this->batch)();

    ($this->issue)($batch, [['inventory_item_id' => $this->amine->id, 'quantity' => 0.00001]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.quantity']);

    expect(ProductionBatchMaterial::count())->toBe(0);
});
