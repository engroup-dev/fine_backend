<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AssemblyOrder;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-07 (decision #12): furniture assembly orders consume component lots and
 * produce a finished-goods lot at the summed actual cost.
 */
beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Furniture Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Furniture', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Workshop', 'unit_type' => 'furniture', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Other Workshop', 'unit_type' => 'furniture', 'currency' => 'LYD', 'status' => 'active',
    ]);
    seedUnitAccounts([$this->unit, $this->otherUnit]);

    $this->componentsWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Components WH']);
    $this->outputWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Finished WH']);
    $this->otherWarehouse = Warehouse::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Other WH']);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
    $this->manager = $makeUser('furniture-manager', $this->unit->id);
    $this->assembler = $makeUser('assembler', $this->unit->id);
    $this->cashier = $makeUser('pos-cashier', $this->unit->id);

    $this->sofa = InventoryItem::create(['name' => 'Sofa', 'code' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece']);
    $this->fabric = InventoryItem::create(['name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter']);
    $this->frame = InventoryItem::create(['name' => 'Frame', 'code' => 'FRM-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'piece']);
    $this->cushion = InventoryItem::create(['name' => 'Cushion', 'code' => 'CUT-1', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'piece']);

    $this->lot = fn (InventoryItem $item, float $quantity, float $unitCost, ?Warehouse $warehouse = null, array $extra = []) => StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->componentsWarehouse)->id,
        'lot_number' => 'LOT-'.$item->code.'-'.uniqid(), 'quantity' => $quantity, 'unit_cost' => $unitCost, 'status' => 'available',
        ...$extra,
    ]);

    $this->as = fn (User $user, ?OperatingUnit $unit = null) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unit)->id]);

    $this->create = fn (array $body = [], ?User $user = null) => ($this->as)($user ?? $this->manager)->postJson('/api/v1/assembly-orders', [
        'finished_item_id' => $this->sofa->id,
        'quantity' => 2,
        'output_warehouse_id' => $this->outputWarehouse->id,
        ...$body,
    ]);

    $this->complete = fn (array $order, ?User $user = null) => ($this->as)($user ?? $this->assembler)
        ->postJson("/api/v1/assembly-orders/{$order['id']}/complete");

    $this->sum = fn (string $orderId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_type', 'AssemblyOrder')->where('source_document_id', $orderId))
        ->where('account_id', Account::where('account_code', $code)->value('id'))
        ->sum($side);
});

test('completion rolls the components\' actual cost into the finished lot', function () {
    $fabricOld = ($this->lot)($this->fabric, 4, 10);
    $this->travel(1)->minutes();
    $fabricNew = ($this->lot)($this->fabric, 10, 12);
    $frame = ($this->lot)($this->frame, 5, 80);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6],
        ['inventory_item_id' => $this->frame->id, 'quantity' => 2, 'stock_lot_id' => $frame->id],
    ]])->assertCreated()->json();

    expect($order['number'])->toStartWith('ASM-')
        ->and($order['status'])->toBe('draft')
        ->and($order['lines'])->toHaveCount(2);

    $done = ($this->complete)($order)->assertOk()->json();

    // Fabric: 4 × 10 (oldest lot) + 2 × 12; frame 2 × 80 → 64 + 160 = 224 for 2 sofas.
    $finished = StockLot::withoutGlobalScopes()->findOrFail($done['finished_stock_lot_id']);

    expect($done['status'])->toBe('completed')
        ->and((float) $done['total_cost'])->toBe(224.0)
        ->and((float) $done['summary']['unit_cost'])->toBe(112.0)
        ->and($done['lines'])->toHaveCount(3)
        ->and($done['completed_by']['id'])->toBe($this->assembler->id)
        ->and($finished->lot_number)->toBe('FG-'.$done['number'])
        ->and($finished->warehouse_id)->toBe($this->outputWarehouse->id)
        ->and($finished->inventory_item_id)->toBe($this->sofa->id)
        ->and((float) $finished->quantity)->toBe(2.0)
        ->and((float) $finished->unit_cost)->toBe(112.0)
        ->and($finished->status)->toBe('available')
        ->and((float) $fabricOld->fresh()->quantity)->toBe(0.0)
        ->and($fabricOld->fresh()->status)->toBe('consumed')
        ->and((float) $fabricNew->fresh()->quantity)->toBe(8.0)
        ->and((float) $frame->fresh()->quantity)->toBe(3.0);

    $fabricLines = collect($done['lines'])->where('inventory_item_id', $this->fabric->id);
    expect($fabricLines->pluck('stock_lot_id')->sort()->values()->all())
        ->toBe(collect([$fabricOld->id, $fabricNew->id])->sort()->values()->all())
        ->and($fabricLines->sum(fn ($line) => (float) $line['total_cost']))->toBe(64.0);
});

