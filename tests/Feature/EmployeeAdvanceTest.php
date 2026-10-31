<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AdvanceInstallment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TreasuryVoucher;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->requestAdvance = function (array $overrides = []): array {
        return ($this->asOwner)()->postJson('/api/v1/employee-advances', array_merge([
            'employee_id' => $this->employee->id,
            'type' => 'loan',
            'amount' => 1500,
            'installments' => 3,
            'start_period' => '2026-10',
        ], $overrides))->assertStatus(201)->json('data');
    };

    $this->paidAdvance = function (array $overrides = []): array {
        $advance = ($this->requestAdvance)($overrides);
        ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);

        return ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", [
            'cash_account_id' => $this->treasury->id,
        ])->assertStatus(200)->json('data');
    };

    $this->calculate = function (string $period): array {
        $run = ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => $period])->assertStatus(201)->json();

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();
    };

    $this->postRun = function (string $runId): array {
        foreach (['submit', 'approve', 'mark-paid'] as $step) {
            ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$runId}/{$step}")->assertStatus(200);
        }

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$runId}/post", ['cash_account_id' => $this->treasury->id])
            ->assertStatus(200)->json();
    };

    $this->staff = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
});

/**
 * Balance of the 1215 lines tagged with the employee (debit − credit).
 */
function advanceBalance(string $employeeId): float
{
    $accountId = Account::where('account_code', '1215')->value('id');

    return round((float) JournalLine::where('account_id', $accountId)
        ->where('employee_id', $employeeId)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
        ->value('balance'), 4);
}

test('a request records an equal monthly schedule, the last installment absorbing the rounding', function (): void {
    $advance = ($this->requestAdvance)(['amount' => 1000, 'installments' => 3]);

    expect($advance['status'])->toBe('requested')
        ->and($advance['type'])->toBe('loan')
        ->and($advance['monthly_amount'])->toBe(333.333)
        ->and($advance['operating_unit_id'])->toBe($this->unit->id)
        ->and($advance['outstanding'])->toEqual(1000.0)
        ->and(collect($advance['schedule'])->pluck('period')->all())->toBe(['2026-10', '2026-11', '2026-12'])
        ->and(collect($advance['schedule'])->pluck('amount')->all())->toEqual([333.333, 333.333, 333.334]);

    // No ledger movement until it is paid out.
    expect(JournalEntry::count())->toBe(0);
});

test('an advance above the salary multiple is refused, and the limit follows the company setting', function (): void {
    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 2000.01,
        'installments' => 5,
        'start_period' => '2026-10',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_LIMIT_EXCEEDED')
        ->assertJsonPath('max_amount', 2000);

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['advance_max_salary_multiple' => 2])
        ->assertStatus(200)
        ->assertJsonPath('advance_max_salary_multiple', 2)
        ->assertJsonPath('advance_max_deduction_pct', 50)
        ->assertJsonPath('annual_leave_days', 30);

    ($this->requestAdvance)(['amount' => 4000, 'installments' => 4]);
});

test('the monthly recovery is limited to the deduction percentage, open advances included', function (): void {
    // 50% of 2000 = 1000 a month.
    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 1500,
        'installments' => 1,
        'start_period' => '2026-10',
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_DEDUCTION_LIMIT');

    ($this->requestAdvance)(['amount' => 1500, 'installments' => 2]); // 750 a month

    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 600,
        'installments' => 2, // 300 more → 1050
        'start_period' => '2026-10',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_DEDUCTION_LIMIT')
        ->assertJsonPath('open_monthly', 750);

    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['advance_max_deduction_pct' => 60])->assertStatus(200);

    ($this->requestAdvance)(['amount' => 600, 'installments' => 2, 'type' => 'advance']);
});

test('an hourly employee has no monthly base salary to advance against', function (): void {
    $this->employee->update(['pay_type' => 'hourly', 'monthly_salary' => null, 'hourly_rate' => 10]);

    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 100,
        'installments' => 1,
        'start_period' => '2026-10',
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_NO_MONTHLY_SALARY');
});

test('recovery cannot start in a past month', function (): void {
    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 100,
        'installments' => 1,
        'start_period' => '2026-09',
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_START_PERIOD_PAST');
});

