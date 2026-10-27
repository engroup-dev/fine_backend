<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Enums\InventoryEventType;
use App\Enums\SalesOrderStatus;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Quotation;
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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Split Co', 'default_currency' => 'LYD']);
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
        'name' => 'Main Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Branch Store',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->otherUnit, '40203');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Office Desk',
        'code' => 'DSK-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom Warehouse',
        'code' => 'WH-SHW',
    ]);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    $this->lot = StockLot::create([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-SPLIT-1',
        'quantity' => 50,
        'unit_cost' => 60,
        'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Drawer',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->bankAccount = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Aman Bank Current',
        'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Tripoli Enterprises']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->accountId = fn (string $code) => Account::where('account_code', $code)->value('id');
    $this->lineSum = fn (string $orderId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $orderId)->where('source_document_type', 'SalesOrder'))
        ->where('account_id', ($this->accountId)($code))
        ->sum($side);
});

test('cash+bank split posts one entry with two treasury debits and revenue credit, two SalePayments, sale settled', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 120, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('payment_method', 'split')
        ->assertJsonPath('cash_account_id', null)
        ->assertJsonPath('status', SalesOrderStatus::Completed->value)
        ->assertJsonCount(2, 'payments');

    $saleId = $response->json('id');
    $sale = SalesOrder::findOrFail($saleId);
    expect($sale->payments)->toHaveCount(2)
        ->and((float) $this->lot->fresh()->quantity)->toBe(48.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);

    $cashPayment = $sale->payments->firstWhere('method', 'cash');
    $bankPayment = $sale->payments->firstWhere('method', 'bank');

    expect($cashPayment)->not->toBeNull()
        ->and((float) $cashPayment->amount)->toBe(80.0)
        ->and($cashPayment->cash_account_id)->toBe($this->drawer->id)
        ->and($bankPayment)->not->toBeNull()
        ->and((float) $bankPayment->amount)->toBe(120.0)
        ->and($bankPayment->cash_account_id)->toBe($this->bankAccount->id);

    $saleEntry = JournalEntry::where('source_document_type', 'SalesOrder')
        ->where('source_document_id', $saleId)
        ->sole();

    expect($cashPayment->journal_entry_id)->toBe($saleEntry->id)
        ->and($bankPayment->journal_entry_id)->toBe($saleEntry->id)
        ->and(($this->lineSum)($saleId, '121102', 'debit'))->toBe(80.0)
        ->and(($this->lineSum)($saleId, '1212', 'debit'))->toBe(120.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0)
        ->and(($this->lineSum)($saleId, '51', 'debit'))->toBe(120.0)
        ->and(($this->lineSum)($saleId, '1134', 'credit'))->toBe(120.0);
});

