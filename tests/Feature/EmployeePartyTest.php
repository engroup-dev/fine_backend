<?php

declare(strict_types=1);

use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Support\AccountTree;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    RolePermissions::grantDefaults($ownerRole);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $entity = Entity::create(['name' => 'Worker A', 'entity_type' => 'individual']);
    $this->employee = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Operator',
        'pay_type' => 'monthly',
        'monthly_salary' => 1200,
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->treasury = linkedTreasury($this->unit, '121101');
});

function advanceVoucher(array $overrides = []): array
{
    return array_merge([
        'kind' => 'payment',
        'cash_account_id' => test()->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => test()->employee->id,
        'amount' => 300.0,
        'description' => 'Salary advance',
        'operating_unit_id' => test()->unit->id,
    ], $overrides);
}

test('1214 and 1215 sit under 12, outside the 121 treasury node', function (): void {
    $current = Account::where('account_code', '12')->value('id');
    $treasuryIds = AccountTree::subtreeIds('121');

    foreach (['1214', '1215'] as $code) {
        $account = Account::where('account_code', $code)->firstOrFail();

        expect($account->parent_account_id)->toBe($current)
            ->and($treasuryIds)->not->toContain($account->id);
    }

    expect($treasuryIds)->toContain(Account::where('account_code', '121101')->value('id'));
});

test('the migration re-parents 1214 and 1215 on an existing database and keeps their postings', function (): void {
    $treasuryNode = Account::where('account_code', '121')->value('id');
    DB::table('accounts')->whereIn('account_code', ['1214', '1215'])->update(['parent_account_id' => $treasuryNode]);

    app(AccountingService::class)->postJournal('Old advance', [
        ['account_code' => '1215', 'debit' => 50, 'employee_id' => $this->employee->id],
        ['account_code' => '121101', 'credit' => 50],
    ]);
    $advanceId = Account::where('account_code', '1215')->value('id');

    (require database_path('migrations/2026_10_31_100000_move_advance_accounts_out_of_treasury.php'))->up();

    $current = Account::where('account_code', '12')->value('id');
    expect(Account::where('account_code', '1214')->value('parent_account_id'))->toBe($current)
        ->and(Account::where('account_code', '1215')->value('parent_account_id'))->toBe($current)
        ->and(Account::where('account_code', '1215')->value('id'))->toBe($advanceId)
        ->and(JournalLine::where('account_id', $advanceId)->count())->toBe(1);
});

test('the migration moves only 1214 and 1215 and leaves hand-made and renamed accounts alone', function (): void {
    $treasuryNode = Account::where('account_code', '121')->firstOrFail();
    $advances = Account::where('account_code', '1215')->firstOrFail();
    $current = Account::where('account_code', '12')->firstOrFail();
    DB::table('accounts')->whereIn('account_code', ['1214', '1215'])->update(['parent_account_id' => $treasuryNode->id]);

    $handMade = fn (string $code, Account $parent): Account => Account::create([
        'chart_of_accounts_id' => $parent->chart_of_accounts_id,
        'account_code' => $code,
        'name' => "Hand-made {$code}",
        'type' => 'asset',
        'currency' => $parent->currency,
        'parent_account_id' => $parent->id,
    ]);

    $unusedLeaf = $handMade('1217', $current);
    $employeeSub = $handMade('121501', $advances);
    $header = $handMade('1216', $treasuryNode);
    $postedChild = $handMade('121601', $header);
    app(AccountingService::class)->postJournal('Hand-made posting', [
        ['account_code' => '121601', 'debit' => 10],
        ['account_code' => '42', 'credit' => 10],
    ]);
    Account::where('account_code', '122')->update(['name' => 'Renamed receivables']);

    (require database_path('migrations/2026_10_31_100000_move_advance_accounts_out_of_treasury.php'))->up();

    expect(Account::whereKey($unusedLeaf->id)->value('parent_account_id'))->toBe($current->id)
        ->and(Account::whereKey($employeeSub->id)->value('parent_account_id'))->toBe($advances->id)
        ->and(Account::whereKey($header->id)->value('parent_account_id'))->toBe($treasuryNode->id)
        ->and(Account::whereKey($postedChild->id)->value('parent_account_id'))->toBe($header->id)
        ->and(AccountTree::subtreeIds('121'))->toContain($postedChild->id)
        ->and(Account::where('account_code', '122')->value('name'))->toBe('Renamed receivables');
});

