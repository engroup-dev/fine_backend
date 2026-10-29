<?php

declare(strict_types=1);

use App\Console\Commands\ReconcileInventoryAccounts;
use App\Enums\CutterWorkOrderStatus;
use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryType;
use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\Entity;
use App\Models\FiscalPeriod;
use App\Models\InventoryItem;
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
use App\Services\AccountingService;
use App\Services\CutterWorkOrderService;
use App\Services\StockLotService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * INV-11: a lot's value enters and leaves through its item type's account,
 * whatever the unit's `purchases` mapping says (111 here for both units).
 */
beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => ['sales_order' => [], 'pos_sale' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->unitA = $makeUnit('Showroom A');
    $this->unitB = $makeUnit('Showroom B');
    linkRevenueAccount($this->unitA, '40204');
    linkRevenueAccount($this->unitB, '40203');
    seedUnitAccounts([$this->unitA, $this->unitB]);

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'WH A', 'code' => 'WH-A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'WH B', 'code' => 'WH-B']);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);
    $this->client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Fatima'])->id,
        'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 1000,
    ]);

    $this->raw = InventoryItem::create(['name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter']);
    $this->sofa = InventoryItem::create(['name' => 'Sofa', 'code' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece']);
    $this->foam = InventoryItem::create(['name' => 'Block D25', 'code' => 'BLK-D25', 'item_type' => 'foam_block', 'unit_of_measure' => 'each']);

    $this->api = fn (?OperatingUnit $unit = null) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unitA)->id]);

    $this->lot = fn (InventoryItem $item, float $quantity, float $unitCost, ?Warehouse $warehouse = null, string $status = 'available') => StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->warehouseA)->id,
        'lot_number' => 'LOT-'.$item->code.'-'.uniqid(), 'quantity' => $quantity, 'unit_cost' => $unitCost, 'status' => $status,
    ]);

    $this->intake = fn (InventoryItem $item, float $quantity, float $unitCost) => app(StockLotService::class)->intake([
        'inventory_item_id' => $item->id, 'warehouse_id' => $this->warehouseA->id,
        'quantity' => $quantity, 'unit_cost' => $unitCost, 'source' => 'purchase_credit',
    ]);

    $this->sum = fn (string $sourceId, string $code, string $side, ?string $unitId = null) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $sourceId))
        ->where('account_id', Account::where('account_code', $code)->value('id'))
        ->when($unitId !== null, fn ($q) => $q->where('operating_unit_id', $unitId))
        ->sum($side);

    $this->sell = fn (array $lines) => ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => $lines,
    ])->assertCreated()->json();
});

test('intake debits the item type account, not the unit purchases mapping', function () {
    $rawLot = ($this->intake)($this->raw, 10, 5);
    $sofaLot = ($this->intake)($this->sofa, 2, 150);
    $blockLot = ($this->intake)($this->foam, 1, 300);

    expect(($this->sum)($rawLot->id, '111', 'debit'))->toBe(50.0)
        ->and(($this->sum)($sofaLot->id, '1134', 'debit'))->toBe(300.0)
        ->and(($this->sum)($sofaLot->id, '111', 'debit'))->toBe(0.0)
        ->and(($this->sum)($blockLot->id, '1131', 'debit'))->toBe(300.0)
        ->and(($this->sum)($blockLot->id, '111', 'debit'))->toBe(0.0);
});

test('selling a foam block credits 1131 against COGS', function () {
    ($this->lot)($this->foam, 1, 300);

    $sale = ($this->sell)([['inventory_item_id' => $this->foam->id, 'quantity' => 1, 'unit_price' => 500]]);

    expect(($this->sum)($sale['id'], '51', 'debit'))->toBe(300.0)
        ->and(($this->sum)($sale['id'], '1131', 'credit'))->toBe(300.0)
        ->and(($this->sum)($sale['id'], '111', 'credit'))->toBe(0.0);
});

