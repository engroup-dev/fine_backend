<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Entity;
use App\Models\EntityRole;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CoaLinkService;
use App\Services\PurchaseOrderStateService;
use App\Services\SupplierService;
use App\Support\CurrentUnitContext;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->unitA = $makeUnit('Showroom A');
    $this->unitB = $makeUnit('Showroom B');

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner', null);
    $this->unitBUser = $makeUser('owner', $this->unitB->id);

    $this->api = fn (?User $user = null, ?string $unitId = null) => $this->actingAs($user ?? $this->owner)
        ->withHeader('X-Operating-Unit-ID', $unitId ?? $this->unitA->id);

    $this->createSupplier = fn (array $payload) => ($this->api)()->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitA->id,
        ...$payload,
    ]);

    /** A supplier sub-account under 21 with an opening credit posted to it. */
    $this->accountWithPostings = function (string $name, float $amount): string {
        $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', $name, $this->company->id);
        $code = DB::table('accounts')->where('id', $accountId)->value('account_code');

        app(AccountingService::class)->postJournal("Purchase from {$name}", [
            ['account_code' => '51', 'debit' => $amount, 'credit' => 0, 'operating_unit_id' => $this->unitA->id],
            ['account_code' => $code, 'debit' => 0, 'credit' => $amount, 'operating_unit_id' => $this->unitA->id],
        ], isManual: true);

        return $accountId;
    };
});

test('backfill gives existing suppliers a vendor entity with a primary contact', function () {
    $insert = function (string $name, ?string $contact, ?string $address, bool $trashed = false): string {
        $id = (string) Str::uuid();
        DB::table('suppliers')->insert([
            'id' => $id,
            'operating_unit_id' => $this->unitA->id,
            'name' => $name,
            'contact' => $contact,
            'address' => $address,
            'default_currency' => 'LYD',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $trashed ? now() : null,
        ]);

        return $id;
    };

    $emailId = $insert('Al Amal Trading', 'sales@alamal.ly', 'Tripoli Harbor');
    $phoneId = $insert('Benghazi Foam', '+218 91-234 5678', null);
    $nameId = $insert('Misrata Wood', 'Ahmed (manager)', null);
    $bareId = $insert('No Contact Co', null, null);
    $trashedId = $insert('Old Vendor', null, 'Somewhere', trashed: true);

    $migration = require database_path('migrations/2026_10_31_170100_backfill_supplier_entities.php');
    $migration->up();

    $email = DB::table('suppliers')->where('id', $emailId)->first();
    $entity = Entity::with(['roles', 'primaryContact'])->findOrFail($email->entity_id);
    expect($entity->name)->toBe('Al Amal Trading')
        ->and($entity->entity_type->value)->toBe('organization')
        ->and($entity->roles)->toHaveCount(1)
        ->and($entity->roles->first()->role_type->value)->toBe('vendor')
        ->and($entity->roles->first()->operating_unit_id)->toBe($this->unitA->id)
        ->and($entity->primaryContact->email)->toBe('sales@alamal.ly')
        ->and($entity->primaryContact->address)->toBe('Tripoli Harbor');

    $phoneEntity = Entity::with('primaryContact')->findOrFail(DB::table('suppliers')->where('id', $phoneId)->value('entity_id'));
    expect($phoneEntity->primaryContact->phone)->toBe('+218 91-234 5678')
        ->and($phoneEntity->primaryContact->email)->toBeNull();

    $nameEntity = Entity::with('primaryContact')->findOrFail(DB::table('suppliers')->where('id', $nameId)->value('entity_id'));
    expect($nameEntity->primaryContact->contact_name)->toBe('Ahmed (manager)');

    $bareEntityId = DB::table('suppliers')->where('id', $bareId)->value('entity_id');
    expect($bareEntityId)->not->toBeNull()
        ->and(DB::table('entity_contacts')->where('entity_id', $bareEntityId)->exists())->toBeFalse();

    // A removed supplier gets its entity but does not list as a vendor.
    $trashedEntityId = DB::table('suppliers')->where('id', $trashedId)->value('entity_id');
    expect($trashedEntityId)->not->toBeNull()
        ->and(EntityRole::where('entity_id', $trashedEntityId)->exists())->toBeFalse();

    // Running it again changes nothing.
    $entities = Entity::count();
    $migration->up();
    expect(Entity::count())->toBe($entities);
});

