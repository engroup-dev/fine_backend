<?php

declare(strict_types=1);

use App\Enums\CutterWorkOrderStatus;
use App\Exceptions\SalesRuleException;
use App\Models\ByproductYield;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\FoamBlockConsumption;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CutterWorkOrderService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-06: the consumed block cost C splits by volume into the cut pieces
 * (C × P / V), the usable remnants restocked (C × R / V) and the byproduct
 * (C × S / V, priced per kg of the weigh-in), and the standalone cut-remnant
 * decision conserves the block's value the same way.
 */
beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Cutter Split', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Cutter', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Cutter Plant', 'unit_type' => 'manufactory', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Cutter WH', 'code' => 'CWH']);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->api = fn () => $this->actingAs($this->owner)->withHeader('X-Operating-Unit-ID', $this->unit->id);

    $this->foamItem = InventoryItem::create(['name' => 'Block D25', 'code' => 'BLK-D25', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3']);
    $this->pieceItem = InventoryItem::create(['name' => 'Seat Piece', 'code' => 'SEAT-1', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'each']);
    $this->fillItem = InventoryItem::create(['name' => 'Fill', 'code' => 'FILL-1', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg']);

    // 2 m³ for 1000.
    $this->block = StockLot::create([
        'inventory_item_id' => $this->foamItem->id, 'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'BLK-1', 'quantity' => 1, 'length_m' => 2.0, 'width_m' => 1.0, 'height_m' => 1.0,
        'unit_cost' => 1000.0, 'grade' => 'standard', 'pressure' => 25, 'status' => 'available',
    ]);

    // Reserve the block on a new order with one line of two 0.25 m³ seats
    // (P = 0.5 m³) and walk it to quality check with the given weigh-in.
    $this->orderAtQualityCheck = function (float $weightKg): string {
        $orderId = ($this->api)()->postJson('/api/v1/cutter-work-orders', ['stock_lot_id' => $this->block->id])
            ->assertCreated()->json('id');

        CutterWorkOrder::findOrFail($orderId)->lines()->create([
            'requested_spec' => 'seat', 'quantity' => 2, 'output_inventory_item_id' => $this->pieceItem->id,
            'template_length_m' => 1.0, 'template_width_m' => 0.5, 'template_height_m' => 0.5,
        ]);

        foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
            ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
        }

        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", $weightKg > 0
            ? ['weight_kg' => $weightKg, 'byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id]
            : ['weight_kg' => 0])->assertCreated();

        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check'])->assertOk();

        return $orderId;
    };

    // A foam block lot of the given size and cost.
    $this->makeBlock = function (string $lotNumber, float $length, float $width, float $height, float $cost, ?InventoryItem $item = null): StockLot {
        return StockLot::create([
            'inventory_item_id' => ($item ?? $this->foamItem)->id, 'warehouse_id' => $this->warehouse->id,
            'lot_number' => $lotNumber, 'quantity' => 1, 'length_m' => $length, 'width_m' => $width, 'height_m' => $height,
            'unit_cost' => $cost, 'grade' => 'standard', 'pressure' => 25, 'status' => 'available',
        ]);
    };

    // A new reserve-path order on the given blocks with one line.
    $this->newOrder = function (array $blocks, array $line, array $attrs = []): string {
        $orderId = ($this->api)()->postJson('/api/v1/cutter-work-orders', [...$attrs, 'stock_lot_id' => $blocks[0]->id])
            ->assertCreated()->json('id');

        foreach (array_slice($blocks, 1) as $block) {
            ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/attach-block", ['stock_lot_id' => $block->id])->assertOk();
        }

        CutterWorkOrder::findOrFail($orderId)->lines()->create([
            'requested_spec' => 'piece', 'output_inventory_item_id' => $this->pieceItem->id, ...$line,
        ]);

        return $orderId;
    };

    // Walk an order from requested to quality check through the given
    // weigh-ins (each a weigh-in payload).
    $this->toQualityCheck = function (string $orderId, array $weighIns): void {
        foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
            ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
        }

        foreach ($weighIns as $payload) {
            ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", $payload)->assertCreated();
            // Fill lot numbers carry the second.
            $this->travel(1)->seconds();
        }

        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check'])->assertOk();
    };

    $this->seatLine = [
        'requested_spec' => 'seat', 'quantity' => 2,
        'template_length_m' => 1.0, 'template_width_m' => 0.5, 'template_height_m' => 0.5,
    ];

    $this->ledger = function (): array {
        $tb = app(AccountingService::class)->trialBalance();
        expect($tb['balanced'])->toBeTrue();

        return collect($tb['rows'])->keyBy('account_code')->all();
    };
});

