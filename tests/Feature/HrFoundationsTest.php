<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\LaborRoleRate;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->makeEmployee = function (string $name, array $overrides = []): Employee {
        $entity = Entity::create(['name' => $name, 'entity_type' => 'individual']);

        return Employee::create(array_merge([
            'entity_id' => $entity->id,
            'operating_unit_id' => $this->unit->id,
            'job_title' => 'Operator',
            'pay_type' => 'hourly',
            'hire_date' => '2026-01-01',
            'status' => 'active',
        ], $overrides));
    };

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('bulk attendance upserts the daily sheet and re-saving corrects it', function () {
    $a = ($this->makeEmployee)('Worker A');
    $b = ($this->makeEmployee)('Worker B');

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-10',
        'entries' => [
            ['employee_id' => $a->id, 'status' => 'present', 'hours_worked' => 8],
            ['employee_id' => $b->id, 'status' => 'absent'],
        ],
    ])->assertStatus(201)->assertJsonPath('count', 2);

    // The supervisor corrects the sheet: B was actually on a half day.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-10',
        'entries' => [
            ['employee_id' => $b->id, 'status' => 'half_day', 'hours_worked' => 4],
        ],
    ])->assertStatus(201);

    expect(Attendance::count())->toBe(2)
        ->and(Attendance::where('employee_id', $b->id)->sole()->status->value)->toBe('half_day')
        ->and((float) Attendance::where('employee_id', $b->id)->sole()->hours_worked)->toBe(4.0);
});

test('attendance refuses future dates and impossible hours', function () {
    $a = ($this->makeEmployee)('Worker A');

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => now()->addDay()->toDateString(),
        'entries' => [['employee_id' => $a->id, 'status' => 'present', 'hours_worked' => 8]],
    ])->assertStatus(422);

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-10',
        'entries' => [['employee_id' => $a->id, 'status' => 'present', 'hours_worked' => 25]],
    ])->assertStatus(422);
});

test('attendance list supports filtering by status, search, and date range', function () {
    $a = ($this->makeEmployee)('Ahmed Ali');
    $b = ($this->makeEmployee)('Bilal Omar');

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-10',
        'entries' => [
            ['employee_id' => $a->id, 'status' => 'present', 'hours_worked' => 8, 'notes' => 'On time'],
            ['employee_id' => $b->id, 'status' => 'absent', 'notes' => 'Unexcused'],
        ],
    ])->assertStatus(201);

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-11',
        'entries' => [
            ['employee_id' => $a->id, 'status' => 'leave', 'notes' => 'Approved leave'],
            ['employee_id' => $b->id, 'status' => 'present', 'hours_worked' => 8],
        ],
    ])->assertStatus(201);

    // Filter by status
    $absentList = ($this->asOwner)()->getJson('/api/v1/attendance?status=absent')
        ->assertStatus(200)->json('data');
    expect($absentList)->toHaveCount(1)
        ->and($absentList[0]['employee_id'])->toBe($b->id);

    // Search by employee name
    $ahmedList = ($this->asOwner)()->getJson('/api/v1/attendance?search=Ahmed')
        ->assertStatus(200)->json('data');
    expect($ahmedList)->toHaveCount(2);

    // Date range filter
    $singleDay = ($this->asOwner)()->getJson('/api/v1/attendance?from=2026-08-10&to=2026-08-10')
        ->assertStatus(200)->json('data');
    expect($singleDay)->toHaveCount(2);
});

test('single attendance record can be stored, updated, and deleted', function () {
    $a = ($this->makeEmployee)('Worker Single');

    // Create / upsert single attendance
    $createRes = ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $a->id,
        'work_date' => '2026-08-10',
        'status' => 'present',
        'hours_worked' => 8,
        'notes' => 'Morning shift',
    ])->assertStatus(201)->json();

    expect($createRes['status'])->toBe('present')
        ->and((float) $createRes['hours_worked'])->toBe(8.0)
        ->and($createRes['notes'])->toBe('Morning shift');

    $id = $createRes['id'];

    // Update single attendance
    $updateRes = ($this->asOwner)()->putJson("/api/v1/attendance/{$id}", [
        'status' => 'half_day',
        'hours_worked' => 4,
        'notes' => 'Left early',
    ])->assertStatus(200)->json();

    expect($updateRes['status'])->toBe('half_day')
        ->and((float) $updateRes['hours_worked'])->toBe(4.0)
        ->and($updateRes['notes'])->toBe('Left early');

    // Delete attendance
    ($this->asOwner)()->deleteJson("/api/v1/attendance/{$id}")
        ->assertStatus(200);

    expect(Attendance::find($id))->toBeNull();
});

