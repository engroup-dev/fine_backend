<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\ProductionBatchService;
use App\Services\StockLotService;
use Illuminate\Database\QueryException;
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

    $this->user = User::factory()->create(['must_change_password' => false]);

    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam_manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block White',
        'code' => 'BLOCK-WHITE',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->makeBatch = fn (int $operationNumber = 191) => ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => $operationNumber,
        'bun_width_m' => 2.4,
        'formula_params' => ['density_band' => '12-14', 'cure_time_minutes' => 15, 'conveyor_speed' => 5],
        'status' => 'graded',
    ]);

    $this->scrapItem = InventoryItem::create([
        'name' => 'Foam Scrap Fill',
        'code' => 'SCRAP-FILL',
        'item_type' => 'byproduct_fill',
        'unit_of_measure' => 'm3',
    ]);

    $this->scrapGroup = fn (int $count, float $length, float $height) => [
        'kind' => 'scrap',
        'count' => $count,
        'length_m' => $length,
        'height_m' => $height,
        'inventory_item_id' => $this->scrapItem->id,
        'warehouse_id' => $this->warehouse->id,
    ];

    $this->blockGroup = fn (int $count, float $length, float $height, int $pressure = 35) => [
        'kind' => 'block',
        'count' => $count,
        'length_m' => $length,
        'height_m' => $height,
        'pressure' => $pressure,
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'unit_cost' => 100.0,
    ];
});

test('block code renders with zero padded sequence', function () {
    $service = app(ProductionBatchService::class);

    expect($service->composeLotNumber(3, 35, 191))->toBe('003-35-191')
        ->and($service->composeLotNumber(36, 35, 191))->toBe('036-35-191');
});

test('a group expands into individually labelled blocks', function () {
    $batch = ($this->makeBatch)();

    $response = ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(25, 2.0, 0.8)],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('blocks_created', 25);

    expect(StockLot::where('production_batch_id', $batch->id)->count())->toBe(25);

    $first = StockLot::where('production_batch_id', $batch->id)->where('sequence_in_batch', 1)->first();
    $last = StockLot::where('production_batch_id', $batch->id)->where('sequence_in_batch', 25)->first();

    expect($first->lot_number)->toBe('001-35-191')
        ->and($last->lot_number)->toBe('025-35-191');
});

test('multiple groups continue the sequence rather than restarting', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ($this->blockGroup)(25, 2.0, 0.8),
            ($this->blockGroup)(3, 1.99, 1.0),
        ],
    ])->assertStatus(201);

    $sequences = StockLot::where('production_batch_id', $batch->id)
        ->orderBy('sequence_in_batch')
        ->pluck('sequence_in_batch')
        ->all();

    expect($sequences)->toBe(range(1, 28));
    expect($batch->fresh()->next_sequence)->toBe(29);
});

test('a second registration call continues from the stored counter', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(2, 2.0, 0.8)],
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(2, 2.3, 0.78)],
    ])->assertStatus(201);

    expect(StockLot::where('production_batch_id', $batch->id)->pluck('sequence_in_batch')->sort()->values()->all())
        ->toBe([1, 2, 3, 4]);
});

test('volume is computed from dimensions and bun width', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $lot = StockLot::where('production_batch_id', $batch->id)->first();

    // 2.4 (bun width, from the batch) x 2.0 x 0.8 = 3.84
    expect((float) $lot->volume_m3)->toBe(3.84)
        ->and((float) $lot->width_m)->toBe(2.4);
});

