<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\Entity;
use App\Models\LeaveRequest;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Furniture', 'workflow_set' => ['production_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Plant', 'code' => 'FURN-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);

    $this->hire = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson('/api/v1/employees', array_merge([
            'name' => 'Salaried Carpenter',
            'operating_unit_id' => $this->unit->id,
            'job_title' => 'Carpenter',
            'pay_type' => 'monthly',
            'monthly_salary' => 3000,
            'hire_date' => '2026-01-01',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->calculate = function (string $period): array {
        $run = ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => $period])
            ->assertStatus(201)->json();

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
            ->assertStatus(200)->json();
    };
});

test('hiring records contract fields and opens the pay history', function () {
    $employee = ($this->hire)([
        'employee_number' => 'EMP-001',
        'contract_start' => '2026-01-01',
        'contract_end' => '2026-12-31',
        'probation_end' => '2026-03-31',
        'bank_name' => 'Jumhouria Bank',
        'bank_account' => 'LY-0012345',
    ]);

    expect($employee['employee_number'])->toBe('EMP-001')
        ->and($employee['contract_start'])->toBe('2026-01-01')
        ->and($employee['contract_end'])->toBe('2026-12-31')
        ->and($employee['probation_end'])->toBe('2026-03-31')
        ->and($employee['termination_date'])->toBeNull()
        ->and($employee['bank_name'])->toBe('Jumhouria Bank')
        ->and($employee['bank_account'])->toBe('LY-0012345');

    $history = ($this->asOwner)()->getJson("/api/v1/employees/{$employee['id']}/compensations")
        ->assertStatus(200)->json('data');

    expect($history)->toHaveCount(1)
        ->and($history[0]['pay_type'])->toBe('monthly')
        ->and((float) $history[0]['monthly_salary'])->toBe(3000.0)
        ->and($history[0]['effective_from'])->toBe('2026-01-01')
        ->and($history[0]['reason'])->toBe(EmployeeCompensation::OPENING_REASON)
        ->and($history[0]['created_by'])->toBe($this->owner->id);

    // The employee number is unique.
    ($this->asOwner)()->postJson('/api/v1/employees', [
        'name' => 'Second Carpenter',
        'employee_number' => 'EMP-001',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Carpenter',
        'pay_type' => 'monthly',
        'hire_date' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('employee_number');
});

test('contract fields are edited on the card and a contract end before its start is refused', function () {
    $employee = ($this->hire)(['contract_start' => '2026-01-01']);

    $updated = ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'employee_number' => 'EMP-007',
        'contract_end' => '2027-01-01',
        'termination_date' => '2026-11-30',
        'bank_name' => 'Wahda Bank',
        'record_version' => $employee['record_version'],
    ])->assertStatus(200)->json('data');

    expect($updated['employee_number'])->toBe('EMP-007')
        ->and($updated['contract_end'])->toBe('2027-01-01')
        ->and($updated['termination_date'])->toBe('2026-11-30')
        ->and($updated['bank_name'])->toBe('Wahda Bank');

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'contract_end' => '2025-12-31',
        'record_version' => $updated['record_version'],
    ])->assertStatus(422)->assertJsonValidationErrors('contract_end');
});

test('the employee edit no longer changes pay in place', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'monthly_salary' => 9000,
        'pay_type' => 'hourly',
        'record_version' => $employee['record_version'],
    ])->assertStatus(422)->assertJsonValidationErrors(['monthly_salary', 'pay_type']);

    expect((float) Employee::find($employee['id'])->monthly_salary)->toBe(3000.0);
});

test('piece-rate pay is refused on hire and on a pay change', function () {
    ($this->asOwner)()->postJson('/api/v1/employees', [
        'name' => 'Piece Worker',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Upholsterer',
        'pay_type' => 'piece_rate',
        'hire_date' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('pay_type');

    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'piece_rate',
        'effective_from' => '2026-05-01',
    ])->assertStatus(422)->assertJsonValidationErrors('pay_type');
});

