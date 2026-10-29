<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SaleBundleComponent;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $storeBlueprint = UnitBlueprint::create([
        'name' => 'Store Blueprint', 'workflow_set' => ['sales_order' => [], 'pos_sale' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $cutterBlueprint = UnitBlueprint::create([
        'name' => 'Cutter Blueprint', 'workflow_set' => ['cutter_work_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $storeBlueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);
    $this->cutter = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $cutterBlueprint->id, 'name' => 'Cutter', 'unit_type' => 'manufactory',
    ]);
    linkRevenueAccount($this->store, '40204');
    linkRevenueAccount($this->cutter, '40202');

    $this->storeWarehouse = Warehouse::create(['operating_unit_id' => $this->store->id, 'name' => 'Store WH', 'code' => 'WH-S']);
    $this->cutterWarehouse = Warehouse::create(['operating_unit_id' => $this->cutter->id, 'name' => 'Cutter WH', 'code' => 'WH-C']);

    seedUnitAccounts($this->store);
    seedUnitAccounts($this->cutter, ['purchases' => '1132']);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $this->client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Fatima'])->id,
        'operating_unit_id' => $this->store->id,
        'credit_limit' => 100,
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->store->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    // Foam pieces priced per m³; fabric per metre.
    $this->foam = InventoryItem::create([
        'name' => 'إسفنج D25', 'code' => 'CUT-D25', 'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each', 'selling_price' => 1000, 'price_basis' => 'm3',
    ]);
    $this->fabric = InventoryItem::create([
        'name' => 'قماش', 'code' => 'FAB-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'meter', 'selling_price' => 15,
    ]);

    $this->sofa = Bundle::create(['name' => 'جلسة عربية']);

    $this->api = fn () => $this->actingAs($this->user)->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);

    // One Arabic sofa sold for cash at 2500.
    $this->sell = function (array $overrides = []): array {
        return ($this->api)()->postJson('/api/v1/sales', array_merge([
            'client_id' => $this->client->id,
            'payment_method' => 'cash',
            'cash_account_id' => $this->drawer->id,
            'lines' => [['line_type' => 'bundle', 'bundle_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 2500]],
        ], $overrides))->assertCreated()->json();
    };

    $this->piece = fn (Warehouse $warehouse, string $lotNumber, array $size, float $cost = 60) => StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $warehouse->id, 'lot_number' => $lotNumber,
        'quantity' => 1, 'unit_cost' => $cost, 'status' => 'available',
        'length_m' => $size[0], 'width_m' => $size[1], 'height_m' => $size[2],
    ]);

    // Seat pieces 2×0.7×0.1 ×4, back pieces 0.7×0.5×0.15 ×2, fabric 12 m.
    $this->define = fn (array $sale) => ($this->api)()->putJson(
        "/api/v1/sales/{$sale['id']}/lines/{$sale['lines'][0]['id']}/components",
        ['components' => [
            ['inventory_item_id' => $this->foam->id, 'quantity' => 4, 'length_m' => 2, 'width_m' => 0.7, 'height_m' => 0.1],
            ['inventory_item_id' => $this->foam->id, 'quantity' => 2, 'length_m' => 0.7, 'width_m' => 0.5, 'height_m' => 0.15],
            ['inventory_item_id' => $this->fabric->id, 'quantity' => 12],
        ]],
    );
});

test('the pieces of a sold bundle are defined after checkout, the same item in different sizes', function () {
    $sale = ($this->sell)();

    $line = ($this->define)($sale)->assertSuccessful()->json();

    expect($line['components'])->toHaveCount(3)
        ->and($line['components'][0]['inventory_item_id'])->toBe($this->foam->id)
        ->and($line['components'][1]['inventory_item_id'])->toBe($this->foam->id)
        // Reference prices from the items: 4 × 0.14 m³ × 1000, 2 × 0.0525 m³ × 1000, 12 m × 15.
        ->and((float) $line['components'][0]['reference_price'])->toBe(560.0)
        ->and((float) $line['components'][1]['reference_price'])->toBe(105.0)
        ->and((float) $line['components'][2]['reference_price'])->toBe(180.0)
        ->and($line['components'][0]['status'])->toBe('pending');

    expect(SalesOrder::find($sale['id'])->fulfillment_status->value)->toBe('in_progress');
});

test('only a sold bundle line can be defined', function () {
    $pending = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null]); // 2500 > limit 100

    ($this->define)($pending)->assertUnprocessable()->assertJsonPath('code', 'SALE_NOT_ACTIVE');

    StockLot::create([
        'inventory_item_id' => $this->fabric->id, 'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FAB-ROLL', 'quantity' => 50, 'unit_cost' => 10, 'status' => 'available',
    ]);
    $itemSale = ($this->sell)(['lines' => [['inventory_item_id' => $this->fabric->id, 'quantity' => 2, 'unit_price' => 15]]]);

    ($this->define)($itemSale)->assertUnprocessable()->assertJsonPath('code', 'NOT_A_BUNDLE');
});

