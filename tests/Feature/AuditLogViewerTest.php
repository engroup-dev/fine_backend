<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * W6-01: audit log viewer — record / unit / search filters, acting user's name,
 * the table list, the lookup indexes and admin access.
 */
beforeEach(function () {
    $company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Factory', 'unit_type' => 'manufactory',
    ]);

    $this->owner = User::factory()->create(['name' => 'Owner Person', 'must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->clerk = User::factory()->create(['name' => 'Salem Clerk', 'must_change_password' => false]);

    AuditLog::query()->delete();

    $this->recordA = (string) str()->uuid();
    $this->recordB = (string) str()->uuid();

    $this->rowA = AuditLog::create([
        'user_id' => $this->clerk->id, 'operating_unit_id' => $this->unitA->id,
        'table_name' => 'clients', 'record_id' => $this->recordA, 'action' => 'updated',
        'old_values' => ['credit_limit' => 100], 'new_values' => ['credit_limit' => 500],
    ]);
    $this->rowB = AuditLog::create([
        'user_id' => $this->owner->id, 'operating_unit_id' => $this->unitB->id,
        'table_name' => 'items', 'record_id' => $this->recordB, 'action' => 'created',
        'old_values' => null, 'new_values' => ['name' => 'إسفنج عالي الكثافة'],
    ]);
    $this->rowSystem = AuditLog::create([
        'user_id' => null, 'operating_unit_id' => null,
        'table_name' => 'clients', 'record_id' => $this->recordB, 'action' => 'deleted',
        'old_values' => ['notes' => 'closed'], 'new_values' => null,
    ]);
});

function auditIds(array $rows): array
{
    return collect($rows)->pluck('id')->sort()->values()->all();
}

test('index filters by record_id', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs?record_id={$this->recordA}")
        ->assertOk()
        ->json('data');

    expect(auditIds($rows))->toBe([$this->rowA->id]);
});

test('index filters by operating_unit_id', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs?operating_unit_id={$this->unitB->id}")
        ->assertOk()
        ->json('data');

    expect(auditIds($rows))->toBe([$this->rowB->id]);
});

test('index rejects a non-uuid operating_unit_id', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?operating_unit_id=nope')
        ->assertStatus(422);
});

test('index rejects a non-uuid record_id', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?record_id=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors('record_id');
});

test('show with a non-uuid record id returns an empty list, like an unknown id', function () {
    DB::enableQueryLog();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs/clients/abc')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    // The bad value never reaches the uuid column (pgsql would raise 22P02).
    $auditQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'audit_logs'));
    expect($auditQueries)->toBeEmpty();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs/clients/'.str()->uuid())
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('search ignores case in the user name and the table name', function () {
    DB::enableQueryLog();

    $byUser = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=sALEM')->assertOk()->json('data');
    expect(auditIds($byUser))->toBe([$this->rowA->id]);

    $byTable = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=ITEMS')->assertOk()->json('data');
    expect(auditIds($byTable))->toBe([$this->rowB->id]);

    // sqlite's LIKE already ignores ASCII case; pgsql's does not, so the match must lower both sides.
    $searchSql = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql) => str_contains($sql, 'ESCAPE'));
    expect(strtolower((string) $searchSql))->toContain('lower(table_name) like lower(?)')
        ->toContain('lower(name) like lower(?)');
});

test('search matches the acting user name', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?search=Salem')
        ->assertOk()
        ->json('data');

    expect(auditIds($rows))->toBe([$this->rowA->id]);
});

test('search matches the table name, record id and logged values', function () {
    $byTable = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=item')->assertOk()->json('data');
    expect(auditIds($byTable))->toBe([$this->rowB->id]);

    $byRecord = $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?search='.substr($this->recordA, 0, 13))
        ->assertOk()
        ->json('data');
    expect(auditIds($byRecord))->toBe([$this->rowA->id]);

    $byValue = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=closed')->assertOk()->json('data');
    expect(auditIds($byValue))->toBe([$this->rowSystem->id]);
});

test('search matches non-ASCII logged values', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?search='.urlencode('الكثافة'))
        ->assertOk()
        ->json('data');

    expect(auditIds($rows))->toBe([$this->rowB->id]);
});

test('search combines with the other filters', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?search=clients&action=deleted')
        ->assertOk()
        ->json('data');

    expect(auditIds($rows))->toBe([$this->rowSystem->id]);
});

test('rows carry the acting user id and name, null for system rows', function () {
    $rows = collect($this->actingAs($this->owner)->getJson('/api/v1/audit-logs')->assertOk()->json('data'))
        ->keyBy('id');

    expect($rows[$this->rowA->id]['user_id'])->toBe($this->clerk->id)
        ->and($rows[$this->rowA->id]['user'])->toBe(['id' => $this->clerk->id, 'name' => 'Salem Clerk'])
        ->and($rows[$this->rowSystem->id]['user'])->toBeNull();

    $history = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/clients/{$this->recordA}")
        ->assertOk()
        ->json('data');

    expect($history[0]['user'])->toBe(['id' => $this->clerk->id, 'name' => 'Salem Clerk']);
});

test('a soft-deleted user keeps their name on the log', function () {
    $this->clerk->delete();

    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs?record_id={$this->recordA}")
        ->assertOk()
        ->json('data');

    expect($rows[0]['user']['name'])->toBe('Salem Clerk');
});