test('a pay change appends history and updates the current terms', function () {
    $employee = ($this->hire)();

    $change = ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-05-01',
        'reason' => 'Annual raise',
    ])->assertStatus(201)->json('data');

    expect((float) $change['monthly_salary'])->toBe(3600.0)
        ->and($change['reason'])->toBe('Annual raise')
        ->and($change['creator']['id'])->toBe($this->owner->id);

    $history = ($this->asOwner)()->getJson("/api/v1/employees/{$employee['id']}/compensations")
        ->assertStatus(200)->json('data');

    // Newest first; the opening row is kept untouched.
    expect(collect($history)->pluck('effective_from')->all())->toBe(['2026-05-01', '2026-01-01'])
        ->and((float) $history[1]['monthly_salary'])->toBe(3000.0);

    expect((float) Employee::find($employee['id'])->monthly_salary)->toBe(3600.0);

    // One version per date.
    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 4000,
        'effective_from' => '2026-05-01',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    // A monthly change needs a salary.
    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'effective_from' => '2026-06-01',
    ])->assertStatus(422)->assertJsonValidationErrors('monthly_salary');
});

test('a future-dated pay change leaves the current terms alone until it takes effect', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 5000,
        'effective_from' => now()->addYear()->toDateString(),
    ])->assertStatus(201);

    expect((float) Employee::find($employee['id'])->monthly_salary)->toBe(3000.0);
});

test('payroll prorates the salary across a mid-month change', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-08-16',
    ])->assertStatus(201);

    $slips = collect(($this->calculate)('2026-08')['payslips'])->keyBy('employee_id');

    // August has 31 days: 1st-15th at 3000, 16th-31st at 3600.
    expect((float) $slips[$employee['id']]['base_pay'])->toBe(round(3000 * 15 / 31 + 3600 * 16 / 31, 4));
});

test('a month entirely before or after a change pays that month\'s salary in full', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-08-01',
    ])->assertStatus(201);

    $july = collect(($this->calculate)('2026-07')['payslips'])->keyBy('employee_id');
    $august = collect(($this->calculate)('2026-08')['payslips'])->keyBy('employee_id');

    expect((float) $july[$employee['id']]['base_pay'])->toBe(3000.0)
        ->and((float) $august[$employee['id']]['base_pay'])->toBe(3600.0);
});

test('hourly pay uses the personal rate in force on each attendance date', function () {
    $employee = ($this->hire)([
        'name' => 'Hourly Helper', 'pay_type' => 'hourly', 'monthly_salary' => null, 'hourly_rate' => 10,
    ]);

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'hourly',
        'hourly_rate' => 12,
        'effective_from' => '2026-08-04',
    ])->assertStatus(201);

    foreach (['2026-08-03', '2026-08-04'] as $day) {
        ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
            'work_date' => $day,
            'entries' => [['employee_id' => $employee['id'], 'status' => 'present', 'hours_worked' => 8]],
        ])->assertStatus(201);
    }

    $slips = collect(($this->calculate)('2026-08')['payslips'])->keyBy('employee_id');

    // 8h × 10 on the 3rd, 8h × 12 on the 4th.
    expect((float) $slips[$employee['id']]['attendance_pay'])->toBe(176.0);
});

test('only hr, accounting or the owner can change pay', function () {
    $employee = ($this->hire)();
    $payload = ['pay_type' => 'monthly', 'monthly_salary' => 3600, 'effective_from' => '2026-09-01'];

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/employees/{$employee['id']}/compensations", $payload)
        ->assertStatus(403)
        ->assertJsonPath('code', 'COMPENSATION_FORBIDDEN');

    $hr = User::factory()->create(['must_change_password' => false]);
    $hrRole = Role::create(['name' => 'HR Manager', 'slug' => 'hr-manager']);
    UserRole::create(['user_id' => $hr->id, 'role_id' => $hrRole->id, 'operating_unit_id' => null]);

    $this->actingAs($hr)
        ->postJson("/api/v1/employees/{$employee['id']}/compensations", $payload)
        ->assertStatus(201);

    $accountant = User::factory()->create(['must_change_password' => false]);
    $accountingRole = Role::create(['name' => 'Accounting Manager', 'slug' => 'accounting-manager']);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $accountingRole->id, 'operating_unit_id' => null]);

    $this->actingAs($accountant)
        ->postJson("/api/v1/employees/{$employee['id']}/compensations", [...$payload, 'effective_from' => '2026-10-01'])
        ->assertStatus(201);
});

