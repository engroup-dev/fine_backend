<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CreditApprovalRequest;
use App\Models\Entity;
use App\Models\InstallmentPlan;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\InstallmentPlanService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');

    $this->company = Company::create(['name' => 'Installments Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
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

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-INS', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ]);
    $warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Store WH', 'code' => 'WH-INS']);
    StockLot::create([
        'warehouse_id' => $warehouse->id, 'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-INS', 'quantity' => 50, 'unit_cost' => 50, 'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $makeClient = fn (string $name) => Client::create([
        'entity_id' => Entity::create(['entity_type' => 'organization', 'name' => $name])->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1000, 'current_balance' => 0,
    ]);
    $this->client = $makeClient('Al-Waha Traders');
    $this->otherClient = $makeClient('Noor Furniture');

    $this->api = fn () => $this->actingAs($this->user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->sell = fn (array $extra = [], float $quantity = 1, float $price = 100) => ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ...$extra,
    ]);

    $this->collect = fn (string $saleId, float $amount) => ($this->api)()->postJson("/api/v1/sales/{$saleId}/payments", [
        'amount' => $amount, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertOk();
});

test('plan math splits evenly and the last installment absorbs the rounding', function () {
    $lines = app(InstallmentPlanService::class)->buildLines(100, 3, '2027-01-31');

    expect(array_column($lines, 'amount'))->toBe([33.333, 33.333, 33.334])
        ->and(array_column($lines, 'due_date'))->toBe(['2027-01-31', '2027-02-28', '2027-03-31'])
        ->and(array_column($lines, 'number'))->toBe([1, 2, 3])
        ->and(round(array_sum(array_column($lines, 'amount')), 4))->toBe(100.0);

    // Exact splits stay even: no float flooring drift on the base installment.
    $service = app(InstallmentPlanService::class);
    expect(array_column($service->buildLines(350.7, 3, '2027-01-31'), 'amount'))->toBe([116.9, 116.9, 116.9])
        ->and(array_column($service->buildLines(2400.6, 6, '2027-01-31'), 'amount'))->toBe(array_fill(0, 6, 400.1))
        ->and(array_column($service->buildLines(0.3, 3, '2027-01-31'), 'amount'))->toBe([0.1, 0.1, 0.1]);
});

test('a receivable checkout with a plan posts as today, collects the down payment and schedules the rest', function () {
    $sale = ($this->sell)([
        'installment_plan' => [
            'count' => 3, 'first_due_date' => '2026-12-01',
            'down_payment' => 100, 'down_payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
        ],
    ], 10, 100)->assertCreated()->json();

    $plan = InstallmentPlan::withoutGlobalScopes()->with('lines')->where('sales_order_id', $sale['id'])->sole();

    expect((float) $sale['amount_paid'])->toBe(100.0)
        ->and($sale['installment_plan']['id'])->toBe($plan->id)
        ->and((float) $plan->financed_amount)->toBe(900.0)
        ->and((float) $plan->down_payment)->toBe(100.0)
        ->and($plan->lines->map(fn ($l) => [$l->due_date->toDateString(), (float) $l->amount])->all())
        ->toBe([['2026-12-01', 300.0], ['2027-01-01', 300.0], ['2027-02-01', 300.0]])
        ->and((float) $this->client->fresh()->current_balance)->toBe(900.0);

    // The sale itself posted the full receivable; the down payment is an ordinary collection.
    $receivable = $this->client->fresh()->account_id;
    $debits = (float) JournalLine::query()->where('account_id', $receivable)->sum('debit');
    $credits = (float) JournalLine::query()->where('account_id', $receivable)->sum('credit');
    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();

    expect($debits)->toBe(1000.0)
        ->and($credits)->toBe(100.0)
        ->and((float) $payment->amount)->toBe(100.0)
        ->and($payment->method)->toBe('cash');
});

test('collections pay installments oldest first and late ones turn overdue', function () {
    $sale = ($this->sell)(['installment_plan' => ['count' => 3, 'first_due_date' => '2026-12-01']], 6, 100)
        ->assertCreated()->json();

    ($this->collect)($sale['id'], 300);

    $schedule = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');

    expect(array_column($schedule['lines'], 'status'))->toBe(['paid', 'partial', 'upcoming'])
        ->and(array_column($schedule['lines'], 'paid_amount'))->toEqual([200, 100, 0])
        ->and(array_column($schedule['lines'], 'remaining'))->toEqual([0, 100, 200])
        ->and($schedule['totals']['remaining'])->toEqual(300)
        ->and($schedule['lines'][1]['status_label'])->toBe('مدفوع جزئياً');

    $this->travelTo('2027-01-15 10:00:00');

    $later = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');

    expect(array_column($later['lines'], 'status'))->toBe(['paid', 'overdue', 'upcoming'])
        ->and($later['lines'][1]['days_overdue'])->toBe(14)
        ->and($later['totals']['overdue'])->toEqual(100);

    ($this->collect)($sale['id'], 300);

    $settled = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');

    expect(array_column($settled['lines'], 'status'))->toBe(['paid', 'paid', 'paid'])
        ->and($settled['totals']['remaining'])->toEqual(0);
});

test('the installments report lists due and overdue lines with days overdue and totals', function () {
    // An older sale whose installments started before today.
    $this->travelTo('2026-09-20 10:00:00');
    $first = ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-10-01']], 4, 100)
        ->assertCreated()->json();
    $this->travelTo('2026-11-15 10:00:00');
    ($this->sell)([
        'client_id' => $this->otherClient->id,
        'installment_plan' => ['count' => 2, 'first_due_date' => '2026-12-10'],
    ], 2, 100)->assertCreated();
    // Plain open credit is not part of the report.
    ($this->sell)([], 1, 100)->assertCreated();

    ($this->collect)($first['id'], 50);

    $due = ($this->api)()->getJson('/api/v1/installments')->assertOk()->json();

    expect($due['status'])->toBe('due')
        ->and($due['totals']['count'])->toBe(4)
        ->and($due['totals']['remaining'])->toEqual(550)
        ->and($due['totals']['overdue'])->toEqual(350)
        ->and(array_column($due['data'], 'due_date'))->toBe(['2026-10-01', '2026-11-01', '2026-12-10', '2027-01-10']);

    $overdue = ($this->api)()->getJson('/api/v1/installments?status=overdue')->assertOk()->json();

    expect($overdue['totals']['count'])->toBe(2)
        ->and($overdue['data'][0]['days_overdue'])->toBe(45)
        ->and($overdue['data'][0]['paid_amount'])->toEqual(50)
        ->and($overdue['data'][0]['remaining'])->toEqual(150)
        ->and($overdue['data'][0]['client_name'])->toBe('Al-Waha Traders')
        ->and($overdue['data'][0]['order_number'])->toBe($first['order_number'])
        ->and($overdue['data'][1]['days_overdue'])->toBe(14);

    $forClient = ($this->api)()->getJson("/api/v1/installments?status=all&client_id={$this->otherClient->id}")->assertOk()->json();
    expect($forClient['totals']['count'])->toBe(2)
        ->and(array_unique(array_column($forClient['data'], 'client_id')))->toBe([$this->otherClient->id]);

    $ranged = ($this->api)()->getJson('/api/v1/installments?from=2026-11-01&to=2026-12-31')->assertOk()->json();
    expect(array_column($ranged['data'], 'due_date'))->toBe(['2026-11-01', '2026-12-10']);

    $onTheDay = ($this->api)()->getJson('/api/v1/installments?status=all&from=2026-12-10&to=2026-12-10')->assertOk()->json();
    expect(array_column($onTheDay['data'], 'due_date'))->toBe(['2026-12-10']);

    // A settled sale drops out of the due list but stays under `all`.
    ($this->collect)($first['id'], 350);
    $afterSettling = ($this->api)()->getJson('/api/v1/installments')->assertOk()->json();
    expect(array_column($afterSettling['data'], 'due_date'))->toBe(['2026-12-10', '2027-01-10'])
        ->and(($this->api)()->getJson('/api/v1/installments?status=all')->assertOk()->json('totals.count'))->toBe(4);

    ($this->api)()->getJson('/api/v1/installments?status=late')->assertUnprocessable();
});

test('cash and walk-in sales cannot carry installments', function () {
    $plan = ['count' => 3, 'first_due_date' => '2026-12-01'];

    ($this->sell)(['payment_method' => 'cash', 'cash_account_id' => $this->drawer->id, 'installment_plan' => $plan])
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENTS_NEED_RECEIVABLE');

    ($this->api)()->postJson('/api/v1/sales', [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'installment_plan' => $plan,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100]],
    ])->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENTS_NEED_RECEIVABLE');

    $cashSale = ($this->sell)(['payment_method' => 'cash', 'cash_account_id' => $this->drawer->id])->assertCreated()->json();
    ($this->api)()->postJson("/api/v1/sales/{$cashSale['id']}/installment-plan", $plan)
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENTS_NEED_RECEIVABLE');

    expect(InstallmentPlan::withoutGlobalScopes()->count())->toBe(0);
});