test('the reserve path splits each block between templates and offcut instead of a zero remainder', function () {
    ($this->orderAtQualityCheck)(20);

    $consumption = FoamBlockConsumption::where('stock_lot_id', $this->block->id)->sole();

    // 0.5 of 2 m³ is templates: a quarter of the 1000.
    expect($consumption->consumption_type)->toBe('partial')
        ->and((float) $consumption->consumed_cost)->toBe(250.0)
        ->and((float) $consumption->remainder_cost)->toBe(750.0)
        ->and((float) $consumption->volume_consumed_m3)->toBe(0.5);

    // Before remnants are known the offcut (1.5 m³) is all byproduct: 750 / 20 kg.
    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    expect((float) $fill->unit_cost)->toBe(37.5);
});

test('completion splits the block cost across pieces, restocked remnants and byproduct', function () {
    $orderId = ($this->orderAtQualityCheck)(20);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    // V = 2, P = 0.5, R = 0.5, S = 1: pieces 250, remnant 250, byproduct 500.
    $pieces = StockLot::where('inventory_item_id', $this->pieceItem->id)->orderBy('lot_number')->get();
    expect($pieces)->toHaveCount(2)
        ->and($pieces->sum(fn (StockLot $p) => (float) $p->unit_cost))->toBe(250.0)
        ->and((float) $pieces->first()->unit_cost)->toBe(125.0);

    $remnant = StockLot::where('remnant_of_lot_id', $this->block->id)->sole();
    expect($remnant->inventory_item_id)->toBe($this->foamItem->id)
        ->and($remnant->lot_number)->toBe('BLK-1-R1')
        ->and($remnant->status)->toBe('available')
        ->and($remnant->warehouse_id)->toBe($this->warehouse->id)
        ->and((float) $remnant->volume_m3)->toBe(0.5)
        ->and((float) $remnant->quantity)->toBe(1.0)
        ->and((int) $remnant->pressure)->toBe(25)
        ->and((float) $remnant->unit_cost)->toBe(250.0);

    // The remnant enters stock through its own movement on the order.
    $movement = InventoryMovement::where('stock_lot_id', $remnant->id)->sole();
    expect($movement->movement_type)->toBe('production_output')
        ->and($movement->reason)->toBe('cutter_remnant_restock')
        ->and($movement->reference_id)->toBe($orderId)
        ->and((float) $movement->unit_cost)->toBe(250.0);

    // The byproduct is re-priced per kg from the final split: 500 / 20 kg.
    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    expect((float) $fill->unit_cost)->toBe(25.0)
        ->and((float) ByproductYield::where('cutter_work_order_id', $orderId)->sole()->yield_cost)->toBe(500.0);

    // Conservation: the outputs carry exactly the block's 1000.
    $outputs = $pieces->sum(fn (StockLot $p) => (float) $p->unit_cost) + (float) $remnant->unit_cost + (float) $fill->unit_cost * 20;
    expect(round($outputs, 4))->toBe(1000.0);

    // DR 1132 pieces + DR 1131 remnant + DR 1133 byproduct / CR 1122.
    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(250.0)
        ->and((float) $ledger['1131']['debit'])->toBe(250.0)
        ->and((float) $ledger['1131']['credit'])->toBe(1000.0)
        ->and((float) $ledger['1133']['debit'])->toBe(500.0);

    expect((float) CutterWorkOrder::findOrFail($orderId)->wip_cost)->toBe(0.0);
});

test('with nothing weighed in the byproduct share lands on the pieces', function () {
    $orderId = ($this->orderAtQualityCheck)(0);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    $pieceValue = StockLot::where('inventory_item_id', $this->pieceItem->id)->get()->sum(fn (StockLot $p) => (float) $p->unit_cost);
    expect($pieceValue)->toBe(750.0)
        ->and((float) StockLot::where('remnant_of_lot_id', $this->block->id)->sole()->unit_cost)->toBe(250.0);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(750.0);
});

