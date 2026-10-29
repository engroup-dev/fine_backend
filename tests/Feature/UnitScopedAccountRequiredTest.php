<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
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

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->fabric = InventoryItem::create([
        'name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter',
    ]);

    $this->inventoryAccount = Account::where('account_code', '111')->sole();
    $this->salesAccount = Account::where('account_code', '41001')->first()
        ?? Account::where('account_code', '41')->first()
        ?? Account::where('account_code', '40201')->sole();
});

test('a unit without a purchases mapping still takes stock intake on the item type account (INV-11)', function () {
    ($this->api)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'FAB-NL-1',
        'quantity' => 50,
        'unit_cost' => 8.0,
        'source' => 'purchase_credit',
    ])->assertStatus(201);

    expect((float) JournalLine::where('account_id', $this->inventoryAccount->id)->sum('debit'))->toBe(400.0);
});

test('linking the unit purchases account lets stock intake succeed', function () {
    OperatingUnitAccount::create([
        'operating_unit_id' => $this->unit->id,
        'event_type' => InventoryEventType::Purchases->value,
        'account_id' => $this->inventoryAccount->id,
    ]);

    ($this->api)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'FAB-OK-1',
        'quantity' => 50,
        'unit_cost' => 8.0,
        'source' => 'purchase_credit',
    ])->assertStatus(201);
});

test('lot value ignores the unit purchases mapping — the item type decides (INV-11)', function () {
    // A unit mapping pointing elsewhere does not move the intake off 111.
    OperatingUnitAccount::create([
        'operating_unit_id' => $this->unit->id,
        'event_type' => InventoryEventType::Purchases->value,
        'account_id' => Account::where('account_code', '1134')->sole()->id,
    ]);

    ($this->api)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $this->fabric->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'FAB-IGNORE',
        'quantity' => 50,
        'unit_cost' => 8.0,
        'source' => 'purchase_credit',
    ])->assertStatus(201);

    expect((float) JournalLine::where('account_id', $this->inventoryAccount->id)->sum('debit'))->toBe(400.0)
        ->and((float) JournalLine::where('account_id', Account::where('account_code', '1134')->value('id'))->sum('debit'))->toBe(0.0);
});
