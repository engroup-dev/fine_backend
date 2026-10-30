<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Midday UTC: the company clock (Africa/Tripoli) and now() agree on the date
    // whatever time the suite runs; 22:00-24:00 UTC is already tomorrow in Tripoli.
    $this->travelTo(now()->setTime(12, 0));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);

    // Owner: company-wide role, no unit binding, no header needed.
    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    // Unit manager: scoped to the unit, not allowed to post manual entries.
    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->balancedPayload = fn (array $overrides = []) => array_merge([
        'description' => 'Correct mispriced freight on operation 191',
        'lines' => [
            ['account_code' => '51', 'debit' => 250.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'reclass'],
            ['account_code' => '111', 'credit' => 250.0, 'operating_unit_id' => $this->unit->id],
        ],
    ], $overrides);
});

test('the owner can post a manual correcting entry', function () {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)())
        ->assertStatus(201);

    expect($response->json('is_manual'))->toBeTrue()
        ->and($response->json('created_by_user_id'))->toBe($this->owner->id)
        ->and($response->json('reference'))->toStartWith('JE-');

    expect(JournalEntry::sole()->isBalanced())->toBeTrue();
});

test('an accounting manager can post a manual entry', function () {
    $accountant = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Accounting Manager', 'slug' => 'accounting-manager']);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->actingAs($accountant)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)())
        ->assertStatus(201);
});

test('a unit manager cannot post manual entries', function () {
    // ACC-04: corrections are reserved for accounting-level roles.
    $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)())
        ->assertForbidden()
        // The route's role middleware answers before the controller's own check.
        ->assertJsonPath('code', 'ACCESS_DENIED');

    expect(JournalEntry::count())->toBe(0);
});

test('an unbalanced manual entry is refused with nothing written', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)([
            'lines' => [
                ['account_code' => '51', 'debit' => 250.0],
                ['account_code' => '111', 'credit' => 100.0],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'UNBALANCED_JOURNAL');

    expect(JournalEntry::count())->toBe(0);
});

test('an unknown account in a manual entry is refused', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)([
            'lines' => [
                ['account_code' => '9999', 'debit' => 250.0],
                ['account_code' => '111', 'credit' => 250.0],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'MISSING_ACCOUNT');
});

test('a line carrying both a debit and a credit is refused', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)([
            'lines' => [
                ['account_code' => '51', 'debit' => 250.0, 'credit' => 250.0],
                ['account_code' => '111', 'credit' => 0],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_JOURNAL');
});

test('a manual entry cannot be dated in the future', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)([
            'entry_date' => now()->addDay()->toDateString(),
        ]))
        ->assertStatus(422);
});

test('a backdated correction takes its reference from its own year', function () {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)([
            'entry_date' => '2025-11-30',
        ]))
        ->assertStatus(201);

    expect($response->json('reference'))->toStartWith('JE-2025-')
        ->and($response->json('entry_date'))->toContain('2025-11-30');
});

test('two backdated corrections into the same year do not collide', function () {
    $first = $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)(['entry_date' => '2025-11-30']))
        ->json('reference');

    $second = $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)(['entry_date' => '2025-12-01']))
        ->json('reference');

    expect($first)->not->toBe($second);
});

test('manual entries surface through the manual_only filter', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/journal-entries', ($this->balancedPayload)())
        ->assertStatus(201);

    $listed = $this->actingAs($this->owner)
        ->getJson('/api/v1/journal-entries?manual_only=1')
        ->assertStatus(200)
        ->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['is_manual'])->toBeTrue();
});