test('pieces and remnants larger than the blocks cut are refused', function () {
    $orderId = ($this->orderAtQualityCheck)(20);

    // P 0.5 + R 1.6 = 2.1 m³ out of a 2 m³ block.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.6, 'width_m' => 1.0, 'height_m' => 1.0]],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_VOLUME_EXCEEDS_BLOCKS');

    expect(CutterWorkOrder::findOrFail($orderId)->status->value)->toBe('quality_check')
        ->and(StockLot::where('remnant_of_lot_id', $this->block->id)->exists())->toBeFalse()
        ->and(StockLot::where('inventory_item_id', $this->pieceItem->id)->exists())->toBeFalse()
        ->and((float) ($this->ledger)()['1122']['balance'])->toBe(1000.0);
});

test('remnants are only accepted with the completion', function () {
    $orderId = ($this->api)()->postJson('/api/v1/cutter-work-orders', ['stock_lot_id' => $this->block->id])
        ->assertCreated()->json('id');

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'confirmed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertUnprocessable()->assertJsonValidationErrors('remnants');
});

test('a standalone cut-remnant decision splits the block between the remnant and the fill lot', function () {
    // 2 × 1 × 1 = 2 m³ for 1000; the remnant is 1 m³.
    $response = ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 1.0],
        'byproduct_weight_kg' => 10,
        'byproduct_inventory_item_id' => $this->fillItem->id,
    ])->assertOk();

    $parent = $this->block->fresh();
    $remnant = StockLot::findOrFail($response->json('remnant_lot.id'));
    $fill = StockLot::findOrFail($response->json('byproduct_lot.id'));

    expect($parent->status)->toBe('consumed')
        ->and((float) $parent->quantity)->toBe(0.0)
        ->and((float) $remnant->unit_cost)->toBe(500.0)
        ->and($fill->inventory_item_id)->toBe($this->fillItem->id)
        ->and((float) $fill->quantity)->toBe(10.0)
        ->and((float) $fill->unit_cost)->toBe(50.0);

    // The fill enters stock with its lot, not as a lot-less movement.
    expect(InventoryMovement::where('stock_lot_id', $fill->id)->where('movement_type', 'byproduct_yield')->exists())->toBeTrue();

    // No duplicated cost: remnant 500 + fill 500 = the block's 1000.
    $ledger = ($this->ledger)();
    expect((float) $ledger['1131']['debit'])->toBe(500.0)
        ->and((float) $ledger['1131']['credit'])->toBe(1000.0)
        ->and((float) $ledger['1133']['debit'])->toBe(500.0);
});

test('a standalone cut with nothing salvaged writes the rest of the block off to 52', function () {
    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 0.5, 'width_m' => 1.0, 'height_m' => 1.0],
    ])->assertOk()->assertJsonPath('byproduct_lot', null);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1131']['debit'])->toBe(250.0)
        ->and((float) $ledger['1131']['credit'])->toBe(1000.0)
        ->and((float) $ledger['52']['debit'])->toBe(750.0);
});

test('a standalone cut refuses a remnant bigger than the block and a block already cut', function () {
    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 2.5, 'width_m' => 1.0, 'height_m' => 1.0],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_VOLUME_EXCEEDS_BLOCKS');

    expect($this->block->fresh()->status)->toBe('available');

    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'convert_to_byproduct',
        'byproduct_weight_kg' => 5,
    ])->assertOk();

    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'convert_to_byproduct',
        'byproduct_weight_kg' => 5,
    ])->assertUnprocessable()->assertJsonPath('code', 'LOT_NOT_AVAILABLE');
});