test('an existing receivable sale can be put on installments over what it still owes, once', function () {
    $sale = ($this->sell)([], 6, 100)->assertCreated()->json();
    ($this->collect)($sale['id'], 200);

    $schedule = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/installment-plan", [
        'count' => 2, 'first_due_date' => '2026-12-15',
        'down_payment' => 100, 'down_payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertCreated()->json();

    expect($schedule['financed_amount'])->toEqual(300)
        ->and($schedule['down_payment'])->toEqual(100)
        ->and(array_column($schedule['lines'], 'amount'))->toEqual([150, 150])
        ->and(array_column($schedule['lines'], 'status'))->toBe(['upcoming', 'upcoming'])
        ->and((float) SalesOrder::withoutGlobalScopes()->find($sale['id'])->amount_paid)->toBe(300.0);

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/installment-plan", ['count' => 2, 'first_due_date' => '2026-12-15'])
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENT_PLAN_EXISTS');

    $paid = ($this->sell)([], 1, 100)->assertCreated()->json();
    ($this->collect)($paid['id'], 100);
    ($this->api)()->postJson("/api/v1/sales/{$paid['id']}/installment-plan", ['count' => 2, 'first_due_date' => '2026-12-15'])
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENT_NOTHING_OUTSTANDING');
});

