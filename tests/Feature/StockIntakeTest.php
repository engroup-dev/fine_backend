<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->fabric = InventoryItem::create([
        'name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter',
    ]);

    // Link the unit's Purchases override so intake can post. Without it, the
    // new INVENTORY_ACCOUNT_NOT_LINKED guard hard-fails every intake.
    OperatingUnitAccount::create([
        'operating_unit_id' => $this->unit->id,
        'event_type' => InventoryEventType::Purchases->value,
        'account_id' => Account::where('account_code', '111')->sole()->id,
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->intake = fn (array $overrides = []) => ($this->api)()->postJson('/api/v1/stock-lots/intake', array_merge([
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'FAB-'.fake()->unique()->numberBetween(1000, 9999),
        'quantity' => 100,
        'unit_cost' => 8.5,
        'source' => 'purchase_credit',
    ], $overrides));
});

test('intake creates the lot, the movement and the journal in one stroke', function () {
    $response = ($this->intake)()->assertStatus(201);

    $lot = StockLot::find($response->json('id'));
    expect((float) $lot->quantity)->toBe(100.0)
        ->and($lot->status)->toBe('available');

    // INV-06: the appearance is on the movement ledger.
    $movement = InventoryMovement::where('stock_lot_id', $lot->id)->sole();
    expect($movement->movement_type)->toBe('intake')
        ->and((float) $movement->quantity_delta)->toBe(100.0)
        ->and($movement->reason)->toBe('purchase_credit')
        ->and($movement->to_warehouse_id)->toBe($this->warehouse->id);

    // ACC-02: 850 of fabric on credit → DR 1110 / CR 2100.
    $entry = JournalEntry::where('source_document_type', 'StockLot')
        ->where('source_document_id', $lot->id)->sole();
    $lines = $entry->lines()->with('account')->get();

    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '111')->debit)->toBe(850.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '21')->credit)->toBe(850.0);
});

test('an opening balance lands against retained earnings and cash purchases against cash', function () {
    $opening = ($this->intake)(['source' => 'opening_balance'])->assertStatus(201);
    ($this->intake)(['source' => 'purchase_cash', 'quantity' => 10, 'unit_cost' => 2])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cash_account_id');
    $cash = ($this->intake)(['source' => 'purchase_cash', 'quantity' => 10, 'unit_cost' => 2, 'cash_account_id' => linkedTreasury($this->unit)->id])->assertStatus(201);

    $openingEntry = JournalEntry::where('source_document_id', $opening->json('id'))->sole();
    $cashEntry = JournalEntry::where('source_document_id', $cash->json('id'))->sole();

    expect($openingEntry->lines()->with('account')->get()
        ->firstWhere(fn ($l) => (float) $l->credit > 0)->account->account_code)->toBe('31')
        ->and($cashEntry->lines()->with('account')->get()
            ->firstWhere(fn ($l) => (float) $l->credit > 0)->account->account_code)->toBe('121101');

    $tb = ($this->api)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();
    expect($tb['balanced'])->toBeTrue();
});

test('an import receipt materialises lots without double-posting the ledger', function () {
    $supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Chem Co', 'default_currency' => 'USD',
    ]);
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id, 'supplier_id' => $supplier->id,
        'currency' => 'USD', 'negotiated_price' => 100, 'quantity' => 50, 'status' => 'received',
    ]);

    $response = ($this->intake)([
        'source' => 'import_receipt',
        'purchase_order_id' => $order->id,
    ])->assertStatus(201);

    // The movement carries the trail back to the order…
    $movement = InventoryMovement::where('stock_lot_id', $response->json('id'))->sole();
    expect($movement->reference_document_type)->toBe('PurchaseOrder')
        ->and($movement->reference_id)->toBe($order->id);

    // …but no journal: PurchaseOrder.Complete posts the landed value.
    expect(JournalEntry::where('source_document_type', 'StockLot')->count())->toBe(0);
});

test('an import receipt without its order is refused, as is an unknown source', function () {
    ($this->intake)(['source' => 'import_receipt'])->assertStatus(422);
    ($this->intake)(['source' => 'found_it_somewhere'])->assertStatus(422);
});