test('a new supplier is created on a vendor entity with its tax number', function () {
    $response = ($this->createSupplier)([
        'name' => 'Global Logistics',
        'contact' => 'ops@global.ly',
        'address' => 'Misrata Free Zone',
        'tax_number' => 'TX-1001',
    ])->assertCreated()
        ->assertJsonPath('data.tax_number', 'TX-1001')
        ->assertJsonPath('data.record_version', 1);

    $supplier = Supplier::findOrFail($response->json('data.id'));
    $entity = Entity::with(['roles', 'primaryContact'])->findOrFail($supplier->entity_id);

    expect($response->json('data.entity_id'))->toBe($entity->id)
        ->and($entity->name)->toBe('Global Logistics')
        ->and($entity->tax_number)->toBe('TX-1001')
        ->and($entity->roles->pluck('role_type')->map->value->all())->toBe(['vendor'])
        ->and($entity->roles->first()->operating_unit_id)->toBe($this->unitA->id)
        ->and($entity->primaryContact->email)->toBe('ops@global.ly')
        ->and($entity->supplier->id)->toBe($supplier->id);

    $this->assertDatabaseHas('audit_logs', ['table_name' => 'suppliers', 'record_id' => $supplier->id, 'action' => 'created']);
});

test('a supplier can link an existing client entity and the lookup shows both roles', function () {
    $clientId = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Sahara Furniture',
        'tax_number' => 'TX-2002',
    ])->assertCreated()->json('data.id');
    $entityId = DB::table('clients')->where('id', $clientId)->value('entity_id');

    $supplierId = ($this->createSupplier)([
        'name' => 'Sahara Furniture (supply)',
        'entity_id' => $entityId,
    ])->assertCreated()
        ->assertJsonPath('data.entity_id', $entityId)
        ->assertJsonPath('data.tax_number', 'TX-2002')
        ->json('data.id');

    expect(Entity::count())->toBe(1)
        ->and(Entity::findOrFail($entityId)->supplier->id)->toBe($supplierId);

    $row = collect(($this->api)()->getJson('/api/v1/entities?role_type=vendor')->assertSuccessful()->json('data'))
        ->firstWhere('id', $entityId);

    expect($row)->not->toBeNull()
        ->and(collect($row['roles'])->pluck('role_type')->sort()->values()->all())->toBe(['client', 'vendor']);
});