test('matching stock finds the same size in the showroom and at the cutter, in any orientation', function () {
    ($this->piece)($this->storeWarehouse, 'SEAT-S1', [2, 0.7, 0.1]);
    ($this->piece)($this->cutterWarehouse, 'SEAT-C1', [0.7, 2, 0.1]);      // rotated
    ($this->piece)($this->storeWarehouse, 'SEAT-BIG', [2.2, 0.7, 0.1]);    // wrong size
    ($this->piece)($this->storeWarehouse, 'SEAT-OFF', [2.004, 0.7, 0.1]);  // within half a centimetre

    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    $lots = ($this->api)()->getJson("/api/v1/sale-components/{$components[0]['id']}/matching-stock")
        ->assertSuccessful()->json('lots');

    expect(collect($lots)->pluck('lot_number')->all())
        ->toEqualCanonicalizing(['SEAT-S1', 'SEAT-C1', 'SEAT-OFF']);
});

test('setting stock aside must cover the piece, reserves whole pieces and splits a roll', function () {
    $seats = collect([
        ($this->piece)($this->storeWarehouse, 'SEAT-1', [2, 0.7, 0.1]),
        ($this->piece)($this->storeWarehouse, 'SEAT-2', [2, 0.7, 0.1]),
        ($this->piece)($this->cutterWarehouse, 'SEAT-3', [2, 0.7, 0.1], 55),
        ($this->piece)($this->cutterWarehouse, 'SEAT-4', [2, 0.7, 0.1], 55),
    ]);
    $roll = StockLot::create([
        'inventory_item_id' => $this->fabric->id, 'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FAB-ROLL', 'quantity' => 50, 'unit_cost' => 10, 'status' => 'available',
        'bin_location' => 'F-02',
    ]);

    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    // Three seats cannot fill a row of four.
    ($this->api)()->postJson("/api/v1/sale-components/{$components[0]['id']}/reserve", [
        'allocations' => $seats->take(3)->map(fn ($lot) => ['stock_lot_id' => $lot->id, 'quantity' => 1])->all(),
    ])->assertUnprocessable()->assertJsonPath('code', 'ALLOCATION_MISMATCH');

    ($this->api)()->postJson("/api/v1/sale-components/{$components[0]['id']}/reserve", [
        'allocations' => $seats->map(fn ($lot) => ['stock_lot_id' => $lot->id, 'quantity' => 1])->all(),
    ])->assertSuccessful()->assertJsonPath('status', 'ready');

    expect(StockLot::withoutGlobalScopes()->whereIn('id', $seats->pluck('id'))->pluck('status')->unique()->all())->toBe(['reserved']);

    // 12 m off the 50 m roll: the roll keeps 38, a reserved 12 m lot is split off.
    ($this->api)()->postJson("/api/v1/sale-components/{$components[2]['id']}/reserve", [
        'allocations' => [['stock_lot_id' => $roll->id, 'quantity' => 12]],
    ])->assertSuccessful();

    $split = StockLot::where('source_stock_lot_id', $roll->id)->sole();
    expect((float) $roll->fresh()->quantity)->toBe(38.0)
        ->and($roll->fresh()->status)->toBe('available')
        ->and((float) $split->quantity)->toBe(12.0)
        ->and($split->status)->toBe('reserved')
        ->and($split->bin_location)->toBe('F-02') // split in place: same shelf
        ->and(InventoryMovement::where('reason', 'reserve_for_sale')->sum('quantity_delta'))->toEqual(0);
});

test('a lot of the wrong size cannot be set aside for a piece', function () {
    $wrong = ($this->piece)($this->storeWarehouse, 'BACK-1', [0.7, 0.5, 0.15]);

    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    ($this->api)()->postJson("/api/v1/sale-components/{$components[0]['id']}/reserve", [
        'allocations' => [['stock_lot_id' => $wrong->id, 'quantity' => 4]],
    ])->assertUnprocessable();
});

test('a reserved piece locks the definition until released', function () {
    $backs = [($this->piece)($this->storeWarehouse, 'BACK-1', [0.7, 0.5, 0.15]), ($this->piece)($this->storeWarehouse, 'BACK-2', [0.5, 0.7, 0.15])];

    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    ($this->api)()->postJson("/api/v1/sale-components/{$components[1]['id']}/reserve", [
        'allocations' => array_map(fn ($lot) => ['stock_lot_id' => $lot->id, 'quantity' => 1], $backs),
    ])->assertSuccessful();

    ($this->define)($sale)->assertUnprocessable()->assertJsonPath('code', 'DEFINITION_LOCKED');

    ($this->api)()->postJson("/api/v1/sale-components/{$components[1]['id']}/release")
        ->assertSuccessful()->assertJsonPath('status', 'pending');

    expect(StockLot::whereIn('lot_number', ['BACK-1', 'BACK-2'])->pluck('status')->unique()->all())->toBe(['available']);

    ($this->define)($sale)->assertSuccessful();
});