test('finished-good item types land on their own inventory accounts', function () {
    $block = InventoryItem::create([
        'name' => 'Foam Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    // The per-event override guard requires an explicit Purchases link on
    // the unit; re-route this unit to the foam-block account 1131 so the
    // intake lands on the right inventory sub-account.
    OperatingUnitAccount::where('operating_unit_id', $this->unit->id)
        ->where('event_type', InventoryEventType::Purchases->value)
        ->update([
            'account_id' => Account::where('account_code', '1131')->sole()->id,
        ]);

    // Serialized items keep their rules on this path too: two blocks in one
    // lot is refused (SERIALIZED_QUANTITY_INVALID)…
    ($this->intake)([
        'inventory_item_id' => $block->id,
        'source' => 'opening_balance',
        'quantity' => 2,
        'unit_cost' => 300,
    ])->assertStatus(422)->assertJsonPath('code', 'SERIALIZED_QUANTITY_INVALID');

    // …while a single block lands on 1131 Finished Goods — Foam Blocks.
    $response = ($this->intake)([
        'inventory_item_id' => $block->id,
        'source' => 'opening_balance',
        'quantity' => 1,
        'unit_cost' => 600,
    ])->assertStatus(201);

    $entry = JournalEntry::where('source_document_id', $response->json('id'))->sole();
    $debit = $entry->lines()->with('account')->get()->firstWhere(fn ($l) => (float) $l->debit > 0);

    expect($debit->account->account_code)->toBe('1131')
        ->and((float) $debit->debit)->toBe(600.0);
});

test('stock intake auto-generates unique lot number when omitted', function () {
    $res1 = ($this->intake)(['lot_number' => null])->assertStatus(201);
    $res2 = ($this->intake)(['lot_number' => ''])->assertStatus(201);

    $lot1 = StockLot::find($res1->json('id'));
    $lot2 = StockLot::find($res2->json('id'));

    expect($lot1->lot_number)->toContain('LOT-FAB-')
        ->and($lot2->lot_number)->toContain('LOT-FAB-')
        ->and($lot1->lot_number)->not->toBe($lot2->lot_number);
});

test('stock intake gracefully resolves duplicate vendor lot numbers with suffixes', function () {
    $res1 = ($this->intake)(['lot_number' => 'VENDOR-BATCH-001'])->assertStatus(201);
    $res2 = ($this->intake)(['lot_number' => 'VENDOR-BATCH-001'])->assertStatus(201);
    $res3 = ($this->intake)(['lot_number' => 'VENDOR-BATCH-001'])->assertStatus(201);

    $lot1 = StockLot::find($res1->json('id'));
    $lot2 = StockLot::find($res2->json('id'));
    $lot3 = StockLot::find($res3->json('id'));

    expect($lot1->lot_number)->toBe('VENDOR-BATCH-001')
        ->and($lot2->lot_number)->toBe('VENDOR-BATCH-001-01')
        ->and($lot2->attribute_values['vendor_lot_number'])->toBe('VENDOR-BATCH-001')
        ->and($lot3->lot_number)->toBe('VENDOR-BATCH-001-02')
        ->and($lot3->attribute_values['vendor_lot_number'])->toBe('VENDOR-BATCH-001');
});

test('stock intake records container_quantity and container_capacity', function () {
    $res = ($this->intake)([
        'quantity' => 2000.0,
        'container_quantity' => 10.0,
        'container_capacity' => 200.0,
        'primary_uom' => 'barrel',
        'secondary_uom' => 'liter',
        'save_as_item_default' => true,
    ])->assertStatus(201);

    $lot = StockLot::find($res->json('id'));

    expect((float) $lot->quantity)->toBe(2000.0)
        ->and((float) $lot->container_quantity)->toBe(10.0)
        ->and((float) $lot->attribute_values['container_capacity'])->toBe(200.0);

    $this->fabric->refresh();
    expect((float) $this->fabric->container_capacity)->toBe(200.0)
        ->and($this->fabric->primary_uom)->toBe('barrel')
        ->and($this->fabric->secondary_uom)->toBe('liter');
});

test('stock intake auto-derives container_quantity when container_quantity is omitted but capacity is given', function () {
    $res = ($this->intake)([
        'quantity' => 1500.0,
        'container_capacity' => 250.0,
    ])->assertStatus(201);

    $lot = StockLot::find($res->json('id'));

    expect((float) $lot->quantity)->toBe(1500.0)
        ->and((float) $lot->container_quantity)->toBe(6.0); // 1500 / 250 = 6
});

test('a typed lot number that reads as an item code or barcode is refused', function () {
    $this->fabric->forceFill(['barcode' => '6281234567890'])->save();

    ($this->intake)(['lot_number' => 'FAB-1'])->assertStatus(422)->assertJsonValidationErrors('lot_number');
    ($this->intake)(['lot_number' => '6281234567890'])->assertStatus(422)->assertJsonValidationErrors('lot_number');
    ($this->intake)(['lot_number' => 'FAB-LOT-77'])->assertStatus(201);
});
