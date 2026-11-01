<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\SalesOrder;
use App\Models\UnitBlueprint;
use App\Services\AccountingService;
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
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $entity = Entity::create(['name' => 'شركة البناء الراقي', 'entity_type' => 'organization', 'phone' => '0910000001']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 50000.0,
        'current_balance' => 0.0,
    ]);

    $this->parent = Account::where('account_code', '122')->firstOrFail();
    $this->child = Account::create([
        'chart_of_accounts_id' => $this->parent->chart_of_accounts_id,
        'account_code' => '122001',
        'name' => 'Client sub-account',
        'type' => $this->parent->type,
        'section' => $this->parent->section,
        'currency' => $this->parent->currency,
        'parent_account_id' => $this->parent->id,
    ]);
});

function historyLine(string $debitCode, ?string $sourceId = null): JournalLine
{
    $entry = app(AccountingService::class)->postJournal('History', [
        ['account_code' => $debitCode, 'debit' => 100],
        ['account_code' => '40201', 'credit' => 100],
    ], $sourceId === null ? null : 'SalesOrder', $sourceId, isManual: true);

    return JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', Account::where('account_code', $debitCode)->value('id'))
        ->firstOrFail();
}

function salesOrderFor(?string $clientId): SalesOrder
{
    return SalesOrder::factory()->create(['operating_unit_id' => test()->unit->id, 'client_id' => $clientId]);
}

test('assigns by client sub-account', function () {
    $this->client->update(['account_id' => $this->child->id]);
    $line = historyLine('122001');

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sub-account: 1')
        ->expectsOutputToContain('By sales order: 0')
        ->expectsOutputToContain('Still unassigned: 0')
        ->assertExitCode(0);

    expect($line->fresh()->client_id)->toBe($this->client->id);
});

test('skips sub-accounts shared by several clients', function () {
    // Shared accounts predate W6-06's unique index; drop it to stage that data.
    (require database_path('migrations/2026_11_01_110200_add_unique_account_to_clients_and_suppliers.php'))->down();

    $entity = Entity::create(['name' => 'Other', 'entity_type' => 'organization', 'phone' => '0910000002']);
    $other = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1.0, 'current_balance' => 0.0,
    ]);
    $this->client->update(['account_id' => $this->child->id]);
    $other->update(['account_id' => $this->child->id]);
    $line = historyLine('122001');

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sub-account: 0')
        ->expectsOutputToContain('Still unassigned: 1')
        ->assertExitCode(0);

    expect($line->fresh()->client_id)->toBeNull();
});

test('assigns by sales order source', function () {
    $order = salesOrderFor($this->client->id);
    $line = historyLine('122', $order->id);

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sub-account: 0')
        ->expectsOutputToContain('By sales order: 1')
        ->expectsOutputToContain('Still unassigned: 0')
        ->assertExitCode(0);

    expect($line->fresh()->client_id)->toBe($this->client->id);
});

test('skips walk-in sales orders without a client', function () {
    $order = salesOrderFor(null);
    $line = historyLine('122', $order->id);

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sales order: 0')
        ->expectsOutputToContain('Still unassigned: 1');

    expect($line->fresh()->client_id)->toBeNull();
});

test('leaves unresolvable lines null', function () {
    $line = historyLine('122');

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('Still unassigned: 1')
        ->assertExitCode(0);

    expect($line->fresh()->client_id)->toBeNull();
});

test('dry run writes nothing', function () {
    $this->client->update(['account_id' => $this->child->id]);
    $a = historyLine('122001');
    $b = historyLine('122', salesOrderFor($this->client->id)->id);

    $this->artisan('ledger:backfill-clients', ['--dry-run' => true])
        ->expectsOutputToContain('By sub-account: 1')
        ->expectsOutputToContain('By sales order: 1')
        ->expectsOutputToContain('Still unassigned: 0')
        ->assertExitCode(0);

    expect($a->fresh()->client_id)->toBeNull()
        ->and($b->fresh()->client_id)->toBeNull();
});

test('is idempotent', function () {
    $this->client->update(['account_id' => $this->child->id]);
    historyLine('122001');
    historyLine('122', salesOrderFor($this->client->id)->id);

    $this->artisan('ledger:backfill-clients')->assertExitCode(0);

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sub-account: 0')
        ->expectsOutputToContain('By sales order: 0')
        ->expectsOutputToContain('Still unassigned: 0')
        ->assertExitCode(0);
});

test('ignores non-receivable lines', function () {
    $order = salesOrderFor($this->client->id);
    $entry = app(AccountingService::class)->postJournal('History', [
        ['account_code' => '122', 'debit' => 100],
        ['account_code' => '40201', 'credit' => 100],
    ], 'SalesOrder', $order->id, isManual: true);

    $this->artisan('ledger:backfill-clients')->assertExitCode(0);

    $revenue = JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', Account::where('account_code', '40201')->value('id'))->firstOrFail();
    expect($revenue->client_id)->toBeNull();
});

test('pass 1 ignores a client linked to the bare root account', function () {
    $this->client->update(['account_id' => $this->parent->id]);

    $entity = Entity::create(['name' => 'Other', 'entity_type' => 'organization', 'phone' => '0910000003']);
    $other = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1.0, 'current_balance' => 0.0,
    ]);
    $line = historyLine('122', salesOrderFor($other->id)->id);

    $this->artisan('ledger:backfill-clients')
        ->expectsOutputToContain('By sub-account: 0')
        ->expectsOutputToContain('By sales order: 1')
        ->expectsOutputToContain('Still unassigned: 0')
        ->assertExitCode(0);

    expect($line->fresh()->client_id)->toBe($other->id)
        ->and(JournalLine::where('client_id', $this->client->id)->count())->toBe(0);
});