test('pieces a little larger than the blocks still complete when no remnant is claimed', function () {
    // 4 blocks of 1.955 × 1.205 × 0.255 m: each lot keeps 0.6007 m³ (V = 2.4028)
    // while the template keeps 0.600723 m³ (P = 2.402892). Rounding alone.
    $blocks = collect(range(1, 4))->map(fn (int $n) => ($this->makeBlock)("EXACT-{$n}", 1.955, 1.205, 0.255, 250.0))->all();
    $orderId = ($this->newOrder)($blocks, [
        'quantity' => 4, 'template_length_m' => 1.955, 'template_width_m' => 1.205, 'template_height_m' => 0.255,
    ]);
    ($this->toQualityCheck)($orderId, [['weight_kg' => 0]]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed'])->assertOk();

    expect(StockLot::where('inventory_item_id', $this->pieceItem->id)->get()->sum(fn (StockLot $p) => (float) $p->unit_cost))->toBe(1000.0)
        ->and((float) ($this->ledger)()['1122']['balance'])->toBe(0.0);
});

test('a reserve-path order whose pieces exceed its blocks completes with everything on the pieces, but takes no remnant', function () {
    // Three 1 m³ seats out of a 2 m³ block (P = 3 > V = 2).
    $orderId = ($this->newOrder)([$this->block], [
        'quantity' => 3, 'template_length_m' => 1.0, 'template_width_m' => 1.0, 'template_height_m' => 1.0,
    ]);
    ($this->toQualityCheck)($orderId, [['weight_kg' => 5, 'byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id]]);

    // Nothing is left over for a remnant.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 0.5, 'width_m' => 0.5, 'height_m' => 0.5]],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_VOLUME_EXCEEDS_BLOCKS');

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed'])->assertOk();

    // S = max(0, V − P) = 0: the byproduct takes nothing.
    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(1000.0)
        ->and(StockLot::where('inventory_item_id', $this->pieceItem->id)->count())->toBe(3);
});

test('the per-line selectBlock path restocks a remnant with the same split', function () {
    $orderId = ($this->api)()->postJson('/api/v1/cutter-work-orders', [])->assertCreated()->json('id');
    $line = CutterWorkOrder::findOrFail($orderId)->lines()->create([
        ...$this->seatLine, 'output_inventory_item_id' => $this->pieceItem->id,
    ]);
    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$line->id}/select-block", ['stock_lot_id' => $this->block->id])->assertSuccessful();

    ($this->toQualityCheck)($orderId, [['weight_kg' => 20, 'byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id]]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    expect((float) StockLot::where('remnant_of_lot_id', $this->block->id)->sole()->unit_cost)->toBe(250.0)
        ->and((float) StockLot::where('inventory_item_id', $this->fillItem->id)->sole()->unit_cost)->toBe(25.0);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(250.0)
        ->and((float) $ledger['1131']['debit'])->toBe(250.0)
        ->and((float) $ledger['1133']['debit'])->toBe(500.0);
});

test('two blocks and two remnants each restock as their own block and the split conserves to the cent', function () {
    $foamB = InventoryItem::create(['name' => 'Block D30', 'code' => 'BLK-D30', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3']);
    $this->block->update(['unit_cost' => 700.0]);
    $blockB = ($this->makeBlock)('BLK-2', 1.0, 1.0, 1.0, 300.0, $foamB);
    $blockB->update(['pressure' => 30, 'grade' => 'premium']);

    // C = 1000, V = 3, P = 0.5.
    $orderId = ($this->newOrder)([$this->block, $blockB], $this->seatLine);
    ($this->toQualityCheck)($orderId, [['weight_kg' => 7, 'byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id]]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [
            ['length_m' => 0.3, 'width_m' => 1.0, 'height_m' => 1.0],
            ['length_m' => 0.4, 'width_m' => 1.0, 'height_m' => 1.0, 'stock_lot_id' => $blockB->id],
        ],
    ])->assertOk();

    // R = 0.7, S = 1.8: remnants 100 and 133.3333, byproduct 600, pieces 166.6667.
    $first = StockLot::where('remnant_of_lot_id', $this->block->id)->sole();
    $second = StockLot::where('remnant_of_lot_id', $blockB->id)->sole();
    expect($first->inventory_item_id)->toBe($this->foamItem->id)
        ->and($first->lot_number)->toBe('BLK-1-R1')
        ->and((float) $first->unit_cost)->toBe(100.0)
        ->and($second->inventory_item_id)->toBe($foamB->id)
        ->and($second->lot_number)->toBe('BLK-2-R1')
        ->and((int) $second->pressure)->toBe(30)
        ->and($second->grade)->toBe('premium')
        ->and((float) $second->unit_cost)->toBe(133.3333);

    $pieces = StockLot::where('inventory_item_id', $this->pieceItem->id)->get();
    expect(round($pieces->sum(fn (StockLot $p) => (float) $p->unit_cost), 4))->toBe(166.6667)
        ->and((float) ByproductYield::where('cutter_work_order_id', $orderId)->sole()->yield_cost)->toBe(600.0);

    // Every cent that left 1122 lands on an output account.
    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(166.6667)
        ->and((float) $ledger['1131']['debit'])->toBe(233.3333)
        ->and((float) $ledger['1131']['credit'])->toBe(1000.0)
        ->and((float) $ledger['1133']['debit'])->toBe(600.0);
});

test('a remnant must fit its own block and name a block the order cut', function () {
    $blockB = ($this->makeBlock)('BLK-2', 1.0, 1.0, 1.0, 500.0);
    $orderId = ($this->newOrder)([$this->block, $blockB], $this->seatLine);
    ($this->toQualityCheck)($orderId, [['weight_kg' => 0]]);

    // 1.5 m³ fits the 2.5 m³ left overall, but not the 1 m³ block it names.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.5, 'width_m' => 1.0, 'height_m' => 1.0, 'stock_lot_id' => $blockB->id]],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_VOLUME_EXCEEDS_BLOCKS');

    $stranger = ($this->makeBlock)('BLK-X', 1.0, 1.0, 1.0, 100.0);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 0.5, 'width_m' => 0.5, 'height_m' => 0.5, 'stock_lot_id' => $stranger->id]],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_REMNANT_NO_SOURCE_BLOCK');

    // A side that rounds away to nothing is refused by validation.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 0.0004, 'width_m' => 1.0, 'height_m' => 1.0]],
    ])->assertUnprocessable()->assertJsonValidationErrors('remnants.0.length_m');

    // So is a remnant whose volume rounds to zero.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 0.01, 'width_m' => 0.01, 'height_m' => 0.01]],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_REMNANT_TOO_SMALL');

    expect(CutterWorkOrder::findOrFail($orderId)->status->value)->toBe('quality_check');
});

test('two weigh-ins share the byproduct by weight', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);
    $fillPayload = ['byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id];
    ($this->toQualityCheck)($orderId, [['weight_kg' => 15, ...$fillPayload], ['weight_kg' => 5, ...$fillPayload]]);

    // Before remnants S = 1.5 m³ = 750, shared 562.5 / 187.5: the second
    // weigh-in re-priced the first lot, so both carry 37.5 per kg.
    $yields = ByproductYield::where('cutter_work_order_id', $orderId)->orderBy('weight_kg', 'desc')->get();
    expect($yields->map(fn (ByproductYield $y) => (float) $y->yield_cost)->all())->toBe([562.5, 187.5]);

    $fills = StockLot::where('inventory_item_id', $this->fillItem->id)->orderBy('quantity', 'desc')->get();
    expect($fills)->toHaveCount(2)
        ->and($fills->map(fn (StockLot $l) => (float) $l->unit_cost)->all())->toBe([37.5, 37.5]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    // S = 1 m³ = 500, shared 375 / 125: 25 per kg on both lots.
    $yields = ByproductYield::where('cutter_work_order_id', $orderId)->orderBy('weight_kg', 'desc')->get();
    expect($yields->map(fn (ByproductYield $y) => (float) $y->yield_cost)->all())->toBe([375.0, 125.0]);

    $fills = StockLot::where('inventory_item_id', $this->fillItem->id)->orderBy('quantity', 'desc')->get();
    expect($fills->every(fn (StockLot $l) => (float) $l->unit_cost === 25.0))->toBeTrue();

    // Movements are append-only: each yield movement keeps its weigh-in price
    // and every re-price is its own zero-quantity revaluation movement.
    $first = InventoryMovement::where('stock_lot_id', $fills[0]->id)->orderBy('created_at')->orderBy('id')->get();
    expect($first->map(fn (InventoryMovement $m) => [$m->movement_type, (float) $m->quantity_delta, (float) $m->unit_cost])->all())
        ->toBe([['byproduct_yield', 15.0, 50.0], ['adjustment', 0.0, 37.5], ['adjustment', 0.0, 25.0]])
        ->and($first->pluck('reference_id')->unique()->all())->toBe([$orderId]);

    $second = InventoryMovement::where('stock_lot_id', $fills[1]->id)->orderBy('created_at')->orderBy('id')->get();
    expect($second->map(fn (InventoryMovement $m) => [$m->movement_type, (float) $m->quantity_delta, (float) $m->unit_cost])->all())
        ->toBe([['byproduct_yield', 5.0, 37.5], ['adjustment', 0.0, 25.0]]);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1133']['debit'])->toBe(500.0);
});

test('a fill lot that moves after two weigh-ins does not block a completion without remnants', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);
    $fillPayload = ['byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id];
    ($this->toQualityCheck)($orderId, [['weight_kg' => 15, ...$fillPayload], ['weight_kg' => 5, ...$fillPayload]]);

    // 5 kg of the first lot leaves at the 37.5 per kg it now carries.
    $first = StockLot::where('inventory_item_id', $this->fillItem->id)->where('quantity', 15)->sole();
    $first->update(['quantity' => 10]);
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $first->id, 'from_warehouse_id' => $this->warehouse->id,
        'sku' => 'FILL-1', 'movement_type' => 'issue', 'quantity_delta' => -5, 'unit_cost' => 37.5,
        'reason' => 'test_issue', 'reference_document_type' => 'Test', 'reference_id' => $first->id,
    ]);

    // Remnants would lower a price that moved stock was already costed at.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertUnprocessable()->assertJsonPath('code', 'BYPRODUCT_ALREADY_MOVED');

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed'])->assertOk();

    // Nothing is re-priced: both lots keep 37.5 per kg, the byproduct 750.
    expect(StockLot::where('inventory_item_id', $this->fillItem->id)->get()->every(fn (StockLot $l) => (float) $l->unit_cost === 37.5))->toBeTrue()
        ->and(InventoryMovement::where('reason', 'cutter_byproduct_reprice')->count())->toBe(1);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(250.0)
        ->and((float) $ledger['1133']['debit'])->toBe(750.0);
});

test('a further weigh-in is refused once an earlier fill lot has moved', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);
    $fillPayload = ['byproduct_inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id];

    foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
    }

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", ['weight_kg' => 15, ...$fillPayload])->assertCreated();
    $first = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    $first->update(['status' => 'reserved']);
    $this->travel(1)->seconds();

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", ['weight_kg' => 5, ...$fillPayload])
        ->assertUnprocessable()->assertJsonPath('code', 'BYPRODUCT_ALREADY_MOVED');

    expect(ByproductYield::where('cutter_work_order_id', $orderId)->count())->toBe(1)
        ->and(StockLot::where('inventory_item_id', $this->fillItem->id)->count())->toBe(1)
        ->and((float) $first->fresh()->unit_cost)->toBe(50.0);
});