test('a pay change cannot be dated into a period whose payroll left review', function () {
    $employee = ($this->hire)();

    $run = ($this->calculate)('2026-08');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-08-20',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-09-01',
    ])->assertStatus(201);
});

test('the backfill opens the pay history of existing employees once', function () {
    $entity = Entity::create(['name' => 'Legacy Worker', 'entity_type' => 'individual']);
    $legacy = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Worker',
        'pay_type' => 'hourly',
        'hourly_rate' => 7.5,
        'hire_date' => '2025-06-01',
        'status' => 'active',
    ]);

    $hired = ($this->hire)();

    $migration = require database_path('migrations/2026_10_31_110200_backfill_employee_compensations.php');
    $migration->up();
    $migration->up();

    $legacyHistory = EmployeeCompensation::where('employee_id', $legacy->id)->get();

    expect($legacyHistory)->toHaveCount(1)
        ->and($legacyHistory[0]->pay_type->value)->toBe('hourly')
        ->and((float) $legacyHistory[0]->hourly_rate)->toBe(7.5)
        ->and($legacyHistory[0]->effective_from->toDateString())->toBe('2025-06-01')
        ->and($legacyHistory[0]->reason)->toBe(EmployeeCompensation::OPENING_REASON);

    // An employee hired through the API already has history and is left alone.
    expect(EmployeeCompensation::where('employee_id', $hired['id'])->count())->toBe(1);
});

test('leave requests filter by employee', function () {
    $first = ($this->hire)();
    $second = ($this->hire)(['name' => 'Second Carpenter']);

    foreach ([$first, $second] as $employee) {
        ($this->asOwner)()->postJson('/api/v1/leave-requests', [
            'employee_id' => $employee['id'],
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-03',
            'leave_type' => 'annual',
        ])->assertStatus(201);
    }

    $listed = ($this->asOwner)()->getJson("/api/v1/leave-requests?employee_id={$first['id']}")
        ->assertStatus(200)->json();

    expect($listed['total'])->toBe(1)
        ->and($listed['data'][0]['employee_id'])->toBe($first['id'])
        ->and(LeaveRequest::count())->toBe(2);
});

test('a first pay change for an employee with no history keeps the old terms before it', function () {
    $this->travelTo('2026-10-31 12:00:00');

    $entity = Entity::create(['name' => 'Seeded Worker', 'entity_type' => 'individual']);
    $seeded = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Worker',
        'pay_type' => 'monthly',
        'monthly_salary' => 1000,
        'hire_date' => '2024-03-01',
        'status' => 'active',
    ]);

    ($this->asOwner)()->postJson("/api/v1/employees/{$seeded->id}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 2000,
        'effective_from' => '2026-09-16',
    ])->assertStatus(201);

    // The row's terms open the history from the hire date.
    $history = EmployeeCompensation::where('employee_id', $seeded->id)->orderBy('effective_from')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->effective_from->toDateString())->toBe('2024-03-01')
        ->and((float) $history[0]->monthly_salary)->toBe(1000.0)
        ->and($history[0]->reason)->toBe(EmployeeCompensation::OPENING_REASON);

    // September has 30 days: 1st-15th at 1000, 16th-30th at 2000.
    $slips = collect(($this->calculate)('2026-09')['payslips'])->keyBy('employee_id');

    expect((float) $slips[$seeded->id]['base_pay'])->toBe(round(1000 * 15 / 30 + 2000 * 15 / 30, 4));
});

