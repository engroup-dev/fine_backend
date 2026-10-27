<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Enums\SalesOrderStatus;
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
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Support\SalesAccounts;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Discount Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Store',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->otherUnit, '40203');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa Set',
        'code' => 'SOFA-DSC',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom WH',
        'code' => 'WH-DSC',
    ]);

    Warehouse::create([
        'operating_unit_id' => $this->otherUnit->id,
        'name' => 'Furniture WH',
        'code' => 'WH-FURN',
    ]);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::SalesReturns, '40404'],
        [InventoryEventType::GrantedDiscount, '40704'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    $this->lot = StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-DSC-01',
        'quantity' => 20,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom Drawer',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->bankAccount = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom Bank',
        'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Amal Co']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1000,
        'current_balance' => 0,
    ]);

    $this->api = fn (?OperatingUnit $unit = null) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unit)->id]);

    $this->accountId = fn (string $code) => Account::where('account_code', $code)->value('id');
    $this->lineSum = fn (string $orderId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $orderId)->where('source_document_type', 'SalesOrder'))
        ->where('account_id', ($this->accountId)($code))
        ->sum($side);
});

test('line discount posts gross revenue, discount DR on the unit 405 leaf, and treasury DR equal to net', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 25,
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 175.0)
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 175.0)
        ->assertJsonPath('lines.0.discount_amount', fn ($v): bool => (float) $v === 25.0);

    $saleId = $response->json('id');
    $sale = SalesOrder::with('lines')->findOrFail($saleId);

    expect($sale->lines->first()->lineTotal())->toBe(175.0)
        ->and(($this->lineSum)($saleId, '121102', 'debit'))->toBe(175.0)
        ->and(($this->lineSum)($saleId, '40704', 'debit'))->toBe(25.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0)
        ->and(($this->lineSum)($saleId, '51', 'debit'))->toBe(100.0)
        ->and(($this->lineSum)($saleId, '1134', 'credit'))->toBe(100.0);

    $discountLine = JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $saleId))
        ->where('account_id', ($this->accountId)('40704'))
        ->sole();

    expect($discountLine->operating_unit_id)->toBe($this->unit->id);

    $invoice = ($this->api)()->getJson("/api/v1/sales/{$saleId}/invoice")->assertOk();
    $invoice->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 175.0)
        ->assertJsonPath('lines.0.discount_amount', fn ($v): bool => (float) $v === 25.0)
        ->assertJsonPath('lines.0.line_total', fn ($v): bool => (float) $v === 175.0);
});

test('invoice discount alone posts gross revenue, discount DR on 40704, and treasury DR equal to net', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 30,
        'lines' => [
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 2,
                'unit_price' => 100,
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 30.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 170.0)
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 170.0)
        ->assertJsonPath('lines.0.discount_amount', fn ($v): bool => (float) $v === 0.0);

    $saleId = $response->json('id');

    expect(($this->lineSum)($saleId, '121102', 'debit'))->toBe(170.0)
        ->and(($this->lineSum)($saleId, '40704', 'debit'))->toBe(30.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0);
});

test('line and invoice discounts together combine on the unit 405 leaf', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 15,
        'lines' => [
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 20,
            ],
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 100,
                'discount_amount' => 5,
            ],
        ],
    ])->assertCreated();

    // Gross = 300, line discounts = 25, subtotal = 275, invoice discount = 15, net = 260
    $response->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 15.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 260.0)
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 260.0);

    $saleId = $response->json('id');

    expect(($this->lineSum)($saleId, '121102', 'debit'))->toBe(260.0)
        ->and(($this->lineSum)($saleId, '40704', 'debit'))->toBe(40.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(300.0);
});

test('cash tender and split portions validate against the net total', function (): void {
    // Gross = 200, net = 160; tender of 150 fails against 160
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 150,
        'invoice_discount' => 15,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 25],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_BELOW_TOTAL')
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 160.0)
        ->assertJsonPath('tendered', fn ($v): bool => (float) $v === 150.0);

    // Tender of 180 against net 160 succeeds with change 20
    $cashSale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 180,
        'invoice_discount' => 15,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 25],
        ],
    ])->assertCreated();

    $cashSale->assertJsonPath('payments.0.tendered_amount', fn ($v): bool => (float) $v === 180.0)
        ->assertJsonPath('payments.0.change_given', fn ($v): bool => (float) $v === 20.0);

    // Split portions summing to gross (200) instead of net (160) fail with SPLIT_TOTAL_MISMATCH
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'invoice_discount' => 15,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 25],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'SPLIT_TOTAL_MISMATCH')
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 160.0)
        ->assertJsonPath('paid', fn ($v): bool => (float) $v === 200.0);

    // Split portions summing to net (160) succeed and post DR 60 + DR 100 + DR 40 discount = CR 200 revenue
    $splitSale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'split',
        'invoice_discount' => 15,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 25],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 60, 'cash_account_id' => $this->drawer->id],
            ['method' => 'bank', 'amount' => 100, 'cash_account_id' => $this->bankAccount->id],
        ],
    ])->assertCreated();

    $splitId = $splitSale->json('id');
    expect(($this->lineSum)($splitId, '121102', 'debit'))->toBe(60.0)
        ->and(($this->lineSum)($splitId, '1212', 'debit'))->toBe(100.0)
        ->and(($this->lineSum)($splitId, '40704', 'debit'))->toBe(40.0)
        ->and(($this->lineSum)($splitId, '40204', 'credit'))->toBe(200.0);
});

