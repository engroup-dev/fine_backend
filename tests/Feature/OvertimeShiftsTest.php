<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WorkShift;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W5-04 (HR-14): overtime, work shifts and daily pay.
 */
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

    $this->treasury = linkedTreasury($this->unit);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);

    $this->hire = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson('/api/v1/employees', array_merge([
            'name' => 'Worker',
            'operating_unit_id' => $this->unit->id,
            'job_title' => 'Carpenter',
            'pay_type' => 'monthly',
            'monthly_salary' => 2080,
            'hire_date' => '2026-01-01',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->shift = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson('/api/v1/work-shifts', array_merge([
            'name' => 'Day shift',
            'standard_hours' => 8,
            'overtime_multiplier' => 2,
            'start_time' => '07:00',
            'end_time' => '15:00',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->attend = function (string $employeeId, string $date, string $status, float $hours, float $overtime = 0): void {
        ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
            'work_date' => $date,
            'entries' => [[
                'employee_id' => $employeeId,
                'status' => $status,
                'hours_worked' => $hours,
                'overtime_hours' => $overtime,
            ]],
        ])->assertStatus(201);
    };

    $this->calculate = function (string $period): array {
        $run = ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => $period])
            ->assertStatus(201)->json();

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
            ->assertStatus(200)->json();
    };

    $this->slipOf = fn (array $run, string $employeeId): array => collect($run['payslips'])->firstWhere('employee_id', $employeeId);
});

test('hourly pay adds overtime at the hourly rate times the shift multiplier', function () {
    $shift = ($this->shift)(['overtime_multiplier' => 2]);
    $employee = ($this->hire)([
        'pay_type' => 'hourly', 'monthly_salary' => null, 'hourly_rate' => 10, 'work_shift_id' => $shift['id'],
    ]);

    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 2);
    ($this->attend)($employee['id'], '2026-08-04', 'present', 8, 1.5);
    // Overtime only, no regular hours: still paid.
    ($this->attend)($employee['id'], '2026-08-08', 'present', 0, 3);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // Regular 16 h × 10 = 160; overtime 6.5 h × 10 × 2 = 130.
    expect((float) $slip['attendance_pay'])->toBe(160.0)
        ->and((float) $slip['overtime_pay'])->toBe(130.0)
        ->and((float) $slip['gross_pay'])->toBe(290.0)
        ->and((float) $slip['net_pay'])->toBe(290.0);
});

test('an employee without a shift gets the company overtime multiplier', function () {
    $employee = ($this->hire)(['pay_type' => 'hourly', 'monthly_salary' => null, 'hourly_rate' => 10]);

    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 2);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // Default 1.5: 2 h × 10 × 1.5 = 30.
    expect((float) $slip['attendance_pay'])->toBe(80.0)
        ->and((float) $slip['overtime_pay'])->toBe(30.0)
        ->and((float) $slip['gross_pay'])->toBe(110.0);
});

test('daily pay counts present days, half days at half, and overtime on the shift standard hours', function () {
    $shift = ($this->shift)(['standard_hours' => 10, 'overtime_multiplier' => 1.5]);
    $employee = ($this->hire)([
        'pay_type' => 'daily', 'monthly_salary' => null, 'daily_rate' => 100, 'work_shift_id' => $shift['id'],
    ]);

    expect($employee['pay_type'])->toBe('daily')
        ->and((float) $employee['daily_rate'])->toBe(100.0)
        ->and($employee['work_shift_id'])->toBe($shift['id'])
        ->and($employee['work_shift']['name'])->toBe('Day shift');

    ($this->attend)($employee['id'], '2026-08-03', 'present', 10, 2);
    ($this->attend)($employee['id'], '2026-08-04', 'present', 10);
    ($this->attend)($employee['id'], '2026-08-05', 'half_day', 5);
    ($this->attend)($employee['id'], '2026-08-06', 'absent', 0);
    ($this->attend)($employee['id'], '2026-08-07', 'leave', 0);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // 2.5 days × 100 = 250; overtime 2 h × 100 / 10 × 1.5 = 30.
    expect((float) $slip['base_pay'])->toBe(0.0)
        ->and((float) $slip['attendance_pay'])->toBe(250.0)
        ->and((float) $slip['overtime_pay'])->toBe(30.0)
        ->and((float) $slip['gross_pay'])->toBe(280.0);
});