test('a period is calculated as its own month whatever day it is run on', function () {
    // On the 31st, a period parsed without a fixed day rolls into the next month.
    $this->travelTo('2026-10-31 12:00:00');

    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-09-21',
    ])->assertStatus(201);

    $run = ($this->calculate)('2026-09');
    $slips = collect($run['payslips'])->keyBy('employee_id');

    // 1st-20th at 3000 and 21st-30th at 3600, over September's 30 days.
    expect((float) $slips[$employee['id']]['base_pay'])->toBe(round(3000 * 20 / 30 + 3600 * 10 / 30, 4));
});

test('a pay change before the hire date is refused', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 5000,
        'effective_from' => '2025-12-01',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    expect(EmployeeCompensation::where('employee_id', $employee['id'])->count())->toBe(1);
});

test('a future-dated change reaches the current terms once its date arrives', function () {
    $this->travelTo('2026-10-31 12:00:00');

    $employee = ($this->hire)(['monthly_salary' => 3500]);

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 4000,
        'effective_from' => '2026-11-15',
    ])->assertStatus(201);

    // Nothing changed on the row, so an open card keeps its record_version.
    $row = Employee::find($employee['id']);
    expect((float) $row->monthly_salary)->toBe(3500.0)
        ->and($row->record_version)->toBe($employee['record_version']);

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'job_title' => 'Senior Carpenter',
        'record_version' => $employee['record_version'],
    ])->assertStatus(200);

    $this->travelTo('2026-11-20 12:00:00');

    // The card shows the terms in force today even before the daily sync.
    ($this->asOwner)()->getJson("/api/v1/employees/{$employee['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.monthly_salary', 4000);

    $this->artisan('hr:sync-pay-terms')->assertSuccessful();

    expect((float) Employee::find($employee['id'])->monthly_salary)->toBe(4000.0);

    $version = Employee::find($employee['id'])->record_version;
    $this->artisan('hr:sync-pay-terms')->assertSuccessful();
    expect(Employee::find($employee['id'])->record_version)->toBe($version);
});

test('an hourly pay change needs a personal rate when the employee has no labor role', function () {
    $employee = ($this->hire)();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'hourly',
        'effective_from' => '2026-09-01',
    ])->assertStatus(422)->assertJsonValidationErrors('hourly_rate');

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'hourly',
        'hourly_rate' => 15,
        'effective_from' => '2026-09-01',
    ])->assertStatus(201);
});

test('a termination date before the hire date or the contract start is refused', function () {
    ($this->asOwner)()->postJson('/api/v1/employees', [
        'name' => 'Early Leaver',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Carpenter',
        'pay_type' => 'monthly',
        'monthly_salary' => 3000,
        'hire_date' => '2026-01-01',
        'termination_date' => '2025-12-31',
    ])->assertStatus(422)->assertJsonValidationErrors('termination_date');

    $employee = ($this->hire)(['hire_date' => '2026-01-01', 'contract_start' => '2026-02-01']);

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'termination_date' => '2026-01-15',
        'record_version' => $employee['record_version'],
    ])->assertStatus(422)->assertJsonValidationErrors('termination_date');
});

test('bank details are shown only to the roles that set pay', function () {
    $employee = ($this->hire)(['bank_name' => 'Wahda Bank', 'bank_account' => 'LY-998877']);

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson("/api/v1/employees/{$employee['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.job_title', 'Carpenter')
        ->assertJsonMissingPath('data.bank_name')
        ->assertJsonMissingPath('data.bank_account');

    ($this->asOwner)()->getJson("/api/v1/employees/{$employee['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.bank_account', 'LY-998877');
});

test('a pay change dated into a calculated period must be recalculated before submit', function () {
    $employee = ($this->hire)();

    $run = ($this->calculate)('2026-08');
    expect($run['needs_recalculation'])->toBeFalse();

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-08-16',
    ])->assertStatus(201);

    expect(PayrollRun::find($run['id'])->needs_recalculation)->toBeTrue();

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PAYROLL_TRANSITION');

    $recalculated = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
        ->assertStatus(200)->json();

    // 15 days at 3000 + 16 days at 3600 over 31.
    expect($recalculated['needs_recalculation'])->toBeFalse()
        ->and((float) $recalculated['payslips'][0]['base_pay'])->toBe(round((3000 * 15 + 3600 * 16) / 31, 4));

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);
});

