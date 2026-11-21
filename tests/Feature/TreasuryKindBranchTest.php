<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\DashboardService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CR 8: banks (1212) and cash boxes are kept apart in the chart, so
 * their totals can be reported separately.
 */
beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Foam Plant',
        'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $bankParent = Account::where('account_code', '1212')->firstOrFail();
    $this->bankLeaf = Account::create([
        'chart_of_accounts_id' => $bankParent->chart_of_accounts_id, 'account_code' => '121201',
        'name' => 'مصرف التجارة', 'type' => 'asset', 'parent_account_id' => $bankParent->id, 'currency' => 'LYD',
    ]);
    $this->cashLeaf = Account::where('account_code', '121104')->sole();

    $this->api = fn () => $this->actingAs($this->owner)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('a bank must link under 1212 and a cash box must not', function (): void {
    ($this->api)()->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id, 'name' => 'Wrong bank', 'kind' => 'bank', 'account_id' => $this->cashLeaf->id,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    ($this->api)()->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id, 'name' => 'Wrong cash', 'kind' => 'cash', 'account_id' => $this->bankLeaf->id,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    ($this->api)()->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id, 'name' => 'Bank', 'kind' => 'bank', 'account_id' => $this->bankLeaf->id,
    ])->assertCreated();
});

test('changing the kind re-checks the link, but an existing mislink does not block a rename', function (): void {
    $legacy = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Legacy', 'kind' => 'bank',
        'account_id' => $this->cashLeaf->id, 'currency' => 'LYD', 'balance' => 0,
    ]);

    ($this->api)()->putJson("/api/v1/cash-accounts/{$legacy->id}", ['name' => 'Legacy renamed'])->assertOk();
    ($this->api)()->putJson("/api/v1/cash-accounts/{$legacy->id}", ['kind' => 'cash'])->assertOk();
    ($this->api)()->putJson("/api/v1/cash-accounts/{$legacy->id}", ['kind' => 'bank'])
        ->assertStatus(422)->assertJsonValidationErrors('account_id');
});

test('the link check lists mislinked and unlinked treasuries without changing them', function (): void {
    CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Bank in cash branch', 'kind' => 'bank',
        'account_id' => $this->cashLeaf->id, 'currency' => 'LYD', 'balance' => 0,
    ]);
    CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Unlinked box', 'kind' => 'cash',
        'account_id' => null, 'currency' => 'LYD', 'balance' => 0,
    ]);

    $this->artisan('ledger:check-treasury-links')
        ->expectsOutputToContain('Bank in cash branch')
        ->expectsOutputToContain('Unlinked box')
        ->assertSuccessful();

    expect(CashAccount::where('name', 'Bank in cash branch')->sole()->account_id)->toBe($this->cashLeaf->id);
});

test('the dashboard splits the cash position into cash boxes and banks', function (): void {
    $accounting = app(AccountingService::class);
    $accounting->postJournal('Cash in', [
        ['account_code' => '121104', 'debit' => 300.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'credit' => 300.0, 'operating_unit_id' => $this->unit->id],
    ]);
    $accounting->postJournal('Bank in', [
        ['account_code' => '121201', 'debit' => 1200.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'credit' => 1200.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $kpis = app(DashboardService::class)->kpis();

    expect((float) $kpis['cash_position'])->toBe(1500.0)
        ->and((float) $kpis['cash_position_cash'])->toBe(300.0)
        ->and((float) $kpis['cash_position_banks'])->toBe(1200.0);
});