test('daily overtime without a shift uses an 8-hour standard day', function () {
    $employee = ($this->hire)(['pay_type' => 'daily', 'monthly_salary' => null, 'daily_rate' => 80]);

    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 4);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // 80 + 4 h × 80 / 8 × 1.5 = 80 + 60.
    expect((float) $slip['attendance_pay'])->toBe(80.0)
        ->and((float) $slip['overtime_pay'])->toBe(60.0);
});

test('monthly overtime is paid at salary over the company monthly standard hours', function () {
    $employee = ($this->hire)(['monthly_salary' => 2080]);

    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 4);
    ($this->attend)($employee['id'], '2026-08-04', 'absent', 0);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // 2080 / 208 = 10 an hour; 4 h × 10 × 1.5 = 60. Attendance adds nothing to a salary.
    expect((float) $slip['base_pay'])->toBe(2080.0)
        ->and((float) $slip['attendance_pay'])->toBe(0.0)
        ->and((float) $slip['overtime_pay'])->toBe(60.0)
        ->and((float) $slip['gross_pay'])->toBe(2140.0);
});

test('the company standard hours and default multiplier are HR settings', function () {
    $settings = ($this->asOwner)()->getJson('/api/v1/hr-settings')->assertStatus(200)->json();

    expect((float) $settings['monthly_standard_hours'])->toBe(208.0)
        ->and((float) $settings['overtime_multiplier'])->toBe(1.5);

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['monthly_standard_hours' => 160, 'overtime_multiplier' => 2])
        ->assertStatus(200)
        ->assertJsonPath('monthly_standard_hours', 160)
        ->assertJsonPath('overtime_multiplier', 2);

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['monthly_standard_hours' => 0])
        ->assertStatus(422)->assertJsonValidationErrors('monthly_standard_hours');
    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['overtime_multiplier' => 0.5])
        ->assertStatus(422)->assertJsonValidationErrors('overtime_multiplier');

    $employee = ($this->hire)(['monthly_salary' => 1600]);
    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 3);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // 1600 / 160 = 10 an hour; 3 h × 10 × 2 = 60.
    expect((float) $slip['overtime_pay'])->toBe(60.0);
});

test('overtime follows the pay terms in force on the day across a mid-month change to daily pay', function () {
    $employee = ($this->hire)(['monthly_salary' => 3120]);

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'daily',
        'daily_rate' => 100,
        'effective_from' => '2026-08-16',
        'reason' => 'Moved to daily pay',
    ])->assertStatus(201)->assertJsonPath('data.daily_rate', 100);

    // Monthly day: 2 h × 3120 / 208 × 1.5 = 45. Daily day: 100 + 1 h × 100 / 8 × 1.5 = 100 + 18.75.
    ($this->attend)($employee['id'], '2026-08-10', 'present', 8, 2);
    ($this->attend)($employee['id'], '2026-08-20', 'present', 8, 1);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // Salary for 1-15 August: 3120 × 15 / 31.
    expect((float) $slip['base_pay'])->toBe(round(3120 * 15 / 31, 4))
        ->and((float) $slip['attendance_pay'])->toBe(100.0)
        ->and((float) $slip['overtime_pay'])->toBe(63.75);
});