test('the migration leaves a 1215 that was moved by hand where it is', function (): void {
    $elsewhere = Account::where('account_code', '13')->value('id');
    DB::table('accounts')->where('account_code', '1215')->update(['parent_account_id' => $elsewhere]);

    (require database_path('migrations/2026_10_31_100000_move_advance_accounts_out_of_treasury.php'))->up();

    expect(Account::where('account_code', '1215')->value('parent_account_id'))->toBe($elsewhere);
});

test('a payment voucher may use 1215 as its counter account and tags the employee', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher())
        ->assertCreated();

    $entry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $response->json('data.id'))
        ->sole();
    $advanceLine = $entry->lines->firstWhere('account_id', Account::where('account_code', '1215')->value('id'));

    expect($advanceLine)->not->toBeNull()
        ->and((float) $advanceLine->debit)->toBe(300.0)
        ->and($advanceLine->employee_id)->toBe($this->employee->id)
        ->and($entry->lines->whereNotNull('employee_id'))->toHaveCount(1);
});

test('a voucher on 1215 without an employee is refused PARTY_EMPLOYEE_REQUIRED', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['employee_id' => null]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_EMPLOYEE_REQUIRED');

    expect(JournalEntry::count())->toBe(0);
});

test('a voucher counter account inside 121 is still refused as treasury', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '121104', 'employee_id' => null]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VOUCHER_COUNTER_IS_TREASURY');
});

test('the dashboard cash position excludes employee advances', function (): void {
    app(AccountingService::class)->postJournal('Capital in', [
        ['account_code' => '121101', 'debit' => 1000, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '42', 'credit' => 1000, 'operating_unit_id' => $this->unit->id],
    ]);

    $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', advanceVoucher())->assertCreated();

    $kpis = $this->actingAs($this->owner)->getJson('/api/v1/dashboard/kpis')->assertOk()->json();

    expect((float) $kpis['cash_position'])->toBe(700.0);
});

test('a 1215 line without an employee is refused before any write', function (): void {
    expect(fn () => app(AccountingService::class)->postJournal('Advance', [
        ['account_code' => '1215', 'debit' => 100],
        ['account_code' => '121101', 'credit' => 100],
    ]))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_REQUIRED');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('an employee on a non-advance account is refused PARTY_ON_NON_EMPLOYEE_ACCOUNT', function (): void {
    expect(fn () => app(AccountingService::class)->postJournal('Bad party', [
        ['account_code' => '54', 'debit' => 100, 'employee_id' => $this->employee->id],
        ['account_code' => '121101', 'credit' => 100],
    ]))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_ON_NON_EMPLOYEE_ACCOUNT');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('an unknown employee is refused EMPLOYEE_NOT_FOUND', function (): void {
    expect(fn () => app(AccountingService::class)->postJournal('Ghost', [
        ['account_code' => '1215', 'debit' => 100, 'employee_id' => (string) Str::uuid()],
        ['account_code' => '121101', 'credit' => 100],
    ]))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('EMPLOYEE_NOT_FOUND');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('the employee party is stored and carried onto a reversal', function (): void {
    $accounting = app(AccountingService::class);
    $entry = $accounting->postJournal('Advance', [
        ['account_code' => '1215', 'debit' => 100, 'employee_id' => $this->employee->id],
        ['account_code' => '121101', 'credit' => 100],
    ]);

    $reversal = $accounting->reverseEntry($entry, null, 'Wrong employee', $this->owner->id);

    $advanceId = Account::where('account_code', '1215')->value('id');
    $original = $entry->lines->firstWhere('account_id', $advanceId);
    $mirror = $reversal->lines->firstWhere('account_id', $advanceId);

    expect($original->employee_id)->toBe($this->employee->id)
        ->and($original->employee->is($this->employee))->toBeTrue()
        ->and($mirror->employee_id)->toBe($this->employee->id)
        ->and((float) $mirror->credit)->toBe(100.0);
});

test('a manual entry accepts an employee on 1215 and refuses one elsewhere', function (): void {
    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Opening advance balance',
        'lines' => [
            ['account_code' => '1215', 'debit' => 100, 'employee_id' => $this->employee->id],
            ['account_code' => '121101', 'credit' => 100],
        ],
    ])->assertCreated();

    $advanceLine = JournalLine::whereNotNull('employee_id')->sole();
    expect($advanceLine->employee_id)->toBe($this->employee->id)
        ->and($advanceLine->operating_unit_id)->toBe($this->unit->id);

    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Bad party',
        'lines' => [
            ['account_code' => '54', 'debit' => 100, 'employee_id' => $this->employee->id],
            ['account_code' => '121101', 'credit' => 100],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'PARTY_ON_NON_EMPLOYEE_ACCOUNT');

    expect(JournalEntry::count())->toBe(1);
});

