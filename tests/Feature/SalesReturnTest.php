<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
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
use App\Models\OperatingUnitAccount;
use App\Models\PosDailyClose;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Support\CompanyClock;
use App\Support\RolePermissions;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Returns Co', 'default_currency' => 'LYD']);
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

    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->otherUnit, '40203');

    foreach ([$this->unit, $this->otherUnit] as $unit) {
        foreach ([
            [InventoryEventType::Purchases, '1134'],
            [InventoryEventType::Waste, '52'],
        ] as [$event, $code]) {
            OperatingUnitAccount::create([
                'operating_unit_id' => $unit->id,
                'event_type' => $event->value,
                'account_id' => Account::where('account_code', $code)->sole()->id,
            ]);
        }
    }

    $this->user = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa Set',
        'code' => 'SOFA-RET',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Showroom WH', 'code' => 'WH-RET']);
    $this->otherWarehouse = Warehouse::create(['operating_unit_id' => $this->otherUnit->id, 'name' => 'Furniture WH', 'code' => 'WH-RET-2']);

    // Two lots: FIFO takes the older one (cost 40) first, then the newer (cost 50).
    $this->oldLot = StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-RET-OLD',
        'quantity' => 2,
        'unit_cost' => 40,
        'status' => 'available',
    ]);
    $this->oldLot->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->newLot = StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-RET-NEW',
        'quantity' => 20,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    $this->drawer = linkedTreasury($this->unit, '121103');
    $this->emptyDrawer = linkedTreasury($this->unit, '121104');
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

    $this->api = fn (?OperatingUnit $unit = null, ?User $user = null) => $this->actingAs($user ?? $this->user)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unit)->id]);

    $this->sell = function (array $overrides = [], ?OperatingUnit $unit = null): SalesOrder {
        $id = ($this->api)($unit)->postJson('/api/v1/sales', array_merge([
            'walk_in_name' => 'Walk-in Ali',
            'payment_method' => 'cash',
            'cash_account_id' => $this->drawer->id,
            'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 4, 'unit_price' => 100]],
        ], $overrides))->assertCreated()->json('id');

        return SalesOrder::withoutGlobalScopes()->with('lines')->findOrFail($id);
    };

    $this->returnSale = fn (SalesOrder $sale, array $payload, ?User $user = null) => ($this->api)(null, $user)
        ->postJson("/api/v1/sales/{$sale->id}/returns", $payload);

    $this->accountId = fn (string $code) => Account::where('account_code', $code)->value('id');
    $this->returnLineSum = fn (string $returnId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $returnId)->where('source_document_type', 'SalesReturn'))
        ->where('account_id', ($this->accountId)($code))
        ->sum($side);
});

test('partial return of a cash sale refunds cash, restocks the drawn lots most recent first and reopens a consumed lot', function (): void {
    $sale = ($this->sell)();
    $line = $sale->lines->first();

    expect($this->oldLot->fresh()->status)->toBe('consumed')
        ->and((float) $this->newLot->fresh()->quantity)->toBe(18.0);

    $response = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 3, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'reason' => 'Wrong colour',
    ])->assertCreated();

    $response->assertJsonPath('amount', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('total_cost', fn ($v): bool => (float) $v === 140.0)
        ->assertJsonPath('refund_method', 'cash')
        ->assertJsonPath('lines.0.unit_cost', fn ($v): bool => abs((float) $v - 46.6667) < 0.0001);

    $returnId = $response->json('id');
    expect($response->json('number'))->toStartWith('RET-');

    // DR returns leaf / CR drawer; DR inventory / CR 51 at the original lot cost.
    expect(($this->returnLineSum)($returnId, '40404', 'debit'))->toBe(300.0)
        ->and(($this->returnLineSum)($returnId, '121103', 'credit'))->toBe(300.0)
        ->and(($this->returnLineSum)($returnId, '1134', 'debit'))->toBe(140.0)
        ->and(($this->returnLineSum)($returnId, '51', 'credit'))->toBe(140.0);

    // Newer lot (drawn last) refilled first, then the consumed older lot reopened.
    $old = $this->oldLot->fresh();
    expect((float) $this->newLot->fresh()->quantity)->toBe(20.0)
        ->and((float) $old->quantity)->toBe(1.0)
        ->and($old->status)->toBe('available');

    $movements = InventoryMovement::where('reference_document_type', 'SalesReturn')->where('reference_id', $returnId)->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->unique()->all())->toBe(['sale_return'])
        ->and((float) $movements->sum('quantity_delta'))->toBe(3.0)
        ->and($movements->firstWhere('stock_lot_id', $this->oldLot->id)->to_warehouse_id)->toBe($this->warehouse->id);

    $refund = SalePayment::where('sales_return_id', $returnId)->sole();
    expect((float) $refund->amount)->toBe(-300.0)
        ->and($refund->method)->toBe('cash')
        ->and($refund->change_given)->toBeNull();

    $sale->refresh();
    expect((float) $sale->amount_paid)->toBe(100.0)
        ->and((float) $sale->returned_amount)->toBe(300.0)
        ->and($sale->outstanding())->toBe(0.0);

    ($this->api)()->getJson("/api/v1/sales/{$sale->id}")
        ->assertOk()
        ->assertJsonPath('returned_amount', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('returns.0.number', $response->json('number'))
        ->assertJsonPath('returns.0.refund_method', 'cash');
});