test('completion writes consumption movements and a production output movement', function () {
    $fabric = ($this->lot)($this->fabric, 10, 10);
    $frame = ($this->lot)($this->frame, 5, 80);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6],
        ['inventory_item_id' => $this->frame->id, 'quantity' => 2],
    ]])->assertCreated()->json();

    $done = ($this->complete)($order)->assertOk()->json();

    $movements = InventoryMovement::withoutGlobalScopes()
        ->where('reference_document_type', 'AssemblyOrder')
        ->where('reference_id', $order['id'])
        ->get();

    $consumption = $movements->where('movement_type', 'consumption');
    $output = $movements->where('movement_type', 'production_output')->sole();

    expect($consumption)->toHaveCount(2)
        ->and((float) $consumption->firstWhere('stock_lot_id', $fabric->id)->quantity_delta)->toBe(-6.0)
        ->and((float) $consumption->firstWhere('stock_lot_id', $frame->id)->quantity_delta)->toBe(-2.0)
        ->and($consumption->every(fn ($m) => $m->operating_unit_id === $this->unit->id))->toBeTrue()
        ->and($output->stock_lot_id)->toBe($done['finished_stock_lot_id'])
        ->and($output->to_warehouse_id)->toBe($this->outputWarehouse->id)
        ->and((float) $output->quantity_delta)->toBe(2.0)
        ->and((float) $output->unit_cost)->toBe(110.0);
});

test('completion posts DR 1134 against each component lot\'s account', function () {
    ($this->lot)($this->fabric, 10, 10);
    ($this->lot)($this->cushion, 4, 25);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 5],
        ['inventory_item_id' => $this->cushion->id, 'quantity' => 4],
    ]])->assertCreated()->json();

    $done = ($this->complete)($order)->assertOk()->json();

    $entry = JournalEntry::findOrFail($done['journal_entry_id']);

    expect($entry->source_document_type)->toBe('AssemblyOrder')
        ->and(($this->sum)($order['id'], '1134', 'debit'))->toBe(150.0)
        ->and(($this->sum)($order['id'], '111', 'credit'))->toBe(50.0)
        ->and(($this->sum)($order['id'], '1132', 'credit'))->toBe(100.0)
        ->and(($this->sum)($order['id'], '1123', 'debit'))->toBe(0.0)
        ->and(JournalLine::where('journal_entry_id', $entry->id)->pluck('operating_unit_id')->unique()->all())->toBe([$this->unit->id]);
});

test('an order opened from a bundle template prefills its components', function () {
    $bundle = Bundle::create(['name' => 'Sofa set']);
    BundleItem::create(['bundle_id' => $bundle->id, 'inventory_item_id' => $this->frame->id, 'suggested_quantity' => 1, 'position' => 0]);
    BundleItem::create(['bundle_id' => $bundle->id, 'inventory_item_id' => $this->fabric->id, 'suggested_quantity' => 3.5, 'position' => 1]);

    $order = ($this->create)(['bundle_id' => $bundle->id])->assertCreated()->json();

    expect($order['bundle']['id'])->toBe($bundle->id)
        ->and(collect($order['lines'])->pluck('inventory_item_id')->all())->toBe([$this->frame->id, $this->fabric->id])
        ->and(collect($order['lines'])->map(fn ($l) => (float) $l['quantity'])->all())->toBe([2.0, 7.0])
        ->and(collect($order['lines'])->pluck('stock_lot_id')->filter()->all())->toBe([]);
});

