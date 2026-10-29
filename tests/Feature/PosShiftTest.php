<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\PosDailyClose;
use App\Models\PosShift;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\PosShiftService;
use App\Support\CompanyClock;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Shifts Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Waste, '52'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    RolePermissions::ensureCatalog();

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $cashierRole = Role::firstOrCreate(['slug' => 'pos-cashier'], ['name' => 'POS Cashier']);
    RolePermissions::grantDefaults($cashierRole);
    $this->cashier = User::factory()->create(['must_change_password' => false, 'name' => 'Cashier One']);
    UserRole::create(['user_id' => $this->cashier->id, 'role_id' => $cashierRole->id, 'operating_unit_id' => $this->unit->id]);
    $this->otherCashier = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->otherCashier->id, 'role_id' => $cashierRole->id, 'operating_unit_id' => $this->unit->id]);

    $managerRole = Role::firstOrCreate(['slug' => 'store-manager'], ['name' => 'Store Manager']);
    RolePermissions::grantDefaults($managerRole);
    $this->manager = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa Set',
        'code' => 'SOFA-SHIFT',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Showroom WH', 'code' => 'WH-SHIFT']);

    $this->lot = StockLot::create([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-SHIFT',
        'quantity' => 50,
        'unit_cost' => 40,
        'status' => 'available',
    ]);

    $this->drawer = linkedTreasury($this->unit, '121103');
    $this->bank = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom Bank',
        'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Amal Co']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->api = fn (?User $user = null) => $this->actingAs($user ?? $this->cashier)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->enableShifts = fn () => $this->unit->update(['pos_shifts_enabled' => true]);

    $this->openShift = fn (float $float = 100, ?User $user = null, ?CashAccount $drawer = null) => ($this->api)($user)
        ->postJson('/api/v1/pos/shifts', [
            'cash_account_id' => ($drawer ?? $this->drawer)->id,
            'opening_float' => $float,
        ]);

    $this->sell = fn (array $overrides = [], ?User $user = null) => ($this->api)($user)->postJson('/api/v1/sales', array_merge([
        'walk_in_name' => 'Walk-in Ali',
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 4, 'unit_price' => 100]],
    ], $overrides));

    $this->shiftLineSum = fn (string $shiftId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $shiftId)->where('source_document_type', 'PosShift'))
        ->where('account_id', Account::where('account_code', $code)->value('id'))
        ->sum($side);
});

test('a cashier opens a shift on a cash drawer with an opening float', function (): void {
    ($this->enableShifts)();
    $response = ($this->openShift)(150)->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('cash_account_id', $this->drawer->id)
        ->assertJsonPath('cashier_user_id', $this->cashier->id)
        ->assertJsonPath('opening_float', fn ($v): bool => (float) $v === 150.0)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 150.0);

    expect($response->json('number'))->toStartWith('SH-');

    ($this->api)()->getJson("/api/v1/pos/shifts/current?cash_account_id={$this->drawer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $response->json('id'));

    ($this->api)()->getJson('/api/v1/pos/shifts?status=open')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $response->json('id'));
});

test('a second shift on the same drawer is refused', function (): void {
    ($this->enableShifts)();
    $number = ($this->openShift)(100)->assertCreated()->json('number');

    ($this->openShift)(50, $this->otherCashier)
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_ALREADY_OPEN')
        ->assertJsonPath('shift_number', $number)
        ->assertJsonPath('cashier', 'Cashier One');

    expect(PosShift::withoutGlobalScopes()->count())->toBe(1);
});

test('a shift cannot open in a unit that has not enabled shifts', function (): void {
    ($this->openShift)(100)
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFTS_NOT_ENABLED');

    expect(PosShift::withoutGlobalScopes()->count())->toBe(0);
});

test('a shift cannot open on a bank account', function (): void {
    ($this->enableShifts)();
    ($this->openShift)(100, null, $this->bank)
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_DRAWER_NOT_CASH');

    expect(PosShift::withoutGlobalScopes()->count())->toBe(0);
});

test('with shifts enabled a cash sale through a drawer without an open shift is refused and writes nothing', function (): void {
    ($this->enableShifts)();

    ($this->sell)()
        ->assertStatus(422)
        ->assertJsonPath('code', 'NO_OPEN_SHIFT')
        ->assertJsonPath('cash_account_id', $this->drawer->id);

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(50.0);
});

