<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\ReferenceLookup;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Database\Seeders\ReferenceLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);
    $this->seed(ReferenceLookupSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $entity = Entity::create(['name' => 'Worker A', 'entity_type' => 'individual']);
    $this->employee = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Operator',
        'pay_type' => 'monthly',
        'monthly_salary' => 2000,
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->treasury = linkedTreasury($this->unit, '121101');

    $this->asOwner = fn () => $this->actingAs($this->owner);

    $this->addItem = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson("/api/v1/employees/{$this->employee->id}/pay-items", array_merge([
            'kind' => 'addition',
            'code' => 'ALW-TRN',
            'calc' => 'fixed',
            'value' => 150,
            'effective_from' => '2026-10-01',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->addAdjustment = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson('/api/v1/employee-adjustments', array_merge([
            'employee_id' => $this->employee->id,
            'period' => '2026-10',
            'kind' => 'addition',
            'code' => 'ALW-PERF',
            'amount' => 250,
            'reason' => 'Monthly target met',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->approve = fn (string $id) => ($this->asOwner)()
        ->postJson("/api/v1/employee-adjustments/{$id}/approve")
        ->assertStatus(200)->json('data');

    $this->openRun = fn (string $period): array => ($this->asOwner)()
        ->postJson('/api/v1/payroll-runs', ['period' => $period])->assertStatus(201)->json();

    $this->calculate = function (string $period): array {
        $run = ($this->openRun)($period);

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();
    };

    $this->postRun = function (string $runId): void {
        foreach (['submit', 'approve', 'mark-paid'] as $step) {
            ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$runId}/{$step}")->assertStatus(200);
        }

        ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$runId}/post", ['cash_account_id' => $this->treasury->id])
            ->assertStatus(200);
    };

    $this->staff = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->accrual = fn (string $runId) => JournalEntry::with('lines.account')
        ->where('source_document_type', 'PayrollRun')
        ->where('source_document_id', $runId)
        ->where('description', 'like', '%accrued')
        ->firstOrFail();
});

test('standing fixed and percentage items add to gross and deduct as locked lines', function (): void {
    ($this->addItem)(); // transport 150 fixed
    ($this->addItem)(['code' => 'ALW-RISK', 'calc' => 'percent_of_base', 'value' => 7.5]); // 150
    ($this->addItem)(['kind' => 'deduction', 'code' => 'DED-SSF', 'calc' => 'percent_of_base', 'value' => 3.75]); // 75

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect((float) $payslip->base_pay)->toBe(2000.0)
        ->and((float) $payslip->additions_pay)->toBe(300.0)
        ->and($payslip->additions)->toHaveCount(2)
        ->and($payslip->additions[0]['type'])->toBe('ALW-TRN')
        ->and($payslip->additions[0]['source'])->toBe('pay_item')
        ->and((float) $payslip->additions[1]['amount'])->toBe(150.0)
        ->and((float) $payslip->gross_pay)->toBe(2300.0)
        ->and($payslip->deductions)->toHaveCount(1)
        ->and($payslip->deductions[0]['type'])->toBe('DED-SSF')
        ->and($payslip->deductions[0]['locked'])->toBeTrue()
        ->and($payslip->deductions[0]['account_role'])->toBe('liability')
        // The percentage is of base + attendance pay, not of the allowances.
        ->and((float) $payslip->deductions[0]['amount'])->toBe(75.0)
        ->and((float) $payslip->net_pay)->toBe(2225.0)
        ->and((float) $run['total_gross'])->toBe(2300.0)
        ->and((float) $run['total_net'])->toBe(2225.0);
});

test('items apply only while in force and active', function (): void {
    ($this->addItem)(['effective_from' => '2026-11-01']);
    ($this->addItem)(['code' => 'ALW-HOU', 'value' => 300, 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31']);
    $inactive = ($this->addItem)(['code' => 'ALW-RISK', 'value' => 50, 'active' => false]);

    $october = ($this->calculate)('2026-10');
    expect((float) Payslip::where('payroll_run_id', $october['id'])->firstOrFail()->additions_pay)->toBe(300.0);

    ($this->asOwner)()->putJson("/api/v1/employees/{$this->employee->id}/pay-items/{$inactive['id']}", ['active' => true])
        ->assertStatus(200)
        ->assertJsonPath('data.active', true);

    // The change reached the calculated October run.
    expect(PayrollRun::findOrFail($october['id'])->needs_recalculation)->toBeTrue();

    $november = ($this->calculate)('2026-11');
    expect((float) Payslip::where('payroll_run_id', $november['id'])->firstOrFail()->additions_pay)->toBe(200.0);
});

test('a one-off adjustment counts only once approved', function (): void {
    $bonus = ($this->addAdjustment)();
    $penalty = ($this->addAdjustment)(['kind' => 'deduction', 'code' => 'DED-LATE', 'amount' => 40, 'reason' => 'Late x4']);

    expect($bonus['status'])->toBe('draft');

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect((float) $payslip->gross_pay)->toBe(2000.0)
        ->and($payslip->deductions)->toBe([]);

    ($this->approve)($bonus['id']);
    $approved = ($this->approve)($penalty['id']);

    expect($approved['status'])->toBe('approved')
        ->and($approved['approved_by']['id'])->toBe($this->owner->id)
        ->and(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();

    $run = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect((float) $payslip->additions_pay)->toBe(250.0)
        ->and($payslip->additions[0]['source'])->toBe('adjustment')
        ->and($payslip->additions[0]['adjustment_id'])->toBe($bonus['id'])
        ->and((float) $payslip->gross_pay)->toBe(2250.0)
        ->and($payslip->deductions[0]['adjustment_id'])->toBe($penalty['id'])
        ->and($payslip->deductions[0]['account_role'])->toBe('expense_reduction')
        ->and((float) $payslip->net_pay)->toBe(2210.0);

    // Another period is unaffected.
    $november = ($this->calculate)('2026-11');
    expect((float) Payslip::where('payroll_run_id', $november['id'])->firstOrFail()->gross_pay)->toBe(2000.0);
});

test('posting routes additions to 57, liability deductions to 225 and expense reductions back to 57', function (): void {
    ($this->addItem)(); // +150
    ($this->addItem)(['kind' => 'deduction', 'code' => 'DED-TAX', 'calc' => 'percent_of_base', 'value' => 5]); // 100 → 225
    ($this->approve)(($this->addAdjustment)(['kind' => 'deduction', 'code' => 'DED-ABS', 'amount' => 80, 'reason' => 'Absent'])['id']); // 80 → 57

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    // A manual line typed as a deduction-type code takes its role; any other is a withholding.
    ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
        'deductions' => [
            ...$payslip->deductions,
            ['type' => 'DED-PEN', 'amount' => 20],
            ['type' => 'union fee', 'amount' => 10],
        ],
    ])->assertStatus(200)
        ->assertJsonPath('deductions.2.account_role', 'expense_reduction')
        ->assertJsonPath('deductions.3.account_role', 'liability')
        ->assertJsonPath('net_pay', '1940.0000');

    ($this->postRun)($run['id']);

    $accrual = ($this->accrual)($run['id']);
    $byCode = fn (string $code) => $accrual->lines->filter(fn ($line) => $line->account->account_code === $code);

    expect((float) $byCode('57')->sum('debit'))->toBe(2150.0)
        ->and((float) $byCode('57')->firstWhere('memo', 'allowances and bonuses')->debit)->toBe(150.0)
        ->and((float) $byCode('57')->sum('credit'))->toBe(100.0) // 80 absence + 20 penalty
        ->and((float) $byCode('225')->sum('credit'))->toBe(110.0) // 100 tax + 10 untyped
        ->and((float) $byCode('226')->sum('credit'))->toBe(1940.0)
        ->and((float) $accrual->lines->sum('debit'))->toBe((float) $accrual->lines->sum('credit'));
});

test('standing deductions count like manual deductions for the advance cap', function (): void {
    ($this->addItem)(['kind' => 'deduction', 'code' => 'DED-ABS', 'value' => 400]);

    $advance = ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id, 'type' => 'loan', 'amount' => 2000, 'installments' => 2, 'start_period' => '2026-10',
    ])->assertStatus(201)->json('data');
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])->assertStatus(200);

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    // 50% of (2000 − 400) = 800, not 50% of 2000.
    expect($payslip->deductions)->toHaveCount(2)
        ->and($payslip->deductions[0]['type'])->toBe('DED-ABS')
        ->and($payslip->deductions[1]['type'])->toBe('advance')
        ->and((float) $payslip->deductions[1]['amount'])->toBe(800.0)
        ->and((float) $payslip->net_pay)->toBe(800.0);

    // The editor keeps the locked lines; a manual line lowers the advance line only.
    $updated = ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
        'deductions' => [
            [...$payslip->deductions[0], 'amount' => 1],
            ['type' => 'DED-LATE', 'amount' => 200],
        ],
    ])->assertStatus(200)->json();

    expect($updated['deductions'])->toHaveCount(3)
        ->and((float) $updated['deductions'][0]['amount'])->toBe(400.0)
        ->and($updated['deductions'][1]['type'])->toBe('advance')
        ->and((float) $updated['deductions'][1]['amount'])->toBe(700.0)
        ->and((float) $updated['net_pay'])->toBe(700.0);
});