test('a down payment must leave something to schedule', function () {
    ($this->sell)(['installment_plan' => [
        'count' => 2, 'first_due_date' => '2026-12-01',
        'down_payment' => 300, 'down_payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ]], 3, 100)->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENT_DOWN_PAYMENT_INVALID');

    ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-12-01', 'down_payment' => 50]], 3, 100)
        ->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('a down payment taken at checkout counts against the credit limit', function () {
    // Limit 1000: 1200 less a 300 down payment leaves 900 on credit.
    $sale = ($this->sell)(['installment_plan' => [
        'count' => 3, 'first_due_date' => '2026-12-01',
        'down_payment' => 300, 'down_payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ]], 12, 100)->assertCreated()->json();

    expect($sale['status'])->not->toBe('pending_approval')
        ->and((float) $sale['amount_paid'])->toBe(300.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(900.0);
});

test('the first installment cannot fall due before the sale', function () {
    ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-11-14']], 2, 100)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INSTALLMENT_FIRST_DUE_DATE_INVALID');

    // Due on the day of the sale is allowed.
    ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-11-15']], 2, 100)->assertCreated();

    // An older sale can be scheduled from its own date, not before it.
    $this->travelTo('2026-10-01 10:00:00');
    $older = ($this->sell)([], 2, 100)->assertCreated()->json();
    $this->travelTo('2026-11-15 10:00:00');

    ($this->api)()->postJson("/api/v1/sales/{$older['id']}/installment-plan", ['count' => 2, 'first_due_date' => '2026-09-30'])
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENT_FIRST_DUE_DATE_INVALID');
    ($this->api)()->postJson("/api/v1/sales/{$older['id']}/installment-plan", ['count' => 2, 'first_due_date' => '2026-10-01'])
        ->assertCreated();
});

test('a plan too small to split into the chosen count is refused', function () {
    expect(fn () => app(InstallmentPlanService::class)->buildLines(0.002, 3, '2026-12-01'))
        ->toThrow(SalesRuleException::class, 'at most 2');

    expect(array_column(app(InstallmentPlanService::class)->buildLines(0.002, 2, '2026-12-01'), 'amount'))
        ->toBe([0.001, 0.001]);
});

test('sale responses carry the derived schedule in one shape', function () {
    $sale = ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-12-01']], 2, 100)
        ->assertCreated()->json();

    expect($sale['installment_plan']['lines'][0]['status'])->toBe('upcoming')
        ->and($sale['installment_plan']['totals']['remaining'])->toEqual(200)
        ->and($sale['installment_plan'])->not->toHaveKey('opening_paid');

    $collected = ($this->collect)($sale['id'], 150)->json();
    $shown = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');

    expect(array_column($collected['installment_plan']['lines'], 'status'))->toBe(['paid', 'partial'])
        ->and($collected['installment_plan'])->toEqual($shown);

    $plain = ($this->sell)([], 1, 100)->assertCreated()->json();
    expect($plain)->toHaveKey('installment_plan')
        ->and($plain['installment_plan'])->toBeNull();
});

test('an over-limit sale with a plan is refused until credit is approved', function () {
    ($this->sell)(['installment_plan' => ['count' => 3, 'first_due_date' => '2026-12-01']], 12, 100)
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENT_CREDIT_PENDING');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(InstallmentPlan::withoutGlobalScopes()->count())->toBe(0);
});

test('an over-limit sale goes to approval without a plan, then takes the plan once approved', function () {
    // Deliberate: checkout refuses a plan while credit is pending (nothing posts,
    // so no down payment can be taken). The recovery is pending -> approve -> add plan.
    $sale = ($this->sell)([], 12, 100)->assertCreated()->json();
    expect($sale['status'])->toBe('pending_approval');

    $plan = ['count' => 3, 'first_due_date' => '2026-12-01'];
    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/installment-plan", $plan)
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALLMENTS_NEED_RECEIVABLE');

    $approval = CreditApprovalRequest::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();
    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval->id}/approve")->assertOk();

    $schedule = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/installment-plan", $plan)
        ->assertCreated()->json();

    expect($schedule['financed_amount'])->toEqual(1200)
        ->and(array_column($schedule['lines'], 'amount'))->toEqual([400, 400, 400])
        ->and($schedule['totals']['remaining'])->toEqual(1200);
});

test('a return cuts the schedule from the last installment backwards', function () {
    $sale = ($this->sell)(['installment_plan' => ['count' => 2, 'first_due_date' => '2026-12-01']], 3, 100)
        ->assertCreated()->json();
    $lineId = $sale['lines'][0]['id'];

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'credit',
    ])->assertCreated();

    $schedule = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');
    $order = SalesOrder::withoutGlobalScopes()->find($sale['id']);

    expect(array_column($schedule['lines'], 'status'))->toBe(['upcoming', 'upcoming'])
        ->and(array_column($schedule['lines'], 'amount'))->toEqual([150, 50])
        ->and($schedule['totals']['remaining'])->toEqual(200)
        ->and($schedule['totals']['remaining'])->toEqual($order->outstanding());

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1, 'condition' => 'restock']],
        'refund_method' => 'credit',
    ])->assertCreated();

    $after = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');

    expect(array_column($after['lines'], 'status'))->toBe(['upcoming', 'cancelled'])
        ->and(array_column($after['lines'], 'amount'))->toEqual([100, 0])
        ->and($after['lines'][1]['status_label'])->toBe('ملغى بالمرتجع')
        ->and($after['totals']['remaining'])->toEqual(100);
});