test('a full return brings every unit back and refunds the whole sale', function (): void {
    $sale = ($this->sell)();
    $line = $sale->lines->first();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 4, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->assertJsonPath('amount', fn ($v): bool => (float) $v === 400.0);

    expect((float) $this->oldLot->fresh()->quantity)->toBe(2.0)
        ->and((float) $this->newLot->fresh()->quantity)->toBe(20.0);

    $sale->refresh();
    expect((float) $sale->amount_paid)->toBe(0.0)
        ->and((float) $sale->returned_amount)->toBe(400.0);

    ($this->api)()->getJson("/api/v1/sales/{$sale->id}/returnable")
        ->assertOk()
        ->assertJsonPath('lines.0.remaining_qty', fn ($v): bool => (float) $v === 0.0);
});

test('two returns that together exceed the sold quantity: the second is refused', function (): void {
    $sale = ($this->sell)();
    $line = $sale->lines->first();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 3, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 2, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_SOLD')
        ->assertJsonPath('sales_order_line_id', $line->id)
        ->assertJsonPath('remaining', fn ($v): bool => (float) $v === 1.0);

    expect(SalesReturn::count())->toBe(1);
});

test('a damaged line posts its cost to waste and moves no stock', function (): void {
    $sale = ($this->sell)();
    $line = $sale->lines->first();
    // FIFO: 2 × 40 + 2 × 50 = 180 → 45 per unit.
    expect((float) $line->unit_cost_actual)->toBe(45.0);

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 2, 'condition' => 'damaged']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->json('id');

    expect(($this->returnLineSum)($returnId, '52', 'debit'))->toBe(90.0)
        ->and(($this->returnLineSum)($returnId, '51', 'credit'))->toBe(90.0)
        ->and(($this->returnLineSum)($returnId, '1134', 'debit'))->toBe(0.0)
        ->and(InventoryMovement::where('reference_document_type', 'SalesReturn')->count())->toBe(0)
        ->and((float) $this->newLot->fresh()->quantity)->toBe(18.0);
});

test('the daily report and close net a cash refund out of expected cash', function (): void {
    $sale = ($this->sell)();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    ($this->api)()->getJson('/api/v1/pos/daily-report')
        ->assertOk()
        ->assertJsonPath('sales_count', 1)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('collected.cash.count', 1)
        ->assertJsonPath('collected.cash.total', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('refunds.cash.count', 1)
        ->assertJsonPath('refunds.cash.total', fn ($v): bool => (float) $v === -100.0);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 300])->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(300.0)
        ->and((float) $close['difference'])->toBe(0.0);
});

test('a bank refund needs a bank treasury', function (): void {
    $sale = ($this->sell)(['payment_method' => 'bank', 'cash_account_id' => $this->bank->id]);
    $lineId = $sale->lines->first()->id;

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'bank',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'TREASURY_KIND_MISMATCH');

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'bank',
        'cash_account_id' => $this->bank->id,
    ])->assertCreated()->json('id');

    expect(($this->returnLineSum)($returnId, '1212', 'credit'))->toBe(100.0)
        ->and((float) SalePayment::where('sales_return_id', $returnId)->sole()->amount)->toBe(-100.0);
});

test('a refund above the treasury balance is refused', function (): void {
    $sale = ($this->sell)();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->emptyDrawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'TREASURY_INSUFFICIENT_BALANCE');

    expect(SalesReturn::count())->toBe(0)
        ->and((float) $this->newLot->fresh()->quantity)->toBe(18.0);
});