test('cash+cheque split creates an in-portfolio cheque, receivable nets to zero, client balance unchanged overall, cheque SalePayment linked', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 150],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            [
                'method' => 'cheque',
                'amount' => 200,
                'cheque' => [
                    'cheque_no' => 'CHQ-SPLIT-99',
                    'bank_name' => 'Aman Bank',
                    'drawer_name' => 'Tripoli Enterprises',
                    'due_date' => '2026-11-25',
                    'is_certified' => true,
                ],
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('status', SalesOrderStatus::Completed->value)
        ->assertJsonPath('payments.0.method', 'cash')
        ->assertJsonPath('payments.0.amount', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('payments.1.method', 'cheque')
        ->assertJsonPath('payments.1.amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('payments.1.cheque.cheque_no', 'CHQ-SPLIT-99')
        ->assertJsonPath('payments.1.cheque.number', fn ($v): bool => ! empty($v))
        ->assertJsonPath('payments.1.cheque.id', fn ($v): bool => ! empty($v));

    $saleId = $response->json('id');
    $chequeId = $response->json('payments.1.cheque.id');

    $cheque = Cheque::findOrFail($chequeId);
    expect($cheque->status)->toBe(ChequeStatus::InPortfolio)
        ->and($cheque->cheque_no)->toBe('CHQ-SPLIT-99')
        ->and((float) $cheque->amount)->toBe(200.0)
        ->and($cheque->client_id)->toBe($this->client->id);

    // Client balance is unchanged overall: +200 on sale entry, -200 on cheque receive
    expect((float) $this->client->fresh()->current_balance)->toBe(0.0);

    // Sale entry debits cash drawer (100) and client receivable (200), credits revenue (300)
    $saleEntry = JournalEntry::where('source_document_type', 'SalesOrder')
        ->where('source_document_id', $saleId)
        ->sole();

    $clientReceivableAccountId = $this->client->fresh()->account_id;
    $saleReceivableLine = JournalLine::where('journal_entry_id', $saleEntry->id)
        ->where('account_id', $clientReceivableAccountId)
        ->sole();
    expect((float) $saleReceivableLine->debit)->toBe(200.0)
        ->and($saleReceivableLine->client_id)->toBe($this->client->id)
        ->and(($this->lineSum)($saleId, '121102', 'debit'))->toBe(100.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(300.0);

    // Cheque receive entry debits 124 (Notes Receivable) and credits receivable
    $receiveEntry = JournalEntry::where('source_document_type', 'Cheque')
        ->where('source_document_id', $cheque->id)
        ->sole();

    $notesReceivableAccountId = ($this->accountId)('124');
    $chequeDebitLine = JournalLine::where('journal_entry_id', $receiveEntry->id)
        ->where('account_id', $notesReceivableAccountId)
        ->sole();
    $chequeCreditLine = JournalLine::where('journal_entry_id', $receiveEntry->id)
        ->where('account_id', $clientReceivableAccountId)
        ->sole();

    expect((float) $chequeDebitLine->debit)->toBe(200.0)
        ->and((float) $chequeCreditLine->credit)->toBe(200.0)
        ->and($chequeCreditLine->client_id)->toBe($this->client->id);

    // Cheque SalePayment links to cheque receive entry
    $chequePayment = SalePayment::where('sales_order_id', $saleId)->where('method', 'cheque')->sole();
    expect($chequePayment->cheque_id)->toBe($cheque->id)
        ->and($chequePayment->journal_entry_id)->toBe($receiveEntry->id)
        ->and($chequePayment->cash_account_id)->toBeNull();
});

test('three-way split across cash, bank, and cheque', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 3, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 50, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
            [
                'method' => 'cheque',
                'amount' => 150,
                'cheque' => [
                    'cheque_no' => 'CHQ-SPLIT-3WAY',
                    'bank_name' => 'Sahara Bank',
                    'due_date' => '2026-12-01',
                ],
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('status', SalesOrderStatus::Completed->value)
        ->assertJsonCount(3, 'payments');

    $saleId = $response->json('id');
    $clientReceivableCode = $this->client->fresh()->account->account_code;
    expect(SalePayment::where('sales_order_id', $saleId)->count())->toBe(3)
        ->and(($this->lineSum)($saleId, '121102', 'debit'))->toBe(50.0)
        ->and(($this->lineSum)($saleId, '1212', 'debit'))->toBe(100.0)
        ->and(($this->lineSum)($saleId, $clientReceivableCode, 'debit'))->toBe(150.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(300.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);
});

test('split payment mismatch is refused with SPLIT_TOTAL_MISMATCH and nothing is written', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'SPLIT_TOTAL_MISMATCH')
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('paid', fn ($v): bool => (float) $v === 180.0);

    expect(SalesOrder::count())->toBe(0)
        ->and(SalePayment::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(50.0);
});

test('two cash portions are refused with SPLIT_MULTIPLE_CASH', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'SPLIT_MULTIPLE_CASH');
    expect(SalesOrder::count())->toBe(0)
        ->and(SalePayment::count())->toBe(0);
});

test('cash tender below portion is refused with TENDER_BELOW_TOTAL', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id, 'tendered_amount' => 80],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'TENDER_BELOW_TOTAL')
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('tendered', fn ($v): bool => (float) $v === 80.0);

    expect(SalesOrder::count())->toBe(0);
});

test('cash portion with valid tender records change given', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id, 'tendered_amount' => 120],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $response->assertJsonPath('payments.0.tendered_amount', fn ($v): bool => (float) $v === 120.0)
        ->assertJsonPath('payments.0.change_given', fn ($v): bool => (float) $v === 20.0);

    $payment = SalePayment::where('method', 'cash')->sole();
    expect((float) $payment->tendered_amount)->toBe(120.0)
        ->and((float) $payment->change_given)->toBe(20.0);
});