test('a likely duplicate supplier is refused unless forced', function () {
    ($this->createSupplier)(['name' => 'شركة الأمل للتجارة', 'tax_number' => '12-345'])->assertCreated();

    // Same name once letter variants and spacing are normalized.
    ($this->createSupplier)(['name' => '  شركة   الامل للتجارة. '])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_DUPLICATE')
        ->assertJsonPath('duplicates.0.match', 'name');

    // Same tax number written differently.
    ($this->createSupplier)(['name' => 'Al Amal Co', 'tax_number' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_DUPLICATE')
        ->assertJsonPath('duplicates.0.match', 'tax_number')
        ->assertJsonPath('duplicates.0.tax_number', '12-345');

    expect(Supplier::count())->toBe(1);

    // The caller confirms: saved anyway.
    ($this->createSupplier)(['name' => 'شركة الامل للتجارة', 'force' => true])->assertCreated();

    // Another unit's suppliers are not duplicates.
    ($this->api)(null, $this->unitB->id)->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitB->id,
        'name' => 'شركة الأمل للتجارة',
    ])->assertCreated();

    // Renaming onto an existing supplier is refused too; an unrelated edit is not.
    $other = ($this->createSupplier)(['name' => 'Desert Steel'])->assertCreated()->json('data');
    ($this->api)()->putJson("/api/v1/suppliers/{$other['id']}", ['name' => 'شركة الأمل للتجارة'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_DUPLICATE');
    ($this->api)()->putJson("/api/v1/suppliers/{$other['id']}", ['address' => 'Tripoli', 'tax_number' => 'TX-9'])
        ->assertSuccessful()
        ->assertJsonPath('data.tax_number', 'TX-9');

    // A stale record_version is a conflict; omitting it stays allowed.
    ($this->api)()->putJson("/api/v1/suppliers/{$other['id']}", ['address' => 'Late', 'record_version' => 1])
        ->assertStatus(409);
});

test('merge moves orders, cheques and the account with postings to the target', function () {
    $sourceAccountId = ($this->accountWithPostings)('Duplicate vendor', 700);

    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Amal Trading', 'account_id' => $sourceAccountId]);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Al Amal Trading']);
    $sourceEntityId = app(SupplierService::class)->ensureEntity($source)->id;

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $source->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Local, 'negotiated_price' => 700, 'quantity' => 1, 'status' => PurchaseOrderStatus::Received,
    ]);
    PaymentRequest::create([
        'operating_unit_id' => $this->unitA->id, 'purchase_order_id' => $order->id, 'route' => 'market',
        'amount_requested' => 100, 'fx_rate_used' => 1, 'status' => 'paid',
    ]);
    $cheque = Cheque::create([
        'company_id' => $this->company->id, 'operating_unit_id' => $this->unitA->id, 'cheque_number_internal' => 'CHQ-2026-00001',
        'direction' => 'outgoing', 'cheque_no' => 'OUT-1', 'bank_name' => 'Aman Bank', 'supplier_id' => $source->id,
        'amount' => 50, 'currency' => 'LYD', 'due_date' => '2026-11-15', 'received_date' => '2026-10-31', 'status' => ChequeStatus::Issued,
    ]);
    $sourceCode = DB::table('accounts')->where('id', $sourceAccountId)->value('account_code');
    DB::table('payable_settlements')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'operating_unit_id' => $this->unitA->id,
        'account_code' => $sourceCode, 'amount' => 20, 'settled_at' => '2026-10-31', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $linesBefore = JournalLine::orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->toArray();

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.account_id', $sourceAccountId)
        ->assertJsonPath('merged_supplier_id', $source->id)
        ->assertJsonPath('moved.purchase_orders', 1)
        ->assertJsonPath('moved.cheques', 1)
        ->assertJsonPath('moved.payment_requests', 1)
        ->assertJsonPath('moved.payable_settlements', 1);

    expect($order->fresh()->supplier_id)->toBe($target->id)
        ->and($order->fresh()->record_version)->toBe(2)
        ->and($cheque->fresh()->supplier_id)->toBe($target->id)
        ->and($target->fresh()->account_id)->toBe($sourceAccountId)
        ->and(Supplier::find($source->id))->toBeNull()
        ->and(Supplier::withTrashed()->find($source->id)->account_id)->toBeNull()
        // Posted lines are untouched: the account they sit on now belongs to the target.
        ->and(JournalLine::orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->toArray())->toBe($linesBefore)
        // The source's entity no longer lists as a vendor of the unit.
        ->and(EntityRole::where('entity_id', $sourceEntityId)->where('role_type', 'vendor')->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', ['table_name' => 'suppliers', 'record_id' => $source->id, 'action' => 'deleted']);

    // The cheque's payee change and the merge itself are on the audit trail.
    $chequeAudit = DB::table('audit_logs')->where('table_name', 'cheques')->where('record_id', $cheque->id)->where('action', 'updated')->first();
    expect($chequeAudit)->not->toBeNull()
        ->and(json_decode($chequeAudit->old_values, true))->toBe(['supplier_id' => $source->id])
        ->and(json_decode($chequeAudit->new_values, true))->toBe(['supplier_id' => $target->id]);

    $mergeAudit = DB::table('audit_logs')->where('table_name', 'suppliers')->where('record_id', $target->id)->where('action', 'merged')->first();
    expect($mergeAudit)->not->toBeNull()
        ->and(json_decode($mergeAudit->old_values, true)['merged_supplier_id'])->toBe($source->id)
        ->and(json_decode($mergeAudit->new_values, true))->toBe([
            'purchase_order_ids' => [$order->id],
            'cheque_ids' => [$cheque->id],
            'account_id' => $sourceAccountId,
        ]);
});

test('merge keeps the target account when only the target has postings', function () {
    $targetAccountId = ($this->accountWithPostings)('Main vendor', 300);
    $unusedAccountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Unused', $this->company->id);

    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Dup', 'account_id' => $unusedAccountId]);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main', 'account_id' => $targetAccountId]);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertSuccessful()
        ->assertJsonPath('data.account_id', $targetAccountId)
        ->assertJsonPath('moved.payable_settlements', 0);

    expect(Supplier::withTrashed()->find($source->id)->trashed())->toBeTrue();
});