function secondUnitEmployee(): Employee
{
    $unit = OperatingUnit::create([
        'company_id' => test()->company->id, 'blueprint_id' => test()->unit->blueprint_id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $entity = Entity::create(['name' => 'Worker B', 'entity_type' => 'individual']);

    return Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $unit->id,
        'job_title' => 'Upholsterer',
        'pay_type' => 'monthly',
        'monthly_salary' => 1000,
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);
}

test('a payment voucher on 1214 (custody) posts without an employee', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '1214', 'employee_id' => null]))
        ->assertCreated();

    $entry = JournalEntry::where('source_document_id', $response->json('data.id'))->sole();
    expect($entry->lines->firstWhere('account_id', Account::where('account_code', '1214')->value('id')))->not->toBeNull()
        ->and($entry->lines->whereNotNull('employee_id'))->toHaveCount(0);
});

test('an employee on 1214 is refused PARTY_ON_NON_EMPLOYEE_ACCOUNT', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '1214']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_ON_NON_EMPLOYEE_ACCOUNT');

    expect(JournalEntry::count())->toBe(0);
});

test('a voucher may not name both a client and an employee', function (): void {
    $client = Client::create([
        'entity_id' => Entity::create(['name' => 'Client', 'entity_type' => 'organization', 'phone' => '0910000077'])->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1000.0,
        'current_balance' => 0.0,
    ]);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['client_id' => $client->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);

    expect(JournalEntry::count())->toBe(0);
});

test('a voucher with an employee on a non-advance counter account is refused', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '54']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_ON_NON_EMPLOYEE_ACCOUNT');

    expect(JournalEntry::count())->toBe(0);
});

test('a sub-account under 1215 also needs the employee', function (): void {
    $advances = Account::where('account_code', '1215')->firstOrFail();
    Account::create([
        'chart_of_accounts_id' => $advances->chart_of_accounts_id,
        'account_code' => '121501',
        'name' => 'Worker A advances',
        'type' => 'asset',
        'currency' => $advances->currency,
        'parent_account_id' => $advances->id,
    ]);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '121501', 'employee_id' => null]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_EMPLOYEE_REQUIRED');

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['counter_account_code' => '121501']))
        ->assertCreated();

    expect(JournalLine::where('account_id', Account::where('account_code', '121501')->value('id'))->value('employee_id'))
        ->toBe($this->employee->id);
});

test("a unit user cannot pay an advance to another unit's employee", function (): void {
    $other = secondUnitEmployee();
    $officer = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'treasury-officer'], ['name' => 'treasury-officer']);
    RolePermissions::grantDefaults($role);
    UserRole::create(['user_id' => $officer->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($officer)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['employee_id' => $other->id]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'EMPLOYEE_NOT_FOUND');

    $this->actingAs($officer)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher())
        ->assertCreated();

    expect(JournalEntry::count())->toBe(1);
});

test("an advance posted in a unit other than the employee's is refused PARTY_EMPLOYEE_UNIT_MISMATCH", function (): void {
    $other = secondUnitEmployee();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['employee_id' => $other->id]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_EMPLOYEE_UNIT_MISMATCH');

    expect(fn () => app(AccountingService::class)->postJournal('Wrong unit', [
        ['account_code' => '1215', 'debit' => 100, 'employee_id' => $this->employee->id, 'operating_unit_id' => $other->operating_unit_id],
        ['account_code' => '121101', 'credit' => 100, 'operating_unit_id' => $other->operating_unit_id],
    ]))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_UNIT_MISMATCH');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('a deleted employee gets no new advance but may still repay one', function (): void {
    $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', advanceVoucher())->assertCreated();
    $this->employee->delete();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher())
        ->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_EMPLOYEE_DELETED');

    $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', advanceVoucher(['kind' => 'receipt', 'description' => 'Advance repaid']))
        ->assertCreated();

    $advanceId = Account::where('account_code', '1215')->value('id');
    $lines = JournalLine::where('account_id', $advanceId)->where('employee_id', $this->employee->id)->get();
    expect($lines)->toHaveCount(2)
        ->and((float) $lines->sum('debit') - (float) $lines->sum('credit'))->toBe(0.0);
});