test('daily pay needs a daily rate and the employee edit cannot change it', function () {
    ($this->asOwner)()->postJson('/api/v1/employees', [
        'name' => 'Day Worker',
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Helper',
        'pay_type' => 'daily',
        'hire_date' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('daily_rate');

    $employee = ($this->hire)(['pay_type' => 'daily', 'monthly_salary' => null, 'daily_rate' => 90]);

    $history = ($this->asOwner)()->getJson("/api/v1/employees/{$employee['id']}/compensations")
        ->assertStatus(200)->json('data');

    expect($history[0]['pay_type'])->toBe('daily')
        ->and((float) $history[0]['daily_rate'])->toBe(90.0);

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'daily_rate' => 120,
        'record_version' => $employee['record_version'],
    ])->assertStatus(422)->assertJsonValidationErrors('daily_rate');

    ($this->asOwner)()->postJson("/api/v1/employees/{$employee['id']}/compensations", [
        'pay_type' => 'daily',
        'effective_from' => '2026-09-01',
    ])->assertStatus(422)->assertJsonValidationErrors('daily_rate');
});

test('attendance refuses more than 24 hours a day and overtime on a day off', function () {
    $employee = ($this->hire)();

    // Bulk: regular + overtime over 24.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'present', 'hours_worked' => 20, 'overtime_hours' => 5]],
    ])->assertStatus(422)->assertJsonValidationErrors('entries.0.overtime_hours');

    // Overtime alone above 24.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'present', 'hours_worked' => 0, 'overtime_hours' => 25]],
    ])->assertStatus(422)->assertJsonValidationErrors('entries.0.overtime_hours');

    // Overtime on an absence or leave would never be paid.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'absent', 'overtime_hours' => 2]],
    ])->assertStatus(422)->assertJsonValidationErrors('entries.0.overtime_hours');

    // Exactly 24 is fine.
    ($this->attend)($employee['id'], '2026-08-03', 'present', 16, 8);
    expect((float) Attendance::sole()->overtime_hours)->toBe(8.0);

    // Single entry: a present day defaults to 8 h, so 17 h overtime is too much.
    ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $employee['id'], 'work_date' => '2026-08-04', 'status' => 'present', 'overtime_hours' => 17,
    ])->assertStatus(422)->assertJsonValidationErrors('overtime_hours');

    $created = ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $employee['id'], 'work_date' => '2026-08-04', 'status' => 'present', 'overtime_hours' => 2,
    ])->assertStatus(201)->json();

    expect((float) $created['hours_worked'])->toBe(8.0)
        ->and((float) $created['overtime_hours'])->toBe(2.0);

    // Update: checked against the stored hours.
    ($this->asOwner)()->putJson("/api/v1/attendance/{$created['id']}", ['overtime_hours' => 16.5])
        ->assertStatus(422)->assertJsonValidationErrors('overtime_hours');
    ($this->asOwner)()->putJson("/api/v1/attendance/{$created['id']}", ['status' => 'leave'])
        ->assertStatus(422)->assertJsonValidationErrors('overtime_hours');
    ($this->asOwner)()->putJson("/api/v1/attendance/{$created['id']}", ['overtime_hours' => 4])
        ->assertStatus(200)->assertJsonPath('overtime_hours', '4.00');
});