test('an insufficient component refuses completion and moves nothing', function () {
    $fabric = ($this->lot)($this->fabric, 3, 10);
    $frame = ($this->lot)($this->frame, 5, 80);
    ($this->lot)($this->fabric, 50, 10, $this->otherWarehouse);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->frame->id, 'quantity' => 2],
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6],
    ]])->assertCreated()->json();

    ($this->complete)($order)
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortfalls.0.inventory_item_id', $this->fabric->id);

    expect((float) $fabric->fresh()->quantity)->toBe(3.0)
        ->and((float) $frame->fresh()->quantity)->toBe(5.0)
        ->and(AssemblyOrder::findOrFail($order['id'])->status->value)->toBe('draft')
        ->and(InventoryMovement::withoutGlobalScopes()->where('reference_document_type', 'AssemblyOrder')->exists())->toBeFalse()
        ->and(JournalEntry::where('source_document_type', 'AssemblyOrder')->exists())->toBeFalse();
});

test('a hand-picked lot that cannot cover its line is refused', function () {
    $small = ($this->lot)($this->fabric, 2, 10);
    ($this->lot)($this->fabric, 20, 10);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 5, 'stock_lot_id' => $small->id],
    ]])->assertCreated()->json();

    // The report names the short lot, not the item's total stock (which could cover it).
    ($this->complete)($order)
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonCount(1, 'shortfalls')
        ->assertJsonPath('shortfalls.0.stock_lot_id', $small->id)
        ->assertJsonPath('shortfalls.0.lot_number', $small->lot_number)
        ->assertJsonPath('shortfalls.0.required', 5)
        ->assertJsonPath('shortfalls.0.available', 2);

    expect((float) $small->fresh()->quantity)->toBe(2.0);
});

test('a lot of another unit or another item cannot be picked', function () {
    $foreign = ($this->lot)($this->fabric, 10, 10, $this->otherWarehouse);
    $frame = ($this->lot)($this->frame, 10, 10);

    ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1, 'stock_lot_id' => $foreign->id]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSEMBLY_LOT_REJECTED');

    ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1, 'stock_lot_id' => $frame->id]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSEMBLY_LOT_REJECTED');
});

test('lots of an open foam batch are never drawn', function () {
    $batch = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 701, 'bun_width_m' => 2.4, 'status' => 'graded']);
    ($this->lot)($this->fabric, 10, 0, null, ['production_batch_id' => $batch->id]);

    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]])->assertCreated()->json();

    ($this->complete)($order)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
});