test('hiring needs the pay the pay type depends on', function () {
    $base = [
        'name' => 'New Hire',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Helper',
        'hire_date' => '2026-01-01',
    ];

    ($this->asOwner)()->postJson('/api/v1/employees', [...$base, 'pay_type' => 'monthly'])
        ->assertStatus(422)->assertJsonValidationErrors('monthly_salary');

    ($this->asOwner)()->postJson('/api/v1/employees', [...$base, 'pay_type' => 'hourly'])
        ->assertStatus(422)->assertJsonValidationErrors('hourly_rate');

    ($this->asOwner)()->postJson('/api/v1/employees', [...$base, 'pay_type' => 'hourly', 'labor_role' => 'carpenter'])
        ->assertStatus(201);

    ($this->asOwner)()->postJson('/api/v1/employees', [...$base, 'pay_type' => 'hourly', 'hourly_rate' => 12])
        ->assertStatus(201);
});

test('a mistaken pay change no payroll covers yet can be removed', function () {
    $this->travelTo('2026-10-31 12:00:00');
    $employee = ($this->hire)();

    $mistake = ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 36000,
        'effective_from' => '2026-10-01',
    ])->assertStatus(201)->json('data');

    expect((float) Employee::find($employee['id'])->monthly_salary)->toBe(36000.0);

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson("/api/v1/employees/{$employee['id']}/compensations/{$mistake['id']}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'COMPENSATION_FORBIDDEN');

    ($this->asOwner)()->deleteJson("/api/v1/employees/{$employee['id']}/compensations/{$mistake['id']}")
        ->assertStatus(200);

    // The row's current terms fall back to the opening version.
    expect(EmployeeCompensation::where('employee_id', $employee['id'])->count())->toBe(1)
        ->and((float) Employee::find($employee['id'])->monthly_salary)->toBe(3000.0);

    // Corrected by recording the right amount on the same date.
    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-10-01',
    ])->assertStatus(201);

    $opening = EmployeeCompensation::where('employee_id', $employee['id'])
        ->where('reason', EmployeeCompensation::OPENING_REASON)->firstOrFail();

    ($this->asOwner)()->deleteJson("/api/v1/employees/{$employee['id']}/compensations/{$opening->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_COMPENSATION');
});

test('a pay change inside a frozen payroll period cannot be removed', function () {
    $employee = ($this->hire)();

    $change = ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3600,
        'effective_from' => '2026-08-16',
    ])->assertStatus(201)->json('data');

    $run = ($this->calculate)('2026-08');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    ($this->asOwner)()->deleteJson("/api/v1/employees/{$employee['id']}/compensations/{$change['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_COMPENSATION');

    expect(EmployeeCompensation::whereKey($change['id'])->exists())->toBeTrue();
});

