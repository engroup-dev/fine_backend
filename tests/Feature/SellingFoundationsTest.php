<?php

declare(strict_types=1);

use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\DocumentNumberService;
use App\Support\SalesAccounts;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Store Manager', 'slug' => 'store_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);
});

test('a unit-priced item suggests price × quantity', function () {
    $item = InventoryItem::create([
        'name' => 'Pillow', 'code' => 'PIL-1', 'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'each', 'selling_price' => 25,
    ]);

    expect($item->price_basis)->toBe('unit')
        ->and($item->priceFor(3))->toBe(75.0);
});

test('an m3-priced item suggests rate × L × W × H, falling back to its own size', function () {
    $item = InventoryItem::create([
        'name' => 'Foam D25 piece', 'code' => 'CUT-D25', 'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each', 'selling_price' => 1000, 'price_basis' => 'm3',
        'length_m' => 2, 'width_m' => 1, 'height_m' => 0.1,
    ]);

    // Given size: 2 pieces of 2 × 0.7 × 0.1 = 0.14 m³ each.
    expect($item->priceFor(2, 2, 0.7, 0.1))->toBe(280.0)
        // Catalog size: 0.2 m³.
        ->and($item->priceFor(1))->toBe(200.0);
});

test('an item without a selling price suggests nothing', function () {
    $item = InventoryItem::create([
        'name' => 'Frame', 'code' => 'FRM-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'each',
    ]);

    expect($item->priceFor(1))->toBeNull();
});

test('the item API stores the selling price and its basis', function () {
    ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => itemCategoryId(),
        'name' => 'Foam D30 piece', 'code' => 'CUT-D30', 'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each', 'selling_price' => 1200, 'price_basis' => 'm3',
    ])->assertCreated()
        ->assertJsonPath('price_basis', 'm3')
        ->assertJsonPath('selling_price', '1200.0000');

    ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => itemCategoryId(),
        'name' => 'Bad basis', 'code' => 'BAD-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'each', 'price_basis' => 'kg',
    ])->assertUnprocessable()->assertJsonValidationErrors('price_basis');
});

test('a treasury can be created as a bank account linked to its ledger account', function () {
    $bankAccount = Account::where('account_code', '1212')->firstOrFail();

    $created = ($this->api)()->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->store->id,
        'name' => 'مصرف الجمهورية',
        'kind' => 'bank',
        'account_id' => $bankAccount->id,
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'bank')
        ->assertJsonPath('data.account.account_code', '1212');

    ($this->api)()->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->store->id,
        'name' => 'خزينة الصالة',
    ])->assertCreated()->assertJsonPath('data.kind', 'cash');

    ($this->api)()->getJson('/api/v1/cash-accounts?kind=bank')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created->json('data.id'));
});

test('an existing treasury can be linked to its ledger account later', function () {
    $treasury = CashAccount::create(['operating_unit_id' => $this->store->id, 'name' => 'Drawer']);
    $cashBox = Account::where('account_code', '121102')->firstOrFail();

    ($this->api)()->putJson("/api/v1/cash-accounts/{$treasury->id}", ['account_id' => $cashBox->id])
        ->assertSuccessful()
        ->assertJsonPath('data.account.account_code', '121102');
});

test('a quick-added client joins the current unit and gets its own receivable account under 122', function () {
    $first = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'محمد علي',
        'entity_type' => 'individual',
        'phone' => '0912345678',
    ])->assertCreated()
        ->assertJsonPath('data.operating_unit_id', $this->store->id);

    $code1 = $first->json('data.account.account_code');
    expect($code1)->toStartWith('122');

    $second = ($this->api)()->postJson('/api/v1/clients', ['name' => 'شركة النور'])
        ->assertCreated();

    $code2 = $second->json('data.account.account_code');
    expect($code2)->toStartWith('122')
        ->and((int) substr($code2, 3))->toBe((int) substr($code1, 3) + 1);

    $account = Account::where('account_code', $code1)->firstOrFail();
    expect($account->parent?->account_code)->toBe('122')
        ->and($account->name)->toBe('عميل - محمد علي');

    $client = Client::with('entity.primaryContact')->findOrFail($first->json('data.id'));
    expect($client->entity->primaryContact->phone)->toBe('0912345678');
});