test('HR requests, but only approve-advances holders approve and pay out', function (): void {
    $storeManager = ($this->staff)('store-manager', $this->unit->id);
    $hrManager = ($this->staff)('hr-manager');

    $advance = $this->actingAs($storeManager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/employee-advances', [
            'employee_id' => $this->employee->id,
            'type' => 'advance',
            'amount' => 500,
            'installments' => 1,
            'start_period' => '2026-10',
        ])->assertStatus(201)->json('data');

    expect($advance['requested_by']['id'])->toBe($storeManager->id);

    $this->actingAs($storeManager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/employee-advances/{$advance['id']}/approve")
        ->assertStatus(403);

    $approved = $this->actingAs($hrManager)->postJson("/api/v1/employee-advances/{$advance['id']}/approve")
        ->assertStatus(200)->json('data');

    expect($approved['status'])->toBe('approved')
        ->and($approved['approved_by']['id'])->toBe($hrManager->id);

    $this->actingAs($storeManager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(403);

    // The permission is in the catalog and granted to the configured roles.
    expect(Permission::where('slug', 'approve-advances')->exists())->toBeTrue()
        ->and(Role::where('slug', 'hr-manager')->first()->permissions->pluck('slug'))->toContain('approve-advances')
        ->and(Role::where('slug', 'store-manager')->first()->permissions->pluck('slug'))->not->toContain('approve-advances');
});

test('a payout posts DR 1215 with the employee and CR the treasury', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 900]);

    expect($advance['status'])->toBe('paid')
        ->and($advance['cash_account_id'])->toBe($this->treasury->id)
        ->and($advance['payout_entry_id'])->not->toBeNull();

    $entry = JournalEntry::with('lines.account')->findOrFail($advance['payout_entry_id']);
    $debit = $entry->lines->firstWhere('debit', '>', 0);
    $credit = $entry->lines->firstWhere('credit', '>', 0);

    expect($entry->source_document_type)->toBe('EmployeeAdvance')
        ->and($entry->source_document_id)->toBe($advance['id'])
        ->and($debit->account->account_code)->toBe('1215')
        ->and((float) $debit->debit)->toBe(900.0)
        ->and($debit->employee_id)->toBe($this->employee->id)
        ->and($debit->operating_unit_id)->toBe($this->unit->id)
        ->and($credit->account->account_code)->toBe('121101')
        ->and((float) $credit->credit)->toBe(900.0)
        ->and($credit->employee_id)->toBeNull()
        ->and(advanceBalance($this->employee->id))->toBe(900.0);

    // Paying twice is refused.
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('a request can be cancelled before payout but not after', function (): void {
    $requested = ($this->requestAdvance)();

    $cancelled = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$requested['id']}/cancel")
        ->assertStatus(200)->json('data');

    expect($cancelled['status'])->toBe('cancelled')
        ->and(collect($cancelled['schedule'])->pluck('status')->unique()->all())->toBe(['cancelled']);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$requested['id']}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // A cancelled request no longer counts toward the monthly limit, and its
    // installments are never deducted.
    $paid = ($this->paidAdvance)(['amount' => 1500, 'installments' => 2]);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$paid['id']}/cancel")
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    $run = ($this->calculate)('2026-10');
    $lines = Payslip::where('payroll_run_id', $run['id'])->first()->deductions;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['employee_advance_id'])->toBe($paid['id']);
});