test('a credit return of a receivable sale lowers the client balance and the outstanding', function (): void {
    $sale = ($this->sell)(['client_id' => $this->client->id, 'walk_in_name' => null, 'payment_method' => 'receivable', 'cash_account_id' => null]);
    expect((float) $this->client->fresh()->current_balance)->toBe(400.0);

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'credit',
    ])->assertCreated()->json('id');

    $receivable = $this->client->fresh()->load('account')->account->account_code;
    $credit = JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $returnId))
        ->where('account_id', ($this->accountId)($receivable))
        ->sole();

    expect((float) $credit->credit)->toBe(100.0)
        ->and($credit->client_id)->toBe($this->client->id)
        ->and((float) $this->client->fresh()->current_balance)->toBe(300.0)
        ->and(SalePayment::where('sales_return_id', $returnId)->exists())->toBeFalse();

    $sale->refresh();
    expect((float) $sale->amount_paid)->toBe(0.0)
        ->and($sale->outstanding())->toBe(300.0);

    // Collecting more than what is now owed is refused.
    ($this->api)()->postJson("/api/v1/sales/{$sale->id}/payments", [
        'amount' => 350,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_EXCEEDS_OUTSTANDING');
});

test('credit is refused for a walk-in sale', function (): void {
    $sale = ($this->sell)();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'credit',
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_REFUND_METHOD_INVALID');
});

test('a discounted sale refunds the net share: line discount plus invoice discount share', function (): void {
    // Line A: 2 × 100 − 20 = 180; line B: 1 × 100 = 100; subtotal 280; invoice discount 28.
    $sale = ($this->sell)([
        'invoice_discount' => 28,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 20],
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ]);
    $lineA = $sale->lines->first();

    // Line A net = 180 − 28 × 180/280 = 162 → 81 per unit.
    ($this->api)()->getJson("/api/v1/sales/{$sale->id}/returnable")
        ->assertOk()
        ->assertJsonPath('lines.0.net_unit_amount', fn ($v): bool => abs((float) $v - 81.0) < 0.0001)
        ->assertJsonPath('lines.1.net_unit_amount', fn ($v): bool => abs((float) $v - 90.0) < 0.0001);

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $lineA->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->assertJsonPath('amount', fn ($v): bool => abs((float) $v - 81.0) < 0.0001);
});