test('only furniture finished goods can be assembled, and not from themselves', function () {
    ($this->create)(['finished_item_id' => $this->fabric->id, 'lines' => [['inventory_item_id' => $this->frame->id, 'quantity' => 1]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSEMBLY_NOT_FINISHED_GOOD');

    ($this->create)(['lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 1]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSEMBLY_COMPONENT_IS_PRODUCT');

    ($this->create)()->assertStatus(422)->assertJsonValidationErrors('lines');
});

test('cancelling a draft releases nothing because nothing was reserved', function () {
    $fabric = ($this->lot)($this->fabric, 10, 10);

    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 6, 'stock_lot_id' => $fabric->id]]])
        ->assertCreated()->json();

    expect((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and($fabric->fresh()->status)->toBe('available');

    $cancelled = ($this->as)($this->assembler)->postJson("/api/v1/assembly-orders/{$order['id']}/cancel")->assertOk()->json();

    expect($cancelled['status'])->toBe('cancelled')
        ->and((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and($fabric->fresh()->status)->toBe('available')
        ->and(InventoryMovement::withoutGlobalScopes()->where('reference_document_type', 'AssemblyOrder')->exists())->toBeFalse()
        ->and(JournalEntry::where('source_document_type', 'AssemblyOrder')->exists())->toBeFalse();

    ($this->complete)($order)->assertStatus(422)->assertJsonPath('code', 'ASSEMBLY_STATUS_INVALID');
});

test('a completed order cannot be completed or cancelled again', function () {
    ($this->lot)($this->fabric, 10, 10);

    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 2]]])->assertCreated()->json();
    ($this->complete)($order)->assertOk();

    ($this->complete)($order)->assertStatus(422)->assertJsonPath('code', 'ASSEMBLY_STATUS_INVALID');
    ($this->as)($this->assembler)->postJson("/api/v1/assembly-orders/{$order['id']}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSEMBLY_STATUS_INVALID');
});

test('opening needs create-furniture, completing edit-furniture, reading view-furniture', function () {
    ($this->lot)($this->fabric, 10, 10);
    $lines = ['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]];

    // The assembler has edit-furniture but not create-furniture.
    ($this->create)($lines, $this->assembler)->assertForbidden();
    ($this->create)($lines, $this->cashier)->assertForbidden();

    $order = ($this->create)($lines)->assertCreated()->json();

    ($this->as)($this->cashier)->getJson('/api/v1/assembly-orders')->assertForbidden();
    ($this->complete)($order, $this->cashier)->assertForbidden();

    ($this->as)($this->assembler)->getJson('/api/v1/assembly-orders')
        ->assertOk()
        ->assertJsonPath('data.0.id', $order['id']);
    ($this->as)($this->assembler)->getJson("/api/v1/assembly-orders/{$order['id']}")->assertOk();
});

test('orders are scoped to their unit', function () {
    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]])->assertCreated()->json();

    $otherManager = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $otherManager->id,
        'role_id' => Role::where('slug', 'furniture-manager')->value('id'),
        'operating_unit_id' => $this->otherUnit->id,
    ]);

    ($this->as)($otherManager, $this->otherUnit)->getJson("/api/v1/assembly-orders/{$order['id']}")->assertNotFound();
    ($this->as)($otherManager, $this->otherUnit)->getJson('/api/v1/assembly-orders')->assertOk()->assertJsonCount(0, 'data');
    ($this->as)($otherManager, $this->otherUnit)->postJson('/api/v1/assembly-orders', [
        'finished_item_id' => $this->sofa->id,
        'quantity' => 1,
        'output_warehouse_id' => $this->outputWarehouse->id,
        'lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('output_warehouse_id');
});

test('the finished lot sells through the POS at its assembled cost', function () {
    linkRevenueAccount($this->unit, '40204');
    ($this->lot)($this->fabric, 10, 10);
    ($this->lot)($this->frame, 4, 80);

    $order = ($this->create)(['quantity' => 2, 'lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6],
        ['inventory_item_id' => $this->frame->id, 'quantity' => 2],
    ]])->assertCreated()->json();
    $done = ($this->complete)($order)->assertOk()->json();

    $drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);
    $client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'organization', 'name' => 'Walk-in buyer'])->id,
        'operating_unit_id' => $this->unit->id, 'credit_limit' => 0, 'current_balance' => 0,
    ]);

    $sale = ($this->as)($this->cashier)->postJson('/api/v1/sales', [
        'client_id' => $client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $drawer->id,
        'lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 400]],
    ])->assertCreated()->json();

    $saleSum = fn (string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $sale['id']))
        ->where('account_id', Account::where('account_code', $code)->value('id'))
        ->sum($side);

    $finished = StockLot::withoutGlobalScopes()->findOrFail($done['finished_stock_lot_id']);

    expect((float) $finished->quantity)->toBe(1.0)
        ->and($saleSum('51', 'debit'))->toBe(110.0)
        ->and($saleSum('1134', 'credit'))->toBe(110.0)
        ->and($saleSum('40204', 'credit'))->toBe(400.0);
});