test('merge is refused when both suppliers have accounts with postings', function () {
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'One', 'account_id' => ($this->accountWithPostings)('One', 100)]);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Two', 'account_id' => ($this->accountWithPostings)('Two', 200)]);
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $source->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Local, 'negotiated_price' => 100, 'quantity' => 1, 'status' => PurchaseOrderStatus::Received,
    ]);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_MERGE_ACCOUNTS');

    expect($order->fresh()->supplier_id)->toBe($source->id)
        ->and(Supplier::find($source->id))->not->toBeNull();
});

test('merge refuses itself, another unit and suppliers outside the caller unit', function () {
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'A one']);
    $otherUnit = Supplier::create(['operating_unit_id' => $this->unitB->id, 'name' => 'B one']);

    // A company-wide caller with no unit header sees both, but units never mix.
    $this->actingAs($this->owner)->withoutHeader('X-Operating-Unit-ID')->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $otherUnit->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_MERGE_UNIT_MISMATCH');

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $source->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target_id']);

    // A unit B caller cannot reach unit A's supplier.
    ($this->api)($this->unitBUser, $this->unitB->id)->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $otherUnit->id])
        ->assertNotFound();

    expect(DB::table('suppliers')->whereNull('deleted_at')->count())->toBe(2);
});

test('merge is refused while a part-way order would clear on another account', function () {
    $balanceOf15 = fn () => (float) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.account_code', '15')
        ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
        ->value('net');

    // The source has no account of its own: its import order was paid as an
    // advance on the shared 15 (what executePayment posts).
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'No account vendor']);
    $targetAccountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Main vendor', $this->company->id);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main vendor', 'account_id' => $targetAccountId]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $source->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Foreign, 'negotiated_price' => 400, 'quantity' => 1, 'status' => PurchaseOrderStatus::Paid,
    ]);
    $request = PaymentRequest::create([
        'operating_unit_id' => $this->unitA->id, 'purchase_order_id' => $order->id, 'route' => 'market',
        'amount_requested' => 400, 'fx_rate_used' => 1, 'status' => 'paid',
    ]);
    app(AccountingService::class)->postJournal('Import PO paid', [
        ['account_code' => '15', 'debit' => 400, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '121102', 'credit' => 400, 'operating_unit_id' => $this->unitA->id],
    ], 'PaymentRequest', $request->id);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_MERGE_OPEN_ORDERS')
        ->assertJsonPath('purchase_order_ids', [$order->id]);

    expect($order->fresh()->supplier_id)->toBe($source->id)
        ->and(Supplier::find($source->id))->not->toBeNull();

    // Once the order completes (clearing 15 with the same amount), the merge
    // goes through and 15 nets to zero.
    app(AccountingService::class)->postJournal('Import PO complete', [
        ['account_code' => '51', 'debit' => 400, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '15', 'credit' => 400, 'operating_unit_id' => $this->unitA->id],
    ], 'PurchaseOrder', $order->id);
    $order->update(['status' => PurchaseOrderStatus::Complete]);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertSuccessful()
        ->assertJsonPath('moved.purchase_orders', 1);

    expect($balanceOf15())->toBe(0.0)
        ->and($order->fresh()->supplier_id)->toBe($target->id);
});

test('merge is refused when the inherited account would take over the target open orders', function () {
    // The source's account has postings, so it moves to the target, whose
    // own paid order sits on 15 and would otherwise clear on that account.
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Ledger vendor', 'account_id' => ($this->accountWithPostings)('Ledger vendor', 100)]);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Plain vendor']);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $target->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Foreign, 'negotiated_price' => 250, 'quantity' => 1, 'status' => PurchaseOrderStatus::Paid,
    ]);
    app(AccountingService::class)->postJournal('Import PO paid', [
        ['account_code' => '15', 'debit' => 250, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '121102', 'credit' => 250, 'operating_unit_id' => $this->unitA->id],
    ], 'PurchaseOrder', $order->id);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_MERGE_OPEN_ORDERS')
        ->assertJsonPath('purchase_order_ids', [$order->id]);

    expect($target->fresh()->account_id)->toBeNull();
});