test('only the roles that see bank details can change them', function () {
    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);
    $asManager = fn () => $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $asManager()->postJson('/api/v1/employees', [
        'name' => 'Unit Hire',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Helper',
        'pay_type' => 'monthly',
        'monthly_salary' => 2000,
        'hire_date' => '2026-01-01',
        'bank_account' => 'LY-ATTACKER',
    ])->assertStatus(403)->assertJsonPath('code', 'BANK_DETAILS_FORBIDDEN');

    $employee = ($this->hire)(['bank_name' => 'Wahda Bank', 'bank_account' => 'LY-998877']);

    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'bank_account' => 'LY-ATTACKER',
        'record_version' => $employee['record_version'],
    ])->assertStatus(403)->assertJsonPath('code', 'BANK_DETAILS_FORBIDDEN');

    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'bank_account' => null,
        'record_version' => $employee['record_version'],
    ])->assertStatus(403);

    expect(Employee::find($employee['id'])->bank_account)->toBe('LY-998877');

    // Editing other fields, or sending back the stored value, is fine.
    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'job_title' => 'Senior Carpenter',
        'bank_name' => 'Wahda Bank',
        'record_version' => $employee['record_version'],
    ])->assertStatus(200);
});

test('raw hr responses never carry bank details', function () {
    $employee = ($this->hire)(['bank_name' => 'Wahda Bank', 'bank_account' => 'LY-998877']);

    ($this->asOwner)()->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee['id'],
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'leave_type' => 'annual',
    ])->assertStatus(201);

    ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $employee['id'],
        'work_date' => '2026-08-10',
        'status' => 'present',
        'hours_worked' => 8,
    ])->assertStatus(201);

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    foreach (['/api/v1/leave-requests', '/api/v1/attendance'] as $uri) {
        $response = $this->actingAs($manager)
            ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
            ->getJson($uri)
            ->assertStatus(200);

        expect($response->getContent())->toContain($employee['id'])
            ->not->toContain('bank_account')
            ->not->toContain('LY-998877');
    }
});

test('correcting the hire date moves the start of the pay history', function () {
    $employee = ($this->hire)();

    $updated = ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'hire_date' => '2025-11-01',
        'record_version' => $employee['record_version'],
    ])->assertStatus(200)->json('data');

    expect(EmployeeCompensation::where('employee_id', $employee['id'])->sole()->effective_from->toDateString())
        ->toBe('2025-11-01');

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'monthly',
        'monthly_salary' => 3300,
        'effective_from' => '2025-12-01',
    ])->assertStatus(201);

    // The hire date cannot move onto or past a later pay change.
    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'hire_date' => '2025-12-01',
        'record_version' => Employee::find($employee['id'])->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    expect(Employee::find($employee['id'])->hire_date->toDateString())->toBe('2025-11-01');

    $updated = ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'hire_date' => '2025-11-15',
        'record_version' => Employee::find($employee['id'])->record_version,
    ])->assertStatus(200)->json('data');

    expect($updated['hire_date'])->toBe('2025-11-15')
        ->and(EmployeeCompensation::where('employee_id', $employee['id'])->orderBy('effective_from')->first()->effective_from->toDateString())
        ->toBe('2025-11-15');
});

test('the hire date cannot move inside a frozen payroll period', function () {
    $employee = ($this->hire)(['hire_date' => '2026-08-10']);

    $run = ($this->calculate)('2026-08');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'hire_date' => '2026-08-01',
        'record_version' => $employee['record_version'],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    expect(Employee::find($employee['id'])->hire_date->toDateString())->toBe('2026-08-10');
});