/**
 * A 1215 balance posted before W5-00, when 1215 was a plain leaf and lines
 * carried no employee. Posted tagged, then untagged at the table level.
 */
function legacyUntaggedAdvance(float $amount): void
{
    app(AccountingService::class)->postJournal('Pre-W5-00 advance', [
        ['account_code' => '1215', 'debit' => $amount, 'employee_id' => test()->employee->id],
        ['account_code' => '121101', 'credit' => $amount],
    ]);

    DB::table('journal_lines')
        ->where('account_id', Account::where('account_code', '1215')->value('id'))
        ->update(['employee_id' => null]);
}

test('a manual entry may reclassify an untagged pre-W5-00 balance to an employee, up to that balance', function (): void {
    legacyUntaggedAdvance(100);

    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Reclassify old advance to Worker A',
        'lines' => [
            ['account_code' => '1215', 'debit' => 60, 'employee_id' => $this->employee->id],
            ['account_code' => '1215', 'credit' => 60],
        ],
    ])->assertCreated();

    // 40 untagged is left, so clearing 50 is refused.
    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Write off more than is left',
        'lines' => [
            ['account_code' => '54', 'debit' => 50],
            ['account_code' => '1215', 'credit' => 50],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PARTY_EMPLOYEE_REQUIRED');

    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Write off the rest',
        'lines' => [
            ['account_code' => '54', 'debit' => 40],
            ['account_code' => '1215', 'credit' => 40],
        ],
    ])->assertCreated();

    $advanceId = Account::where('account_code', '1215')->value('id');
    $untagged = JournalLine::where('account_id', $advanceId)->whereNull('employee_id')->get();
    $tagged = JournalLine::where('account_id', $advanceId)->where('employee_id', $this->employee->id)->get();

    expect(round($untagged->sum('debit') - $untagged->sum('credit'), 4))->toBe(0.0)
        ->and(round($tagged->sum('debit') - $tagged->sum('credit'), 4))->toBe(60.0);
});

test('only a manual credit may clear an untagged advance balance; debits and automatic postings still need the employee', function (): void {
    legacyUntaggedAdvance(100);
    $accounting = app(AccountingService::class);

    expect(fn () => $accounting->postJournal('Automatic untagged credit', [
        ['account_code' => '121101', 'debit' => 30],
        ['account_code' => '1215', 'credit' => 30],
    ]))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_REQUIRED');
    });

    expect(fn () => $accounting->postJournal('Manual untagged debit', [
        ['account_code' => '1215', 'debit' => 30],
        ['account_code' => '121101', 'credit' => 30],
    ], isManual: true))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_REQUIRED');
    });

    // With no untagged balance at all, a manual untagged credit is refused too.
    DB::table('journal_lines')->delete();
    DB::table('journal_entries')->delete();

    expect(fn () => $accounting->postJournal('Manual untagged credit, nothing to clear', [
        ['account_code' => '121101', 'debit' => 30],
        ['account_code' => '1215', 'credit' => 30],
    ], isManual: true))->toThrow(function (LedgerRuleException $e): void {
        expect($e->errorCode)->toBe('PARTY_EMPLOYEE_REQUIRED');
    });

    expect(JournalEntry::count())->toBe(0);
});

test('a treasury cannot be linked to an employee advances account', function (): void {
    $advanceId = Account::where('account_code', '1215')->value('id');

    $this->actingAs($this->owner)->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id,
        'name' => 'Advances box',
        'account_id' => $advanceId,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    $this->actingAs($this->owner)->putJson("/api/v1/cash-accounts/{$this->treasury->id}", [
        'account_id' => $advanceId,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    // 1214 (custody) needs no employee, so it stays linkable as before.
    $this->actingAs($this->owner)->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id,
        'name' => 'Custody box',
        'account_id' => Account::where('account_code', '1214')->value('id'),
    ])->assertCreated();
});