test('an internal transfer and a pending sale cannot be returned', function (): void {
    $transferId = ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->otherUnit->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');
    $transfer = SalesOrder::with('lines')->findOrFail($transferId);

    ($this->returnSale)($transfer, [
        'lines' => [['sales_order_line_id' => $transfer->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_SALE_NOT_COMPLETED');

    $this->client->update(['credit_limit' => 10]);
    $pending = ($this->sell)(['client_id' => $this->client->id, 'walk_in_name' => null, 'payment_method' => 'receivable', 'cash_account_id' => null]);
    expect($pending->status->value)->toBe('pending_approval');

    ($this->returnSale)($pending, [
        'lines' => [['sales_order_line_id' => $pending->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'credit',
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_SALE_NOT_COMPLETED');
});

test('a bundle line is not returnable', function (): void {
    $bundle = Bundle::create(['name' => 'جلسة عربية']);
    $sale = ($this->sell)(['lines' => [['line_type' => 'bundle', 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 500]]]);

    ($this->api)()->getJson("/api/v1/sales/{$sale->id}/returnable")
        ->assertOk()
        ->assertJsonPath('lines.0.returnable', false);

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_LINE_NOT_RETURNABLE');
});

test('an empty return is refused', function (): void {
    $sale = ($this->sell)();

    ($this->returnSale)($sale, [
        'lines' => [],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_EMPTY');
});

test('a return on a closed register day is refused', function (): void {
    $sale = ($this->sell)();

    PosDailyClose::create([
        'operating_unit_id' => $this->unit->id,
        'close_date' => CompanyClock::today($this->unit),
        'expected_cash' => 400,
        'counted_cash' => 400,
        'difference' => 0,
        'sales_count' => 1,
        'total_sales' => 400,
        'closed_by_user_id' => $this->user->id,
    ]);

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');
});

test('a pos-cashier can view returns but gets 403 on create', function (): void {
    RolePermissions::ensureCatalog();
    $cashierRole = Role::firstOrCreate(['slug' => 'pos-cashier'], ['name' => 'POS Cashier']);
    RolePermissions::grantDefaults($cashierRole);
    $cashier = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $cashierRole->id, 'operating_unit_id' => $this->unit->id]);

    $sale = ($this->sell)();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ], $cashier)->assertForbidden();

    ($this->api)(null, $cashier)->getJson('/api/v1/sales-returns')->assertOk();
    ($this->api)(null, $cashier)->getJson("/api/v1/sales/{$sale->id}/returnable")->assertOk();
});

test('another unit\'s sale is not found', function (): void {
    $foreignLot = StockLot::create([
        'warehouse_id' => $this->otherWarehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-RET-FOREIGN',
        'quantity' => 5,
        'unit_cost' => 50,
        'status' => 'available',
    ]);
    $foreignDrawer = linkedTreasury($this->otherUnit, '121101');

    $sale = ($this->sell)(['cash_account_id' => $foreignDrawer->id], $this->otherUnit);
    expect((float) $foreignLot->fresh()->quantity)->toBe(1.0);

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertNotFound();

    ($this->api)()->getJson("/api/v1/sales/{$sale->id}/returnable")->assertNotFound();
});

test('the returnable endpoint reports sold, returned and remaining per line', function (): void {
    $sale = ($this->sell)();
    $line = $sale->lines->first();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 1, 'condition' => 'damaged']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    ($this->api)()->getJson("/api/v1/sales/{$sale->id}/returnable")
        ->assertOk()
        ->assertJsonPath('lines.0.sales_order_line_id', $line->id)
        ->assertJsonPath('lines.0.line_type', 'item')
        ->assertJsonPath('lines.0.description', 'Sofa Set')
        ->assertJsonPath('lines.0.sold_qty', fn ($v): bool => (float) $v === 4.0)
        ->assertJsonPath('lines.0.returned_qty', fn ($v): bool => (float) $v === 1.0)
        ->assertJsonPath('lines.0.remaining_qty', fn ($v): bool => (float) $v === 3.0)
        ->assertJsonPath('lines.0.net_unit_amount', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('lines.0.returnable', true);
});

test('list and show endpoints return the return with its lines, sale and people', function (): void {
    $sale = ($this->sell)(['client_id' => $this->client->id, 'walk_in_name' => null]);
    $other = ($this->sell)(['walk_in_name' => 'Samir']);

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 2, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->json('id');

    ($this->returnSale)($other, [
        'lines' => [['sales_order_line_id' => $other->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    ($this->api)()->getJson('/api/v1/sales-returns')->assertOk()->assertJsonPath('total', 2);
    ($this->api)()->getJson('/api/v1/sales-returns?search=Al-Amal')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $returnId);
    ($this->api)()->getJson('/api/v1/sales-returns?search=Samir')->assertOk()->assertJsonPath('total', 1);
    ($this->api)()->getJson('/api/v1/sales-returns?search='.$sale->order_number)->assertOk()->assertJsonPath('data.0.id', $returnId);
    ($this->api)()->getJson('/api/v1/sales-returns?client_id='.$this->client->id)->assertOk()->assertJsonPath('total', 1);
    ($this->api)()->getJson('/api/v1/sales-returns?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonPath('total', 0);

    ($this->api)()->getJson("/api/v1/sales-returns/{$returnId}")
        ->assertOk()
        ->assertJsonPath('order.order_number', $sale->order_number)
        ->assertJsonPath('client.entity.name', 'Al-Amal Co')
        ->assertJsonPath('cash_account.name', $this->drawer->name)
        ->assertJsonPath('created_by.name', $this->user->name)
        ->assertJsonPath('lines.0.order_line.description', 'Sofa Set')
        ->assertJsonPath('lines.0.quantity', fn ($v): bool => (float) $v === 2.0);
});

test('an accounting-manager can see what is returnable, raise a return and view it', function (): void {
    RolePermissions::ensureCatalog();
    $role = Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);
    RolePermissions::grantDefaults($role);
    $accountant = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $sale = ($this->sell)();
    $lineId = $sale->lines->first()->id;

    ($this->api)(null, $accountant)->getJson("/api/v1/sales/{$sale->id}/returnable")
        ->assertOk()
        ->assertJsonPath('lines.0.sales_order_line_id', $lineId);

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ], $accountant)->assertCreated()->json('id');

    ($this->api)(null, $accountant)->getJson('/api/v1/sales-returns')
        ->assertOk()
        ->assertJsonPath('data.0.id', $returnId);
    ($this->api)(null, $accountant)->getJson("/api/v1/sales-returns/{$returnId}")
        ->assertOk()
        ->assertJsonPath('id', $returnId);
});

test('a restock goes back to the lot its own line was sold from', function (): void {
    // Line A is tied to the newer lot (cost 50); line B is FIFO and draws the older lot (cost 40).
    $sale = ($this->sell)([
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100, 'stock_lot_id' => $this->newLot->id],
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
    ]);
    $lineA = $sale->lines->firstWhere('stock_lot_id', $this->newLot->id);
    $lineB = $sale->lines->firstWhere('stock_lot_id', null);

    expect($this->oldLot->fresh()->status)->toBe('consumed')
        ->and((float) $this->newLot->fresh()->quantity)->toBe(19.0);

    // The FIFO line comes back into the older lot it drew, at 40 — not the more recently created lot.
    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $lineB->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->assertJsonPath('total_cost', fn ($v): bool => (float) $v === 40.0);

    expect((float) $this->oldLot->fresh()->quantity)->toBe(1.0)
        ->and($this->oldLot->fresh()->status)->toBe('available')
        ->and((float) $this->newLot->fresh()->quantity)->toBe(19.0);

    // The lot-tied line goes back to its own lot at 50; the FIFO line's last unit to the older lot.
    $returnId = ($this->returnSale)($sale, [
        'lines' => [
            ['sales_order_line_id' => $lineA->id, 'quantity' => 1, 'condition' => 'restock'],
            ['sales_order_line_id' => $lineB->id, 'quantity' => 1, 'condition' => 'restock'],
        ],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()
        ->assertJsonPath('total_cost', fn ($v): bool => (float) $v === 90.0)
        ->json('id');

    expect((float) $this->newLot->fresh()->quantity)->toBe(20.0)
        ->and((float) $this->oldLot->fresh()->quantity)->toBe(2.0)
        ->and(($this->returnLineSum)($returnId, '1134', 'debit'))->toBe(90.0);

    $lines = SalesReturn::with('lines')->findOrFail($returnId)->lines;
    expect((float) $lines->firstWhere('sales_order_line_id', $lineA->id)->unit_cost)->toBe(50.0)
        ->and((float) $lines->firstWhere('sales_order_line_id', $lineB->id)->unit_cost)->toBe(40.0);
});

test('a return that moves no value posts no journal', function (): void {
    $gift = InventoryItem::create([
        'name' => 'Gift Cushion',
        'code' => 'GIFT-RET',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);
    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $gift->id,
        'lot_number' => 'LOT-GIFT',
        'quantity' => 5,
        'unit_cost' => 0,
        'status' => 'available',
    ]);

    $sale = ($this->sell)([
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
            ['inventory_item_id' => $gift->id, 'quantity' => 1, 'unit_price' => 10, 'discount_amount' => 10],
        ],
    ]);
    $giftLine = $sale->lines->firstWhere('inventory_item_id', $gift->id);

    $returnId = ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $giftLine->id, 'quantity' => 1, 'condition' => 'damaged']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated()
        ->assertJsonPath('amount', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('total_cost', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('journal_entry_id', null)
        ->json('id');

    expect(JournalLine::whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $returnId))->exists())->toBeFalse()
        ->and(SalePayment::where('sales_return_id', $returnId)->exists())->toBeFalse();
});

test('a cash refund above what the sale took in is refused and writes nothing', function (): void {
    $sale = ($this->sell)(['client_id' => $this->client->id, 'walk_in_name' => null, 'payment_method' => 'receivable', 'cash_account_id' => null]);

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'RETURN_REFUND_METHOD_INVALID');

    $sale->refresh();
    expect(SalesReturn::count())->toBe(0)
        ->and(InventoryMovement::where('movement_type', 'sale_return')->count())->toBe(0)
        ->and(SalePayment::whereNotNull('sales_return_id')->count())->toBe(0)
        ->and((float) $this->newLot->fresh()->quantity)->toBe(18.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(400.0)
        ->and((float) $sale->returned_amount)->toBe(0.0);
});

test('a damaged return on a unit without a waste account is refused', function (): void {
    $sale = ($this->sell)();

    OperatingUnitAccount::where('operating_unit_id', $this->unit->id)
        ->where('event_type', InventoryEventType::Waste->value)
        ->delete();

    ($this->returnSale)($sale, [
        'lines' => [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1, 'condition' => 'damaged']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertStatus(422)->assertJsonPath('code', 'INVENTORY_ACCOUNT_NOT_LINKED');

    expect(SalesReturn::count())->toBe(0);
});
