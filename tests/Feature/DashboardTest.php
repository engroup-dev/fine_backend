<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\CreditApprovalRequest;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\LeaveRequest;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Midday UTC: the company clock (Africa/Tripoli) and now() agree on the date
    // whatever time the suite runs; 22:00-24:00 UTC is already tomorrow in Tripoli.
    $this->travelTo(now()->setTime(12, 0));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitA->id]);

    $accounting = app(AccountingService::class);

    // This month: 1000 revenue, 600 COGS in unit A; 200 cash held company-wide.
    $accounting->postJournal('Sale', [
        ['account_code' => '121101', 'debit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '40201', 'credit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
    ]);
    $accounting->postJournal('COGS', [
        ['account_code' => '51', 'debit' => 600.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '1131', 'credit' => 600.0, 'operating_unit_id' => $this->unitA->id],
    ]);
    $accounting->postJournal('Cash out', [
        ['account_code' => '54', 'debit' => 200.0],
        ['account_code' => '121101', 'credit' => 200.0],
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('kpis aggregate the month from the ledger', function () {
    $supplier = Supplier::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Chem Co', 'default_currency' => 'USD',
    ]);
    PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $supplier->id,
        'currency' => 'USD', 'negotiated_price' => 100, 'quantity' => 50, 'status' => 'in_transit',
    ]);
    PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $supplier->id,
        'currency' => 'USD', 'negotiated_price' => 10, 'quantity' => 10, 'status' => 'complete',
    ]);

    $kpis = ($this->asOwner)()->getJson('/api/v1/dashboard/kpis')
        ->assertStatus(200)->json();

    expect((float) $kpis['revenue_mtd'])->toBe(1000.0)
        ->and((float) $kpis['cogs_mtd'])->toBe(600.0)
        ->and((float) $kpis['gross_margin_pct'])->toBe(40.0)
        ->and((float) $kpis['net_profit_mtd'])->toBe(200.0) // 1000 − 600 − 200 overhead
        ->and((float) $kpis['cash_position'])->toBe(800.0)
        // Only the in-flight order counts toward exposure; complete does not.
        ->and((float) $kpis['fx_exposure']['USD'])->toBe(5000.0);
});

test('unit comparison pairs profit with inventory value per unit', function () {
    $comparison = ($this->asOwner)()->getJson('/api/v1/dashboard/unit-comparison')
        ->assertStatus(200)->json();

    $byName = collect($comparison['rows'])->keyBy('unit_name');

    expect($comparison['rows'])->toHaveCount(2)
        ->and((float) $byName['Foam Plant']['revenue'])->toBe(1000.0)
        ->and((float) $byName['Foam Plant']['net'])->toBe(400.0)
        ->and((float) $byName['Showroom']['revenue'])->toBe(0.0)
        ->and((float) $comparison['unallocated_net'])->toBe(-200.0);
});

test('the operational pipeline counts by status', function () {
    ProductionBatch::create([
        'operating_unit_id' => $this->unitA->id, 'operation_number' => 500,
        'bun_width_m' => 2.0, 'status' => 'running',
    ]);
    ProductionBatch::create([
        'operating_unit_id' => $this->unitA->id, 'operation_number' => 501,
        'bun_width_m' => 2.0, 'status' => 'closed',
        'material_cost' => 1000.0,
    ]);

    $pipeline = ($this->asOwner)()->getJson('/api/v1/dashboard/operational-pipeline')
        ->assertStatus(200)->json();

    expect($pipeline['foam_batches']['total'])->toBe(2)
        ->and($pipeline['foam_batches']['statuses']['running'])->toBe(1)
        ->and($pipeline['foam_batches']['statuses']['closed'])->toBe(1)
        ->and($pipeline['purchase_orders']['total'])->toBe(0);
});

test('the approvals inbox gathers every kind of pending decision', function () {
    $clientEntity = Entity::create(['name' => 'Big Client', 'entity_type' => 'organization']);
    $client = Client::create([
        'entity_id' => $clientEntity->id, 'operating_unit_id' => $this->unitB->id,
        'credit_limit' => 100, 'current_balance' => 0,
    ]);
    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitB->id, 'order_number' => 'SO-1',
        'buyer_type' => 'client', 'client_id' => $client->id, 'status' => 'pending_approval',
    ]);
    CreditApprovalRequest::create([
        'sales_order_id' => $order->id, 'amount_over_limit' => 250, 'status' => 'pending',
    ]);

    PayrollRun::create([
        'company_id' => $this->company->id, 'period' => '2026-07',
        'status' => 'pending_approval', 'total_net' => 5000,
    ]);

    $entity = Entity::create(['name' => 'Worker', 'entity_type' => 'individual']);
    $employee = Employee::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
        'job_title' => 'Operator', 'pay_type' => 'hourly', 'hire_date' => '2026-01-01', 'status' => 'active',
    ]);
    LeaveRequest::create([
        'employee_id' => $employee->id, 'operating_unit_id' => $this->unitA->id,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-02',
        'leave_type' => 'annual', 'status' => 'pending',
    ]);

    $inbox = ($this->asOwner)()->getJson('/api/v1/dashboard/pending-approvals')
        ->assertStatus(200)->json();

    expect($inbox['total'])->toBe(3)
        ->and($inbox['credit_approvals'][0]['client_name'])->toBe('Big Client')
        ->and((float) $inbox['credit_approvals'][0]['amount_over_limit'])->toBe(250.0)
        ->and($inbox['payroll_runs'][0]['period'])->toBe('2026-07')
        ->and($inbox['leave_requests'][0]['employee_name'])->toBe('Worker');

    // The KPI counters agree with the inbox.
    $kpis = ($this->asOwner)()->getJson('/api/v1/dashboard/kpis')->json();
    expect($kpis['pending_approvals']['credit'])->toBe(1)
        ->and($kpis['pending_approvals']['payroll'])->toBe(1)
        ->and($kpis['pending_approvals']['leave'])->toBe(1)
        ->and($kpis['pending_approvals']['restock'])->toBe(0);
});

test('the inventory rollup sums units into the company total', function () {
    $rollup = ($this->asOwner)()->getJson('/api/v1/dashboard/inventory-rollup')
        ->assertStatus(200)->json();

    expect($rollup['units'])->toHaveCount(2)
        ->and($rollup['company']['company_total_valuation'])->toBe(0);
});

test('a unit manager is refused everywhere on the dashboard', function () {
    foreach (['kpis', 'unit-comparison', 'inventory-rollup', 'operational-pipeline', 'pending-approvals'] as $endpoint) {
        $this->actingAs($this->manager)
            ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
            ->getJson("/api/v1/dashboard/{$endpoint}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'DASHBOARD_FORBIDDEN');
    }
});