test('selling a finished good credits 1134', function () {
    ($this->lot)($this->sofa, 5, 120);

    $sale = ($this->sell)([['inventory_item_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => 400]]);

    expect(($this->sum)($sale['id'], '51', 'debit'))->toBe(240.0)
        ->and(($this->sum)($sale['id'], '1134', 'credit'))->toBe(240.0)
        ->and(($this->sum)($sale['id'], '111', 'credit'))->toBe(0.0);
});

test('bundle delivery credits each lot its own account', function () {
    $sofaLot = ($this->lot)($this->sofa, 1, 200);
    $roll = ($this->lot)($this->raw, 50, 10);
    $bundle = Bundle::create(['name' => 'Living room']);

    $sale = ($this->sell)([['line_type' => 'bundle', 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 900]]);
    $components = ($this->api)()->putJson("/api/v1/sales/{$sale['id']}/lines/{$sale['lines'][0]['id']}/components", [
        'components' => [
            ['inventory_item_id' => $this->sofa->id, 'quantity' => 1],
            ['inventory_item_id' => $this->raw->id, 'quantity' => 6],
        ],
    ])->assertSuccessful()->json('components');

    ($this->api)()->postJson("/api/v1/sale-components/{$components[0]['id']}/reserve", ['allocations' => [['stock_lot_id' => $sofaLot->id, 'quantity' => 1]]])->assertSuccessful();
    ($this->api)()->postJson("/api/v1/sale-components/{$components[1]['id']}/reserve", ['allocations' => [['stock_lot_id' => $roll->id, 'quantity' => 6]]])->assertSuccessful();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/deliver")->assertSuccessful();

    expect(($this->sum)($sale['id'], '51', 'debit'))->toBe(260.0)
        ->and(($this->sum)($sale['id'], '1134', 'credit'))->toBe(200.0)
        ->and(($this->sum)($sale['id'], '111', 'credit'))->toBe(60.0);
});

test('an internal transfer moves the value on the same account between the two units', function () {
    ($this->lot)($this->sofa, 5, 120);

    $order = ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->unitB->id,
        'lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => 0]],
    ])->assertCreated()->json();

    expect(($this->sum)($order['id'], '1134', 'debit', $this->unitB->id))->toBe(240.0)
        ->and(($this->sum)($order['id'], '1134', 'credit', $this->unitA->id))->toBe(240.0)
        ->and(($this->sum)($order['id'], '111', 'debit'))->toBe(0.0)
        ->and(($this->sum)($order['id'], '111', 'credit'))->toBe(0.0);
});

test('a restock return of an item archived since the sale goes back into its own account', function () {
    ($this->lot)($this->sofa, 5, 120);
    $sale = ($this->sell)([['inventory_item_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => 400]]);

    $this->sofa->delete();

    $returnId = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $sale['lines'][0]['id'], 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'reason' => 'Changed mind',
    ])->assertCreated()->json('id');

    expect(($this->sum)($returnId, '1134', 'debit'))->toBe(120.0)
        ->and(($this->sum)($returnId, '111', 'debit'))->toBe(0.0)
        ->and(($this->sum)($returnId, '51', 'credit'))->toBe(120.0);
});

/**
 * Drift as the pre-INV-11 flows left it: a finished good booked into 111,
 * plus a raw lot that never reached the ledger. A foam block reserved by a
 * cutter order sits in 1122 and must not count as drift.
 */
function seedInventoryDrift(object $test): void
{
    ($test->lot)($test->sofa, 10, 50);
    app(AccountingService::class)->postJournal('Legacy intake', [
        ['account_code' => '111', 'debit' => 500, 'operating_unit_id' => $test->unitA->id],
        ['account_code' => '31', 'credit' => 500, 'operating_unit_id' => $test->unitA->id],
    ], entryDate: '2026-09-15');

    ($test->lot)($test->raw, 5, 20);

    $block = ($test->intake)($test->foam, 1, 300);
    $order = CutterWorkOrder::create(['operating_unit_id' => $test->unitA->id, 'order_number' => 'CW-REC-1', 'status' => 'confirmed']);
    app(CutterWorkOrderService::class)->attachBlock($order, $block);
}

test('reconcile without --post reports the drift and writes nothing', function () {
    seedInventoryDrift($this);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts')
        ->expectsTable(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], [
            ['Showroom A', '111', '100.00', '500.00', '-400.00'],
            ['Showroom A', '1134', '500.00', '0.00', '500.00'],
        ])
        ->assertSuccessful();

    expect(JournalEntry::count())->toBe($entries);
});

test('reconcile --post moves each account to the lots, nets to 52, and a second run posts nothing', function () {
    seedInventoryDrift($this);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true, '--date' => '2026-10-20'])->assertSuccessful();

    $entry = JournalEntry::where('entry_type', JournalEntryType::Adjustment->value)->with('lines.account')->sole();
    $line = fn (string $code, string $side) => (float) $entry->lines->filter(fn ($l) => $l->account->account_code === $code)->sum($side);

    expect(JournalEntry::count())->toBe($entries + 1)
        ->and($entry->description)->toBe(ReconcileInventoryAccounts::DESCRIPTION)
        ->and($entry->entry_date->toDateString())->toBe('2026-10-20')
        ->and($line('1134', 'debit'))->toBe(500.0)
        ->and($line('111', 'credit'))->toBe(400.0)
        ->and($line('52', 'credit'))->toBe(100.0)
        ->and((float) $entry->lines->sum('debit'))->toBe((float) $entry->lines->sum('credit'))
        ->and($entry->lines->pluck('operating_unit_id')->unique()->all())->toBe([$this->unitA->id]);

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();

    expect(JournalEntry::count())->toBe($entries + 1);
});