test('payroll deducts the due installment as a locked line, credits 1215 and marks it recovered', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1500, 'installments' => 3]);

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect($payslip->deductions)->toHaveCount(1)
        ->and($payslip->deductions[0]['type'])->toBe('advance')
        ->and($payslip->deductions[0]['locked'])->toBeTrue()
        ->and((float) $payslip->deductions[0]['amount'])->toBe(500.0)
        ->and((float) $payslip->net_pay)->toBe(1500.0)
        ->and((float) $run['total_deductions'])->toBe(500.0)
        ->and((float) $run['total_net'])->toBe(1500.0);

    // Manual deductions are added beside the locked line; the locked line sent
    // back from the editor is ignored, never doubled or edited.
    $updated = ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
        'deductions' => [
            [...$payslip->deductions[0], 'amount' => 1],
            ['type' => 'DED-SSF', 'amount' => 75],
        ],
    ])->assertStatus(200)->json();

    // Leaving it out does not remove it either.
    ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", ['deductions' => []])
        ->assertStatus(200)
        ->assertJsonPath('deductions.0.installment_id', $payslip->deductions[0]['installment_id']);

    $updated = ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
        'deductions' => [['type' => 'DED-SSF', 'amount' => 75]],
    ])->assertStatus(200)->json();

    expect($updated['deductions'])->toHaveCount(2)
        ->and($updated['deductions'][0]['installment_id'])->toBe($payslip->deductions[0]['installment_id'])
        ->and((float) $updated['deductions'][0]['amount'])->toBe(500.0)
        ->and($updated['deductions'][1]['type'])->toBe('DED-SSF')
        ->and((float) $updated['net_pay'])->toBe(1425.0);

    ($this->postRun)($run['id']);

    $accrual = JournalEntry::with('lines.account')
        ->where('source_document_type', 'PayrollRun')
        ->where('description', 'like', '%accrued')
        ->firstOrFail();

    $byCode = fn (string $code) => $accrual->lines->filter(fn ($line) => $line->account->account_code === $code);

    expect((float) $byCode('57')->sum('debit'))->toBe(2000.0)
        ->and((float) $byCode('226')->sum('credit'))->toBe(1425.0)
        ->and((float) $byCode('225')->sum('credit'))->toBe(75.0)
        ->and((float) $byCode('1215')->sum('credit'))->toBe(500.0)
        ->and($byCode('1215')->first()->employee_id)->toBe($this->employee->id)
        ->and(advanceBalance($this->employee->id))->toBe(1000.0);

    $installment = AdvanceInstallment::where('employee_advance_id', $advance['id'])->where('period', '2026-10')->firstOrFail();

    expect($installment->status->value)->toBe('recovered')
        ->and((float) $installment->recovered_amount)->toBe(500.0)
        ->and($installment->payslip_id)->toBe($payslip->id);

    $show = ($this->asOwner)()->getJson("/api/v1/employee-advances/{$advance['id']}")->assertStatus(200)->json('data');

    expect($show['outstanding'])->toEqual(1000.0)
        ->and($show['recovered'])->toEqual(500.0)
        ->and($show['status'])->toBe('paid');
});

test('a shortfall under the deduction cap carries forward to the next period', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1600, 'installments' => 2]); // 800 a month

    // The cap is lowered after payout: October can recover only 25% of 2000.
    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['advance_max_deduction_pct' => 25])->assertStatus(200);

    $october = ($this->calculate)('2026-10');
    $slip = Payslip::where('payroll_run_id', $october['id'])->firstOrFail();

    expect((float) $slip->deductions[0]['amount'])->toBe(500.0)
        ->and((float) $slip->net_pay)->toBe(1500.0);

    ($this->postRun)($october['id']);

    $first = AdvanceInstallment::where('employee_advance_id', $advance['id'])->where('period', '2026-10')->firstOrFail();

    expect($first->status->value)->toBe('pending')
        ->and((float) $first->recovered_amount)->toBe(500.0);

    // November: the 300 left from October first, then November's own 800.
    ($this->asOwner)()->putJson('/api/v1/hr-settings', ['advance_max_deduction_pct' => 60])->assertStatus(200);
    $november = ($this->calculate)('2026-11');
    $lines = Payslip::where('payroll_run_id', $november['id'])->firstOrFail()->deductions;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['installment_id'])->toBe($first->id)
        ->and((float) $lines[0]['amount'])->toBe(300.0)
        ->and($lines[1]['period'])->toBe('2026-11')
        ->and((float) $lines[1]['amount'])->toBe(800.0);

    ($this->postRun)($november['id']);

    $settled = EmployeeAdvance::findOrFail($advance['id']);

    expect($settled->status->value)->toBe('settled')
        ->and($settled->settled_at)->not->toBeNull()
        ->and(advanceBalance($this->employee->id))->toBe(0.0);
});