test('a sized bundle component draws only lots of its size', function () {
    $wrong = ($this->lot)($this->cushion, 10, 5, null, ['length_m' => 0.5, 'width_m' => 0.5, 'height_m' => 0.15]);
    $this->travel(1)->minutes();
    // Stocked in another orientation: still the same piece.
    $right = ($this->lot)($this->cushion, 10, 9, null, ['length_m' => 0.15, 'width_m' => 0.6, 'height_m' => 0.6]);

    $bundle = Bundle::create(['name' => 'Cushion set']);
    BundleItem::create([
        'bundle_id' => $bundle->id, 'inventory_item_id' => $this->cushion->id, 'suggested_quantity' => 2,
        'length_m' => 0.6, 'width_m' => 0.6, 'height_m' => 0.15, 'position' => 0,
    ]);

    $order = ($this->create)(['bundle_id' => $bundle->id])->assertCreated()->json();

    expect((float) $order['lines'][0]['length_m'])->toBe(0.6)
        ->and((float) $order['lines'][0]['height_m'])->toBe(0.15);

    $done = ($this->complete)($order)->assertOk()->json();

    expect((float) $wrong->fresh()->quantity)->toBe(10.0)
        ->and((float) $right->fresh()->quantity)->toBe(6.0)
        ->and(collect($done['lines'])->pluck('stock_lot_id')->all())->toBe([$right->id])
        ->and((float) $done['total_cost'])->toBe(36.0);

    // A hand-picked lot of another size is refused when the draft is opened.
    ($this->create)(['lines' => [[
        'inventory_item_id' => $this->cushion->id, 'quantity' => 1, 'stock_lot_id' => $wrong->id,
        'length_m' => 0.6, 'width_m' => 0.6, 'height_m' => 0.15,
    ]]])->assertStatus(422)->assertJsonPath('code', 'ASSEMBLY_LOT_SIZE_MISMATCH');

    // Not enough of the size: the shortfall names the size, though other sizes are in stock.
    $short = ($this->create)(['lines' => [[
        'inventory_item_id' => $this->cushion->id, 'quantity' => 7,
        'length_m' => 0.6, 'width_m' => 0.6, 'height_m' => 0.15,
    ]]])->assertCreated()->json();

    ($this->complete)($short)
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortfalls.0.inventory_item_id', $this->cushion->id)
        ->assertJsonPath('shortfalls.0.required', 7)
        ->assertJsonPath('shortfalls.0.available', 6);
});

test('a cost that does not divide evenly puts the remainder on one unit so the lots sum to the debit', function () {
    ($this->lot)($this->frame, 1, 100);

    $order = ($this->create)(['quantity' => 3, 'lines' => [['inventory_item_id' => $this->frame->id, 'quantity' => 1]]])
        ->assertCreated()->json();

    $done = ($this->complete)($order)->assertOk()->json();

    $lots = StockLot::withoutGlobalScopes()->where('inventory_item_id', $this->sofa->id)->orderBy('lot_number')->get();
    $outputs = InventoryMovement::withoutGlobalScopes()
        ->where('reference_id', $order['id'])
        ->where('movement_type', 'production_output')
        ->get();

    expect($lots)->toHaveCount(2)
        ->and($lots[0]->lot_number)->toBe('FG-'.$done['number'])
        ->and((float) $lots[0]->quantity)->toBe(2.0)
        ->and((float) $lots[0]->unit_cost)->toBe(33.3333)
        ->and($lots[1]->lot_number)->toBe('FG-'.$done['number'].'-2')
        ->and((float) $lots[1]->quantity)->toBe(1.0)
        ->and((float) $lots[1]->unit_cost)->toBe(33.3334)
        ->and(round($lots->sum(fn ($lot) => (float) $lot->quantity * (float) $lot->unit_cost), 4))->toBe(100.0)
        ->and($done['finished_stock_lot_id'])->toBe($lots[0]->id)
        ->and($done['finished_stock_lots'])->toHaveCount(2)
        ->and($outputs)->toHaveCount(2)
        ->and(round($outputs->sum(fn ($m) => (float) $m->quantity_delta), 4))->toBe(3.0)
        ->and(($this->sum)($order['id'], '1134', 'debit'))->toBe(100.0)
        ->and(($this->sum)($order['id'], '111', 'credit'))->toBe(100.0);
});

