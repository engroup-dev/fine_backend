<?php

declare(strict_types=1);

use App\Enums\JournalEntryType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\TreasuryBalanceService;
use App\Services\TreasuryOpeningBalanceService;
use App\Support\LedgerAccounts;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->leaf = fn (string $code): string => Account::where('account_code', $code)->sole()->id;
    $this->ledgerBalance = fn (CashAccount $treasury): float => (float) app(TreasuryBalanceService::class)->balanceFor($treasury->fresh('account'))['ledger_balance'];

    $this->createTreasury = fn (array $attributes) => $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/cash-accounts', [
            'operating_unit_id' => $this->unit->id,
            'name' => 'Main Treasury',
            'kind' => 'cash',
            ...$attributes,
        ]);

    $this->openingEntries = fn (CashAccount $treasury) => JournalEntry::query()
        ->where('source_document_type', TreasuryOpeningBalanceService::SOURCE_TYPE)
        ->where('source_document_id', $treasury->id);
});

test('creating a treasury with a balance posts the opening against equity 34', function (): void {
    $response = ($this->createTreasury)(['account_id' => ($this->leaf)('121104'), 'balance' => 1500])->assertCreated();

    $treasury = CashAccount::findOrFail($response->json('data.id'));
    $entry = ($this->openingEntries)($treasury)->sole();

    expect($entry->entry_type)->toBe(JournalEntryType::Opening)
        ->and(($this->ledgerBalance)($treasury))->toBe(1500.0)
        ->and((float) JournalLine::where('journal_entry_id', $entry->id)->where('account_id', ($this->leaf)(LedgerAccounts::OPENING_BALANCES))->sole()->credit)->toBe(1500.0)
        ->and((float) $response->json('data.ledger_balance'))->toBe(1500.0);
});

test('a negative opening balance credits the treasury', function (): void {
    $response = ($this->createTreasury)(['account_id' => ($this->leaf)('121104'), 'balance' => -200])->assertCreated();

    expect(($this->ledgerBalance)(CashAccount::findOrFail($response->json('data.id'))))->toBe(-200.0);
});

test('a zero balance posts nothing', function (): void {
    $response = ($this->createTreasury)(['account_id' => ($this->leaf)('121104'), 'balance' => 0])->assertCreated();

    expect(($this->openingEntries)(CashAccount::findOrFail($response->json('data.id')))->exists())->toBeFalse();
});

test('a foreign currency opening needs a rate, and without one nothing is created', function (): void {
    ($this->createTreasury)(['account_id' => ($this->leaf)('121104'), 'currency' => 'USD', 'balance' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'FX_RATE_REQUIRED');
    expect(CashAccount::count())->toBe(0);

    $response = ($this->createTreasury)([
        'account_id' => ($this->leaf)('121104'), 'currency' => 'USD', 'balance' => 100, 'opening_fx_rate' => 4.85,
    ])->assertCreated();

    $treasury = CashAccount::findOrFail($response->json('data.id'));
    $line = JournalLine::where('journal_entry_id', ($this->openingEntries)($treasury)->sole()->id)
        ->where('account_id', ($this->leaf)('121104'))->sole();

    expect((float) $line->debit)->toBe(485.0)
        ->and($line->currency)->toBe('USD')
        ->and((float) $line->original_amount)->toBe(100.0);
});

test('a treasury created unlinked posts its opening once, when it is linked', function (): void {
    $response = ($this->createTreasury)(['balance' => 300])->assertCreated();
    $treasury = CashAccount::findOrFail($response->json('data.id'));
    expect(($this->openingEntries)($treasury)->exists())->toBeFalse();

    $put = fn (array $data) => $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/cash-accounts/{$treasury->id}", $data)
        ->assertOk();

    $put(['account_id' => ($this->leaf)('121104')]);
    $put(['name' => 'Renamed']);

    expect(($this->openingEntries)($treasury)->count())->toBe(1)
        ->and(($this->ledgerBalance)($treasury))->toBe(300.0);
});

test('the balance cannot be changed after creation', function (): void {
    $response = ($this->createTreasury)(['account_id' => ($this->leaf)('121104'), 'balance' => 50])->assertCreated();
    $treasury = CashAccount::findOrFail($response->json('data.id'));

    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/cash-accounts/{$treasury->id}", ['balance' => 9999])
        ->assertOk();

    expect((float) $treasury->fresh()->balance)->toBe(50.0)
        ->and(($this->ledgerBalance)($treasury))->toBe(50.0);
});

test('the backfill command posts missing openings and skips anything that could double-count', function (): void {
    $make = fn (string $name, ?string $code, float $balance, string $currency = 'LYD') => CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => $name,
        'kind' => 'cash',
        'account_id' => $code !== null ? ($this->leaf)($code) : null,
        'currency' => $currency,
        'balance' => $balance,
    ]);

    $plain = $make('A plain', '121101', 1000);
    $foreign = $make('B dollars', '121102', 10, 'USD');
    $withPostings = $make('C used', '121103', 400);
    $sharedOne = $make('D shared 1', '121105', 70);
    $sharedTwo = $make('E shared 2', '121105', 80);
    $unlinked = $make('F unlinked', null, 90);

    app(AccountingService::class)->postJournal('Earlier deposit', [
        ['account_code' => '121103', 'debit' => 400.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'credit' => 400.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $this->artisan('ledger:post-treasury-openings')->assertFailed();

    $this->artisan('ledger:post-treasury-openings', ['--dry-run' => true])->assertSuccessful();
    expect(JournalEntry::where('source_document_type', TreasuryOpeningBalanceService::SOURCE_TYPE)->exists())->toBeFalse();

    $this->artisan('ledger:post-treasury-openings', ['--date' => '2026-11-01'])->assertSuccessful();

    expect(($this->openingEntries)($plain)->sole()->entry_date->toDateString())->toBe('2026-11-01')
        ->and(($this->openingEntries)($foreign)->exists())->toBeFalse()
        ->and(($this->openingEntries)($withPostings)->exists())->toBeFalse()
        ->and(($this->openingEntries)($sharedOne)->exists())->toBeFalse()
        ->and(($this->openingEntries)($sharedTwo)->exists())->toBeFalse()
        ->and(($this->openingEntries)($unlinked)->exists())->toBeFalse();

    $this->artisan('ledger:post-treasury-openings', ['--date' => '2026-11-01', '--fx' => ['USD=5']])->assertSuccessful();

    expect(($this->openingEntries)($plain)->count())->toBe(1)
        ->and(($this->ledgerBalance)($foreign))->toBe(50.0);
});

test('the backfill command rejects a malformed rate', function (): void {
    $this->artisan('ledger:post-treasury-openings', ['--dry-run' => true, '--fx' => ['USD']])->assertFailed();
});