test('receivable sale with discount raises client balance by the net total and checks credit limit against net', function (): void {
    // Client credit limit is 1000. Gross is 1200, discount is 300, net is 900 (passes credit limit!).
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'invoice_discount' => 100,
        'lines' => [
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 3,
                'unit_price' => 400,
                'discount_amount' => 200,
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('status', SalesOrderStatus::Open->value)
        ->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 1200.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 900.0);

    $saleId = $response->json('id');
    $clientReceivableCode = $this->client->fresh()->account->account_code;

    expect((float) $this->client->fresh()->current_balance)->toBe(900.0)
        ->and(SalesOrder::find($saleId)->outstanding())->toBe(900.0)
        ->and(($this->lineSum)($saleId, $clientReceivableCode, 'debit'))->toBe(900.0)
        ->and(($this->lineSum)($saleId, '40704', 'debit'))->toBe(300.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(1200.0);
});

test('DISCOUNT_INVALID is returned when line discount exceeds line gross or invoice discount exceeds subtotal, with nothing written', function (): void {
    // Line discount (250) > qty * unit_price (200) on line at position 1
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 250],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'DISCOUNT_INVALID')
        ->assertJsonPath('position', 1);

    // Invoice discount (160) > subtotal after line discount (200 - 50 = 150)
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 160,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 50],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'DISCOUNT_INVALID');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(20.0);
});

test('internal transfer refuses any line or invoice discount with DISCOUNT_INVALID', function (): void {
    ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->otherUnit->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 50, 'discount_amount' => 5],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'DISCOUNT_INVALID')
        ->assertJsonPath('position', 0);

    ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->otherUnit->id,
        'invoice_discount' => 5,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 50],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'DISCOUNT_INVALID');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(20.0);
});

test('quotation with line and invoice discounts stores and converts to a sale with the same amounts', function (): void {
    $quoteResponse = ($this->api)()->postJson('/api/v1/quotations', [
        'client_id' => $this->client->id,
        'invoice_discount' => 10,
        'lines' => [
            [
                'inventory_item_id' => $this->item->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 20,
            ],
        ],
    ])->assertCreated();

    $quoteResponse->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 10.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 170.0)
        ->assertJsonPath('lines.0.discount_amount', fn ($v): bool => (float) $v === 20.0);

    $quotationId = $quoteResponse->json('id');
    expect(Quotation::with('lines')->findOrFail($quotationId)->lines->first()->lineTotal())->toBe(180.0);

    $saleResponse = ($this->api)()->postJson("/api/v1/quotations/{$quotationId}/convert", [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertCreated();

    $saleResponse->assertJsonPath('quotation_id', $quotationId)
        ->assertJsonPath('gross_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('invoice_discount', fn ($v): bool => (float) $v === 10.0)
        ->assertJsonPath('total_amount', fn ($v): bool => (float) $v === 170.0)
        ->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 170.0)
        ->assertJsonPath('lines.0.discount_amount', fn ($v): bool => (float) $v === 20.0);

    $saleId = $saleResponse->json('id');
    expect(($this->lineSum)($saleId, '121102', 'debit'))->toBe(170.0)
        ->and(($this->lineSum)($saleId, '40704', 'debit'))->toBe(30.0)
        ->and(($this->lineSum)($saleId, '40204', 'credit'))->toBe(200.0);
});

test('a unit with no discount mapping or mapped to its revenue account but revenue 40204 resolves 40704', function (): void {
    OperatingUnitAccount::where('operating_unit_id', $this->unit->id)
        ->where('event_type', InventoryEventType::GrantedDiscount->value)
        ->delete();

    expect(SalesAccounts::discountFor($this->unit->fresh()))->toBe('40704');

    // Even if a legacy row points GrantedDiscount at the unit's own revenue account (40204), it derives 40704
    OperatingUnitAccount::create([
        'operating_unit_id' => $this->unit->id,
        'event_type' => InventoryEventType::GrantedDiscount->value,
        'account_id' => $this->unit->revenue_account_id,
    ]);

    expect(SalesAccounts::discountFor($this->unit->fresh()))->toBe('40704');

    $sale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 20,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated()->json();

    expect(($this->lineSum)($sale['id'], '40704', 'debit'))->toBe(20.0)
        ->and(($this->lineSum)($sale['id'], '40204', 'credit'))->toBe(100.0);
});

test('a unit with an unrelated revenue account sells without a discount and refuses UNIT_DISCOUNT_NOT_LINKED only when a discount is given', function (): void {
    $revenueHeader = Account::where('account_code', '41')->sole();
    $customRevenue = Account::create([
        'chart_of_accounts_id' => $revenueHeader->chart_of_accounts_id,
        'parent_account_id' => $revenueHeader->id,
        'account_code' => '40299',
        'name' => 'مبيعات خاصة',
        'type' => 'revenue',
        'currency' => 'LYD',
    ]);

    $this->unit->update(['revenue_account_id' => $customRevenue->id]);
    OperatingUnitAccount::where('operating_unit_id', $this->unit->id)
        ->where('event_type', InventoryEventType::GrantedDiscount->value)
        ->delete();

    // Sale without discount succeeds and does not need a discount account
    $undiscounted = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated()->json();

    expect(($this->lineSum)($undiscounted['id'], '40299', 'credit'))->toBe(100.0);

    // Sale with discount refuses UNIT_DISCOUNT_NOT_LINKED and rolls back
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'invoice_discount' => 10,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'UNIT_DISCOUNT_NOT_LINKED');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(1)
        ->and((float) $this->lot->fresh()->quantity)->toBe(19.0);
});