test('a large build of a small cost never stocks a lot below zero and the lots sum to the debit', function (float $quantity, float $cost) {
    ($this->lot)($this->frame, 1, $cost);

    $order = ($this->create)(['quantity' => $quantity, 'lines' => [['inventory_item_id' => $this->frame->id, 'quantity' => 1]]])
        ->assertCreated()->json();
    ($this->complete)($order)->assertOk();

    $lots = StockLot::withoutGlobalScopes()->where('inventory_item_id', $this->sofa->id)->get();

    expect($lots->every(fn ($lot) => (float) $lot->unit_cost >= 0))->toBeTrue()
        ->and(round($lots->sum(fn ($lot) => (float) $lot->quantity), 4))->toBe($quantity)
        ->and(round($lots->sum(fn ($lot) => (float) $lot->quantity * (float) $lot->unit_cost), 8))->toBe($cost)
        ->and(($this->sum)($order['id'], '1134', 'debit'))->toBe($cost);
})->with([
    'rounding up would overshoot' => [600.0, 10.05],
    'a fractional quantity' => [1.5, 1.0],
    'a fractional quantity of an odd remainder' => [2.0001, 100.0],
]);

test('a hand-entered size needs all three dimensions', function () {
    ($this->create)(['lines' => [['inventory_item_id' => $this->cushion->id, 'quantity' => 1, 'length_m' => 0.6]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.width_m', 'lines.0.height_m']);

    ($this->create)(['lines' => [[
        'inventory_item_id' => $this->cushion->id, 'quantity' => 1, 'length_m' => 0.6, 'width_m' => 0.6, 'height_m' => 0.15,
    ]]])->assertCreated();
});

test('a build from zero-cost lots posts no entry and stocks the finished lot at zero', function () {
    ($this->lot)($this->fabric, 5, 0);

    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 2]]])->assertCreated()->json();
    $done = ($this->complete)($order)->assertOk()->json();

    $finished = StockLot::withoutGlobalScopes()->findOrFail($done['finished_stock_lot_id']);

    expect($done['status'])->toBe('completed')
        ->and($done['journal_entry_id'])->toBeNull()
        ->and((float) $done['total_cost'])->toBe(0.0)
        ->and((float) $finished->quantity)->toBe(2.0)
        ->and((float) $finished->unit_cost)->toBe(0.0)
        ->and(JournalEntry::where('source_document_type', 'AssemblyOrder')->exists())->toBeFalse();
});

test('a stale record_version on complete or cancel is a conflict and leaves the draft', function () {
    $fabric = ($this->lot)($this->fabric, 10, 10);

    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 2]]])->assertCreated()->json();
    $stale = $order['record_version'] + 5;

    ($this->as)($this->assembler)->postJson("/api/v1/assembly-orders/{$order['id']}/complete", ['record_version' => $stale])
        ->assertStatus(409)
        ->assertJsonPath('code', 'OPTIMISTIC_LOCK_CONFLICT');
    ($this->as)($this->assembler)->postJson("/api/v1/assembly-orders/{$order['id']}/cancel", ['record_version' => $stale])
        ->assertStatus(409)
        ->assertJsonPath('code', 'OPTIMISTIC_LOCK_CONFLICT');

    expect(AssemblyOrder::findOrFail($order['id'])->status->value)->toBe('draft')
        ->and((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and(InventoryMovement::withoutGlobalScopes()->where('reference_document_type', 'AssemblyOrder')->exists())->toBeFalse();

    ($this->as)($this->assembler)->postJson("/api/v1/assembly-orders/{$order['id']}/complete", ['record_version' => $order['record_version']])
        ->assertOk();
});