test('a supplier edit can link a client entity and keeps the client tax number', function () {
    $clientId = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Oasis Interiors',
        'tax_number' => 'TX-3003',
    ])->assertCreated()->json('data.id');
    $clientEntityId = DB::table('clients')->where('id', $clientId)->value('entity_id');

    $supplier = ($this->createSupplier)(['name' => 'Oasis Interiors Supply'])->assertCreated()->json('data');
    $ownEntityId = $supplier['entity_id'];

    // A different tax number than the client's is refused, nothing changes.
    ($this->api)()->putJson("/api/v1/suppliers/{$supplier['id']}", ['entity_id' => $clientEntityId, 'tax_number' => 'TX-OTHER'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_TAX_NUMBER_CONFLICT');
    expect(Supplier::findOrFail($supplier['id'])->entity_id)->toBe($ownEntityId)
        ->and(Entity::findOrFail($clientEntityId)->tax_number)->toBe('TX-3003');

    ($this->api)()->putJson("/api/v1/suppliers/{$supplier['id']}", ['entity_id' => $clientEntityId])
        ->assertSuccessful()
        ->assertJsonPath('data.entity_id', $clientEntityId)
        ->assertJsonPath('data.tax_number', 'TX-3003');

    expect(EntityRole::where('entity_id', $clientEntityId)->where('role_type', 'vendor')->where('operating_unit_id', $this->unitA->id)->exists())->toBeTrue()
        ->and(EntityRole::where('entity_id', $ownEntityId)->where('role_type', 'vendor')->exists())->toBeFalse()
        ->and(Entity::findOrFail($clientEntityId)->name)->toBe('Oasis Interiors');

    // Through the supplier, the client's tax number can neither be replaced nor cleared.
    ($this->api)()->putJson("/api/v1/suppliers/{$supplier['id']}", ['tax_number' => 'TX-9999'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_TAX_NUMBER_CONFLICT');
    ($this->api)()->putJson("/api/v1/suppliers/{$supplier['id']}", ['tax_number' => null])
        ->assertSuccessful();
    expect(Entity::findOrFail($clientEntityId)->tax_number)->toBe('TX-3003');
});

test('linking an entity on create checks its tax number', function () {
    ($this->createSupplier)(['name' => 'Coastal Paints', 'tax_number' => 'TX-4004'])->assertCreated();

    $clientId = ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Coastal Paints Retail',
        'tax_number' => 'TX 4004',
    ])->assertCreated()->json('data.id');
    $clientEntityId = DB::table('clients')->where('id', $clientId)->value('entity_id');

    // The linked entity's tax number matches an existing supplier of the unit.
    ($this->createSupplier)(['name' => 'Retail arm', 'entity_id' => $clientEntityId])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_DUPLICATE')
        ->assertJsonPath('duplicates.0.match', 'tax_number');

    // A different tax number never overwrites the client's.
    ($this->createSupplier)(['name' => 'Retail arm', 'entity_id' => $clientEntityId, 'tax_number' => 'TX-5005', 'force' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_TAX_NUMBER_CONFLICT');

    expect(Entity::findOrFail($clientEntityId)->tax_number)->toBe('TX 4004')
        ->and(Supplier::count())->toBe(1);
});

test('a caller cannot create or probe suppliers in a unit outside its roles', function () {
    ($this->createSupplier)(['name' => 'Secret Vendor', 'tax_number' => 'TX-7007'])->assertCreated();

    ($this->api)($this->unitBUser, $this->unitB->id)->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitA->id,
        'name' => 'Secret Vendor',
        'tax_number' => 'TX-7007',
    ])->assertForbidden()
        ->assertJsonPath('code', 'SUPPLIER_UNIT_FORBIDDEN')
        ->assertJsonMissingPath('duplicates');

    // Nor move one of its own suppliers there.
    $own = ($this->api)($this->unitBUser, $this->unitB->id)->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitB->id,
        'name' => 'B vendor',
    ])->assertCreated()->json('data.id');
    ($this->api)($this->unitBUser, $this->unitB->id)->putJson("/api/v1/suppliers/{$own}", ['operating_unit_id' => $this->unitA->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'SUPPLIER_UNIT_FORBIDDEN');

    expect(Supplier::withoutGlobalScopes()->count())->toBe(2);
});

test('vendor roles follow a supplier unit change and its deletion', function () {
    $supplier = ($this->createSupplier)(['name' => 'Moving Vendor'])->assertCreated()->json('data');
    $entityId = $supplier['entity_id'];
    $vendorUnits = fn () => EntityRole::where('entity_id', $entityId)->where('role_type', 'vendor')->pluck('operating_unit_id')->all();

    expect($vendorUnits())->toBe([$this->unitA->id]);

    ($this->api)()->putJson("/api/v1/suppliers/{$supplier['id']}", ['operating_unit_id' => $this->unitB->id])
        ->assertSuccessful();

    expect($vendorUnits())->toBe([$this->unitB->id]);

    ($this->api)(null, $this->unitB->id)->deleteJson("/api/v1/suppliers/{$supplier['id']}")
        ->assertSuccessful();

    expect($vendorUnits())->toBe([]);
});