test('a weigh-in with weight needs a fill item and lands in the block\'s warehouse by default', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);

    foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
    }

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", ['weight_kg' => 20])
        ->assertUnprocessable()->assertJsonPath('code', 'BYPRODUCT_ITEM_REQUIRED');

    expect(ByproductYield::where('cutter_work_order_id', $orderId)->exists())->toBeFalse();

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", [
        'weight_kg' => 20, 'byproduct_inventory_item_id' => $this->fillItem->id,
    ])->assertCreated();

    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    expect($fill->warehouse_id)->toBe($this->warehouse->id)
        ->and((float) $fill->unit_cost)->toBe(37.5);
});

test('a weigh-in recorded with weight but no fill lot before W4-06 leaves its share on the pieces', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);
    ($this->toQualityCheck)($orderId, [['weight_kg' => 0]]);

    // Such rows can no longer be written through the API.
    ByproductYield::where('cutter_work_order_id', $orderId)->update(['weight_kg' => 20, 'yield_cost' => 750]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    // No lot holds the byproduct, so 1133 takes nothing: pieces 750, remnant 250.
    expect((float) ByproductYield::where('cutter_work_order_id', $orderId)->sole()->yield_cost)->toBe(0.0);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(750.0)
        ->and((float) $ledger['1131']['debit'])->toBe(250.0)
        ->and($ledger['1133']['debit'] ?? 0.0)->toEqual(0.0);
});