test('a cash repayment posts DR treasury / CR 1215 and shortens the schedule from the end', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1500, 'installments' => 3]);

    $repaid = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 700,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200)->json('data');

    expect($repaid['outstanding'])->toEqual(800.0)
        ->and(collect($repaid['schedule'])->pluck('recovered_amount')->all())->toEqual([0.0, 200.0, 500.0])
        ->and(collect($repaid['schedule'])->pluck('status')->all())->toBe(['pending', 'pending', 'recovered'])
        ->and(advanceBalance($this->employee->id))->toBe(800.0);

    $entry = JournalEntry::with('lines.account')
        ->where('source_document_type', 'EmployeeAdvance')
        ->whereKeyNot($advance['payout_entry_id'])
        ->firstOrFail();
    $debit = $entry->lines->firstWhere('debit', '>', 0);
    $credit = $entry->lines->firstWhere('credit', '>', 0);

    expect($debit->account->account_code)->toBe('121101')
        ->and((float) $debit->debit)->toBe(700.0)
        ->and($credit->account->account_code)->toBe('1215')
        ->and($credit->employee_id)->toBe($this->employee->id);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 800.01,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_REPAYMENT_EXCEEDS');

    $show = ($this->asOwner)()->getJson("/api/v1/employee-advances/{$advance['id']}")->assertStatus(200)->json('data');

    expect(collect($show['entries'])->pluck('kind')->all())->toBe(['payout', 'repayment'])
        ->and(collect($show['entries'])->pluck('amount')->all())->toEqual([1500.0, 700.0]);

    $settled = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 800,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200)->json('data');

    expect($settled['status'])->toBe('settled')
        ->and($settled['outstanding'])->toEqual(0.0)
        ->and(advanceBalance($this->employee->id))->toBe(0.0);
});

test('a repayment into a calculated payroll flags it for recalculation, and the frozen part is kept', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1000, 'installments' => 1]);
    $run = ($this->calculate)('2026-10');

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 400,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200);

    expect(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(422);

    $recalculated = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();

    expect((float) $recalculated['total_deductions'])->toBe(600.0);

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    // Past review, the deducted 600 is reserved: nothing is left to repay in cash.
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 1,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_REPAYMENT_EXCEEDS');
});

test('a unit user cannot request an advance for another unit\'s employee', function (): void {
    $blueprint = UnitBlueprint::first();
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);
    $manager = ($this->staff)('store-manager', $otherUnit->id);

    $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id])
        ->postJson('/api/v1/employee-advances', [
            'employee_id' => $this->employee->id,
            'type' => 'advance',
            'amount' => 100,
            'installments' => 1,
            'start_period' => '2026-10',
        ])->assertStatus(422)->assertJsonPath('code', 'EMPLOYEE_NOT_FOUND');
});

test('advances list by employee and status', function (): void {
    $paid = ($this->paidAdvance)(['amount' => 600, 'installments' => 2]);
    ($this->requestAdvance)(['amount' => 300, 'installments' => 1, 'type' => 'advance']);

    $all = ($this->asOwner)()->getJson('/api/v1/employee-advances?employee_id='.$this->employee->id)->assertStatus(200)->json('data');
    $paidOnly = ($this->asOwner)()->getJson('/api/v1/employee-advances?status=paid')->assertStatus(200)->json('data');

    expect($all)->toHaveCount(2)
        ->and($paidOnly)->toHaveCount(1)
        ->and($paidOnly[0]['id'])->toBe($paid['id'])
        ->and($paidOnly[0]['employee']['name'])->toBe('Worker A')
        ->and($paidOnly[0]['cash_account']['id'])->toBe($this->treasury->id);
});

test('a voucher against 1215 stores the employee, filters by it and names them', function (): void {
    $voucher = ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => 250,
        'description' => 'Salary advance',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data');

    expect($voucher['employee_id'])->toBe($this->employee->id)
        ->and($voucher['employee']['name'])->toBe('Worker A');

    ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '57',
        'amount' => 50,
        'description' => 'Other expense',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $filtered = ($this->asOwner)()->getJson('/api/v1/treasury-vouchers?employee_id='.$this->employee->id)
        ->assertStatus(200)->json('data');

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['id'])->toBe($voucher['id']);

    // Still named after the employee is deleted, so the print keeps the name.
    $this->employee->delete();

    ($this->asOwner)()->getJson("/api/v1/treasury-vouchers/{$voucher['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.employee.name', 'Worker A');
});

test('the employee list includes deleted employees on with_trashed=1', function (): void {
    $this->employee->delete();

    expect(($this->asOwner)()->getJson('/api/v1/employees')->assertStatus(200)->json('data'))->toHaveCount(0);

    $withTrashed = ($this->asOwner)()->getJson('/api/v1/employees?with_trashed=1')->assertStatus(200)->json('data');

    expect($withTrashed)->toHaveCount(1)
        ->and($withTrashed[0]['id'])->toBe($this->employee->id)
        ->and($withTrashed[0]['deleted_at'])->not->toBeNull();
});