test('with shifts enabled the cash portion of a split sale needs an open shift', function (): void {
    ($this->enableShifts)();

    ($this->sell)([
        'payment_method' => 'split',
        'cash_account_id' => null,
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 300, 'cash_account_id' => $this->bank->id],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('with shifts disabled the same cash sale goes through unstamped', function (): void {
    $id = ($this->sell)()->assertCreated()->json('id');

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $id)->sole();
    expect($payment->pos_shift_id)->toBeNull()
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($id)->pos_shift_id)->toBeNull();
});

test('a cash sale and its cash refund are stamped with the shift and the X-report nets them', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');

    $saleId = ($this->sell)()->assertCreated()->json('id');
    $sale = SalesOrder::withoutGlobalScopes()->with('lines')->findOrFail($saleId);

    expect($sale->pos_shift_id)->toBe($shiftId)
        ->and(SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->sole()->pos_shift_id)->toBe($shiftId);

    $returnId = ($this->api)($this->owner)->postJson("/api/v1/sales/{$saleId}/returns", [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->json('id');

    $refund = SalePayment::withoutGlobalScopes()->where('sales_return_id', $returnId)->sole();
    expect((float) $refund->amount)->toBe(-100.0)
        ->and($refund->pos_shift_id)->toBe($shiftId);

    ($this->api)()->getJson("/api/v1/pos/shifts/{$shiftId}/x-report")
        ->assertOk()
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('totals.opening_float', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('totals.cash_in', fn ($v): bool => (float) $v === 400.0)
        ->assertJsonPath('totals.cash_refunds', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('totals.sales_count', 1)
        ->assertJsonPath('totals.returns_count', 1)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 400.0);

    expect(PosShift::withoutGlobalScopes()->findOrFail($shiftId)->status)->toBe('open');
});

test('with shifts enabled a cash refund through a drawer without an open shift is refused', function (): void {
    $saleId = ($this->sell)()->assertCreated()->json('id');
    $sale = SalesOrder::withoutGlobalScopes()->with('lines')->findOrFail($saleId);
    ($this->enableShifts)();

    ($this->api)($this->owner)->postJson("/api/v1/sales/{$saleId}/returns", [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    expect(SalePayment::withoutGlobalScopes()->whereNotNull('sales_return_id')->count())->toBe(0);
});

test('closing a shift short posts DR 625 / CR drawer and stores the Z-report totals', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    ($this->sell)()->assertCreated();

    $response = ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", [
        'counted_cash' => 480,
        'notes' => 'Short 20',
    ])->assertOk()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('closed_by_user_id', $this->cashier->id)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 500.0)
        ->assertJsonPath('counted_cash', fn ($v): bool => (float) $v === 480.0)
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === -20.0)
        ->assertJsonPath('totals.cash_in', fn ($v): bool => (float) $v === 400.0)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 500.0);

    expect($response->json('journal_entry_id'))->not->toBeNull()
        ->and(($this->shiftLineSum)($shiftId, '625', 'debit'))->toBe(20.0)
        ->and(($this->shiftLineSum)($shiftId, '121103', 'credit'))->toBe(20.0);

    $shift = PosShift::withoutGlobalScopes()->findOrFail($shiftId);
    expect($shift->totals['expected_cash'])->toEqual(500)
        ->and($shift->notes)->toBe('Short 20');

    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 480])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_ALREADY_CLOSED');

    ($this->api)()->getJson("/api/v1/pos/shifts/{$shiftId}")
        ->assertOk()
        ->assertJsonPath('cashier.name', 'Cashier One')
        ->assertJsonPath('cash_account.id', $this->drawer->id)
        ->assertJsonPath('journal_entry_id', $response->json('journal_entry_id'))
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 500.0);
});

test('closing a shift with exact cash posts nothing', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    ($this->sell)()->assertCreated();

    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 500])
        ->assertOk()
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('journal_entry_id', null);

    expect(JournalEntry::where('source_document_type', 'PosShift')->count())->toBe(0);
});