test('a fill lot priced before W4-06 that has moved keeps its price and the difference stays on the pieces', function () {
    $orderId = ($this->orderAtQualityCheck)(20);

    // The reserve path used to price the fill at 0; then 10 kg left at 0.
    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    $fill->update(['unit_cost' => 0, 'quantity' => 10]);
    ByproductYield::where('cutter_work_order_id', $orderId)->update(['yield_cost' => 0]);
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $fill->id, 'from_warehouse_id' => $this->warehouse->id,
        'sku' => 'FILL-1', 'movement_type' => 'issue', 'quantity_delta' => -10, 'unit_cost' => 0,
        'reason' => 'test_issue', 'reference_document_type' => 'Test', 'reference_id' => $fill->id,
    ]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    // Pieces 250 + the byproduct's 500 they keep; the remnant 250.
    expect((float) $fill->fresh()->unit_cost)->toBe(0.0)
        ->and(StockLot::where('inventory_item_id', $this->pieceItem->id)->get()->sum(fn (StockLot $p) => (float) $p->unit_cost))->toBe(750.0)
        ->and((float) StockLot::where('remnant_of_lot_id', $this->block->id)->sole()->unit_cost)->toBe(250.0);

    $ledger = ($this->ledger)();
    expect((float) $ledger['1122']['balance'])->toBe(0.0)
        ->and((float) $ledger['1132']['debit'])->toBe(750.0);
});