test('net pay never goes negative', function (): void {
    ($this->approve)(($this->addAdjustment)(['kind' => 'deduction', 'code' => 'DED-ABS', 'amount' => 2100, 'reason' => 'Absent'])['id']);

    $run = ($this->openRun)('2026-10');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYROLL_DEDUCTIONS_EXCEED_GROSS')
        ->assertJsonPath('employee_id', $this->employee->id)
        ->assertJsonPath('employees.0.employee_id', $this->employee->id)
        ->assertJsonCount(1, 'employees');

    expect(PayrollRun::findOrFail($run['id'])->status->value)->toBe('draft');

    // An addition in the period covers it.
    ($this->approve)(($this->addAdjustment)(['amount' => 100])['id']);

    $run = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect((float) $payslip->net_pay)->toBe(0.0);

    // A manual line on top would push it below zero.
    ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
        'deductions' => [['type' => 'DED-LATE', 'amount' => 1]],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_DEDUCTIONS');
});

test('an employee who earned nothing in the period gets no payslip and no allowances', function (): void {
    $this->employee->update(['hire_date' => '2026-11-15']);
    DB::table('employee_compensations')->where('employee_id', $this->employee->id)->update(['effective_from' => '2026-11-15']);
    ($this->addItem)();

    $run = ($this->calculate)('2026-10');

    expect(Payslip::where('payroll_run_id', $run['id'])->count())->toBe(0);
});