test('scrap enters stock at zero cost without consuming a sequence', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ($this->scrapGroup)(1, 2.0, 1.0),
            ($this->blockGroup)(1, 2.0, 0.8),
        ],
    ])->assertStatus(201)
        ->assertJsonPath('blocks_created', 1)
        ->assertJsonPath('scrap_lots_created', 1);

    $batch->refresh();

    // Scrap volume: 2.4 x 2.0 x 1.0 = 4.8
    expect((float) $batch->scrap_volume_m3)->toBe(4.8)
        // The block still takes sequence 1 — scrap consumed none.
        ->and($batch->next_sequence)->toBe(2);

    $scrap = $batch->scrapLots()->first();

    expect($scrap->lot_number)->toBe('SCRAP-191-01')
        ->and((float) $scrap->quantity)->toBe(4.8)
        ->and((float) $scrap->unit_cost)->toBe(0.0)
        ->and($scrap->sequence_in_batch)->toBeNull();

    // The block keeps its own code, untouched by the scrap row.
    expect($batch->blocks()->first()->lot_number)->toBe('001-35-191');
});

test('scrap yields a byproduct movement', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->scrapGroup)(1, 2.0, 1.0), ($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $movements = ($this->api)()
        ->getJson("/api/v1/inventory-movements/for-document/ProductionBatch/{$batch->id}")
        ->json();

    $types = collect($movements)->pluck('movement_type')->sort()->values()->all();

    expect($types)->toBe(['byproduct_yield', 'production_output']);
});

test('block and scrap volumes reconcile to the run total', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ($this->blockGroup)(25, 2.0, 0.8),
            ($this->blockGroup)(3, 1.99, 1.0),
            ($this->blockGroup)(2, 1.38, 0.44),
            ($this->blockGroup)(1, 2.3, 0.78),
            ($this->blockGroup)(1, 2.5, 0.59),
            ($this->blockGroup)(1, 1.95, 0.69),
            ($this->blockGroup)(1, 2.3, 0.75),
            ($this->scrapGroup)(1, 2.0, 1.0),
            ($this->scrapGroup)(1, 2.0, 0.5),
        ],
    ])->assertStatus(201)->assertJsonPath('blocks_created', 34);

    $batch->refresh();

    $blockVolume = (float) $batch->blocks()->sum('volume_m3');
    $total = round($blockVolume + (float) $batch->scrap_volume_m3, 3);

    // Matches the printed total on the source production report.
    expect($total)->toBe(135.657);
});

test('a block group without pressure is rejected', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block',
            'count' => 1,
            'length_m' => 2.0,
            'height_m' => 0.8,
            'inventory_item_id' => $this->blockItem->id,
            'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(422);
});

test('two blocks in one batch can carry different measured pressures', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ($this->blockGroup)(1, 2.0, 0.8, 35),
            ($this->blockGroup)(1, 2.0, 0.8, 40),
        ],
    ])->assertStatus(201);

    $numbers = StockLot::where('production_batch_id', $batch->id)
        ->orderBy('sequence_in_batch')
        ->pluck('lot_number')
        ->all();

    expect($numbers)->toBe(['001-35-191', '002-40-191']);
});

test('pressure is stored as a column not a json attribute', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[...($this->blockGroup)(1, 2.0, 0.8), 'color' => 'white']],
    ])->assertStatus(201);

    $lot = StockLot::where('production_batch_id', $batch->id)->first();

    expect($lot->pressure)->toBe(35)
        ->and($lot->attribute_values)->toBe(['color' => 'white']);
});

test('a duplicate operation number is rejected', function () {
    ($this->makeBatch)(191);

    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('operation_number');
});

test('a duplicate against a soft deleted batch is still rejected', function () {
    ($this->makeBatch)(191)->delete();

    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('operation_number');
});

test('a non sequential operation number warns but is not blocked', function () {
    ($this->makeBatch)(190);

    // Unconfirmed: warned, with the expected value returned.
    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 199,
        'bun_width_m' => 2.4,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'NON_SEQUENTIAL_OPERATION_NUMBER')
        ->assertJsonPath('expected_operation_number', 191);

    // Confirmed: accepted.
    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 199,
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(201)->assertJsonPath('operation_number', 199);
});

test('the expected next operation number counts soft deleted batches', function () {
    ($this->makeBatch)(191)->delete();

    expect(app(ProductionBatchService::class)->nextExpectedOperationNumber())->toBe(192);
});