test('another cashier cannot close the shift but a store manager can', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');

    ($this->api)($this->otherCashier)->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_CLOSE_NOT_ALLOWED');

    expect(PosShift::withoutGlobalScopes()->findOrFail($shiftId)->status)->toBe('open');

    ($this->api)($this->manager)->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 100])
        ->assertOk()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('closed_by_user_id', $this->manager->id);
});

test('the register day cannot close while a shift is open', function (): void {
    ($this->enableShifts)();
    $number = ($this->openShift)(100)->assertCreated()->json('number');

    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPEN_SHIFTS_EXIST')
        ->assertJsonPath('shift_numbers', [$number]);

    expect(PosDailyClose::withoutGlobalScopes()->count())->toBe(0);
});

test('the register day closes from its closed shifts, sums them and posts nothing', function (): void {
    ($this->enableShifts)();
    $drawerB = linkedTreasury($this->unit, '121104');

    $shiftA = ($this->openShift)(100)->assertCreated()->json('id');
    $shiftB = ($this->openShift)(50, $this->otherCashier, $drawerB)->assertCreated()->json('id');

    ($this->sell)()->assertCreated();
    ($this->sell)(['cash_account_id' => $drawerB->id, 'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 200]]], $this->otherCashier)->assertCreated();

    // A: expected 500, counted 490 (−10). B: expected 250, counted 255 (+5).
    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftA}/close", ['counted_cash' => 490])->assertOk();
    ($this->api)($this->otherCashier)->postJson("/api/v1/pos/shifts/{$shiftB}/close", ['counted_cash' => 255])->assertOk();

    expect(JournalEntry::where('source_document_type', 'PosShift')->count())->toBe(2);

    $close = ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])
        ->assertCreated()
        ->assertJsonPath('shift_based', true)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 750.0)
        ->assertJsonPath('counted_cash', fn ($v): bool => (float) $v === 745.0)
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === -5.0)
        ->assertJsonPath('sales_count', 2)
        ->assertJsonPath('journal_entry_id', null)
        ->json();

    expect(JournalEntry::where('source_document_type', 'PosDailyClose')->count())->toBe(0)
        ->and(PosDailyClose::withoutGlobalScopes()->findOrFail($close['id'])->shift_based)->toBeTrue();

    // The day is locked as before.
    ($this->sell)()->assertStatus(422)->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');
});

test('bank and receivable sales are not touched by shifts', function (): void {
    ($this->enableShifts)();

    $bankId = ($this->sell)(['payment_method' => 'bank', 'cash_account_id' => $this->bank->id])->assertCreated()->json('id');
    $receivableId = ($this->sell)([
        'walk_in_name' => null,
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'cash_account_id' => null,
    ])->assertCreated()->json('id');

    expect(SalePayment::withoutGlobalScopes()->where('sales_order_id', $bankId)->sole()->pos_shift_id)->toBeNull()
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($bankId)->pos_shift_id)->toBeNull()
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($receivableId)->pos_shift_id)->toBeNull();
});

test('owner and unit managers switch shifts on; a cashier cannot', function (): void {
    ($this->api)($this->cashier)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => true])
        ->assertForbidden();

    ($this->api)($this->manager)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => true])
        ->assertOk()
        ->assertJsonPath('pos_shifts_enabled', true);

    expect($this->unit->fresh()->pos_shifts_enabled)->toBeTrue();

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertOk()
        ->assertJsonPath('pos_shifts_enabled', false);

    ($this->api)($this->cashier)->getJson('/api/v1/operating-units')
        ->assertOk()
        ->assertJsonPath('data.0.pos_shifts_enabled', false);
});

test('switching shifts off is refused while a shift is open or after a shift closed today', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    $number = PosShift::withoutGlobalScopes()->findOrFail($shiftId)->number;

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPEN_SHIFTS_EXIST')
        ->assertJsonPath('shift_numbers', [$number]);

    ($this->sell)()->assertCreated();
    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 480])->assertOk();

    // The day was run on shifts; switching off now would reconcile it twice.
    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'POS_DAY_IN_PROGRESS');

    expect($this->unit->fresh()->pos_shifts_enabled)->toBeTrue();

    // Once the day is closed the change takes effect from the next day.
    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])->assertCreated();
    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertOk()
        ->assertJsonPath('pos_shifts_enabled', false);

    expect(JournalEntry::where('source_document_type', 'PosDailyClose')->count())->toBe(0);
});