test('an entity behind suppliers of two units keeps its name and tax number', function () {
    $first = ($this->createSupplier)(['name' => 'Twin Vendor', 'tax_number' => 'TX-5005'])->assertCreated()->json('data');
    $entityId = $first['entity_id'];

    // Unit B links unit A's supplier entity for its own supplier.
    $second = ($this->api)(null, $this->unitB->id)->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitB->id,
        'name' => 'Twin Vendor B',
        'entity_id' => $entityId,
    ])->assertCreated()->json('data');
    expect($second['entity_id'])->toBe($entityId);

    $unitBApi = fn () => ($this->api)($this->unitBUser, $this->unitB->id);

    // A different tax number through unit B's supplier is refused; an empty
    // one clears nothing; a rename stays on the supplier.
    $unitBApi()->putJson("/api/v1/suppliers/{$second['id']}", ['tax_number' => 'TX-OTHER'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_TAX_NUMBER_CONFLICT');
    $unitBApi()->putJson("/api/v1/suppliers/{$second['id']}", ['tax_number' => null])
        ->assertSuccessful()
        ->assertJsonPath('data.tax_number', 'TX-5005');
    $unitBApi()->putJson("/api/v1/suppliers/{$second['id']}", ['name' => 'Renamed in B'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Renamed in B');

    // The same holds from unit A's side, where the entity is a vendor of unit B too.
    ($this->api)()->putJson("/api/v1/suppliers/{$first['id']}", ['name' => 'Renamed in A', 'tax_number' => 'TX-6006'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_TAX_NUMBER_CONFLICT');
    ($this->api)()->putJson("/api/v1/suppliers/{$first['id']}", ['name' => 'Renamed in A'])
        ->assertSuccessful();

    $entity = Entity::findOrFail($entityId);
    expect($entity->tax_number)->toBe('TX-5005')
        ->and($entity->name)->toBe('Twin Vendor')
        ->and(Supplier::findOrFail($first['id'])->name)->toBe('Renamed in A');

    // A forced second supplier on the same entity in one unit shares it too.
    $sameUnit = ($this->createSupplier)(['name' => 'Twin Vendor again', 'entity_id' => $entityId, 'force' => true])
        ->assertCreated()->json('data');
    ($this->api)()->putJson("/api/v1/suppliers/{$sameUnit['id']}", ['tax_number' => null, 'name' => 'Twin again', 'force' => true])
        ->assertSuccessful();
    expect(Entity::findOrFail($entityId)->only(['name', 'tax_number']))->toBe(['name' => 'Twin Vendor', 'tax_number' => 'TX-5005']);

    // A supplier's own private entity still follows its edits.
    $own = ($this->createSupplier)(['name' => 'Solo Vendor', 'tax_number' => 'TX-8008'])->assertCreated()->json('data');
    ($this->api)()->putJson("/api/v1/suppliers/{$own['id']}", ['name' => 'Solo Vendor Ltd', 'tax_number' => 'TX-8009'])
        ->assertSuccessful()
        ->assertJsonPath('data.tax_number', 'TX-8009');
    expect(Entity::findOrFail($own['entity_id'])->only(['name', 'tax_number']))->toBe(['name' => 'Solo Vendor Ltd', 'tax_number' => 'TX-8009']);
});

test('merge reads where an order opened, not the supplier account it has now', function () {
    // The order was paid as an advance on 15 while the source had no account;
    // the source was given its own account afterwards.
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Late account vendor']);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Ledger vendor', 'account_id' => ($this->accountWithPostings)('Ledger vendor', 100)]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $source->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Foreign, 'negotiated_price' => 300, 'quantity' => 1, 'status' => PurchaseOrderStatus::Paid,
    ]);
    app(AccountingService::class)->postJournal('Import PO paid', [
        ['account_code' => '15', 'debit' => 300, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '121102', 'credit' => 300, 'operating_unit_id' => $this->unitA->id],
    ], 'PurchaseOrder', $order->id);

    $source->update(['account_id' => app(CoaLinkService::class)->provisionNextSubAccount('21', 'Late account vendor', $this->company->id)]);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SUPPLIER_MERGE_OPEN_ORDERS')
        ->assertJsonPath('purchase_order_ids', [$order->id]);

    expect($order->fresh()->supplier_id)->toBe($source->id);
});

test('an order payment after a merge posts on the surviving supplier account', function () {
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Merged away']);
    $targetAccountId = ($this->accountWithPostings)('Survivor', 100);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Survivor', 'account_id' => $targetAccountId]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $source->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Foreign, 'negotiated_price' => 200, 'quantity' => 1, 'status' => PurchaseOrderStatus::PendingPayment,
    ]);
    $request = PaymentRequest::create([
        'operating_unit_id' => $this->unitA->id, 'purchase_order_id' => $order->id, 'route' => 'market',
        'amount_requested' => 200, 'status' => 'pending',
    ]);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])
        ->assertSuccessful();

    app(PurchaseOrderStateService::class)->executePayment($request->fresh(), 1.0, paymentSourceAccountId: cashLeafId('121102'));

    $debitOn = fn (string $accountId) => (float) JournalLine::where('account_id', $accountId)->sum('debit');
    expect($debitOn($targetAccountId))->toBe(200.0)
        ->and($debitOn(cashLeafId('15')))->toBe(0.0);
});