test('operation number is editable until a block is registered', function () {
    $batch = ($this->makeBatch)(191);

    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'operation_number' => 192,
        'record_version' => $batch->record_version,
    ])->assertStatus(200)->assertJsonPath('operation_number', 192);

    $batch->refresh();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $batch->refresh();

    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'operation_number' => 193,
        'record_version' => $batch->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'OPERATION_NUMBER_IMMUTABLE');
});

test('a batch with registered blocks cannot be deleted', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->deleteJson("/api/v1/production-batches/{$batch->id}")->assertStatus(200);

    $fresh = ($this->makeBatch)(192);

    ($this->api)()->postJson("/api/v1/production-batches/{$fresh->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    ($this->api)()->deleteJson("/api/v1/production-batches/{$fresh->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BATCH_HAS_BLOCKS');
});

test('a stock lot cannot be created without the intake movement', function () {
    // The bare POST /stock-lots (which could also point a lot at any batch)
    // is gone: stock enters only through /stock-lots/intake, which records the
    // INV-06 movement, or through block registration on a batch.
    ($this->api)()->postJson('/api/v1/stock-lots', [
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'MANUAL-001',
        'quantity' => 1,
        'unit_cost' => 10,
        'production_batch_id' => '00000000-0000-4000-8000-000000000000',
    ])->assertStatus(405);

    expect(StockLot::where('lot_number', 'MANUAL-001')->exists())->toBeFalse();
});

test('duplicate sequences within a batch are rejected by the database', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    // Bypass the service to prove the constraint, not just the application logic.
    expect(fn () => StockLot::create([
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'production_batch_id' => $batch->id,
        'sequence_in_batch' => 1,
        'pressure' => 35,
        'lot_number' => 'DIFFERENT-CODE',
        'quantity' => 1,
        'unit_cost' => 10,
    ]))->toThrow(QueryException::class);
});

test('a bun wider than the machine limit is rejected', function () {
    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 191,
        'bun_width_m' => 3.0, // FOAM-01 caps this at 2.4
        'confirm_non_sequential' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('bun_width_m');

    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(201);
});

test('a foam block lot cannot carry a quantity other than one', function () {
    ($this->api)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'BULK-BLOCKS',
        'quantity' => 25,
        'unit_cost' => 100,
        'source' => 'opening_balance',
    ])->assertStatus(422)->assertJsonPath('code', 'SERIALIZED_QUANTITY_INVALID');
});

test('a non-serialized item may carry any quantity', function () {
    $bulk = InventoryItem::create([
        'name' => 'Polyol', 'code' => 'CHEM-POLY', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    // The serialized-quantity rule lives on the model, so exercise it there.
    $lot = StockLot::create([
        'inventory_item_id' => $bulk->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'BULK-POLY-1',
        'quantity' => 899,
        'unit_cost' => 10,
    ]);

    expect((float) $lot->fresh()->quantity)->toBe(899.0);
});

test('a block lot drawn down to zero is still allowed', function () {
    // Consumption legitimately empties a lot; the rule must not block that.
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $lot = StockLot::where('production_batch_id', $batch->id)->first();
    $lot->quantity = 0;

    expect(fn () => $lot->save())->not->toThrow(Exception::class);
});

test('a company-wide role without a selected unit gets a clear error, not a crash', function () {
    // Owner-style role: company-wide, so operating_unit_id is null and the
    // middleware lets the request through with no unit context at all.
    $owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);

    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null,
    ]);

    $this->actingAs($owner)
        ->postJson('/api/v1/production-batches', [
            'operation_number' => 191,
            'bun_width_m' => 2.4,
            'confirm_non_sequential' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPERATING_UNIT_REQUIRED');

    expect(ProductionBatch::count())->toBe(0);
});

test('a company-wide role can create once it names a unit', function () {
    $owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);

    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null,
    ]);

    $this->actingAs($owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/production-batches', [
            'operation_number' => 191,
            'bun_width_m' => 2.4,
            'confirm_non_sequential' => true,
        ])
        ->assertStatus(201)
        ->assertJsonPath('operating_unit_id', $this->unit->id);
});