test('a deleted employee can still repay an advance in cash', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 500, 'installments' => 1]);
    $this->employee->delete();

    $repaid = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 500,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200)->json('data');

    expect($repaid['status'])->toBe('settled')
        ->and($repaid['employee']['deleted_at'])->not->toBeNull()
        ->and(advanceBalance($this->employee->id))->toBe(0.0);
});

test('a receipt voucher on 1215 is refused while the employee has an advance being recovered', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1000, 'installments' => 2]);

    ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => 500,
        'description' => 'Advance repaid',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_USE_REPAY')
        ->assertJsonPath('employee_advance_ids', [$advance['id']])
        ->assertJsonPath('unscheduled_balance', 0);

    // Nothing was credited, so payroll still deducts exactly the schedule.
    expect(advanceBalance($this->employee->id))->toBe(1000.0);

    $run = ($this->calculate)('2026-10');
    expect((float) $run['total_deductions'])->toBe(500.0);

    // Once settled, a receipt voucher on 1215 posts as before.
    AdvanceInstallment::where('employee_advance_id', $advance['id'])->update(['status' => 'recovered', 'recovered_amount' => 500]);
    EmployeeAdvance::whereKey($advance['id'])->update(['status' => 'settled']);

    ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => 100,
        'description' => 'Other repayment',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);
});

test('a payout is refused once the employee is terminated after approval', function (): void {
    $advance = ($this->requestAdvance)(['amount' => 500, 'installments' => 1]);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);

    Employee::whereKey($this->employee->id)->update(['status' => 'terminated']);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(422)->assertJsonPath('code', 'ADVANCE_EMPLOYEE_INACTIVE');

    expect(EmployeeAdvance::findOrFail($advance['id'])->status->value)->toBe('approved')
        ->and(JournalEntry::count())->toBe(0);
});

test('recovery cannot start in a month whose payroll left review, and a late payout moves the schedule forward', function (): void {
    $advance = ($this->requestAdvance)(['amount' => 900, 'installments' => 3]);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);

    $run = ($this->calculate)('2026-10');
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    ($this->asOwner)()->postJson('/api/v1/employee-advances', [
        'employee_id' => $this->employee->id,
        'type' => 'advance',
        'amount' => 100,
        'installments' => 1,
        'start_period' => '2026-10',
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_START_PERIOD_CLOSED');

    // October has left review: the schedule starts in November instead.
    $paid = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(200)->json('data');

    expect($paid['start_period'])->toBe('2026-11')
        ->and(collect($paid['schedule'])->pluck('period')->all())->toBe(['2026-11', '2026-12', '2027-01']);

    // Paid in a later month: the passed months move forward too.
    $late = ($this->requestAdvance)(['amount' => 300, 'installments' => 2, 'start_period' => '2026-11']);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$late['id']}/approve")->assertStatus(200);

    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00:00', 'Africa/Tripoli'));

    $latePaid = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$late['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(200)->json('data');

    expect(collect($latePaid['schedule'])->pluck('period')->all())->toBe(['2027-01', '2027-02']);
});

test('posting a run whose advance installment changed after calculation is refused and rolled back', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1000, 'installments' => 1]);
    $run = ($this->calculate)('2026-10');

    foreach (['submit', 'approve', 'mark-paid'] as $step) {
        ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/{$step}")->assertStatus(200);
    }

    // Mimic a race: the installment moved after the run froze its deduction.
    $installment = AdvanceInstallment::where('employee_advance_id', $advance['id'])->firstOrFail();
    AdvanceInstallment::whereKey($installment->id)->update(['recovered_amount' => 600]);

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/post", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(422)->assertJsonPath('code', 'ADVANCE_RECALCULATE_REQUIRED');

    expect(PayrollRun::findOrFail($run['id'])->status->value)->toBe('paid')
        ->and(JournalEntry::where('source_document_type', 'PayrollRun')->count())->toBe(0)
        ->and((float) $installment->fresh()->recovered_amount)->toBe(600.0)
        ->and($installment->fresh()->status->value)->toBe('pending')
        ->and(advanceBalance($this->employee->id))->toBe(1000.0);
});

test('a repayment flag tells HR why the run must be recalculated', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1000, 'installments' => 1]);
    $run = ($this->calculate)('2026-10');

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 100,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200);

    expect(($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(422)->json('message'))
        ->toContain('advance repayment');
});