test('work shifts are managed by the pay roles and cannot be deleted while assigned', function () {
    $created = ($this->asOwner)()->postJson('/api/v1/work-shifts', [
        'name' => 'Night shift', 'standard_hours' => 7.5, 'start_time' => '22:00', 'end_time' => '05:30',
    ])->assertStatus(201)->json('data');

    expect((float) $created['overtime_multiplier'])->toBe(1.5)
        ->and((float) $created['standard_hours'])->toBe(7.5)
        ->and($created['start_time'])->toBe('22:00')
        ->and($created['end_time'])->toBe('05:30');

    ($this->asOwner)()->postJson('/api/v1/work-shifts', ['name' => 'Bad', 'standard_hours' => 0])
        ->assertStatus(422)->assertJsonValidationErrors('standard_hours');
    ($this->asOwner)()->postJson('/api/v1/work-shifts', ['name' => 'Bad', 'standard_hours' => 8, 'overtime_multiplier' => 0.8])
        ->assertStatus(422)->assertJsonValidationErrors('overtime_multiplier');

    $updated = ($this->asOwner)()->putJson("/api/v1/work-shifts/{$created['id']}", [
        'overtime_multiplier' => 1.75,
        'record_version' => $created['record_version'],
    ])->assertStatus(200)->json('data');

    expect((float) $updated['overtime_multiplier'])->toBe(1.75)
        ->and($updated['name'])->toBe('Night shift');

    expect(($this->asOwner)()->getJson('/api/v1/work-shifts')->assertStatus(200)->json('data'))->toHaveCount(1);

    // A store manager reads HR but does not set pay.
    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/work-shifts', ['name' => 'Mine', 'standard_hours' => 8])
        ->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');

    // Nor edits or deletes one: either would change overtime pay for every holder.
    $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/work-shifts/{$created['id']}", [
            'overtime_multiplier' => 5,
            'record_version' => $updated['record_version'],
        ])
        ->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');
    $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson("/api/v1/work-shifts/{$created['id']}")
        ->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');

    $untouched = WorkShift::find($created['id']);
    expect($untouched)->not->toBeNull()
        ->and((float) $untouched->overtime_multiplier)->toBe(1.75)
        ->and($untouched->record_version)->toBe($updated['record_version']);

    // Assigned through the employee edit; then the shift cannot be deleted.
    $employee = ($this->hire)();
    $edited = ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => $created['id'],
        'record_version' => $employee['record_version'],
    ])->assertStatus(200)->json('data');

    expect($edited['work_shift_id'])->toBe($created['id']);

    ($this->asOwner)()->deleteJson("/api/v1/work-shifts/{$created['id']}")
        ->assertStatus(422)->assertJsonPath('code', 'WORK_SHIFT_IN_USE');

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => null,
        'record_version' => $edited['record_version'],
    ])->assertStatus(200)->assertJsonPath('data.work_shift_id', null);

    ($this->asOwner)()->deleteJson("/api/v1/work-shifts/{$created['id']}")->assertStatus(200);

    expect(WorkShift::count())->toBe(0);

    // A deleted shift cannot be assigned.
    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => $created['id'],
        'record_version' => Employee::find($employee['id'])->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
});

test('posting debits overtime to wage expense with the rest of gross', function () {
    $employee = ($this->hire)(['monthly_salary' => 2080]);
    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 4);

    $run = ($this->calculate)('2026-08');

    expect((float) $run['total_gross'])->toBe(2140.0);

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/mark-paid")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/post", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(200);

    $lines = JournalEntry::where('source_document_type', 'PayrollRun')
        ->where('description', 'Payroll 2026-08 accrued')
        ->sole()
        ->lines()->with('account')->get();

    expect((float) $lines->filter(fn ($l) => $l->account->account_code === '57')->sum('debit'))->toBe(2140.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '226')->credit)->toBe(2140.0);
});

test('only the pay roles assign or change an employee shift', function () {
    $shift = ($this->shift)(['overtime_multiplier' => 3]);

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
        'work_shift_id' => $shift['id'],
    ])->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');

    $employee = ($this->hire)();

    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => $shift['id'],
        'record_version' => $employee['record_version'],
    ])->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');

    expect(Employee::find($employee['id'])->work_shift_id)->toBeNull();

    $assigned = ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => $shift['id'],
        'record_version' => $employee['record_version'],
    ])->assertStatus(200)->json('data');

    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'work_shift_id' => null,
        'record_version' => $assigned['record_version'],
    ])->assertStatus(403)->assertJsonPath('code', 'WORK_SHIFT_FORBIDDEN');

    // Sending back the stored shift with another edit is not a change.
    $asManager()->putJson("/api/v1/employees/{$employee['id']}", [
        'job_title' => 'Senior Carpenter',
        'work_shift_id' => $shift['id'],
        'record_version' => $assigned['record_version'],
    ])->assertStatus(200);
});