test('tables lists the distinct audited table names, sorted', function () {
    $tables = $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs/tables')
        ->assertOk()
        ->json('data');

    expect($tables)->toBe(collect(AuditLog::distinct()->pluck('table_name'))->sort()->values()->all())
        ->and($tables)->toContain('clients', 'items')
        ->and(array_count_values($tables)['clients'])->toBe(1);
});

test('audit_logs is indexed on (table_name, record_id) and created_at', function () {
    $indexes = collect(Schema::getIndexes('audit_logs'))->pluck('columns');

    expect($indexes)->toContain(['table_name', 'record_id'])
        ->and($indexes)->toContain(['created_at']);
});

test('an admin may read the audit log', function () {
    $admin = User::factory()->create(['must_change_password' => false]);
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $adminRole->id, 'operating_unit_id' => null]);

    $this->actingAs($admin)->getJson('/api/v1/audit-logs')->assertOk();
    $this->actingAs($admin)->getJson('/api/v1/audit-logs/tables')->assertOk();
    $this->actingAs($admin)->getJson("/api/v1/audit-logs/clients/{$this->recordA}")->assertOk();
});

test('other roles may not read the audit log', function () {
    $manager = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Accounting Manager', 'slug' => 'accounting-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->actingAs($manager)->getJson('/api/v1/audit-logs')->assertForbidden();
    $this->actingAs($manager)->getJson('/api/v1/audit-logs/tables')->assertForbidden();
    $this->actingAs($manager)->getJson("/api/v1/audit-logs/clients/{$this->recordA}")->assertForbidden();
});

test('a unit-scoped admin sees only the rows written in their unit context', function () {
    $admin = User::factory()->create(['must_change_password' => false]);
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $adminRole->id, 'operating_unit_id' => $this->unitA->id]);
    $headers = ['X-Operating-Unit-ID' => $this->unitA->id];

    $rows = $this->actingAs($admin)->getJson('/api/v1/audit-logs', $headers)->assertOk()->json('data');
    expect(auditIds($rows))->toBe([$this->rowA->id]);

    $otherUnit = $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs?operating_unit_id={$this->unitB->id}", $headers)
        ->assertOk()
        ->json('data');
    expect($otherUnit)->toBe([]);

    $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs/items/{$this->recordB}", $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs/clients/{$this->recordB}", $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs/clients/{$this->recordA}", $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $tables = $this->actingAs($admin)->getJson('/api/v1/audit-logs/tables', $headers)->assertOk()->json('data');
    expect($tables)->toBe(['clients']);

    $this->actingAs($admin)
        ->getJson('/api/v1/audit-logs', ['X-Operating-Unit-ID' => $this->unitB->id])
        ->assertForbidden();
});

test('search treats % and _ literally', function () {
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=credit%5Flimit')
        ->assertOk()
        ->assertJsonCount(1, 'data');
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=credit%25limit')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=cl_ents')->assertOk()->assertJsonCount(0, 'data');
});

test('a search term that is not valid UTF-8 matches nothing instead of everything', function () {
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search=%FF')->assertOk()->assertJsonCount(0, 'data');
});

test('search treats the ! escape character literally', function () {
    AuditLog::create([
        'user_id' => null, 'operating_unit_id' => null,
        'table_name' => 'clients', 'record_id' => (string) str()->uuid(), 'action' => 'updated',
        'old_values' => null, 'new_values' => ['notes' => 'urgent!50 done'],
    ]);

    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search='.urlencode('urgent!50'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search='.urlencode('urgent!%'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?search='.urlencode('urgent_50'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('admin in one unit gains nothing from another role in a second unit', function () {
    $user = User::factory()->create(['must_change_password' => false]);
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    $clerkRole = Role::create(['name' => 'Store Clerk', 'slug' => 'store-clerk']);
    UserRole::create(['user_id' => $user->id, 'role_id' => $adminRole->id, 'operating_unit_id' => $this->unitA->id]);
    UserRole::create(['user_id' => $user->id, 'role_id' => $clerkRole->id, 'operating_unit_id' => $this->unitB->id]);
    $headersB = ['X-Operating-Unit-ID' => $this->unitB->id];

    $this->actingAs($user)->getJson('/api/v1/audit-logs', $headersB)->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit-logs/tables', $headersB)->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/audit-logs/items/{$this->recordB}", $headersB)->assertForbidden();

    $rows = $this->actingAs($user)
        ->getJson('/api/v1/audit-logs', ['X-Operating-Unit-ID' => $this->unitA->id])
        ->assertOk()
        ->json('data');
    expect(auditIds($rows))->toBe([$this->rowA->id]);
});

test('a company-wide non-admin role does not widen a unit admin to every unit', function () {
    $user = User::factory()->create(['must_change_password' => false]);
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    $managerRole = Role::create(['name' => 'Accounting Manager', 'slug' => 'accounting-manager']);
    UserRole::create(['user_id' => $user->id, 'role_id' => $adminRole->id, 'operating_unit_id' => $this->unitA->id]);
    UserRole::create(['user_id' => $user->id, 'role_id' => $managerRole->id, 'operating_unit_id' => null]);

    // No unit selected: the company-wide manager role passes unit scoping but is no audit reader.
    $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit-logs', ['X-Operating-Unit-ID' => $this->unitB->id])
        ->assertForbidden();

    $headersA = ['X-Operating-Unit-ID' => $this->unitA->id];
    $rows = $this->actingAs($user)->getJson('/api/v1/audit-logs', $headersA)->assertOk()->json('data');
    expect(auditIds($rows))->toBe([$this->rowA->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs?operating_unit_id={$this->unitB->id}", $headersA)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