test('switching shifts on is refused once today took cash outside a shift', function (): void {
    ($this->sell)()->assertCreated();

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'POS_DAY_IN_PROGRESS');

    expect($this->unit->fresh()->pos_shifts_enabled)->toBeFalse();

    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', ['counted_cash' => 400])->assertCreated();

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => true])
        ->assertOk()
        ->assertJsonPath('pos_shifts_enabled', true);
});

test('a shift that closed after the early check refuses the cash payment inside the transaction', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    $saleId = ($this->sell)()->assertCreated()->json('id');
    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 500])->assertOk();

    $order = SalesOrder::withoutGlobalScopes()->findOrFail($saleId);

    try {
        app(PosShiftService::class)->shiftForPayment($order, 'cash', $this->drawer);
        $this->fail('Expected NO_OPEN_SHIFT.');
    } catch (SalesRuleException $e) {
        expect($e->errorCode)->toBe('NO_OPEN_SHIFT')
            ->and($e->context)->toBe(['cash_account_id' => $this->drawer->id]);
    }

    // Non-cash is never stamped nor refused.
    expect(app(PosShiftService::class)->shiftForPayment($order, 'bank', $this->bank))->toBeNull();
});

test('another unit\'s shift on a shared drawer is not this unit\'s shift', function (): void {
    ($this->enableShifts)();
    $unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Branch B',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
        'pos_shifts_enabled' => true,
    ]);

    $shiftB = $this->actingAs($this->owner)->withHeaders(['X-Operating-Unit-ID' => $unitB->id])
        ->postJson('/api/v1/pos/shifts', ['cash_account_id' => $this->drawer->id, 'opening_float' => 0])
        ->assertCreated()
        ->json();

    // Unit A sees no shift of its own on the drawer and cannot take cash through it.
    ($this->api)()->getJson("/api/v1/pos/shifts/current?cash_account_id={$this->drawer->id}")
        ->assertOk()
        ->assertJsonPath('data', null);

    ($this->sell)()->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    ($this->openShift)(100)
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_ALREADY_OPEN')
        ->assertJsonPath('shift_number', $shiftB['number']);

    // A unit without shifts uses the drawer unstamped; B's shift stays empty.
    $this->unit->update(['pos_shifts_enabled' => false]);
    $saleId = ($this->sell)()->assertCreated()->json('id');

    expect(SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->sole()->pos_shift_id)->toBeNull()
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($saleId)->pos_shift_id)->toBeNull()
        ->and(SalePayment::withoutGlobalScopes()->where('pos_shift_id', $shiftB['id'])->count())->toBe(0);
});

test('a missed earlier day closes from its shifts even while a shift is open today', function (): void {
    ($this->enableShifts)();

    $this->travelTo(CompanyClock::now()->subDay()->setTime(12, 0));
    $yesterday = CompanyClock::today();
    $oldShift = ($this->openShift)(100)->assertCreated()->json('id');
    ($this->sell)()->assertCreated();
    ($this->api)()->postJson("/api/v1/pos/shifts/{$oldShift}/close", ['counted_cash' => 500])->assertOk();
    $this->travelBack();

    $todayNumber = ($this->openShift)(50)->assertCreated()->json('number');

    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', ['date' => $yesterday])
        ->assertCreated()
        ->assertJsonPath('shift_based', true)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 500.0);

    // Today's own close still waits for today's shift.
    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPEN_SHIFTS_EXIST')
        ->assertJsonPath('shift_numbers', [$todayNumber]);
});