test('a hire date move that changes no frozen payslip is allowed after payroll is posted', function () {
    $veteran = ($this->hire)(['hire_date' => '2020-03-01']);
    $newcomer = ($this->hire)(['name' => 'September Hire', 'hire_date' => '2026-09-10']);

    $run = ($this->calculate)('2026-08');
    PayrollRun::whereKey($run['id'])->update(['status' => 'posted']);

    // Both dates before the first frozen period: every frozen month is paid in full either way.
    ($this->asOwner)()->putJson("/api/v1/employees/{$veteran['id']}", [
        'hire_date' => '2019-11-01',
        'record_version' => $veteran['record_version'],
    ])->assertStatus(200)->assertJsonPath('data.hire_date', '2019-11-01');

    expect(EmployeeCompensation::where('employee_id', $veteran['id'])->sole()->effective_from->toDateString())
        ->toBe('2019-11-01');

    // Both dates after the last frozen period: no frozen month pays the employee either way.
    ($this->asOwner)()->putJson("/api/v1/employees/{$newcomer['id']}", [
        'hire_date' => '2026-09-01',
        'record_version' => $newcomer['record_version'],
    ])->assertStatus(200)->assertJsonPath('data.hire_date', '2026-09-01');

    // Moving into the frozen span would change a posted payslip.
    ($this->asOwner)()->putJson("/api/v1/employees/{$veteran['id']}", [
        'hire_date' => '2026-08-15',
        'record_version' => Employee::find($veteran['id'])->record_version,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_COMPENSATION');

    expect(Employee::find($veteran['id'])->hire_date->toDateString())->toBe('2019-11-01');
});

test('a hire date correction flags calculated runs even without pay history', function () {
    $entity = Entity::create(['name' => 'Legacy Worker', 'entity_type' => 'individual']);
    $legacy = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Worker',
        'pay_type' => 'monthly',
        'monthly_salary' => 3000,
        'hire_date' => '2026-08-10',
        'status' => 'active',
    ]);

    expect(EmployeeCompensation::where('employee_id', $legacy->id)->exists())->toBeFalse();

    $run = ($this->calculate)('2026-08');
    expect($run['needs_recalculation'])->toBeFalse();

    // Monthly pay is prorated by the hire date, so the calculated run is stale.
    ($this->asOwner)()->putJson("/api/v1/employees/{$legacy->id}", [
        'hire_date' => '2026-08-01',
        'record_version' => $legacy->fresh()->record_version,
    ])->assertStatus(200);

    expect(PayrollRun::find($run['id'])->needs_recalculation)->toBeTrue();
});

test('the annual leave entitlement is a company setting, 30 days by default', function () {
    ($this->asOwner)()->getJson('/api/v1/hr-settings')
        ->assertStatus(200)
        ->assertJsonPath('annual_leave_days', 30);

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/hr-settings')
        ->assertStatus(200)
        ->assertJsonPath('annual_leave_days', 30);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson('/api/v1/hr-settings', ['annual_leave_days' => 45])
        ->assertStatus(403)
        ->assertJsonPath('code', 'HR_SETTINGS_FORBIDDEN');

    $hr = User::factory()->create(['must_change_password' => false]);
    $hrRole = Role::create(['name' => 'HR Manager', 'slug' => 'hr-manager']);
    UserRole::create(['user_id' => $hr->id, 'role_id' => $hrRole->id, 'operating_unit_id' => null]);

    $this->actingAs($hr)->putJson('/api/v1/hr-settings', ['annual_leave_days' => 25])
        ->assertStatus(200)
        ->assertJsonPath('annual_leave_days', 25);

    expect(Company::first()->annual_leave_days)->toBe(25);
});

test('leave requests filter by type and by the year they start in', function () {
    $employee = ($this->hire)();

    foreach ([['2025-12-30', 'annual'], ['2026-03-01', 'annual'], ['2026-04-01', 'sick']] as [$start, $type]) {
        ($this->asOwner)()->postJson('/api/v1/leave-requests', [
            'employee_id' => $employee['id'],
            'start_date' => $start,
            'end_date' => $start === '2025-12-30' ? '2026-01-02' : $start,
            'leave_type' => $type,
        ])->assertStatus(201);
    }

    $listed = ($this->asOwner)()->getJson("/api/v1/leave-requests?employee_id={$employee['id']}&leave_type=annual&year=2026")
        ->assertStatus(200)->json();

    expect($listed['total'])->toBe(1)
        ->and($listed['data'][0]['start_date'])->toStartWith('2026-03-01');

    ($this->asOwner)()->getJson('/api/v1/leave-requests?employee_id=not-a-uuid&leave_type=holiday&year=1999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id', 'leave_type', 'year']);

    ($this->asOwner)()->getJson('/api/v1/leave-requests?year=2101')
        ->assertStatus(422)
        ->assertJsonValidationErrors('year');
});