test('document numbers are sequential per type and shared by every unit', function () {
    $numbers = app(DocumentNumberService::class);
    $year = now()->format('Y');

    expect($numbers->next(DocumentNumberService::SALE))->toBe("S-{$year}-00001")
        ->and($numbers->next(DocumentNumberService::SALE))->toBe("S-{$year}-00002")
        ->and($numbers->next(DocumentNumberService::QUOTATION))->toBe("Q-{$year}-00001");
});

test('sales accounts are leaves: the unit revenue account and the client own receivable', function () {
    // An unlinked unit cannot sell — revenue never lands on the 41 header.
    expect(fn () => SalesAccounts::revenueFor($this->store))
        ->toThrow(SalesRuleException::class, 'no sales revenue account');

    $cutterSales = Account::where('account_code', '40202')->firstOrFail();
    $this->store->update(['revenue_account_id' => $cutterSales->id]);

    expect(SalesAccounts::revenueFor($this->store->fresh()))->toBe('40202');

    // A client created without a receivable sub-account gets one on first use.
    $entity = Entity::create(['entity_type' => 'individual', 'name' => 'Walk-in']);
    $client = Client::create(['entity_id' => $entity->id, 'operating_unit_id' => $this->store->id]);

    $code = SalesAccounts::receivableFor($client);

    expect($code)->toStartWith('122')->not->toBe('122')
        ->and($client->fresh()->account?->account_code)->toBe($code)
        ->and(SalesAccounts::receivableFor($client->fresh()))->toBe($code);

    expect(fn () => SalesAccounts::receivableFor(null))->toThrow(SalesRuleException::class);
});

test('a treasury that is not linked to the ledger cannot take money', function () {
    $treasury = CashAccount::create(['operating_unit_id' => $this->store->id, 'name' => 'Loose drawer']);

    SalesAccounts::treasuryFor($treasury);
})->throws(SalesRuleException::class);

test('the item list can carry what is on this unit shelves', function () {
    $item = InventoryItem::create([
        'name' => 'Pillow', 'code' => 'PIL-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);
    $warehouse = Warehouse::create(['operating_unit_id' => $this->store->id, 'name' => 'WH']);
    StockLot::create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'P-1', 'quantity' => 7, 'unit_cost' => 5, 'status' => 'available']);
    StockLot::create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'P-2', 'quantity' => 3, 'unit_cost' => 5, 'status' => 'reserved']);

    ($this->api)()->getJson('/api/v1/inventory-items?with_stock=1')
        ->assertSuccessful()
        ->assertJsonPath('data.0.available_quantity', fn ($value) => (float) $value === 7.0);
});

test('payment accounts of any unit are offered to the POS', function () {
    $blueprint = UnitBlueprint::firstOrFail();
    $treasuryUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Procurement', 'unit_type' => 'procurement',
    ]);
    $bank = CashAccount::create([
        'operating_unit_id' => $treasuryUnit->id, 'name' => 'Central Bank', 'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->firstOrFail()->id,
    ]);

    ($this->api)()->getJson('/api/v1/sales/payment-accounts?kind=bank')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $bank->id);
});

test('cash and bank ledger sub-accounts are offered to the POS without a treasury record', function () {
    $box = Account::where('account_code', '121102')->firstOrFail();
    $bank = Account::create([
        'chart_of_accounts_id' => $box->chart_of_accounts_id, 'account_code' => '121201',
        'name' => 'مصرف الجمهورية', 'type' => 'asset', 'section' => $box->section, 'currency' => 'LYD',
        'parent_account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $cash = ($this->api)()->getJson('/api/v1/sales/payment-accounts?kind=cash')->assertSuccessful();
    expect(collect($cash->json('data'))->pluck('account_id'))->toContain($box->id);

    ($this->api)()->getJson('/api/v1/sales/payment-accounts?kind=bank')
        ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.account_id', $bank->id);

    expect(CashAccount::where('account_id', $bank->id)->count())->toBe(1);
});