test('reconcile --post into a locked month is refused', function () {
    seedInventoryDrift($this);
    FiscalPeriod::query()->where('calendar_year', 2026)->where('calendar_month', 9)
        ->update(['status' => FiscalPeriodStatus::Locked->value]);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true, '--date' => '2026-09-30'])
        ->expectsOutputToContain('locked')
        ->assertFailed();

    expect(JournalEntry::count())->toBe($entries)
        ->and(JournalEntry::where('entry_type', JournalEntryType::Adjustment->value)->count())->toBe(0);
});

test('reconcile leaves out blocks of a foam batch that is not closed yet', function () {
    $batch = fn (int $number, string $status) => ProductionBatch::create([
        'operating_unit_id' => $this->unitA->id, 'operation_number' => $number, 'bun_width_m' => 2.4, 'status' => $status,
    ]);
    $block = fn (ProductionBatch $batch, float $unitCost) => StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $this->warehouseA->id, 'production_batch_id' => $batch->id,
        'lot_number' => "{$batch->operation_number}-01", 'quantity' => 1, 'unit_cost' => $unitCost, 'status' => 'available',
    ]);

    // Hand-entered group cost on an open batch: nothing has reached 1131 yet.
    $block($batch(501, 'ready_for_grading'), 400);
    $block($batch(502, 'closed'), 250);

    $this->artisan('inventory:reconcile-accounts')
        ->expectsTable(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], [
            ['Showroom A', '1131', '250.00', '0.00', '250.00'],
        ])
        ->assertSuccessful();
});

/**
 * A foam close as ProductionBatchService posts it: DR 1131 / CR 1121, with no
 * consumption posted out of 111 (production never draws raw lots down).
 */
function postFoamClose(object $test, float $value): void
{
    $batch = ProductionBatch::create([
        'operating_unit_id' => $test->unitA->id, 'operation_number' => random_int(600, 999), 'bun_width_m' => 2.4, 'status' => 'closed',
    ]);
    app(AccountingService::class)->postJournal('Foam operation closed', [
        ['account_code' => '1131', 'debit' => $value, 'operating_unit_id' => $test->unitA->id],
        ['account_code' => '1121', 'credit' => $value, 'operating_unit_id' => $test->unitA->id],
    ], 'ProductionBatch', $batch->id);
}