test('the shift list filters from/to by the company-local day', function (): void {
    ($this->enableShifts)();

    // 23:30 UTC on the 28th is 01:30 on the 29th in Tripoli.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 23:30:00', 'UTC'));
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    $this->travelBack();

    ($this->api)()->getJson('/api/v1/pos/shifts?from=2026-10-29&to=2026-10-29')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $shiftId);

    ($this->api)()->getJson('/api/v1/pos/shifts?from=2026-10-28&to=2026-10-28')
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('a cash collection on a receivable needs an open shift and is stamped with it', function (): void {
    ($this->enableShifts)();
    $saleId = ($this->sell)([
        'walk_in_name' => null,
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'cash_account_id' => null,
    ])->assertCreated()->json('id');
    $entriesBefore = JournalEntry::count();

    ($this->api)()->postJson("/api/v1/sales/{$saleId}/payments", [
        'amount' => 150,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    expect(SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->count())->toBe(0)
        ->and(JournalEntry::count())->toBe($entriesBefore)
        ->and((float) SalesOrder::withoutGlobalScopes()->findOrFail($saleId)->amount_paid)->toBe(0.0);

    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');

    ($this->api)()->postJson("/api/v1/sales/{$saleId}/payments", [
        'amount' => 150,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    expect(SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->sole()->pos_shift_id)->toBe($shiftId);

    ($this->api)()->getJson("/api/v1/pos/shifts/{$shiftId}/x-report")
        ->assertOk()
        ->assertJsonPath('totals.cash_in', fn ($v): bool => (float) $v === 150.0)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 250.0);
});

test('a split sale under an open shift stamps only its cash portion and reports the bank portion', function (): void {
    ($this->enableShifts)();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');

    $saleId = ($this->sell)([
        'payment_method' => 'split',
        'cash_account_id' => null,
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 300, 'cash_account_id' => $this->bank->id],
        ],
    ])->assertCreated()->json('id');

    $payments = SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->get();
    expect($payments->firstWhere('method', 'cash')->pos_shift_id)->toBe($shiftId)
        ->and($payments->firstWhere('method', 'bank')->pos_shift_id)->toBeNull()
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($saleId)->pos_shift_id)->toBe($shiftId);

    ($this->api)()->getJson("/api/v1/pos/shifts/{$shiftId}/x-report")
        ->assertOk()
        ->assertJsonPath('totals.cash_in', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('totals.bank', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('totals.sales_count', 1)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 200.0);
});

test('a cash refund of a sale made before the shift does not count it as the shift\'s sale', function (): void {
    ($this->enableShifts)();
    $saleId = ($this->sell)(['payment_method' => 'bank', 'cash_account_id' => $this->bank->id])->assertCreated()->json('id');
    $sale = SalesOrder::withoutGlobalScopes()->with('lines')->findOrFail($saleId);
    $shiftId = ($this->openShift)(200)->assertCreated()->json('id');
    // The shift's own sale (also puts cash in the drawer to refund from).
    ($this->sell)()->assertCreated();

    $returnId = ($this->api)($this->owner)->postJson("/api/v1/sales/{$saleId}/returns", [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->json('id');

    expect(SalePayment::withoutGlobalScopes()->where('sales_return_id', $returnId)->sole()->pos_shift_id)->toBe($shiftId)
        ->and(SalesOrder::withoutGlobalScopes()->findOrFail($saleId)->pos_shift_id)->toBeNull();

    ($this->api)()->getJson("/api/v1/pos/shifts/{$shiftId}/x-report")
        ->assertOk()
        ->assertJsonPath('totals.sales_count', 1)
        ->assertJsonPath('totals.returns_count', 1)
        ->assertJsonPath('totals.bank', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('totals.cash_refunds', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('totals.expected_cash', fn ($v): bool => (float) $v === 500.0);
});

test('a manager role in another unit does not let a cashier close a colleague\'s shift', function (): void {
    ($this->enableShifts)();
    $unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Branch B',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    UserRole::create([
        'user_id' => $this->otherCashier->id,
        'role_id' => Role::where('slug', 'store-manager')->value('id'),
        'operating_unit_id' => $unitB->id,
    ]);

    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');

    ($this->api)($this->otherCashier)->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SHIFT_CLOSE_NOT_ALLOWED');

    expect(PosShift::withoutGlobalScopes()->findOrFail($shiftId)->status)->toBe('open');
});

test('a day whose shift closed after midnight still closes from shifts once shifts are switched off', function (): void {
    ($this->enableShifts)();

    // D1 22:00: the shift opens and takes 400 in cash at 23:00.
    $evening = CompanyClock::now()->subDays(2)->setTime(22, 0);
    $this->travelTo($evening);
    $dayOne = CompanyClock::today();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    $this->travelTo($evening->addHour());
    ($this->sell)()->assertCreated();

    // D2 00:30: the shift counts its cash exactly and D2 closes from it.
    $this->travelTo($evening->addMinutes(150));
    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 500])->assertOk();
    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])
        ->assertCreated()
        ->assertJsonPath('shift_based', true);
    $this->travelBack();

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertOk();

    // D1 holds only cash the shift already reconciled: it closes from shifts, posting nothing.
    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', ['date' => $dayOne])
        ->assertCreated()
        ->assertJsonPath('shift_based', true)
        ->assertJsonPath('journal_entry_id', null);

    expect(JournalEntry::where('source_document_type', 'PosDailyClose')->count())->toBe(0)
        ->and(JournalEntry::where('source_document_type', 'PosShift')->count())->toBe(0);
});