test('codes must be active lookup entries of the kind, and never advance recovery', function (): void {
    $post = fn (array $data) => ($this->asOwner)()->postJson("/api/v1/employees/{$this->employee->id}/pay-items", array_merge([
        'kind' => 'addition', 'code' => 'ALW-TRN', 'calc' => 'fixed', 'value' => 10, 'effective_from' => '2026-10-01',
    ], $data));

    $post(['kind' => 'deduction', 'code' => 'ALW-TRN'])->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_UNKNOWN');
    $post(['code' => 'NOPE'])->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_UNKNOWN');
    $post(['kind' => 'deduction', 'code' => 'DED-ADV'])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_DEDUCTION_MANUAL');
    $post(['calc' => 'percent_of_base', 'value' => 101])->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_INVALID_VALUE');

    ($this->asOwner)()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $this->employee->id, 'period' => '2026-10', 'kind' => 'deduction',
        'code' => 'advance', 'amount' => 10, 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_DEDUCTION_MANUAL');

    // DED-PEN is seeded inactive.
    $post(['kind' => 'deduction', 'code' => 'DED-PEN'])->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_UNKNOWN');

    // Lower case resolves to the lookup's code.
    expect($post(['code' => 'alw-hou'])->assertStatus(201)->json('data.code'))->toBe('ALW-HOU');
});