test('reconcile --post refuses a unit whose 111 surplus may be unposted foam consumption, whatever the 1121 balance', function () {
    // Consumed raw material written down by hand (500 → 200 on the lots, no
    // entry), the batch closed DR 1131 / CR 1121 300, and 500 of overhead
    // absorbed into 1121 — so 1121 carries a DEBIT balance of 200.
    $roll = ($this->intake)($this->raw, 10, 50);
    $roll->update(['quantity' => 4]);
    ($this->lot)($this->foam, 1, 300);
    postFoamClose($this, 300);
    app(AccountingService::class)->postJournal('Overhead absorbed', [
        ['account_code' => '1121', 'debit' => 500, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '31', 'credit' => 500, 'operating_unit_id' => $this->unitA->id],
    ]);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts')
        ->expectsTable(['Unit', 'Account', 'Balance'], [['Showroom A', '1121', '200.00']])
        ->expectsOutputToContain('never posted out of 111')
        ->assertSuccessful();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsOutputToContain('refused')
        ->assertFailed();

    expect(JournalEntry::count())->toBe($entries);

    // The remedy the message names: the lots are already down, so an entry alone.
    app(AccountingService::class)->postJournal('Foam consumption', [
        ['account_code' => '1121', 'debit' => 300, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '111', 'credit' => 300, 'operating_unit_id' => $this->unitA->id],
    ]);

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->doesntExpectOutputToContain('never posted out of 111')
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();
});

test('reconcile --post corrects a foam unit with unposted consumption when 111 is not above its lots', function () {
    // The case this release exists for: a foam block sold before INV-11
    // credited 111 instead of 1131. The batch's consumption is unposted too,
    // but 111 runs below its lots, so nothing of it can be WIP.
    ($this->intake)($this->raw, 10, 50);
    ($this->lot)($this->foam, 1, 300)->update(['quantity' => 0, 'status' => 'consumed']);
    postFoamClose($this, 300);
    app(AccountingService::class)->postJournal('Legacy foam sale cost', [
        ['account_code' => '51', 'debit' => 300, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '111', 'credit' => 300, 'operating_unit_id' => $this->unitA->id],
    ]);

    $this->artisan('inventory:reconcile-accounts', ['--post' => true, '--date' => '2026-10-20'])
        ->expectsOutputToContain('never posted out of 111')
        ->assertSuccessful();

    $entry = JournalEntry::where('entry_type', JournalEntryType::Adjustment->value)->with('lines.account')->sole();
    $line = fn (string $code, string $side) => (float) $entry->lines->filter(fn ($l) => $l->account->account_code === $code)->sum($side);

    expect($line('111', 'debit'))->toBe(300.0)
        ->and($line('1131', 'credit'))->toBe(300.0)
        ->and($entry->lines->filter(fn ($l) => $l->account->account_code === '52')->count())->toBe(0);
});

test('a block attached to a cutter order stays out of the lots even when its status is flipped back to available', function () {
    $block = ($this->intake)($this->foam, 1, 300);
    $order = CutterWorkOrder::create(['operating_unit_id' => $this->unitA->id, 'order_number' => 'CW-FLIP', 'status' => 'confirmed']);
    app(CutterWorkOrderService::class)->attachBlock($order, $block);
    ($this->api)()->patchJson("/api/v1/stock-lots/{$block->id}", ['status' => 'available', 'record_version' => $block->fresh()->record_version])->assertOk();
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();

    expect(JournalEntry::count())->toBe($entries);
});