test('an employee with an open advance cannot move to another unit', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 500, 'installments' => 1]);
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => UnitBlueprint::first()->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    ($this->asOwner)()->putJson("/api/v1/employees/{$this->employee->id}", [
        'operating_unit_id' => $otherUnit->id,
        'record_version' => $this->employee->fresh()->record_version,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'EMPLOYEE_HAS_OPEN_ADVANCE')
        ->assertJsonPath('employee_advance_ids', [$advance['id']]);

    expect($this->employee->fresh()->operating_unit_id)->toBe($this->unit->id);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 500,
        'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200)->assertJsonPath('data.status', 'settled');

    ($this->asOwner)()->putJson("/api/v1/employees/{$this->employee->id}", [
        'operating_unit_id' => $otherUnit->id,
        'record_version' => $this->employee->fresh()->record_version,
    ])->assertStatus(200);
});

test('payouts and repayments refuse a foreign-currency treasury', function (): void {
    $usd = linkedTreasury($this->unit, '121105');
    $usd->update(['currency' => 'USD']);

    $advance = ($this->requestAdvance)(['amount' => 500, 'installments' => 1]);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $usd->id])
        ->assertStatus(422)->assertJsonPath('code', 'ADVANCE_TREASURY_NOT_LYD');

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(200);

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 100,
        'cash_account_id' => $usd->id,
    ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_TREASURY_NOT_LYD');

    expect(JournalEntry::count())->toBe(1);
});

test('an advance payout, repayment or recovering payroll cannot be reversed outside the advance flow', function (): void {
    $advance = ($this->paidAdvance)(['amount' => 1500, 'installments' => 3]);
    $reverse = fn (string $entryId) => ($this->asOwner)()
        ->postJson("/api/v1/journal-entries/{$entryId}/reverse", ['reason' => 'Wrong treasury']);

    $reverse($advance['payout_entry_id'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_USE_ADVANCE_FLOW');

    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/repay", [
        'amount' => 200, 'cash_account_id' => $this->treasury->id,
    ])->assertStatus(200);

    $repayment = JournalEntry::where('source_document_type', 'EmployeeAdvance')
        ->where('source_document_id', $advance['id'])
        ->whereKeyNot($advance['payout_entry_id'])
        ->firstOrFail();

    $reverse($repayment->id)->assertStatus(422)->assertJsonPath('code', 'ADVANCE_USE_ADVANCE_FLOW');

    $run = ($this->calculate)('2026-10');
    ($this->postRun)($run['id']);

    $payrollEntries = JournalEntry::where('source_document_type', 'PayrollRun')->where('source_document_id', $run['id'])->get();
    expect($payrollEntries)->toHaveCount(2);

    foreach ($payrollEntries as $entry) {
        $reverse($entry->id)->assertStatus(422)->assertJsonPath('code', 'ADVANCE_USE_ADVANCE_FLOW');
    }

    $shown = ($this->asOwner)()->getJson("/api/v1/employee-advances/{$advance['id']}")->assertStatus(200)->json('data');

    expect(JournalEntry::where('entry_type', 'reversal')->count())->toBe(0)
        ->and($shown['status'])->toBe('paid')
        ->and($shown['payout_reversed'])->toBeFalse()
        ->and(advanceBalance($this->employee->id))->toBe(800.0);
});