test('a re-saved attendance day keeps its overtime when the field is not sent', function () {
    $employee = ($this->hire)(['pay_type' => 'hourly', 'monthly_salary' => null, 'hourly_rate' => 10]);

    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 2);

    // An older grid re-saves the day without the overtime field.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'present', 'hours_worked' => 8, 'notes' => 'checked']],
    ])->assertStatus(201);

    $day = Attendance::where('employee_id', $employee['id'])->sole();
    expect((float) $day->overtime_hours)->toBe(2.0)->and($day->notes)->toBe('checked');

    ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $employee['id'], 'work_date' => '2026-08-03', 'status' => 'half_day', 'hours_worked' => 4,
    ])->assertStatus(201);

    expect((float) $day->refresh()->overtime_hours)->toBe(2.0);

    // The kept overtime still counts toward the 24-hour day.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'present', 'hours_worked' => 23]],
    ])->assertStatus(422)->assertJsonValidationErrors('entries.0.overtime_hours');

    // A day off carries no overtime: the status change clears it.
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $employee['id'], 'status' => 'absent']],
    ])->assertStatus(201);

    expect((float) $day->refresh()->overtime_hours)->toBe(0.0);

    // A new day without the field starts at zero; sending null clears it.
    ($this->attend)($employee['id'], '2026-08-04', 'present', 8, 1);
    ($this->asOwner)()->postJson('/api/v1/attendance', [
        'employee_id' => $employee['id'], 'work_date' => '2026-08-05', 'status' => 'present',
    ])->assertStatus(201)->assertJsonPath('overtime_hours', '0.00');

    $second = Attendance::where('employee_id', $employee['id'])->whereDate('work_date', '2026-08-04')->sole();
    ($this->asOwner)()->putJson("/api/v1/attendance/{$second->id}", ['overtime_hours' => null])->assertStatus(200);
    expect((float) $second->refresh()->overtime_hours)->toBe(0.0);
});

test('changing an overtime input flags calculated runs that paid overtime', function () {
    $shift = ($this->shift)(['overtime_multiplier' => 2]);
    $withOvertime = ($this->hire)(['work_shift_id' => $shift['id']]);
    $other = ($this->hire)(['name' => 'Other']);
    ($this->attend)($withOvertime['id'], '2026-08-03', 'present', 8, 2);

    $run = ($this->calculate)('2026-08');
    $flagged = fn (): bool => (bool) PayrollRun::find($run['id'])->needs_recalculation;
    $reset = fn () => PayrollRun::whereKey($run['id'])->update(['needs_recalculation' => false]);

    expect($flagged())->toBeFalse();

    // A shift edit that leaves the overtime terms alone does not flag.
    $current = WorkShift::find($shift['id']);
    ($this->asOwner)()->putJson("/api/v1/work-shifts/{$shift['id']}", [
        'name' => 'Renamed', 'record_version' => $current->record_version,
    ])->assertStatus(200);
    expect($flagged())->toBeFalse();

    ($this->asOwner)()->putJson("/api/v1/work-shifts/{$shift['id']}", [
        'overtime_multiplier' => 3, 'record_version' => $current->refresh()->record_version,
    ])->assertStatus(200);
    expect($flagged())->toBeTrue();
    $reset();

    ($this->asOwner)()->putJson("/api/v1/work-shifts/{$shift['id']}", [
        'standard_hours' => 9, 'record_version' => $current->refresh()->record_version,
    ])->assertStatus(200);
    expect($flagged())->toBeTrue();
    $reset();

    // Moving an employee without overtime onto the shift changes nothing paid.
    ($this->asOwner)()->putJson("/api/v1/employees/{$other['id']}", [
        'work_shift_id' => $shift['id'], 'record_version' => Employee::find($other['id'])->record_version,
    ])->assertStatus(200);
    expect($flagged())->toBeFalse();

    ($this->asOwner)()->putJson("/api/v1/employees/{$withOvertime['id']}", [
        'work_shift_id' => null, 'record_version' => Employee::find($withOvertime['id'])->record_version,
    ])->assertStatus(200);
    expect($flagged())->toBeTrue();
    $reset();

    // Re-saving the same settings does not flag; a change does.
    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['monthly_standard_hours' => 208])->assertStatus(200);
    expect($flagged())->toBeFalse();

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['monthly_standard_hours' => 160])->assertStatus(200);
    expect($flagged())->toBeTrue();
    $reset();

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['overtime_multiplier' => 2])->assertStatus(200);
    expect($flagged())->toBeTrue();

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(422);
});