test('a fill lot weighed in on an unfinished cutter order counts only once the order completes', function () {
    $piece = InventoryItem::create(['name' => 'Cushion', 'code' => 'CUSH-1', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'each']);
    $fill = InventoryItem::create(['name' => 'Fill', 'code' => 'FILL-1', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg']);
    $block = StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $this->warehouseA->id, 'lot_number' => 'BLK-CUT',
        'quantity' => 1, 'length_m' => 2, 'width_m' => 1, 'height_m' => 1, 'unit_cost' => 600, 'status' => 'available',
    ]);
    app(AccountingService::class)->postJournal('Block in stock', [
        ['account_code' => '1131', 'debit' => 600, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '31', 'credit' => 600, 'operating_unit_id' => $this->unitA->id],
    ]);

    $service = app(CutterWorkOrderService::class);
    $order = CutterWorkOrder::create(['operating_unit_id' => $this->unitA->id, 'order_number' => 'CW-FILL', 'status' => 'confirmed']);
    $line = $order->lines()->create([
        'requested_spec' => 'Cushion', 'quantity' => 1, 'output_inventory_item_id' => $piece->id,
        'template_length_m' => 1, 'template_width_m' => 1, 'template_height_m' => 1,
    ]);
    $service->selectBlock($line, $block);
    $service->transition($order, CutterWorkOrderStatus::InProduction);
    $service->transition($order->fresh(), CutterWorkOrderStatus::AwaitingByproductWeighIn);
    $service->recordWeighIn($order->fresh(), 30, $fill->id, $this->warehouseA->id);
    $service->transition($order->fresh(), CutterWorkOrderStatus::QualityCheck);
    $entries = JournalEntry::count();

    // The 300 fill lot exists, but its value is still in 1122.
    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsTable(['Unit', 'Account', 'Balance'], [['Showroom A', '1122', '600.00']])
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();
    expect(JournalEntry::count())->toBe($entries);

    $service->transition($order->fresh(), CutterWorkOrderStatus::Completed);

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsTable(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], [
            ['Showroom A', '1132', '300.00', '300.00', '0.00'],
            ['Showroom A', '1133', '300.00', '300.00', '0.00'],
        ])
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();
    expect(JournalEntry::count())->toBe($entries + 1);
});

test('reconcile warns before expensing a block consumed by a cut-remnant decision with no remnant restocked', function () {
    $block = ($this->intake)($this->foam, 1, 300);
    app(StockLotService::class)->processCutRemnant($block, 'convert_to_byproduct', null, 5.0);

    $this->artisan('inventory:reconcile-accounts')
        ->expectsTable(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], [
            ['Showroom A', '1131', '0.00', '300.00', '-300.00'],
        ])
        ->expectsOutputToContain('cut-remnant decision with no remnant restocked')
        ->assertSuccessful();
});

test('reconcile shows lot-account lines with no unit on their own row and never posts them', function () {
    app(AccountingService::class)->postJournal('Unit-less legacy line', [
        ['account_code' => '111', 'debit' => 70],
        ['account_code' => '31', 'credit' => 70],
    ]);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsTable(['Unit', 'Account', 'Expected', 'Actual', 'Difference'], [
            ['(no unit / deleted unit)', '111', '0.00', '70.00', '-70.00'],
        ])
        ->expectsOutputToContain('belong to no unit')
        ->expectsOutputToContain('nothing to post')
        ->assertSuccessful();

    expect(JournalEntry::count())->toBe($entries);
});

test('reconcile --post writes nothing when 52 has become a header account', function () {
    seedInventoryDrift($this);
    $variance = Account::where('account_code', '52')->firstOrFail();
    Account::create([
        'chart_of_accounts_id' => $variance->chart_of_accounts_id, 'parent_account_id' => $variance->id,
        'account_code' => '5201', 'name' => 'فرعي', 'type' => $variance->type, 'section' => $variance->section, 'currency' => 'LYD',
    ]);
    $entries = JournalEntry::count();

    $this->artisan('inventory:reconcile-accounts', ['--post' => true])
        ->expectsOutputToContain('Account 52 has sub-accounts')
        ->assertFailed();

    expect(JournalEntry::count())->toBe($entries);
});