test('role rates are versioned and resolve by date', function () {
    LaborRoleRate::create(['role' => 'tailor', 'hourly_rate' => 10, 'effective_from' => '2026-01-01']);
    LaborRoleRate::create(['role' => 'tailor', 'hourly_rate' => 12, 'effective_from' => '2026-07-01']);
    LaborRoleRate::create(['role' => 'carpenter', 'hourly_rate' => 15, 'effective_from' => '2026-03-01']);

    // HR-04: the version in force on the date, not the newest overall.
    expect(LaborRoleRate::rateFor('tailor', '2026-06-30'))->toBe(10.0)
        ->and(LaborRoleRate::rateFor('tailor', '2026-07-01'))->toBe(12.0)
        ->and(LaborRoleRate::rateFor('tailor', '2025-12-31'))->toBeNull()
        ->and(LaborRoleRate::rateFor('welder'))->toBeNull();

    $current = ($this->asOwner)()->getJson('/api/v1/labor-role-rates/current')
        ->assertStatus(200)->json('data');

    expect($current)->toHaveCount(2)
        ->and((float) collect($current)->firstWhere('role', 'tailor')['hourly_rate'])->toBe(12.0);
});

test('publishing the same role and effective date twice is refused', function () {
    ($this->asOwner)()->postJson('/api/v1/labor-role-rates', [
        'role' => 'tailor', 'hourly_rate' => 10, 'effective_from' => '2026-08-01',
    ])->assertStatus(201);

    ($this->asOwner)()->postJson('/api/v1/labor-role-rates', [
        'role' => 'tailor', 'hourly_rate' => 11, 'effective_from' => '2026-08-01',
    ])->assertStatus(422);

    // A different role on the same date is fine.
    ($this->asOwner)()->postJson('/api/v1/labor-role-rates', [
        'role' => 'carpenter', 'hourly_rate' => 11, 'effective_from' => '2026-08-01',
    ])->assertStatus(201);
});

test('a leave request routes through approval with a named decider', function () {
    $a = ($this->makeEmployee)('Worker A');

    $leave = ($this->asOwner)()->postJson('/api/v1/leave-requests', [
        'employee_id' => $a->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'leave_type' => 'annual',
        'reason' => 'Family visit',
    ])->assertStatus(201)->json();

    expect($leave['status'])->toBe('pending');

    $decided = ($this->asOwner)()->putJson("/api/v1/leave-requests/{$leave['id']}/approve", [
        'notes' => 'Enjoy',
    ])->assertStatus(200)->json();

    expect($decided['status'])->toBe('approved')
        ->and($decided['decided_by_user_id'])->toBe($this->owner->id);

    // HR-09: one decision only.
    ($this->asOwner)()->putJson("/api/v1/leave-requests/{$leave['id']}/reject")
        ->assertStatus(422)
        ->assertJsonPath('code', 'LEAVE_ALREADY_DECIDED');
});

test('a leave request with a backwards range is refused', function () {
    $a = ($this->makeEmployee)('Worker A');

    ($this->asOwner)()->postJson('/api/v1/leave-requests', [
        'employee_id' => $a->id,
        'start_date' => '2026-09-05',
        'end_date' => '2026-09-01',
        'leave_type' => 'annual',
    ])->assertStatus(422);
});

test('employee pay fields round-trip through the API', function () {
    $a = ($this->makeEmployee)('Worker A');

    $updated = ($this->asOwner)()->putJson("/api/v1/employees/{$a->id}", [
        'labor_role' => 'tailor',
        'record_version' => $a->record_version,
    ])->assertStatus(200)->json('data');

    expect($updated['labor_role'])->toBe('tailor');

    // HR-11: pay terms change through a dated pay change, not the employee edit.
    ($this->asOwner)()->postJson("/api/v1/employees/{$a->id}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 2500,
        'hourly_rate' => 9.5,
        'effective_from' => '2026-02-01',
    ])->assertStatus(201);

    $shown = ($this->asOwner)()->getJson("/api/v1/employees/{$a->id}")->assertStatus(200)->json('data');

    expect($shown['pay_type'])->toBe('monthly')
        ->and((float) $shown['monthly_salary'])->toBe(2500.0)
        ->and((float) $shown['hourly_rate'])->toBe(9.5);
});