test('a trashed employee keeps a deleted shift terms and can send the stored shift back once restored', function () {
    $shift = ($this->shift)(['overtime_multiplier' => 3]);
    $employee = ($this->hire)(['monthly_salary' => 2080, 'work_shift_id' => $shift['id']]);
    ($this->attend)($employee['id'], '2026-08-03', 'present', 8, 4);

    Employee::find($employee['id'])->delete();

    // A trashed employee cannot be reassigned, so it does not block the delete.
    ($this->asOwner)()->deleteJson("/api/v1/work-shifts/{$shift['id']}")->assertStatus(200);

    $slip = ($this->slipOf)(($this->calculate)('2026-08'), $employee['id']);

    // Still the deleted shift's multiplier, not the company 1.5: 4 h × 10 × 3 = 120.
    expect((float) $slip['overtime_pay'])->toBe(120.0);

    $restored = Employee::withTrashed()->find($employee['id']);
    $restored->restore();

    ($this->asOwner)()->putJson("/api/v1/employees/{$employee['id']}", [
        'job_title' => 'Senior Carpenter',
        'work_shift_id' => $shift['id'],
        'record_version' => $restored->refresh()->record_version,
    ])->assertStatus(200)
        ->assertJsonPath('data.work_shift_id', $shift['id'])
        ->assertJsonPath('data.job_title', 'Senior Carpenter');

    // Assigning the deleted shift to someone else is still refused.
    $other = ($this->hire)();
    ($this->asOwner)()->putJson("/api/v1/employees/{$other['id']}", [
        'work_shift_id' => $shift['id'],
        'record_version' => $other['record_version'],
    ])->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
});

test('a unit role cannot record attendance or overtime for another unit employee', function () {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Store Two', 'code' => 'STORE-02', 'unit_type' => 'store', 'status' => 'active',
    ]);

    $ours = ($this->hire)();
    $theirs = ($this->hire)([
        'operating_unit_id' => $otherUnit->id, 'pay_type' => 'daily', 'monthly_salary' => null, 'daily_rate' => 100,
    ]);

    $manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);
    $asManager = fn () => $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $asManager()->postJson('/api/v1/attendance', [
        'employee_id' => $theirs['id'], 'work_date' => '2026-08-03', 'status' => 'present', 'overtime_hours' => 8,
    ])->assertStatus(404);

    $asManager()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [
            ['employee_id' => $ours['id'], 'status' => 'present', 'hours_worked' => 8],
            ['employee_id' => $theirs['id'], 'status' => 'present', 'hours_worked' => 8, 'overtime_hours' => 8],
        ],
    ])->assertStatus(404);

    // Nothing was written, not even the own-unit row of the refused bulk save.
    expect(Attendance::withoutGlobalScopes()->count())->toBe(0);

    // The manager still records their own unit.
    $asManager()->postJson('/api/v1/attendance', [
        'employee_id' => $ours['id'], 'work_date' => '2026-08-03', 'status' => 'present', 'overtime_hours' => 2,
    ])->assertStatus(201);

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $ours['id'])->value('overtime_hours'))->toEqual(2);
});