test('a fill lot that moved since its weigh-in cannot be re-priced by a remnant', function () {
    $orderId = ($this->orderAtQualityCheck)(20);

    // Half the fill leaves at the provisional 37.5 per kg.
    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->sole();
    $fill->update(['quantity' => 10]);
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $fill->id, 'from_warehouse_id' => $this->warehouse->id,
        'sku' => 'FILL-1', 'movement_type' => 'issue', 'quantity_delta' => -10, 'unit_cost' => 37.5,
        'reason' => 'test_issue', 'reference_document_type' => 'Test', 'reference_id' => $fill->id,
    ]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertUnprocessable()->assertJsonPath('code', 'BYPRODUCT_ALREADY_MOVED');

    expect(CutterWorkOrder::findOrFail($orderId)->status->value)->toBe('quality_check')
        ->and((float) $fill->fresh()->unit_cost)->toBe(37.5)
        ->and(StockLot::where('remnant_of_lot_id', $this->block->id)->exists())->toBeFalse();

    // Without a remnant the price stands, so the order still completes.
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed'])->assertOk();

    expect((float) $fill->fresh()->unit_cost)->toBe(37.5)
        ->and((float) ($this->ledger)()['1133']['debit'])->toBe(750.0);
});

test('outputs land in the order\'s output warehouse', function () {
    $outputWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Cutter Output', 'code' => 'COUT']);
    $orderId = ($this->newOrder)([$this->block], $this->seatLine, ['output_warehouse_id' => $outputWarehouse->id]);

    // The weigh-in names no warehouse: the fill goes to the output warehouse.
    ($this->toQualityCheck)($orderId, [['weight_kg' => 20, 'byproduct_inventory_item_id' => $this->fillItem->id]]);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed',
        'remnants' => [['length_m' => 1.0, 'width_m' => 1.0, 'height_m' => 0.5]],
    ])->assertOk();

    expect(StockLot::where('inventory_item_id', $this->pieceItem->id)->pluck('warehouse_id')->unique()->all())->toBe([$outputWarehouse->id])
        ->and(StockLot::where('remnant_of_lot_id', $this->block->id)->sole()->warehouse_id)->toBe($outputWarehouse->id)
        ->and(StockLot::where('inventory_item_id', $this->fillItem->id)->sole()->warehouse_id)->toBe($outputWarehouse->id);
});

test('the completion can choose the output warehouse, but only one of the order\'s unit', function () {
    $orderId = ($this->orderAtQualityCheck)(0);
    $order = CutterWorkOrder::findOrFail($orderId);

    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Other Plant', 'unit_type' => 'manufactory', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $foreign = Warehouse::withoutGlobalScopes()->create(['operating_unit_id' => $otherUnit->id, 'name' => 'Other WH', 'code' => 'OWH']);

    expect(fn () => app(CutterWorkOrderService::class)->transition($order, CutterWorkOrderStatus::Completed, [], $foreign->id))
        ->toThrow(fn (SalesRuleException $e) => expect($e->errorCode)->toBe('OUTPUT_WAREHOUSE_OTHER_UNIT'));

    $outputWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Cutter Output', 'code' => 'COUT']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", [
        'status' => 'completed', 'output_warehouse_id' => $outputWarehouse->id,
    ])->assertOk();

    expect($order->fresh()->output_warehouse_id)->toBe($outputWarehouse->id)
        ->and(StockLot::where('inventory_item_id', $this->pieceItem->id)->pluck('warehouse_id')->unique()->all())->toBe([$outputWarehouse->id]);
});