test('a cash refund out of the down payment leaves the schedule equal to what is owed', function () {
    // 1000 sale, 400 down, 3 x 200; then 3 pieces (300) returned and refunded in cash.
    $sale = ($this->sell)(['installment_plan' => [
        'count' => 3, 'first_due_date' => '2026-12-01',
        'down_payment' => 400, 'down_payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ]], 10, 100)->assertCreated()->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $sale['lines'][0]['id'], 'quantity' => 3, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    $schedule = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');
    $order = SalesOrder::withoutGlobalScopes()->find($sale['id']);

    expect($order->outstanding())->toBe(600.0)
        ->and($schedule['totals']['remaining'])->toEqual($order->outstanding())
        ->and(array_column($schedule['lines'], 'amount'))->toEqual([200, 200, 200])
        ->and(array_column($schedule['lines'], 'status'))->toBe(['upcoming', 'upcoming', 'upcoming']);

    // A later collection, then a cash refund larger than it: the refund's excess is not a cut.
    ($this->collect)($sale['id'], 100);
    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['sales_order_line_id' => $sale['lines'][0]['id'], 'quantity' => 2, 'condition' => 'restock']],
        'refund_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    $after = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json('installment_plan');
    $order->refresh();

    expect($order->outstanding())->toBe(500.0)
        ->and($after['totals']['remaining'])->toEqual($order->outstanding())
        ->and(array_column($after['lines'], 'amount'))->toEqual([200, 200, 100])
        ->and(array_column($after['lines'], 'status'))->toBe(['upcoming', 'upcoming', 'upcoming']);
});
