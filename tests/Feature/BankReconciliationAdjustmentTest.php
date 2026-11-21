<?php

declare(strict_types=1);

use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationAdjustment;
use App\Models\BankReconciliationLine;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\TreasuryVoucher;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Support\LedgerAccounts;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * TRS-04 / CR 9: statement items missing from the books (bank charges,
 * interest, direct deposits and debits) are posted from the reconciliation
 * itself, so it can reach a zero difference without leaving the screen.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $makeUser = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        RolePermissions::grantDefaults($role);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->accountant = $makeUser('accounting-manager');
    $this->storeManager = $makeUser('store-manager', $this->unit->id);

    $bankParent = Account::where('account_code', '1212')->firstOrFail();
    $this->bankAccount = Account::create([
        'chart_of_accounts_id' => $bankParent->chart_of_accounts_id,
        'account_code' => '121201',
        'name' => 'حساب مصرف التجارة - الرئيسي',
        'type' => 'asset',
        'parent_account_id' => $bankParent->id,
        'currency' => 'LYD',
    ]);

    $this->bankTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'خزينة مصرف التجارة',
        'kind' => CashAccount::KIND_BANK,
        'account_id' => $this->bankAccount->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $this->accounting = app(AccountingService::class);
    $this->actAs = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    // 1000 deposited on 10 Oct; the statement on 15 Oct shows 975 because the bank took 25 in charges.
    $deposit = $this->accounting->postJournal('Client deposit', [
        ['account_code' => '121201', 'debit' => 1000.0],
        ['account_code' => '31', 'credit' => 1000.0],
    ], entryDate: '2026-10-10');
    $this->depositLineId = $deposit->lines()->where('account_id', $this->bankAccount->id)->sole()->id;

    $this->recId = ($this->actAs)($this->accountant)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 975.0,
    ])->assertStatus(201)->json('data.reconciliation.id');

    ($this->actAs)($this->accountant)->postJson("/api/v1/bank-reconciliations/{$this->recId}/lines", [
        'line_ids' => [$this->depositLineId],
        'cleared' => true,
    ])->assertOk()->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === -25.0);

    $this->addAdjustment = fn (array $data, ?User $user = null) => ($this->actAs)($user ?? $this->accountant)
        ->postJson("/api/v1/bank-reconciliations/{$this->recId}/adjustments", $data);
});

test('a bank charge posts a payment voucher to 610, clears itself and lets the reconciliation finish', function (): void {
    $response = ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 25])
        ->assertStatus(201)
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.adjustments.0.type', 'bank_charge')
        ->assertJsonPath('data.adjustments.0.direction', 'out')
        ->assertJsonPath('data.adjustments.0.date', '2026-10-15')
        ->assertJsonPath('data.adjustments.0.counter_account_code', LedgerAccounts::BANK_CHARGES);

    $voucher = TreasuryVoucher::findOrFail($response->json('data.adjustments.0.voucher_id'));
    expect($voucher->kind)->toBe(TreasuryVoucherKind::Payment)
        ->and((float) $voucher->amount)->toBe(25.0)
        ->and($voucher->voucher_date->toDateString())->toBe('2026-10-15');

    $chargeLine = collect($response->json('data.lines'))->firstWhere('credit', 25);
    expect($chargeLine['cleared'])->toBeTrue();

    ($this->actAs)($this->accountant)->postJson("/api/v1/bank-reconciliations/{$this->recId}/finish")
        ->assertOk()
        ->assertJsonPath('data.reconciliation.status', 'finished');
});

test('interest defaults to account 46 and a direct deposit takes the chosen account', function (): void {
    ($this->addAdjustment)(['type' => 'bank_interest', 'amount' => 5, 'date' => '2026-10-14'])
        ->assertStatus(201)
        ->assertJsonPath('data.adjustments.0.direction', 'in')
        ->assertJsonPath('data.adjustments.0.counter_account_code', LedgerAccounts::BANK_INTEREST);

    ($this->addAdjustment)(['type' => 'direct_deposit', 'amount' => 40])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COUNTER_ACCOUNT_REQUIRED');

    ($this->addAdjustment)(['type' => 'direct_deposit', 'amount' => 40, 'counter_account_code' => '32', 'description' => 'Partner top-up'])
        ->assertStatus(201)
        ->assertJsonPath('data.adjustments.1.counter_account_code', '32')
        ->assertJsonPath('data.adjustments.1.description', 'Partner top-up')
        // 975 statement against 1000 + 5 + 40 cleared.
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === -70.0);
});