test('the merge audit row can be filtered by its action', function () {
    $source = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Filter source']);
    $target = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Filter target']);

    ($this->api)()->postJson("/api/v1/suppliers/{$source->id}/merge", ['target_id' => $target->id])->assertSuccessful();

    ($this->api)()->getJson('/api/v1/audit-logs?action=merged')
        ->assertSuccessful()
        ->assertJsonPath('data.0.record_id', $target->id)
        ->assertJsonPath('data.0.action', 'merged');
    ($this->api)()->getJson("/api/v1/audit-logs/suppliers/{$target->id}?action=merged")
        ->assertSuccessful()
        ->assertJsonPath('data.0.action', 'merged');
});

/**
 * Drives an LYD import order of the supplier through payment (on the
 * current supplier account rule) up to Received, ready to complete.
 */
function supplierLegImportOrderPaid(Supplier $supplier, OperatingUnit $unit, float $amount): PurchaseOrder
{
    $warehouse = Warehouse::firstOrCreate(['operating_unit_id' => $unit->id, 'code' => 'SUP-WH'], ['name' => 'Supplier test depot']);
    $service = app(PurchaseOrderStateService::class);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $unit->id, 'supplier_id' => $supplier->id, 'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Foreign, 'negotiated_price' => $amount, 'quantity' => 1, 'status' => PurchaseOrderStatus::Draft,
    ]);

    $service->transitionToPendingPayment($order);
    $service->selectPaymentRoute($order->fresh(), PaymentRoute::Market, $amount);
    $service->executePayment($order->paymentRequests()->first(), 1.0, paymentSourceAccountId: cashLeafId('121102'));

    return $order->fresh();
}

function supplierLegCompleteImportOrder(PurchaseOrder $order): void
{
    $service = app(PurchaseOrderStateService::class);
    $warehouseId = Warehouse::query()->withoutGlobalScopes()->where('operating_unit_id', $order->operating_unit_id)->value('id');

    $service->confirmShipment($order->fresh());
    $service->arriveAtPort($order->fresh());
    $service->transportToWarehouse($order->fresh());
    $service->arriveAtWarehouse($order->fresh(), $warehouseId);
    $service->receiveGoods($order->fresh(), $warehouseId, 1);
    $service->completeOrder($order->fresh());
}

/** Net debit minus credit on the account, over every posted line. */
function supplierLegNet(string $accountCode): float
{
    return round((float) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.account_code', $accountCode)
        ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
        ->value('net'), 4);
}