test('treasury kind mismatch is refused with TREASURY_KIND_MISMATCH', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'TREASURY_KIND_MISMATCH');
    expect(SalesOrder::count())->toBe(0);
});

test('split sale requires a registered client and refuses internal transfer', function (): void {
    // Internal transfer attempt
    $internal = ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->otherUnit->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 50, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 50, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertStatus(422);

    $internal->assertJsonPath('code', 'SPLIT_NOT_ALLOWED');

    // Missing client attempt with cheque portion
    $missingClient = ($this->api)()->postJson('/api/v1/sales', [
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 50, 'cash_account_id' => $this->drawer->id],
            [
                'method' => 'cheque',
                'amount' => 50,
                'cheque' => [
                    'cheque_no' => 'CHQ-NO-CLIENT',
                    'bank_name' => 'Aman Bank',
                    'due_date' => '2026-12-01',
                ],
            ],
        ],
    ])->assertStatus(422);

    $missingClient->assertJsonPath('code', 'CLIENT_REQUIRED');
});

test('split payment via quotation conversion', function (): void {
    $quotation = Quotation::create([
        'operating_unit_id' => $this->unit->id,
        'quotation_number' => 'QUO-SPLIT-01',
        'client_id' => $this->client->id,
        'status' => Quotation::STATUS_OPEN,
        'valid_until' => now()->addDays(7),
        'created_by_user_id' => $this->user->id,
        'total_amount' => 200,
    ]);

    $quotation->lines()->create([
        'position' => 0,
        'line_type' => 'item',
        'inventory_item_id' => $this->item->id,
        'quantity' => 2,
        'unit_price' => 100,
    ]);

    $requestId = (string) Str::uuid();

    $response = ($this->api)()->postJson("/api/v1/quotations/{$quotation->id}/convert", [
        'client_request_id' => $requestId,
        'payment_method' => 'split',
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 120, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('payment_method', 'split')
        ->assertJsonCount(2, 'payments');

    expect($quotation->fresh()->status)->toBe(Quotation::STATUS_CONVERTED)
        ->and($quotation->fresh()->converted_sales_order_id)->toBe($response->json('id'));

    // Retrying with the same client_request_id returns the same sale
    $retried = ($this->api)()->postJson("/api/v1/quotations/{$quotation->id}/convert", [
        'client_request_id' => $requestId,
        'payment_method' => 'split',
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 120, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertOk();

    expect($retried->json('id'))->toBe($response->json('id'))
        ->and($retried->json('payments'))->toHaveCount(2);
});

test('daily report counts only the cash portion in expected cash', function (): void {
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 75, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 125, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $report = ($this->api)()->getJson('/api/v1/pos/daily-report')->assertOk()->json();

    expect((float) $report['expected_cash'])->toBe(75.0)
        ->and((float) $report['by_method']['split']['total'])->toBe(200.0)
        ->and((float) $report['collected']['cash']['total'])->toBe(75.0)
        ->and((float) $report['collected']['bank']['total'])->toBe(125.0);

    $drawerExpected = collect($report['expected_cash_by_account'])->firstWhere('cash_account_id', $this->drawer->id);
    expect($drawerExpected)->not->toBeNull()
        ->and((float) $drawerExpected['expected'])->toBe(75.0);
});

test('idempotent retry with the same client_request_id returns the same sale', function (): void {
    $requestId = (string) Str::uuid();

    $payload = [
        'client_request_id' => $requestId,
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 80, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 120, 'cash_account_id' => $this->bankAccount->id],
        ],
    ];

    $first = ($this->api)()->postJson('/api/v1/sales', $payload)->assertCreated();
    $second = ($this->api)()->postJson('/api/v1/sales', $payload)->assertSuccessful();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(SalesOrder::count())->toBe(1)
        ->and(SalePayment::count())->toBe(2)
        ->and((float) $this->lot->fresh()->quantity)->toBe(48.0);
});