test('cancelling or reversing a 1215 entry cannot drop the balance below the pending installments', function (): void {
    ($this->paidAdvance)(['amount' => 1000, 'installments' => 2]);

    $voucher = fn (string $kind) => ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => $kind,
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => 250,
        'description' => 'Ad hoc advance',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data');

    // 250 unscheduled is charged, then collected: the balance is back to the 1000 scheduled.
    $payment = $voucher('payment');
    $receipt = $voucher('receipt');
    expect(advanceBalance($this->employee->id))->toBe(1000.0);

    // Undoing the charge would post CR 1215 250 against the schedule.
    ($this->asOwner)()->postJson("/api/v1/treasury-vouchers/{$payment['id']}/cancel", ['reason' => 'Entered twice'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_USE_REPAY')
        ->assertJsonPath('unscheduled_balance', 0);

    ($this->asOwner)()->postJson("/api/v1/journal-entries/{$payment['journal_entry_id']}/reverse", ['reason' => 'Entered twice'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_USE_REPAY');

    expect(TreasuryVoucher::findOrFail($payment['id'])->status->value)->toBe('posted')
        ->and(advanceBalance($this->employee->id))->toBe(1000.0);

    // Undoing the collection debits 1215, which is always allowed; the charge
    // is then unscheduled again and may be cancelled.
    ($this->asOwner)()->postJson("/api/v1/treasury-vouchers/{$receipt['id']}/cancel", ['reason' => 'Cash not received'])
        ->assertStatus(200);
    expect(advanceBalance($this->employee->id))->toBe(1250.0);

    ($this->asOwner)()->postJson("/api/v1/treasury-vouchers/{$payment['id']}/cancel", ['reason' => 'Entered twice'])
        ->assertStatus(200);
    expect(advanceBalance($this->employee->id))->toBe(1000.0);
});

test('HR may cancel a request, but only an approver may cancel an approval', function (): void {
    $storeManager = ($this->staff)('store-manager', $this->unit->id);
    $hrManager = ($this->staff)('hr-manager');
    $asStoreManager = fn () => $this->actingAs($storeManager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $requested = ($this->requestAdvance)(['amount' => 200, 'installments' => 1, 'type' => 'advance']);
    $asStoreManager()->postJson("/api/v1/employee-advances/{$requested['id']}/cancel")
        ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');

    $approved = ($this->requestAdvance)(['amount' => 300, 'installments' => 1]);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$approved['id']}/approve")->assertStatus(200);

    $asStoreManager()->postJson("/api/v1/employee-advances/{$approved['id']}/cancel")
        ->assertStatus(403)->assertJsonPath('code', 'ADVANCE_CANCEL_FORBIDDEN');

    expect(EmployeeAdvance::findOrFail($approved['id'])->status->value)->toBe('approved');

    $this->actingAs($hrManager)->postJson("/api/v1/employee-advances/{$approved['id']}/cancel")
        ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
});

test('a receipt voucher on 1215 may still collect a balance outside the advance schedule', function (): void {
    ($this->paidAdvance)(['amount' => 1000, 'installments' => 2]);

    // W5-00: a payment voucher charges an unscheduled 250 to the same employee.
    ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => 250,
        'description' => 'Ad hoc advance',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $receipt = fn (float $amount) => ($this->asOwner)()->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '1215',
        'employee_id' => $this->employee->id,
        'amount' => $amount,
        'description' => 'Ad hoc advance repaid',
        'operating_unit_id' => $this->unit->id,
    ]);

    $receipt(250.01)->assertStatus(422)
        ->assertJsonPath('code', 'ADVANCE_USE_REPAY')
        ->assertJsonPath('unscheduled_balance', 250);

    $receipt(250)->assertStatus(201);

    expect(advanceBalance($this->employee->id))->toBe(1000.0);
    $receipt(1)->assertStatus(422)->assertJsonPath('code', 'ADVANCE_USE_REPAY');
});

test('a manual journal cannot credit the scheduled part of an advance', function (): void {
    ($this->paidAdvance)(['amount' => 1000, 'installments' => 2]);

    $journal = fn (float $amount) => ($this->asOwner)()->postJson('/api/v1/journal-entries', [
        'description' => 'Advance settled by hand',
        'lines' => [
            ['account_code' => '121101', 'debit' => $amount, 'operating_unit_id' => $this->unit->id],
            ['account_code' => '1215', 'credit' => $amount, 'employee_id' => $this->employee->id],
        ],
    ]);

    $journal(500)->assertStatus(422)->assertJsonPath('code', 'ADVANCE_USE_REPAY');
    expect(advanceBalance($this->employee->id))->toBe(1000.0);

    // A balance charged outside the schedule can still be cleared by hand.
    ($this->asOwner)()->postJson('/api/v1/journal-entries', [
        'description' => 'Ad hoc advance',
        'lines' => [
            ['account_code' => '1215', 'debit' => 100, 'employee_id' => $this->employee->id],
            ['account_code' => '121101', 'credit' => 100, 'operating_unit_id' => $this->unit->id],
        ],
    ])->assertStatus(201);

    $journal(100)->assertStatus(201);
    expect(advanceBalance($this->employee->id))->toBe(1000.0);
});

test('an advance or DED-ADV line cannot be typed in as a manual deduction', function (): void {
    ($this->paidAdvance)(['amount' => 1500, 'installments' => 3]);
    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    foreach (['advance', 'DED-ADV', 'ded-adv'] as $type) {
        ($this->asOwner)()->putJson("/api/v1/payslips/{$payslip->id}/deductions", [
            'deductions' => [['type' => $type, 'amount' => 100]],
        ])->assertStatus(422)->assertJsonPath('code', 'ADVANCE_DEDUCTION_MANUAL');
    }

    $payslip->refresh();

    expect($payslip->deductions)->toHaveCount(1)
        ->and($payslip->deductions[0]['locked'])->toBeTrue()
        ->and((float) $payslip->net_pay)->toBe(1500.0);
});

test('a payout after its first month was calculated forces that run to be recalculated', function (): void {
    $advance = ($this->requestAdvance)(['amount' => 1000, 'installments' => 2, 'start_period' => '2026-10']);
    ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/approve")->assertStatus(200);

    $run = ($this->calculate)('2026-10');
    expect((float) $run['total_deductions'])->toBe(0.0);

    // October is still in review, so the schedule stays on October.
    $paid = ($this->asOwner)()->postJson("/api/v1/employee-advances/{$advance['id']}/pay", ['cash_account_id' => $this->treasury->id])
        ->assertStatus(200)->json('data');

    expect($paid['start_period'])->toBe('2026-10')
        ->and(PayrollRun::findOrFail($run['id'])->needs_recalculation)->toBeTrue();

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(422);

    $recalculated = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200)->json();

    expect((float) $recalculated['total_deductions'])->toBe(500.0);

    ($this->postRun)($run['id']);

    // November deducts only its own installment.
    $november = ($this->calculate)('2026-11');
    expect((float) $november['total_deductions'])->toBe(500.0);
});

test('the advance cap is measured on net pay after manual deductions, and the shortfall carries forward', function (): void {
    $this->employee->update(['monthly_salary' => 1000]);
    $advance = ($this->paidAdvance)(['amount' => 1000, 'installments' => 2]); // 500 a month

    $run = ($this->calculate)('2026-10');
    $payslip = Payslip::where('payroll_run_id', $run['id'])->firstOrFail();

    expect((float) $payslip->gross_pay)->toBe(1000.0)
        ->and((float) $payslip->deductions[0]['amount'])->toBe(500.0);

    $setDeductions = fn (array $lines) => ($this->asOwner)()
        ->putJson("/api/v1/payslips/{$payslip->id}/deductions", ['deductions' => $lines])
        ->assertStatus(200)->json();

    // A 300 penalty leaves 700 of net pay; the advance takes 50% of it.
    $updated = $setDeductions([['type' => 'DED-PENALTY', 'amount' => 300]]);

    expect($updated['deductions'])->toHaveCount(2)
        ->and($updated['deductions'][0]['type'])->toBe('advance')
        ->and($updated['deductions'][0]['locked'])->toBeTrue()
        ->and((float) $updated['deductions'][0]['amount'])->toBe(350.0)
        ->and((float) $updated['deductions'][1]['amount'])->toBe(300.0)
        ->and((float) $updated['net_pay'])->toBe(350.0);

    // Removing the penalty restores the line; adding it back lowers it again.
    expect((float) $setDeductions([])['deductions'][0]['amount'])->toBe(500.0);
    $setDeductions([['type' => 'DED-PENALTY', 'amount' => 300]]);

    expect((float) PayrollRun::findOrFail($run['id'])->total_deductions)->toBe(650.0);

    // A deduction that leaves nothing to share drops the advance line, never net below zero.
    $all = $setDeductions([['type' => 'DED-PENALTY', 'amount' => 1000]]);
    expect($all['deductions'])->toHaveCount(1)->and((float) $all['net_pay'])->toBe(0.0);
    $setDeductions([['type' => 'DED-PENALTY', 'amount' => 300]]);

    ($this->postRun)($run['id']);

    $first = AdvanceInstallment::where('employee_advance_id', $advance['id'])->where('period', '2026-10')->firstOrFail();

    expect($first->status->value)->toBe('pending')
        ->and((float) $first->recovered_amount)->toBe(350.0)
        ->and(advanceBalance($this->employee->id))->toBe(650.0);

    // November: October's 150 first, then 350 of November's own 500 under the 500 cap.
    $november = ($this->calculate)('2026-11');
    $lines = Payslip::where('payroll_run_id', $november['id'])->firstOrFail()->deductions;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['installment_id'])->toBe($first->id)
        ->and((float) $lines[0]['amount'])->toBe(150.0)
        ->and($lines[1]['period'])->toBe('2026-11')
        ->and((float) $lines[1]['amount'])->toBe(350.0);
});