test('delivering hands the pieces over and books their cost, each from the unit that held it', function () {
    $seats = [
        ($this->piece)($this->storeWarehouse, 'SEAT-1', [2, 0.7, 0.1], 60),
        ($this->piece)($this->storeWarehouse, 'SEAT-2', [2, 0.7, 0.1], 60),
        ($this->piece)($this->cutterWarehouse, 'SEAT-3', [2, 0.7, 0.1], 55),
        ($this->piece)($this->cutterWarehouse, 'SEAT-4', [2, 0.7, 0.1], 55),
    ];
    $backs = [($this->piece)($this->storeWarehouse, 'BACK-1', [0.7, 0.5, 0.15], 20), ($this->piece)($this->storeWarehouse, 'BACK-2', [0.7, 0.5, 0.15], 20)];
    $roll = StockLot::create([
        'inventory_item_id' => $this->fabric->id, 'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FAB-ROLL', 'quantity' => 50, 'unit_cost' => 10, 'status' => 'available',
    ]);

    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/deliver")
        ->assertUnprocessable()->assertJsonPath('code', 'NOTHING_TO_DELIVER');

    $reserve = fn (int $i, array $allocations) => ($this->api)()->postJson(
        "/api/v1/sale-components/{$components[$i]['id']}/reserve", ['allocations' => $allocations],
    )->assertSuccessful();

    $reserve(0, array_map(fn ($lot) => ['stock_lot_id' => $lot->id, 'quantity' => 1], $seats));
    $reserve(1, array_map(fn ($lot) => ['stock_lot_id' => $lot->id, 'quantity' => 1], $backs));
    $reserve(2, [['stock_lot_id' => $roll->id, 'quantity' => 12]]);

    expect(SalesOrder::find($sale['id'])->fulfillment_status->value)->toBe('ready');

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/deliver")
        ->assertSuccessful()
        ->assertJsonPath('fulfillment_status', 'delivered')
        ->assertJsonPath('status', 'completed')
        // 4 seats (60+60+55+55) + 2 backs (40) + 12 m fabric (120) = 390.
        ->assertJsonPath('total_cost', '390.0000');

    expect(StockLot::withoutGlobalScopes()->whereIn('id', collect($seats)->pluck('id'))->pluck('status')->unique()->all())->toBe(['consumed'])
        ->and(SaleBundleComponent::pluck('status')->map->value->unique()->all())->toBe(['delivered']);

    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');
    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['40204']['credit'])->toBe(2500.0)
        ->and((float) $byCode['51']['debit'])->toBe(390.0)
        // INV-11: every cut piece leaves 1132 (its item type's account),
        // whichever unit held it — four seats and two backs.
        ->and((float) $byCode['1132']['credit'])->toBe(270.0)
        // Only the 12 m of fabric (raw material) leaves 111.
        ->and((float) $byCode['111']['credit'])->toBe(120.0);

    // The cutter's two seats left the cutter's own subledger.
    $cutterCredit = JournalLine::where('operating_unit_id', $this->cutter->id)->sum('credit');
    expect((float) $cutterCredit)->toBe(110.0);
});

test('the delivery note lists every piece with its size and no prices', function () {
    $sale = ($this->sell)();
    ($this->define)($sale);

    $note = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}/delivery-note")->assertSuccessful()->json();

    expect($note['document_number'])->toBe('DN-'.$sale['order_number'])
        ->and($note['buyer'])->toBe('Fatima')
        ->and($note['lines'][0]['description'])->toBe('جلسة عربية')
        ->and($note['lines'][0]['pieces'])->toHaveCount(3)
        ->and($note['lines'][0]['pieces'][1])->toMatchArray(['quantity' => 2.0, 'length_m' => 0.7, 'width_m' => 0.5, 'height_m' => 0.15]);

    $json = json_encode($note);
    expect($json)->not->toContain('price')->not->toContain('total')->not->toContain('cost');
});

test('another unit cannot touch a sale bundle pieces', function () {
    $sale = ($this->sell)();
    $components = ($this->define)($sale)->json('components');

    $cutterUser = User::factory()->create(['must_change_password' => false]);
    $manager = Role::create(['name' => 'Cutter Manager', 'slug' => 'cutter-manager']);
    UserRole::create(['user_id' => $cutterUser->id, 'role_id' => $manager->id, 'operating_unit_id' => $this->cutter->id]);

    $this->actingAs($cutterUser)->withHeaders(['X-Operating-Unit-ID' => $this->cutter->id])
        ->getJson("/api/v1/sale-components/{$components[0]['id']}/matching-stock")
        ->assertNotFound();
});