test('the operating unit cannot be overridden through the request body', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom',
        'code' => 'SHOW-01',
        'unit_type' => 'store',
    ]);

    // The user is scoped to $this->unit; naming another unit in the body must not
    // place the batch there.
    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'operating_unit_id' => $otherUnit->id,
        'confirm_non_sequential' => true,
    ])
        ->assertStatus(201)
        ->assertJsonPath('operating_unit_id', $this->unit->id);

    expect(ProductionBatch::where('operating_unit_id', $otherUnit->id)->exists())->toBeFalse();
});

test('stock lots can be filtered to a single production batch', function () {
    $first = ($this->makeBatch)(191);
    $second = ($this->makeBatch)(192);

    ($this->api)()->postJson("/api/v1/production-batches/{$first->id}/blocks", [
        'groups' => [($this->blockGroup)(3, 2.0, 0.8)],
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/production-batches/{$second->id}/blocks", [
        'groups' => [($this->blockGroup)(2, 2.0, 0.8)],
    ])->assertStatus(201);

    $response = ($this->api)()
        ->getJson("/api/v1/stock-lots?production_batch_id={$first->id}")
        ->assertStatus(200);

    expect($response->json('total'))->toBe(3);
});

test('warehouses are listed for the current operating unit', function () {
    ($this->api)()->getJson('/api/v1/warehouses')
        ->assertStatus(200)
        ->assertJsonFragment(['name' => 'Main Warehouse']);
});

test('remnant numbering uses a per parent revision counter', function () {
    $batch = ($this->makeBatch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $parent = StockLot::where('production_batch_id', $batch->id)->first();

    $first = ($this->api)()->postJson("/api/v1/stock-lots/{$parent->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 1.2, 'width_m' => 2.4, 'height_m' => 0.8],
    ])->assertStatus(200);

    expect($first->json('remnant_lot.lot_number'))->toBe('001-35-191-R1');

    // Same second, same parent — the old time()-based scheme collided here.
    $remnant = StockLot::find($first->json('remnant_lot.id'));
    $remnant->update(['status' => 'available']);

    $second = ($this->api)()->postJson("/api/v1/stock-lots/{$parent->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 0.5, 'width_m' => 2.4, 'height_m' => 0.8],
    ])->assertStatus(200);

    expect($second->json('remnant_lot.lot_number'))->toBe('001-35-191-R2');
});