test('migration remaps SalesReturns and GrantedDiscount for units on 4020N and leaves other revenue accounts untouched', function (): void {
    // Point unit (40204) mappings at its own revenue account (old bootstrap behaviour)
    // and delete otherUnit (40203) mappings so the migration both updates and creates rows.
    OperatingUnitAccount::where('operating_unit_id', $this->unit->id)
        ->whereIn('event_type', [InventoryEventType::SalesReturns->value, InventoryEventType::GrantedDiscount->value])
        ->update(['account_id' => $this->unit->revenue_account_id]);

    OperatingUnitAccount::where('operating_unit_id', $this->otherUnit->id)
        ->whereIn('event_type', [InventoryEventType::SalesReturns->value, InventoryEventType::GrantedDiscount->value])
        ->delete();

    $revenueHeader = Account::where('account_code', '41')->sole();
    $customRevenue = Account::create([
        'chart_of_accounts_id' => $revenueHeader->chart_of_accounts_id,
        'parent_account_id' => $revenueHeader->id,
        'account_code' => '40299',
        'name' => 'مبيعات خاصة',
        'type' => 'revenue',
        'currency' => 'LYD',
    ]);
    $customUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Custom Unit',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
        'revenue_account_id' => $customRevenue->id,
    ]);

    $migration = require database_path('migrations/2026_10_28_130000_add_sales_returns_and_discount_accounts.php');
    $migration->up();

    expect($this->unit->fresh()->accountFor(InventoryEventType::SalesReturns)?->account_code)->toBe('40404')
        ->and($this->unit->fresh()->accountFor(InventoryEventType::GrantedDiscount)?->account_code)->toBe('40704')
        ->and($this->otherUnit->fresh()->accountFor(InventoryEventType::SalesReturns)?->account_code)->toBe('40403')
        ->and($this->otherUnit->fresh()->accountFor(InventoryEventType::GrantedDiscount)?->account_code)->toBe('40703')
        ->and($customUnit->fresh()->accountFor(InventoryEventType::SalesReturns))->toBeNull()
        ->and($customUnit->fresh()->accountFor(InventoryEventType::GrantedDiscount))->toBeNull();
});

test('SalesAccounts::returnsFor resolves mapped account, derives 4040N from 4020N, and refuses UNIT_RETURNS_NOT_LINKED otherwise', function (): void {
    expect(SalesAccounts::returnsFor($this->unit))->toBe('40404');

    // When unmapped, derives from 40203 -> 40403
    OperatingUnitAccount::where('operating_unit_id', $this->otherUnit->id)
        ->where('event_type', InventoryEventType::SalesReturns->value)
        ->delete();
    expect(SalesAccounts::returnsFor($this->otherUnit->fresh()))->toBe('40403');

    // When mapped to the unit's own revenue account, still derives 40403 (never returns the revenue account)
    OperatingUnitAccount::create([
        'operating_unit_id' => $this->otherUnit->id,
        'event_type' => InventoryEventType::SalesReturns->value,
        'account_id' => $this->otherUnit->revenue_account_id,
    ]);
    expect(SalesAccounts::returnsFor($this->otherUnit->fresh()))->toBe('40403');

    // Unrelated revenue account with no returns mapping throws UNIT_RETURNS_NOT_LINKED
    $revenueHeader = Account::where('account_code', '41')->sole();
    $customRevenue = Account::create([
        'chart_of_accounts_id' => $revenueHeader->chart_of_accounts_id,
        'parent_account_id' => $revenueHeader->id,
        'account_code' => '40299',
        'name' => 'مبيعات خاصة',
        'type' => 'revenue',
        'currency' => 'LYD',
    ]);
    $unlinkedUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Unlinked Returns Unit',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
        'revenue_account_id' => $customRevenue->id,
    ]);

    expect(fn () => SalesAccounts::returnsFor($unlinkedUnit))
        ->toThrow(SalesRuleException::class, 'no sales returns account linked yet');
});