test('an order whose supplier is in another unit pays and clears on the supplier account', function () {
    // The caller works under unit A's header; the supplier belongs to unit B.
    app(CurrentUnitContext::class)->setUnit($this->unitA);
    seedUnitAccounts($this->unitA);

    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Unit B vendor', $this->company->id);
    $accountCode = DB::table('accounts')->where('id', $accountId)->value('account_code');
    $supplier = Supplier::withoutGlobalScopes()->create(['operating_unit_id' => $this->unitB->id, 'name' => 'Unit B vendor', 'account_id' => $accountId]);

    $import = supplierLegImportOrderPaid($supplier, $this->unitA, 400);
    expect(supplierLegNet($accountCode))->toBe(400.0);
    supplierLegCompleteImportOrder($import);

    $category = ItemCategory::create(['name' => 'Raw', 'code' => 'RAW', 'item_type' => 'raw_material']);
    $item = InventoryItem::create(['name' => 'Rod', 'code' => 'ROD-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'piece', 'category_id' => $category->id]);
    $local = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id, 'supplier_id' => $supplier->id, 'currency' => 'LYD', 'kind' => PurchaseOrderKind::Local,
        'negotiated_price' => 250, 'quantity' => 5, 'status' => PurchaseOrderStatus::Received,
        'destination_warehouse_id' => Warehouse::query()->where('operating_unit_id', $this->unitA->id)->value('id'),
    ]);
    PurchaseOrderItem::create([
        'purchase_order_id' => $local->id, 'inventory_item_id' => $item->id, 'quantity' => 5, 'unit_price' => 50, 'currency' => 'LYD', 'received_quantity' => 5,
    ]);
    app(PurchaseOrderStateService::class)->payLocal($local, cashLeafId('121102'));

    // Both orders debited and credited the supplier's own account; 15 was never touched.
    $debitOn = fn (string $code) => (float) JournalLine::where('account_id', cashLeafId($code))->sum('debit');
    expect(supplierLegNet($accountCode))->toBe(0.0)
        ->and($debitOn($accountCode))->toBe(650.0)
        ->and(supplierLegNet('15'))->toBe(0.0)
        ->and($debitOn('15'))->toBe(0.0);
});

test('an order paid on 15 before its supplier had an account clears on 15', function () {
    app(CurrentUnitContext::class)->setUnit($this->unitA);
    seedUnitAccounts($this->unitA);

    $supplier = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Late account vendor']);
    $order = supplierLegImportOrderPaid($supplier, $this->unitA, 300);
    expect(supplierLegNet('15'))->toBe(300.0);

    // The supplier gets its own account while the order is in transit.
    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Late account vendor', $this->company->id);
    $accountCode = DB::table('accounts')->where('id', $accountId)->value('account_code');
    $supplier->update(['account_id' => $accountId]);

    supplierLegCompleteImportOrder($order);

    expect(supplierLegNet('15'))->toBe(0.0)
        ->and(supplierLegNet($accountCode))->toBe(0.0)
        ->and(JournalLine::where('account_id', $accountId)->exists())->toBeFalse();
});

test('an other-unit order paid on 15 under the old unit-scoped rule clears on 15', function () {
    // Before W5-07 the unit-scoped lookup missed a supplier of another unit,
    // so its payment landed on 15 although the supplier had an account.
    app(CurrentUnitContext::class)->setUnit($this->unitA);
    seedUnitAccounts($this->unitA);

    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Unit B vendor', $this->company->id);
    $accountCode = DB::table('accounts')->where('id', $accountId)->value('account_code');
    $supplier = Supplier::withoutGlobalScopes()->create(['operating_unit_id' => $this->unitB->id, 'name' => 'Unit B vendor']);

    $order = supplierLegImportOrderPaid($supplier, $this->unitA, 500);
    Supplier::withoutGlobalScopes()->whereKey($supplier->id)->update(['account_id' => $accountId]);

    supplierLegCompleteImportOrder($order);

    expect(supplierLegNet('15'))->toBe(0.0)
        ->and(supplierLegNet($accountCode))->toBe(0.0);
});

test('a same-unit order still pays and clears on its supplier account, or on 15 without one', function () {
    app(CurrentUnitContext::class)->setUnit($this->unitA);
    seedUnitAccounts($this->unitA);

    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Own account vendor', $this->company->id);
    $accountCode = DB::table('accounts')->where('id', $accountId)->value('account_code');
    $withAccount = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Own account vendor', 'account_id' => $accountId]);
    $withoutAccount = Supplier::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Plain vendor']);

    supplierLegCompleteImportOrder(supplierLegImportOrderPaid($withAccount, $this->unitA, 200));
    supplierLegCompleteImportOrder(supplierLegImportOrderPaid($withoutAccount, $this->unitA, 150));

    $sumOn = fn (string $code, string $side) => (float) JournalLine::where('account_id', cashLeafId($code))->sum($side);
    expect($sumOn($accountCode, 'debit'))->toBe(200.0)
        ->and($sumOn($accountCode, 'credit'))->toBe(200.0)
        ->and($sumOn('15', 'debit'))->toBe(150.0)
        ->and($sumOn('15', 'credit'))->toBe(150.0);
});