test('separator and head cuts are registered with their block_type and optional pressure', function () {
    $batch = ($this->makeBatch)();

    $response = ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            [
                'kind' => 'head',
                'block_type' => 'head',
                'count' => 1,
                'length_m' => 1.5,
                'height_m' => 0.8,
                'inventory_item_id' => $this->blockItem->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [
                'kind' => 'block',
                'block_type' => 'block',
                'count' => 2,
                'length_m' => 2.0,
                'height_m' => 0.8,
                'pressure' => 30,
                'inventory_item_id' => $this->blockItem->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [
                'kind' => 'separator',
                'block_type' => 'separator',
                'count' => 1,
                'length_m' => 0.5,
                'height_m' => 0.8,
                'inventory_item_id' => $this->blockItem->id,
                'warehouse_id' => $this->warehouse->id,
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('blocks_created', 4);

    $lots = StockLot::where('production_batch_id', $batch->id)
        ->orderBy('sequence_in_batch')
        ->get();

    expect($lots)->toHaveCount(4);

    // Lot 1: head cut (no pressure specified -> defaults to 0 in code)
    expect($lots[0]->block_type)->toBe('head')
        ->and($lots[0]->sequence_in_batch)->toBe(1)
        ->and($lots[0]->pressure)->toBeNull()
        ->and($lots[0]->lot_number)->toBe('001-0-191');

    // Lots 2-3: standard blocks
    expect($lots[1]->block_type)->toBe('block')
        ->and($lots[1]->sequence_in_batch)->toBe(2)
        ->and($lots[1]->pressure)->toBe(30)
        ->and($lots[1]->lot_number)->toBe('002-30-191');

    expect($lots[2]->block_type)->toBe('block')
        ->and($lots[2]->sequence_in_batch)->toBe(3)
        ->and($lots[2]->pressure)->toBe(30)
        ->and($lots[2]->lot_number)->toBe('003-30-191');

    // Lot 4: separator cut
    expect($lots[3]->block_type)->toBe('separator')
        ->and($lots[3]->sequence_in_batch)->toBe(4)
        ->and($lots[3]->pressure)->toBeNull()
        ->and($lots[3]->lot_number)->toBe('004-0-191');

    // Filter by block_type in stock-lots API
    $separators = ($this->api)()->getJson("/api/v1/stock-lots?production_batch_id={$batch->id}&block_type=separator")
        ->assertStatus(200)
        ->json('data');

    expect($separators)->toHaveCount(1)
        ->and($separators[0]['block_type'])->toBe('separator');
});

test('batch with separator having null pressure transitions to graded successfully', function () {
    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 205,
        'bun_width_m' => 2.4,
        'status' => 'ready_for_grading',
    ]);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            [
                'kind' => 'block',
                'count' => 1,
                'length_m' => 2.0,
                'height_m' => 0.8,
                'pressure' => 35,
                'inventory_item_id' => $this->blockItem->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [
                'kind' => 'separator',
                'count' => 1,
                'length_m' => 0.4,
                'height_m' => 0.8,
                'inventory_item_id' => $this->blockItem->id,
                'warehouse_id' => $this->warehouse->id,
            ],
        ],
    ])->assertStatus(201);

    // Should transition to graded without failing unmeasured pressure check
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", [
        'status' => 'graded',
    ])->assertStatus(200)->assertJsonPath('status', 'graded');
});

test('a transferred block or scrap lot is not counted or numbered as scrap of its batch', function () {
    $batch = ($this->makeBatch)();
    $yard = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Yard', 'code' => 'WH-YARD']);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->scrapGroup)(1, 2.0, 1.0), ($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $stockLots = app(StockLotService::class);
    $movedBlock = $stockLots->transferBetweenWarehouses($this->blockItem->id, $this->warehouse->id, $yard->id, 1)['lots'][0];
    $stockLots->transferBetweenWarehouses($this->scrapItem->id, $this->warehouse->id, $yard->id, 4.8);

    // The copy keeps the batch for lineage but is neither a block nor scrap of it.
    expect($movedBlock->production_batch_id)->toBe($batch->id)
        ->and($movedBlock->sequence_in_batch)->toBeNull();

    ($this->api)()->getJson("/api/v1/production-batches/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('blocks_count', 1)
        ->assertJsonPath('scrap_lots_count', 1);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->scrapGroup)(1, 1.0, 1.0)],
    ])->assertStatus(201);

    expect($batch->scrapLots()->orderBy('lot_number')->pluck('lot_number')->all())
        ->toBe(['SCRAP-191-01', 'SCRAP-191-02']);
});

test('the batch listing shows a transferred block once unless transfers are asked for', function () {
    $batch = ($this->makeBatch)();
    $yard = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Yard', 'code' => 'WH-YARD']);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [($this->blockGroup)(1, 2.0, 0.8)],
    ])->assertStatus(201);

    $origin = StockLot::where('production_batch_id', $batch->id)->sole();
    $copy = app(StockLotService::class)
        ->transferBetweenWarehouses($this->blockItem->id, $this->warehouse->id, $yard->id, 1)['lots'][0];

    $listing = ($this->api)()->getJson("/api/v1/stock-lots?production_batch_id={$batch->id}")->assertOk();
    expect($listing->json('total'))->toBe(1)
        ->and($listing->json('data.0.id'))->toBe($origin->id);

    $withTransfers = ($this->api)()
        ->getJson("/api/v1/stock-lots?production_batch_id={$batch->id}&include_transfers=1")
        ->assertOk();
    expect(collect($withTransfers->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$origin->id, $copy->id])->sort()->values()->all());
});