test('a standalone cut refuses a lot that is not a foam block before any write', function () {
    $drum = StockLot::create([
        'inventory_item_id' => $this->fillItem->id, 'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'DRUM-1', 'quantity' => 200, 'unit_cost' => 5.0, 'status' => 'available',
    ]);

    ($this->api)()->postJson("/api/v1/stock-lots/{$drum->id}/process-cut-remnant", [
        'remnant_action' => 'convert_to_byproduct',
    ])->assertUnprocessable()->assertJsonPath('code', 'LOT_NOT_FOAM_BLOCK');

    expect($drum->fresh()->status)->toBe('available')
        ->and((float) $drum->fresh()->quantity)->toBe(200.0)
        ->and(InventoryMovement::where('stock_lot_id', $drum->id)->exists())->toBeFalse();
});

test('a standalone cut refuses a weigh-in with no fill item, a block with no size and a remnant too small to measure', function () {
    $this->fillItem->delete();

    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'convert_to_byproduct',
        'byproduct_weight_kg' => 5,
    ])->assertUnprocessable()->assertJsonPath('code', 'BYPRODUCT_ITEM_REQUIRED');

    expect($this->block->fresh()->status)->toBe('available')
        ->and((float) $this->block->fresh()->quantity)->toBe(1.0);

    ($this->api)()->postJson("/api/v1/stock-lots/{$this->block->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 0.01, 'width_m' => 0.01, 'height_m' => 0.01],
    ])->assertUnprocessable()->assertJsonPath('code', 'CUT_REMNANT_TOO_SMALL');

    $sizeless = StockLot::create([
        'inventory_item_id' => $this->foamItem->id, 'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'BLK-NOSIZE', 'quantity' => 1, 'length_m' => 2.0,
        'unit_cost' => 400.0, 'status' => 'available',
    ]);

    ($this->api)()->postJson("/api/v1/stock-lots/{$sizeless->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 0.5, 'width_m' => 0.5, 'height_m' => 0.5],
    ])->assertUnprocessable()->assertJsonPath('code', 'BLOCK_DIMENSIONS_REQUIRED');

    expect($sizeless->fresh()->status)->toBe('available');
});

test('a weigh-in refuses a fill item that is not a byproduct fill', function () {
    $orderId = ($this->newOrder)([$this->block], $this->seatLine);

    foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
    }

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", [
        'weight_kg' => 20, 'byproduct_inventory_item_id' => $this->foamItem->id, 'warehouse_id' => $this->warehouse->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('byproduct_inventory_item_id');

    expect(ByproductYield::where('cutter_work_order_id', $orderId)->exists())->toBeFalse()
        ->and(StockLot::where('inventory_item_id', $this->foamItem->id)->count())->toBe(1);
});

test('a standalone cut refuses a lot of more than one block before any write', function () {
    // The model refuses a foam block lot of more than one (INV-02), so a row
    // like this only exists from before that guard or from a raw write.
    $pair = ($this->makeBlock)('BLK-PAIR', 2.0, 1.0, 1.0, 1000.0);
    StockLot::whereKey($pair->id)->update(['quantity' => 2]);

    ($this->api)()->postJson("/api/v1/stock-lots/{$pair->id}/process-cut-remnant", [
        'remnant_action' => 'restock_remnant',
        'remnant_dimensions' => ['length_m' => 0.5, 'width_m' => 1.0, 'height_m' => 1.0],
    ])->assertUnprocessable()->assertJsonPath('code', 'LOT_NOT_SINGLE_BLOCK');

    expect($pair->fresh()->status)->toBe('available')
        ->and((float) $pair->fresh()->quantity)->toBe(2.0)
        ->and(InventoryMovement::where('stock_lot_id', $pair->id)->exists())->toBeFalse();
});
