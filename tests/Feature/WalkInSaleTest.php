<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Enums\SalesOrderStatus;
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
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->unitA = $makeUnit('Showroom A');
    $this->unitB = $makeUnit('Showroom B');
    linkRevenueAccount($this->unitA, '40204');
    linkRevenueAccount($this->unitB, '40203');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-01', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Store WH', 'code' => 'WH-A']);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unitA->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    StockLot::create([
        'warehouse_id' => $warehouse->id, 'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-01', 'quantity' => 10, 'unit_cost' => 50, 'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->bankAccount = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Aman Bank', 'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Waha Traders']);
    $this->client = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 1000, 'current_balance' => 0,
    ]);

    $this->api = fn (OperatingUnit $unit) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $unit->id]);

    $this->lot = StockLot::withoutGlobalScopes()->where('lot_number', 'LOT-01')->sole();
    $this->accountId = fn (string $code) => Account::where('account_code', $code)->value('id');
    $this->lineSum = fn (string $orderId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $orderId)->where('source_document_type', 'SalesOrder'))
        ->where('account_id', ($this->accountId)($code))
        ->sum($side);
});

test('walk-in cash sale posts DR drawer, CR revenue, COGS and stores buyer_type walk_in, name and phone', function (): void {
    $response = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'walk_in_name' => '  Omar Al-Mukhtar  ',
        'walk_in_phone' => '  0912345678  ',
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $response->assertJsonPath('buyer_type', 'walk_in')
        ->assertJsonPath('client_id', null)
        ->assertJsonPath('client', null)
        ->assertJsonPath('walk_in_name', 'Omar Al-Mukhtar')
        ->assertJsonPath('walk_in_phone', '0912345678')
        ->assertJsonPath('status', SalesOrderStatus::Completed->value)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 200.0);

    $saleId = $response->json('id');

    expect((float) $this->lot->fresh()->quantity)->toBe(8.0)
        ->and(($this->lineSum)($saleId, '121102', 'debit'))->toBe(200.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0)
        ->and(($this->lineSum)($saleId, '51', 'debit'))->toBe(100.0)
        ->and(($this->lineSum)($saleId, '1134', 'credit'))->toBe(100.0);

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $saleId)->sole();
    expect($payment->client_id)->toBeNull()
        ->and((float) $payment->amount)->toBe(200.0);
});

test('walk-in bank sale posts DR bank account and settles immediately', function (): void {
    $response = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'payment_method' => 'bank',
        'cash_account_id' => $this->bankAccount->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 150],
        ],
    ])->assertCreated();

    $response->assertJsonPath('buyer_type', 'walk_in')
        ->assertJsonPath('client_id', null)
        ->assertJsonPath('walk_in_name', null)
        ->assertJsonPath('walk_in_phone', null)
        ->assertJsonPath('payment_method', 'bank')
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 150.0);

    $saleId = $response->json('id');

    expect((float) $this->lot->fresh()->quantity)->toBe(9.0)
        ->and(($this->lineSum)($saleId, '1212', 'debit'))->toBe(150.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(150.0)
        ->and(($this->lineSum)($saleId, '51', 'debit'))->toBe(50.0);
});

test('walk-in cash and bank split sale posts two treasury debits and two payments', function (): void {
    $response = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'walk_in_name' => 'Salma',
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 120, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $response->assertJsonPath('buyer_type', 'walk_in')
        ->assertJsonPath('client_id', null)
        ->assertJsonPath('walk_in_name', 'Salma')
        ->assertJsonPath('payment_method', 'split')
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonCount(2, 'payments');

    $saleId = $response->json('id');

    expect(($this->lineSum)($saleId, '121102', 'debit'))->toBe(80.0)
        ->and(($this->lineSum)($saleId, '1212', 'debit'))->toBe(120.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0);
});

test('walk-in with tender returns change and refuses tender below total', function (): void {
    ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 90,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_BELOW_TOTAL');

    $response = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 150,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $response->assertJsonPath('payments.0.tendered_amount', fn ($v): bool => (float) $v === 150.0)
        ->assertJsonPath('payments.0.change_given', fn ($v): bool => (float) $v === 50.0);
});

test('walk-in receivable or cheque is refused with CLIENT_REQUIRED and nothing is written', function (): void {
    foreach (['receivable', 'cheque'] as $method) {
        ($this->api)($this->unitA)->postJson('/api/v1/sales', [
            'walk_in_name' => 'Anon Buyer',
            'payment_method' => $method,
            'lines' => [
                ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIENT_REQUIRED')
            ->assertJsonPath('message', 'A registered client is required for credit or cheque sales.');
    }

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(10.0);
});

test('walk-in cheque split portion is refused with CLIENT_REQUIRED and nothing is written', function (): void {
    ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            [
                'method' => 'cheque',
                'amount' => 100,
                'cheque' => [
                    'cheque_no' => 'CHQ-WALKIN-01',
                    'bank_name' => 'Aman Bank',
                    'due_date' => '2026-12-01',
                ],
            ],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CLIENT_REQUIRED')
        ->assertJsonPath('message', 'A registered client is required for credit or cheque sales.');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(10.0);
});

test('daily report and daily close count the walk-in sale and its cash', function (): void {
    // One client cash sale (100) + one walk-in cash sale (200)
    ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'walk_in_name' => 'Walk-in Buyer',
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $report = ($this->api)($this->unitA)->getJson('/api/v1/pos/daily-report')->assertOk();
    $report->assertJsonPath('sales_count', 2)
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 300.0);

    $close = ($this->api)($this->unitA)->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 300,
    ])->assertCreated();

    $close->assertJsonPath('sales_count', 2)
        ->assertJsonPath('total_sales', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('expected_cash', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === 0.0);
});

test('sale show and index return a walk-in sale with null client and support search by walk-in name or phone', function (): void {
    $created = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'walk_in_name' => 'Khaled Ibrahim',
        'walk_in_phone' => '0925551122',
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated()->json();

    $show = ($this->api)($this->unitA)->getJson("/api/v1/sales/{$created['id']}")->assertOk();
    $show->assertJsonPath('id', $created['id'])
        ->assertJsonPath('buyer_type', 'walk_in')
        ->assertJsonPath('client_id', null)
        ->assertJsonPath('client', null)
        ->assertJsonPath('walk_in_name', 'Khaled Ibrahim')
        ->assertJsonPath('walk_in_phone', '0925551122');

    $index = ($this->api)($this->unitA)->getJson('/api/v1/sales?search=Khaled')->assertOk();
    $index->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created['id'])
        ->assertJsonPath('data.0.client', null)
        ->assertJsonPath('data.0.walk_in_name', 'Khaled Ibrahim');

    $byPhone = ($this->api)($this->unitA)->getJson('/api/v1/sales?search=0925551122')->assertOk();
    $byPhone->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created['id']);
});

test('walk_in_name and walk_in_phone are ignored for a client sale', function (): void {
    $response = ($this->api)($this->unitA)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'walk_in_name' => 'Should Be Ignored',
        'walk_in_phone' => '0910000000',
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $response->assertJsonPath('buyer_type', 'client')
        ->assertJsonPath('client_id', $this->client->id)
        ->assertJsonPath('walk_in_name', null)
        ->assertJsonPath('walk_in_phone', null);
});