test('only the pay roles change standing items; adjustments are approved by approve-pay-adjustments holders', function (): void {
    $storeManager = ($this->staff)('store-manager', $this->unit->id);
    $hrManager = ($this->staff)('hr-manager');
    $asStoreManager = fn () => $this->actingAs($storeManager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $asStoreManager()->postJson("/api/v1/employees/{$this->employee->id}/pay-items", [
        'kind' => 'addition', 'code' => 'ALW-TRN', 'calc' => 'fixed', 'value' => 10, 'effective_from' => '2026-10-01',
    ])->assertStatus(403)->assertJsonPath('code', 'PAY_ITEM_FORBIDDEN');

    $asStoreManager()->getJson("/api/v1/employees/{$this->employee->id}/pay-items")->assertStatus(200);

    $draft = $asStoreManager()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $this->employee->id, 'period' => '2026-10', 'kind' => 'addition',
        'code' => 'ALW-PERF', 'amount' => 100, 'reason' => 'Good month',
    ])->assertStatus(201)->json('data');

    expect($draft['created_by']['id'])->toBe($storeManager->id)
        ->and($draft['employee']['name'])->toBe('Worker A');

    $asStoreManager()->postJson("/api/v1/employee-adjustments/{$draft['id']}/approve")->assertStatus(403);

    $this->actingAs($hrManager)->postJson("/api/v1/employee-adjustments/{$draft['id']}/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by.id', $hrManager->id);

    // Approved: no more edits, and only an approver removes it.
    $asStoreManager()->putJson("/api/v1/employee-adjustments/{$draft['id']}", ['amount' => 5])
        ->assertStatus(422)->assertJsonPath('code', 'PAY_ADJUSTMENT_NOT_DRAFT');
    $asStoreManager()->deleteJson("/api/v1/employee-adjustments/{$draft['id']}")
        ->assertStatus(403)->assertJsonPath('code', 'PAY_ADJUSTMENT_DELETE_FORBIDDEN');
    $this->actingAs($hrManager)->deleteJson("/api/v1/employee-adjustments/{$draft['id']}")->assertStatus(200);

    expect(EmployeeAdjustment::count())->toBe(0)
        ->and(Permission::where('slug', 'approve-pay-adjustments')->exists())->toBeTrue()
        ->and(Role::where('slug', 'hr-manager')->first()->permissions->pluck('slug'))->toContain('approve-pay-adjustments')
        ->and(Role::where('slug', 'store-manager')->first()->permissions->pluck('slug'))->not->toContain('approve-pay-adjustments');
});

test('a unit user sees and adjusts only their unit employees', function (): void {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Cutter', 'code' => 'CUT-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $manager = ($this->staff)('store-manager', $otherUnit->id);

    ($this->addAdjustment)();

    $asManager = fn () => $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id]);

    expect($asManager()->getJson('/api/v1/employee-adjustments')->assertStatus(200)->json('data'))->toBe([]);

    $asManager()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $this->employee->id, 'period' => '2026-10', 'kind' => 'addition',
        'code' => 'ALW-PERF', 'amount' => 100, 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'EMPLOYEE_NOT_FOUND');

    $asManager()->getJson("/api/v1/employees/{$this->employee->id}/pay-items")->assertStatus(404);
});