test('cancelling needs edit-furniture', function () {
    $order = ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]])->assertCreated()->json();

    ($this->as)($this->cashier)->postJson("/api/v1/assembly-orders/{$order['id']}/cancel")->assertForbidden();

    expect(AssemblyOrder::findOrFail($order['id'])->status->value)->toBe('draft');
});

test('a hand-picked line and an open line share one lot exactly', function () {
    $lot = ($this->lot)($this->fabric, 10, 10);

    $order = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6, 'stock_lot_id' => $lot->id],
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 4],
    ]])->assertCreated()->json();

    $done = ($this->complete)($order)->assertOk()->json();

    expect((float) $lot->fresh()->quantity)->toBe(0.0)
        ->and($lot->fresh()->status)->toBe('consumed')
        ->and(collect($done['lines'])->pluck('stock_lot_id')->unique()->all())->toBe([$lot->id])
        ->and((float) $done['total_cost'])->toBe(100.0);

    $other = ($this->lot)($this->fabric, 10, 10);
    $over = ($this->create)(['lines' => [
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 6, 'stock_lot_id' => $other->id],
        ['inventory_item_id' => $this->fabric->id, 'quantity' => 5],
    ]])->assertCreated()->json();

    ($this->complete)($over)
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortfalls.0.inventory_item_id', $this->fabric->id)
        ->assertJsonPath('shortfalls.0.stock_lot_id', null)
        ->assertJsonPath('shortfalls.0.required', 5)
        ->assertJsonPath('shortfalls.0.available', 4);

    expect((float) $other->fresh()->quantity)->toBe(10.0);
});

test('a lot that cannot be drawn cannot be picked', function () {
    $reserved = ($this->lot)($this->fabric, 10, 10, null, ['status' => 'reserved']);
    $empty = ($this->lot)($this->fabric, 0, 10, null, ['status' => 'consumed']);
    $batch = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 702, 'bun_width_m' => 2.4, 'status' => 'graded']);
    $open = ($this->lot)($this->fabric, 10, 0, null, ['production_batch_id' => $batch->id]);

    foreach ([$reserved, $empty, $open] as $lot) {
        ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1, 'stock_lot_id' => $lot->id]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSEMBLY_LOT_REJECTED');
    }
});

test('completion refuses a product that was retyped or deleted since the draft', function () {
    ($this->lot)($this->fabric, 10, 10);
    $lines = ['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]];

    $retyped = ($this->create)($lines)->assertCreated()->json();
    $this->sofa->update(['item_type' => 'raw_material']);

    ($this->complete)($retyped)->assertStatus(422)->assertJsonPath('code', 'ASSEMBLY_NOT_FINISHED_GOOD');

    $this->sofa->update(['item_type' => 'furniture_finished_good']);
    $deleted = ($this->create)($lines)->assertCreated()->json();
    $this->sofa->delete();

    ($this->complete)($deleted)->assertStatus(422)->assertJsonPath('code', 'ASSEMBLY_ITEM_DELETED');

    expect(StockLot::withoutGlobalScopes()->where('inventory_item_id', $this->sofa->id)->exists())->toBeFalse()
        ->and(JournalEntry::where('source_document_type', 'AssemblyOrder')->exists())->toBeFalse();
});

test('an item named on an assembly order cannot be deleted', function () {
    $owner = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $owner->id, 'role_id' => Role::firstOrCreate(['slug' => 'owner'], ['name' => 'owner'])->id, 'operating_unit_id' => null]);

    ($this->create)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 1]]])->assertCreated();

    ($this->as)($owner)->deleteJson("/api/v1/inventory-items/{$this->sofa->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ITEM_IN_USE')
        ->assertJsonPath('referenced_by', 'assembly_orders');
    ($this->as)($owner)->deleteJson("/api/v1/inventory-items/{$this->fabric->id}")
        ->assertStatus(422)
        ->assertJsonPath('referenced_by', 'assembly_order_lines');
});