test('a drawer count on a day with shift-stamped cash counts only the unstamped cash', function (): void {
    ($this->enableShifts)();

    $evening = CompanyClock::now()->subDays(2)->setTime(22, 0);
    $this->travelTo($evening);
    $dayOne = CompanyClock::today();
    $shiftId = ($this->openShift)(100)->assertCreated()->json('id');
    $this->travelTo($evening->addMinutes(30));
    ($this->sell)()->assertCreated();

    $this->travelTo($evening->addMinutes(150));
    ($this->api)()->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['counted_cash' => 500])->assertOk();
    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', [])->assertCreated();
    $this->travelBack();

    ($this->api)($this->owner)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => false])
        ->assertOk();

    // D1 also took 200 outside any shift, so it is a drawer-count day.
    $this->travelTo($evening->addMinutes(90));
    ($this->sell)(['lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100]]])->assertCreated();
    $this->travelBack();

    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', ['date' => $dayOne, 'counted_cash' => 200])
        ->assertCreated()
        ->assertJsonPath('shift_based', fn ($v): bool => ! $v)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('journal_entry_id', null);

    expect(JournalEntry::where('source_document_type', 'PosDailyClose')->count())->toBe(0);
});

test('a shift cannot open on a cash drawer that is not linked to a ledger account', function (): void {
    ($this->enableShifts)();
    $unlinked = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Loose Drawer',
        'kind' => 'cash',
        'account_id' => null,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    ($this->openShift)(100, null, $unlinked)
        ->assertStatus(422)
        ->assertJsonPath('code', 'TREASURY_NOT_LINKED');

    expect(PosShift::withoutGlobalScopes()->count())->toBe(0);
});

test('an admin switches shifts for a unit as the owner does', function (): void {
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $adminRole->id, 'operating_unit_id' => null]);

    ($this->api)($admin)->putJson("/api/v1/operating-units/{$this->unit->id}/pos-settings", ['pos_shifts_enabled' => true])
        ->assertOk()
        ->assertJsonPath('pos_shifts_enabled', true);
});

test('a drawer-count close is refused too while a shift is still open on the day', function (): void {
    ($this->enableShifts)();
    ($this->openShift)(50)->assertCreated();

    // Cash that reached the drawer outside the shift (e.g. recorded before shifts were on).
    SalePayment::withoutGlobalScopes()->forceCreate([
        'operating_unit_id' => $this->unit->id,
        'sales_order_id' => ($this->sell)()->assertCreated()->json('id'),
        'method' => 'cash',
        'amount' => 30,
        'cash_account_id' => $this->drawer->id,
        'received_at' => now(),
        'pos_shift_id' => null,
    ]);

    ($this->api)($this->owner)->postJson('/api/v1/pos/daily-close', ['counted_cash' => 30])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPEN_SHIFTS_EXIST');

    expect(PosDailyClose::count())->toBe(0);
});

test('the daily report leaves shift-counted cash out of the drawer expectation', function (): void {
    ($this->enableShifts)();
    ($this->openShift)(100)->assertCreated();
    $total = (float) ($this->sell)()->assertCreated()->json('total_amount');

    ($this->api)($this->owner)->getJson('/api/v1/pos/daily-report')
        ->assertOk()
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('shift_cash', fn ($v): bool => (float) $v === $total);
});