test('what applied in a period whose payroll left review cannot change', function (): void {
    $item = ($this->addItem)();
    $adjustment = ($this->addAdjustment)();
    ($this->approve)($adjustment['id']);

    $run = ($this->calculate)('2026-10');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    $itemUrl = "/api/v1/employees/{$this->employee->id}/pay-items/{$item['id']}";

    ($this->asOwner)()->putJson($itemUrl, ['value' => 200])->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_FROZEN');
    // Ending it inside October still covers October, so the frozen payslip is unchanged.
    ($this->asOwner)()->putJson($itemUrl, ['effective_to' => '2026-10-15'])->assertStatus(200);
    ($this->asOwner)()->deleteJson($itemUrl)->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_FROZEN');

    // Ending it after the frozen period, or deactivating it, is fine.
    ($this->asOwner)()->putJson($itemUrl, ['effective_to' => '2026-11-30'])->assertStatus(200)->assertJsonPath('data.effective_to', '2026-11-30');
    ($this->asOwner)()->putJson($itemUrl, ['active' => false])->assertStatus(200);

    ($this->asOwner)()->postJson("/api/v1/employees/{$this->employee->id}/pay-items", [
        'kind' => 'addition', 'code' => 'ALW-HOU', 'calc' => 'fixed', 'value' => 300, 'effective_from' => '2026-10-01',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_PERIOD_CLOSED');

    ($this->asOwner)()->deleteJson("/api/v1/employee-adjustments/{$adjustment['id']}")
        ->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');

    ($this->asOwner)()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $this->employee->id, 'period' => '2026-10', 'kind' => 'addition',
        'code' => 'ALW-PERF', 'amount' => 100, 'reason' => 'late entry',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');

    // A new item from November is fine, and an unused one can be removed.
    $november = ($this->addItem)(['code' => 'ALW-HOU', 'value' => 300, 'effective_from' => '2026-11-01']);
    ($this->asOwner)()->deleteJson("/api/v1/employees/{$this->employee->id}/pay-items/{$november['id']}")->assertStatus(200);
});

test('existing databases get the deduction account roles, and the lookup only accepts known roles', function (): void {
    $ssf = ReferenceLookup::where('category', 'deduction-types')->where('code', 'DED-SSF')->firstOrFail();
    $abs = ReferenceLookup::where('category', 'deduction-types')->where('code', 'DED-ABS')->firstOrFail();
    $late = ReferenceLookup::where('category', 'deduction-types')->where('code', 'DED-LATE')->firstOrFail();

    $ssf->update(['fields' => ['calculation' => 'percentage', 'value' => '3.75']]);
    $abs->update(['fields' => ['calculation' => 'fixed', 'value' => '0']]);
    // A role set by hand is kept.
    $late->update(['fields' => ['calculation' => 'fixed', 'value' => '0', 'account_role' => 'liability']]);

    $migration = require database_path('migrations/2026_10_31_130200_add_account_role_to_deduction_types.php');
    $migration->up();

    expect($ssf->refresh()->fields['account_role'])->toBe('liability')
        ->and($ssf->fields['value'])->toBe('3.75')
        ->and($abs->refresh()->fields['account_role'])->toBe('expense_reduction')
        ->and($late->refresh()->fields['account_role'])->toBe('liability')
        ->and(ReferenceLookup::where('code', 'DED-ADV')->firstOrFail()->fields)->not->toHaveKey('account_role');

    ($this->asOwner)()->putJson("/api/v1/reference-lookups/deduction-types/{$abs->id}", [
        'fields' => ['calculation' => 'fixed', 'value' => '0', 'account_role' => 'income'],
    ])->assertStatus(422)->assertJsonValidationErrors('fields.account_role');

    ($this->asOwner)()->putJson("/api/v1/reference-lookups/deduction-types/{$abs->id}", [
        'fields' => ['calculation' => 'fixed', 'value' => '5', 'account_role' => 'liability'],
    ])->assertStatus(200)
        ->assertJsonPath('fields.account_role', 'liability')
        ->assertJsonPath('fields.value', '5');
});

test('ending an item in the last frozen month and adding a replacement pays only the new amount next period', function (): void {
    $item = ($this->addItem)(['code' => 'ALW-HOU', 'value' => 300, 'effective_from' => '2026-09-01']);

    foreach (['2026-09', '2026-10'] as $period) {
        $run = ($this->calculate)($period);
        ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);
    }

    $itemUrl = "/api/v1/employees/{$this->employee->id}/pay-items/{$item['id']}";

    // Ending it in September would take it out of the frozen October payslip.
    ($this->asOwner)()->putJson($itemUrl, ['effective_to' => '2026-09-30'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAY_ITEM_FROZEN')
        ->assertJsonPath('earliest_end', '2026-10-01');

    ($this->asOwner)()->putJson($itemUrl, ['effective_to' => '2026-10-31'])
        ->assertStatus(200)
        ->assertJsonPath('data.effective_to', '2026-10-31');

    ($this->addItem)(['code' => 'ALW-HOU', 'value' => 400, 'effective_from' => '2026-11-01']);

    $november = ($this->calculate)('2026-11');
    $payslip = Payslip::where('payroll_run_id', $november['id'])->firstOrFail();

    expect((float) $payslip->additions_pay)->toBe(400.0)
        ->and(collect($payslip->additions)->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([400.0]);
});

test('two active items of one code may not share a month', function (): void {
    $first = ($this->addItem)(['code' => 'ALW-HOU', 'value' => 300, 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-15']);
    $url = "/api/v1/employees/{$this->employee->id}/pay-items";

    // November is covered by both, even though the dates do not touch.
    ($this->asOwner)()->postJson($url, [
        'kind' => 'addition', 'code' => 'ALW-HOU', 'calc' => 'fixed', 'value' => 400, 'effective_from' => '2026-11-16',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_OVERLAP')->assertJsonPath('pay_item_id', $first['id']);

    // An inactive duplicate is allowed, but cannot be switched on while the first is in force.
    $second = ($this->addItem)(['code' => 'ALW-HOU', 'value' => 400, 'effective_from' => '2026-11-01', 'active' => false]);
    ($this->asOwner)()->putJson("{$url}/{$second['id']}", ['active' => true])
        ->assertStatus(422)->assertJsonPath('code', 'PAY_ITEM_OVERLAP');

    // Moved to start after the first one's last month, it can.
    ($this->asOwner)()->putJson("{$url}/{$second['id']}", ['effective_from' => '2026-12-01', 'active' => true])
        ->assertStatus(200);

    // Another code is independent.
    ($this->addItem)(['code' => 'ALW-TRN', 'effective_from' => '2026-10-01']);
});

test('approved adjustments are never dropped for an employee payroll does not pay', function (): void {
    $pieceWorker = Employee::create([
        'entity_id' => Entity::create(['name' => 'Worker P', 'entity_type' => 'individual'])->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Sewer',
        'pay_type' => 'piece_rate',
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    ($this->asOwner)()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $pieceWorker->id, 'period' => '2026-10', 'kind' => 'addition',
        'code' => 'ALW-PERF', 'amount' => 100, 'reason' => 'Bonus',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_EMPLOYEE_NOT_ON_PAYROLL');

    ($this->asOwner)()->postJson("/api/v1/employees/{$pieceWorker->id}/pay-items", [
        'kind' => 'addition', 'code' => 'ALW-TRN', 'calc' => 'fixed', 'value' => 10, 'effective_from' => '2026-10-01',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_EMPLOYEE_NOT_ON_PAYROLL');

    // Terminated after the draft: approval is refused.
    $draft = ($this->addAdjustment)();
    DB::table('employees')->where('id', $this->employee->id)->update(['status' => 'terminated']);
    ($this->asOwner)()->postJson("/api/v1/employee-adjustments/{$draft['id']}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'PAY_EMPLOYEE_NOT_ON_PAYROLL');

    // Terminated after approval: the calculation refuses instead of dropping it.
    DB::table('employees')->where('id', $this->employee->id)->update(['status' => 'active']);
    ($this->approve)($draft['id']);
    DB::table('employees')->where('id', $this->employee->id)->update(['status' => 'terminated']);

    $run = ($this->openRun)('2026-10');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYROLL_ADJUSTMENT_UNPAID')
        ->assertJsonPath('employee_id', $this->employee->id)
        ->assertJsonPath('adjustment_ids', [$draft['id']]);

    expect(PayrollRun::findOrFail($run['id'])->status->value)->toBe('draft');

    // Removing it lets the run calculate.
    ($this->asOwner)()->deleteJson("/api/v1/employee-adjustments/{$draft['id']}")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200);
});

test('a pay type in use cannot be deleted or renamed, and a role change flags calculated runs', function (): void {
    $abs = ReferenceLookup::where('category', 'deduction-types')->where('code', 'DED-ABS')->firstOrFail();
    $hou = ReferenceLookup::where('category', 'allowance-types')->where('code', 'ALW-HOU')->firstOrFail();
    $lookupUrl = fn (ReferenceLookup $lookup) => "/api/v1/reference-lookups/{$lookup->category}/{$lookup->id}";

    ($this->addItem)(['kind' => 'deduction', 'code' => 'DED-ABS', 'value' => 100]);
    ($this->approve)(($this->addAdjustment)(['code' => 'ALW-HOU'])['id']);

    ($this->asOwner)()->deleteJson($lookupUrl($abs))
        ->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_IN_USE')->assertJsonPath('referenced_by', 'employee_pay_items');
    ($this->asOwner)()->putJson($lookupUrl($abs), ['code' => 'DED-ABSENCE'])
        ->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_IN_USE');
    ($this->asOwner)()->deleteJson($lookupUrl($hou))
        ->assertStatus(422)->assertJsonPath('referenced_by', 'employee_adjustments');

    // A name change keeps the code, so it is fine; an unused type can still go.
    ($this->asOwner)()->putJson($lookupUrl($abs), ['name' => 'Absence'])->assertStatus(200);
    $unused = ReferenceLookup::where('category', 'allowance-types')->where('code', 'ALW-RISK')->firstOrFail();
    ($this->asOwner)()->deleteJson($lookupUrl($unused))->assertStatus(200);

    $run = ($this->calculate)('2026-10');
    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeFalse();

    // Notes alone leave the run alone; a role change does not.
    ($this->asOwner)()->putJson($lookupUrl($abs), ['notes' => 'x'])->assertStatus(200);
    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeFalse();

    ($this->asOwner)()->putJson($lookupUrl($abs), ['fields' => [...$abs->refresh()->fields, 'account_role' => 'liability']])
        ->assertStatus(200);
    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();
});

test('a draft left in a closed period can be moved or removed, freeing its code', function (): void {
    $forgotten = ($this->addAdjustment)(['code' => 'ALW-RISK', 'reason' => 'Forgotten draft']);
    $moved = ($this->addAdjustment)();

    $run = ($this->calculate)('2026-10');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    $url = fn (array $adjustment) => "/api/v1/employee-adjustments/{$adjustment['id']}";

    // It cannot be approved or changed inside the frozen period...
    ($this->asOwner)()->postJson($url($moved).'/approve')->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');
    ($this->asOwner)()->putJson($url($moved), ['amount' => 300])->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');

    // ...but it can be moved to an open period, or removed.
    ($this->asOwner)()->putJson($url($moved), ['period' => '2026-11'])->assertStatus(200)->assertJsonPath('data.period', '2026-11');
    ($this->asOwner)()->deleteJson($url($forgotten))->assertStatus(200);

    $risk = ReferenceLookup::where('category', 'allowance-types')->where('code', 'ALW-RISK')->firstOrFail();
    ($this->asOwner)()->deleteJson("/api/v1/reference-lookups/allowance-types/{$risk->id}")->assertStatus(200);
});

test('a period before the latest frozen one is closed to adjustments even without its own run', function (): void {
    $septemberDraft = ($this->addAdjustment)(['period' => '2026-09']);

    $run = ($this->calculate)('2026-10');
    ($this->postRun)($run['id']);

    ($this->asOwner)()->postJson('/api/v1/employee-adjustments', [
        'employee_id' => $this->employee->id, 'period' => '2026-09', 'kind' => 'addition',
        'code' => 'ALW-PERF', 'amount' => 100, 'reason' => 'late entry',
    ])->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');

    ($this->asOwner)()->postJson("/api/v1/employee-adjustments/{$septemberDraft['id']}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'PAY_PERIOD_CLOSED');

    ($this->addAdjustment)(['period' => '2026-11']);
});

test('approving an adjustment for an employee without a payslip in the calculated run blocks submission', function (): void {
    $newHire = Employee::create([
        'entity_id' => Entity::create(['name' => 'Worker N', 'entity_type' => 'individual'])->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Operator',
        'pay_type' => 'monthly',
        'monthly_salary' => 1500,
        'hire_date' => '2026-11-05',
        'status' => 'active',
    ]);

    $run = ($this->calculate)('2026-10');
    expect(Payslip::where('payroll_run_id', $run['id'])->where('employee_id', $newHire->id)->exists())->toBeFalse();

    $bonus = ($this->addAdjustment)(['employee_id' => $newHire->id]);
    ($this->approve)($bonus['id']);

    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(422);

    // Submission re-checks on its own, even if the flag were missed.
    PayrollRun::whereKey($run['id'])->update(['needs_recalculation' => false]);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYROLL_ADJUSTMENT_UNPAID')
        ->assertJsonPath('adjustment_ids', [$bonus['id']]);

    expect(PayrollRun::findOrFail($run['id'])->status->value)->toBe('calculated');

    // Removing it flags the run again; once recalculated it submits.
    ($this->asOwner)()->deleteJson("/api/v1/employee-adjustments/{$bonus['id']}")->assertStatus(200);
    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);
});

test('a pay code in use blocks only its own kind and matches case-insensitively', function (): void {
    // Codes are unique per category, so a deduction type may share an allowance code.
    $sharedDeduction = ($this->asOwner)()->postJson('/api/v1/reference-lookups/deduction-types', [
        'name' => 'Risk recovery', 'code' => 'ALW-RISK',
    ])->assertStatus(201)->json();
    ($this->addItem)(['kind' => 'deduction', 'code' => 'ALW-RISK', 'value' => 20]);

    $riskAllowance = ReferenceLookup::where('category', 'allowance-types')->where('code', 'ALW-RISK')->firstOrFail();
    ($this->asOwner)()->deleteJson("/api/v1/reference-lookups/allowance-types/{$riskAllowance->id}")->assertStatus(200);
    ($this->asOwner)()->deleteJson("/api/v1/reference-lookups/deduction-types/{$sharedDeduction['id']}")
        ->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_IN_USE');

    // A lower-case code saved back upper-cased by the desktop is not a rename.
    $meal = ($this->asOwner)()->postJson('/api/v1/reference-lookups/allowance-types', [
        'name' => 'Meal', 'code' => 'alw-meal',
    ])->assertStatus(201)->json();
    ($this->addItem)(['code' => 'alw-meal', 'value' => 30]);
    $mealUrl = "/api/v1/reference-lookups/allowance-types/{$meal['id']}";

    ($this->asOwner)()->putJson($mealUrl, ['name' => 'Meals', 'code' => 'ALW-MEAL'])->assertStatus(200);
    ($this->asOwner)()->putJson($mealUrl, ['code' => 'ALW-FOOD'])->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_IN_USE');
    ($this->asOwner)()->deleteJson($mealUrl)->assertStatus(422)->assertJsonPath('code', 'PAY_CODE_IN_USE');
});