test('an adjustment cannot be dated after the statement', function (): void {
    ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 25, 'date' => '2026-10-16'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ADJUSTMENT_AFTER_STATEMENT');

    expect(TreasuryVoucher::count())->toBe(0);
});

test('removing an adjustment cancels its voucher and leaves no stray uncleared lines', function (): void {
    $adjustmentId = ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 30])->assertStatus(201)->json('data.adjustments.0.id');
    $voucherId = BankReconciliationAdjustment::findOrFail($adjustmentId)->treasury_voucher_id;

    $response = ($this->actAs)($this->accountant)
        ->deleteJson("/api/v1/bank-reconciliations/{$this->recId}/adjustments/{$adjustmentId}")
        ->assertOk()
        ->assertJsonPath('data.adjustments', [])
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === -25.0);

    $voucher = TreasuryVoucher::findOrFail($voucherId);
    expect($voucher->status)->toBe(TreasuryVoucherStatus::Cancelled)
        ->and(collect($response->json('data.lines'))->every(fn (array $line): bool => $line['cleared']))->toBeTrue()
        ->and(collect($response->json('data.lines')))->toHaveCount(3);
});

test('discarding the draft keeps the adjustment vouchers posted', function (): void {
    ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 25])->assertStatus(201);

    ($this->actAs)($this->accountant)->deleteJson("/api/v1/bank-reconciliations/{$this->recId}")->assertNoContent();

    expect(BankReconciliation::count())->toBe(0)
        ->and(BankReconciliationAdjustment::count())->toBe(0)
        ->and(BankReconciliationLine::count())->toBe(0)
        ->and(TreasuryVoucher::sole()->status)->toBe(TreasuryVoucherStatus::Posted);
});

test('a finished reconciliation takes no adjustments', function (): void {
    ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 25])->assertStatus(201);
    ($this->actAs)($this->accountant)->postJson("/api/v1/bank-reconciliations/{$this->recId}/finish")->assertOk();

    ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_FINISHED');
});

test('a store manager cannot add adjustments', function (): void {
    ($this->addAdjustment)(['type' => 'bank_charge', 'amount' => 25], $this->storeManager)->assertForbidden();
});

test('a first reconciliation balances once the opening balance is posted', function (): void {
    $bankParent = Account::where('account_code', '1212')->firstOrFail();
    $leaf = Account::create([
        'chart_of_accounts_id' => $bankParent->chart_of_accounts_id,
        'account_code' => '121202',
        'name' => 'حساب مصرف الجمهورية',
        'type' => 'asset',
        'parent_account_id' => $bankParent->id,
        'currency' => 'LYD',
    ]);

    $bankId = ($this->actAs)($this->accountant)->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id,
        'name' => 'مصرف الجمهورية',
        'kind' => 'bank',
        'account_id' => $leaf->id,
        'balance' => 5000,
        'opening_date' => '2026-10-01',
    ])->assertCreated()->json('data.id');

    $response = ($this->actAs)($this->accountant)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $bankId,
        'statement_date' => '2026-10-20',
        'statement_balance' => 5000.0,
    ])->assertStatus(201);

    $openingLineId = JournalLine::where('account_id', $leaf->id)->sole()->id;
    expect(collect($response->json('data.lines'))->pluck('id')->all())->toBe([$openingLineId]);

    $recId = $response->json('data.reconciliation.id');
    ($this->actAs)($this->accountant)->postJson("/api/v1/bank-reconciliations/{$recId}/lines", [
        'line_ids' => [$openingLineId],
        'cleared' => true,
    ])->assertOk()->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === 0.0);

    ($this->actAs)($this->accountant)->postJson("/api/v1/bank-reconciliations/{$recId}/finish")->assertOk();
});
