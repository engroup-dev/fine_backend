<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\CoaLinkService;
use App\Support\CurrentUnitContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * W6-06 (FIX-15): required item category with its "عام" backfill, unique
 * warehouse names per unit and parent, and one client/supplier per ledger
 * account, each with the migration that checks existing data first.
 */
beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->unitA = $makeUnit('Unit A');
    $this->unitB = $makeUnit('Unit B');

    $this->owner = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->owner->id,
        'role_id' => Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner'])->id,
        'operating_unit_id' => null,
    ]);

    $this->api = fn (?OperatingUnit $unit = null) => $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => ($unit ?? $this->unitA)->id]);

    $this->category = ItemCategory::create(['name' => 'Foam', 'code' => 'CAT-FOAM']);

    $this->migration = fn (string $file) => require database_path("migrations/{$file}");
});

// ---------------------------------------------------------------------------
// Item category
// ---------------------------------------------------------------------------

test('an item cannot be created without a category', function () {
    ($this->api)()->postJson('/api/v1/inventory-items', [
        'name' => 'Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'ITEM_CATEGORY_REQUIRED')
        ->assertJsonPath('errors.category_id.0', 'اختر فئة للصنف (يمكن اختيار الفئة «عام»).');

    // Any other failure keeps the plain validation shape, without a code.
    ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => $this->category->id,
        'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ])->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonMissingPath('code');

    ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => $this->category->id,
        'name' => 'Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ])->assertCreated()->assertJsonPath('category_id', $this->category->id);
});

test('an item update may leave the category out but never clear it', function () {
    $item = InventoryItem::create([
        'category_id' => $this->category->id,
        'name' => 'Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    ($this->api)()->putJson("/api/v1/inventory-items/{$item->id}", ['category_id' => null])
        ->assertStatus(422)->assertJsonValidationErrors('category_id')->assertJsonPath('code', 'ITEM_CATEGORY_REQUIRED');

    ($this->api)()->putJson("/api/v1/inventory-items/{$item->id}", ['name' => 'Big Block'])
        ->assertOk()->assertJsonPath('category_id', $this->category->id);
});

test('a category that still has items cannot be deleted', function () {
    InventoryItem::create([
        'category_id' => $this->category->id,
        'name' => 'Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    ($this->api)()->deleteJson("/api/v1/item-categories/{$this->category->id}")
        ->assertStatus(422)->assertJsonPath('code', 'CATEGORY_HAS_ITEMS');

    expect(ItemCategory::find($this->category->id))->not->toBeNull();
});

test('the backfill moves every uncategorized item, deleted ones too, into a new "عام" category', function () {
    $loose = InventoryItem::create(['name' => 'Loose', 'code' => 'L-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);
    $trashed = InventoryItem::create(['name' => 'Old', 'code' => 'L-2', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);
    $trashed->delete();
    $filed = InventoryItem::create([
        'category_id' => $this->category->id, 'name' => 'Filed', 'code' => 'L-3', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();

    $general = ItemCategory::where('name', 'عام')->sole();
    expect($general->code)->toBe('GEN')
        ->and($general->parent_id)->toBeNull()
        ->and($loose->fresh()->category_id)->toBe($general->id)
        ->and(InventoryItem::withTrashed()->find($trashed->id)->category_id)->toBe($general->id)
        ->and($filed->fresh()->category_id)->toBe($this->category->id)
        ->and(DB::table('inventory_items')->whereNull('category_id')->count())->toBe(0);
});

test('the backfill also moves items whose category was soft-deleted, and logs both counts', function () {
    $retired = ItemCategory::create(['name' => 'Retired', 'code' => 'CAT-RET']);
    $orphan = InventoryItem::create([
        'category_id' => $retired->id, 'name' => 'Orphan', 'code' => 'O-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $trashedOrphan = InventoryItem::create([
        'category_id' => $retired->id, 'name' => 'Old orphan', 'code' => 'O-2', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $trashedOrphan->delete();
    $retired->delete();
    $loose = InventoryItem::create(['name' => 'Loose', 'code' => 'L-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);
    $filed = InventoryItem::create([
        'category_id' => $this->category->id, 'name' => 'Filed', 'code' => 'L-3', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    Log::spy();
    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();

    $general = ItemCategory::where('name', 'عام')->sole();
    expect($orphan->fresh()->category_id)->toBe($general->id)
        ->and(InventoryItem::withTrashed()->find($trashedOrphan->id)->category_id)->toBe($general->id)
        ->and($loose->fresh()->category_id)->toBe($general->id)
        ->and($filed->fresh()->category_id)->toBe($this->category->id);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'moved 1 uncategorized'))->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'moved 2 inventory item(s) whose category was deleted'))->once();

    // The item can now be edited with its (pre-filled) category re-sent.
    ($this->api)()->putJson("/api/v1/inventory-items/{$orphan->id}", ['category_id' => $general->id, 'name' => 'Orphan 2'])
        ->assertOk();
});

test('every database has a top-level "عام" category to pick, even with no items to backfill', function () {
    // RefreshDatabase ran the migration on an empty database (a fresh install).
    $general = ItemCategory::where('name', 'عام')->sole();
    expect($general->code)->toBe('GEN')
        ->and($general->parent_id)->toBeNull()
        ->and($general->item_type)->toBeNull();

    // Re-running ensures it without creating a second one.
    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();
    expect(ItemCategory::where('name', 'عام')->count())->toBe(1);

    // An item can be saved straight away by picking it.
    ($this->api)()->postJson('/api/v1/inventory-items', [
        'category_id' => $general->id,
        'name' => 'Block', 'code' => 'BLK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ])->assertCreated();
});

test('the backfill reuses an existing top-level leaf "عام" category', function () {
    DB::table('item_categories')->where('code', 'GEN')->delete();
    $existing = ItemCategory::create(['name' => 'عام', 'code' => 'MY-GENERAL']);
    $loose = InventoryItem::create(['name' => 'Loose', 'code' => 'L-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);

    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();

    expect($loose->fresh()->category_id)->toBe($existing->id)
        ->and(ItemCategory::where('name', 'عام')->count())->toBe(1);
});

test('the backfill never files items under a sub-category, a parent or a typed category named "عام"', function () {
    DB::table('item_categories')->where('code', 'GEN')->delete();
    $foamGeneral = ItemCategory::create(['name' => 'عام', 'code' => 'CAT-FOAM-GEN', 'parent_id' => $this->category->id]);
    $parentGeneral = ItemCategory::create(['name' => 'عام', 'code' => 'CAT-PARENT-GEN']);
    ItemCategory::create(['name' => 'Child', 'code' => 'CAT-PARENT-GEN-1', 'parent_id' => $parentGeneral->id]);
    $typedGeneral = ItemCategory::create(['name' => 'عام', 'code' => 'CAT-TYPED-GEN', 'item_type' => 'raw_material']);
    $loose = InventoryItem::create(['name' => 'Loose', 'code' => 'L-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);

    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();

    $general = ItemCategory::where('code', 'GEN')->sole();
    expect($general->name)->toBe('عام')
        ->and($general->parent_id)->toBeNull()
        ->and($general->id)->not->toBeIn([$foamGeneral->id, $parentGeneral->id, $typedGeneral->id])
        ->and($loose->fresh()->category_id)->toBe($general->id);
});

test('the backfill picks a free code when GEN is already taken', function () {
    DB::table('item_categories')->where('code', 'GEN')->delete();
    ItemCategory::create(['name' => 'Generators', 'code' => 'GEN']);
    ItemCategory::create(['name' => 'Gen two', 'code' => 'GEN-2']);
    $loose = InventoryItem::create(['name' => 'Loose', 'code' => 'L-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg']);

    ($this->migration)('2026_11_01_110000_backfill_general_item_category.php')->up();

    $general = ItemCategory::where('name', 'عام')->sole();
    expect($general->code)->toBe('GEN-3')
        ->and($loose->fresh()->category_id)->toBe($general->id);
});

// ---------------------------------------------------------------------------
// Warehouses
// ---------------------------------------------------------------------------

test('a top-level warehouse name is unique within its unit only', function () {
    Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main']);

    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Main'])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('code', 'WAREHOUSE_NAME_TAKEN');

    // A too-long name on the same field is a plain validation error, without the code.
    ($this->api)()->postJson('/api/v1/warehouses', ['name' => str_repeat('x', 256)])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonMissingPath('code');

    ($this->api)($this->unitB)->postJson('/api/v1/warehouses', ['name' => 'Main'])
        ->assertCreated()->assertJsonPath('operating_unit_id', $this->unitB->id);
});

test('a sub-warehouse name is unique under its parent only', function () {
    $main = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main']);
    $overflow = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Overflow']);

    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Shelf 1', 'parent_id' => $main->id])->assertCreated();
    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Shelf 1', 'parent_id' => $main->id])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('code', 'WAREHOUSE_NAME_TAKEN');

    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Shelf 1', 'parent_id' => $overflow->id])->assertCreated();
    // A top-level warehouse may share a sub-warehouse's name.
    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Shelf 1'])->assertCreated();
});

test('warehouse store takes the unit from the header and refuses operating_unit_id in the body', function () {
    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Annex', 'operating_unit_id' => $this->unitB->id])
        ->assertStatus(422)->assertJsonValidationErrors('operating_unit_id');

    // The header picks the unit, including for a company-wide caller.
    ($this->api)($this->unitB)->postJson('/api/v1/warehouses', ['name' => 'Annex'])
        ->assertCreated()->assertJsonPath('operating_unit_id', $this->unitB->id);

    // A company-wide caller with no unit pinned cannot pick one through the body.
    // The unit context is a singleton that outlives one test request.
    app(CurrentUnitContext::class)->clear();
    $this->flushHeaders()->actingAs($this->owner)
        ->postJson('/api/v1/warehouses', ['name' => 'Elsewhere', 'operating_unit_id' => $this->unitB->id])
        ->assertStatus(422)->assertJsonValidationErrors('operating_unit_id');

    app(CurrentUnitContext::class)->clear();
    $this->flushHeaders()->actingAs($this->owner)
        ->postJson('/api/v1/warehouses', ['name' => 'Elsewhere'])
        ->assertStatus(422)->assertJsonPath('code', 'OPERATING_UNIT_REQUIRED');

    expect(Warehouse::withoutGlobalScopes()->where('name', 'Elsewhere')->exists())->toBeFalse()
        ->and(Warehouse::withoutGlobalScopes()->where('operating_unit_id', $this->unitA->id)->where('name', 'Annex')->exists())->toBeFalse();
});

test('a warehouse name taken between validation and insert still gets a 422, not a 500', function () {
    // Simulates a concurrent request: a sibling with the same name lands
    // after the check passed and before this insert.
    Event::listen('eloquent.creating: '.Warehouse::class, function (Warehouse $warehouse): void {
        DB::table('warehouses')->insert([
            'id' => (string) Str::uuid(), 'operating_unit_id' => $warehouse->operating_unit_id, 'parent_id' => $warehouse->parent_id,
            'name' => $warehouse->name, 'is_internal_unit' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Main'])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('code', 'WAREHOUSE_NAME_TAKEN');

    expect(Warehouse::withoutGlobalScopes()->where('name', 'Main')->count())->toBe(1);
});

test('a sub-warehouse cannot be created under another unit\'s warehouse', function () {
    $foreign = Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'B Main']);

    ($this->api)()->postJson('/api/v1/warehouses', ['name' => 'Shelf', 'parent_id' => $foreign->id])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');
});

test('renaming a warehouse to a sibling\'s name is refused; keeping its own name is fine', function () {
    Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main']);
    $annex = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Annex']);

    ($this->api)()->putJson("/api/v1/warehouses/{$annex->id}", ['name' => 'Main'])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('code', 'WAREHOUSE_NAME_TAKEN');

    ($this->api)()->putJson("/api/v1/warehouses/{$annex->id}", ['name' => 'Annex', 'location_type' => 'YARD'])
        ->assertOk();
});

test('the admin per-unit warehouse create refuses a duplicate name', function () {
    Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'Main']);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/operating-units/{$this->unitB->id}/warehouses", ['name' => 'Main'])
        ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('code', 'WAREHOUSE_NAME_TAKEN');
});

test('the warehouse migration on clean data renames nothing and the indexes refuse duplicates', function () {
    $migration = ($this->migration)('2026_11_01_110100_add_unique_names_to_warehouses.php');
    $migration->down();

    $main = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Main']);
    Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'Main']);
    Warehouse::create(['operating_unit_id' => $this->unitA->id, 'parent_id' => $main->id, 'name' => 'Main']);

    Log::spy();
    $migration->up();
    Log::shouldNotHaveReceived('warning');

    expect(Warehouse::withoutGlobalScopes()->where('name', 'Main')->count())->toBe(3);

    expect(fn () => DB::table('warehouses')->insert([
        'id' => (string) Str::uuid(), 'operating_unit_id' => $this->unitA->id, 'name' => 'Main',
        'is_internal_unit' => false, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('warehouses')->insert([
        'id' => (string) Str::uuid(), 'operating_unit_id' => $this->unitA->id, 'parent_id' => $main->id, 'name' => 'Main',
        'is_internal_unit' => false, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the warehouse migration renames existing duplicates deterministically and logs each rename', function () {
    $migration = ($this->migration)('2026_11_01_110100_add_unique_names_to_warehouses.php');
    $migration->down();

    $insert = function (string $name, string $unitId, string $createdAt, ?string $parentId = null): string {
        $id = (string) Str::uuid();
        DB::table('warehouses')->insert([
            'id' => $id, 'operating_unit_id' => $unitId, 'parent_id' => $parentId, 'name' => $name,
            'is_internal_unit' => false, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    };

    $oldest = $insert('Main', $this->unitA->id, '2026-01-01 08:00:00');
    $second = $insert('Main', $this->unitA->id, '2026-01-02 08:00:00');
    $taken = $insert('Main (2)', $this->unitA->id, '2026-01-03 08:00:00');
    $third = $insert('Main', $this->unitA->id, '2026-01-04 08:00:00');
    $otherUnit = $insert('Main', $this->unitB->id, '2026-01-05 08:00:00');
    $shelf = $insert('Shelf', $this->unitA->id, '2026-01-06 08:00:00', $oldest);
    $shelfDup = $insert('Shelf', $this->unitA->id, '2026-01-07 08:00:00', $oldest);

    Log::spy();
    $migration->up();

    $name = fn (string $id) => DB::table('warehouses')->where('id', $id)->value('name');
    expect($name($oldest))->toBe('Main')
        ->and($name($second))->toBe('Main (3)')
        ->and($name($taken))->toBe('Main (2)')
        ->and($name($third))->toBe('Main (4)')
        ->and($name($otherUnit))->toBe('Main')
        ->and($name($shelf))->toBe('Shelf')
        ->and($name($shelfDup))->toBe('Shelf (2)');

    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['warehouse_id'] === $second
        && $context['old_name'] === 'Main' && $context['new_name'] === 'Main (3)');
});

// ---------------------------------------------------------------------------
// Clients and suppliers
// ---------------------------------------------------------------------------

test('a receivable account already linked to a client cannot be linked to another', function () {
    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('122', 'عميل - Amal');
    $owner = Client::factory()->create(['operating_unit_id' => $this->unitA->id, 'account_id' => $accountId]);
    $other = Client::factory()->create(['operating_unit_id' => $this->unitA->id]);

    ($this->api)()->postJson('/api/v1/clients', [
        'name' => 'Second Client', 'coa_action' => 'link_existing', 'account_id' => $accountId,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'CLIENT_ACCOUNT_TAKEN');

    ($this->api)()->putJson("/api/v1/clients/{$other->id}", [
        'coa_action' => 'link_existing', 'account_id' => $accountId, 'record_version' => $other->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'CLIENT_ACCOUNT_TAKEN');

    ($this->api)()->putJson("/api/v1/clients/{$owner->id}", [
        'coa_action' => 'link_existing', 'account_id' => $accountId, 'record_version' => $owner->record_version,
    ])->assertOk();

    expect(fn () => DB::table('clients')->where('id', $other->id)->update(['account_id' => $accountId]))
        ->toThrow(QueryException::class);
});

test('a payable account linked to a live supplier cannot be linked to another; a deleted supplier releases it', function () {
    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Amal Trading');
    $owner = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Amal Trading', 'account_id' => $accountId]);
    $other = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Benghazi Foam']);

    ($this->api)()->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitA->id, 'name' => 'Misrata Wood',
        'coa_action' => 'link_existing', 'account_id' => $accountId,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'SUPPLIER_ACCOUNT_TAKEN');

    ($this->api)()->putJson("/api/v1/suppliers/{$other->id}", [
        'coa_action' => 'link_existing', 'account_id' => $accountId,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'SUPPLIER_ACCOUNT_TAKEN');

    ($this->api)()->putJson("/api/v1/suppliers/{$owner->id}", [
        'coa_action' => 'link_existing', 'account_id' => $accountId,
    ])->assertOk();

    expect(fn () => DB::table('suppliers')->where('id', $other->id)->update(['account_id' => $accountId]))
        ->toThrow(QueryException::class);

    // Deleted by mistake: its account (and postings) can move to a replacement.
    ($this->api)()->deleteJson("/api/v1/suppliers/{$owner->id}")->assertOk();

    ($this->api)()->putJson("/api/v1/suppliers/{$other->id}", [
        'coa_action' => 'link_existing', 'account_id' => $accountId,
    ])->assertOk()->assertJsonPath('data.account_id', $accountId);

    expect(Supplier::withTrashed()->find($owner->id)->account_id)->toBe($accountId);
});

test('a client or supplier account taken between validation and save still gets a 422, not a 500', function () {
    $clientAccount = app(CoaLinkService::class)->provisionNextSubAccount('122', 'عميل - Race');
    $rivalClient = Client::factory()->create(['operating_unit_id' => $this->unitA->id]);
    $client = Client::factory()->create(['operating_unit_id' => $this->unitA->id]);

    $supplierAccount = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Race Vendor');
    $rivalSupplier = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Rival Vendor']);

    // Simulates a concurrent request: another party takes the account after
    // the unique rule passed and before this write.
    Event::listen('eloquent.updating: '.Client::class, function () use ($rivalClient, $clientAccount): void {
        DB::table('clients')->where('id', $rivalClient->id)->update(['account_id' => $clientAccount]);
    });
    Event::listen('eloquent.creating: '.Supplier::class, function () use ($rivalSupplier, $supplierAccount): void {
        DB::table('suppliers')->where('id', $rivalSupplier->id)->update(['account_id' => $supplierAccount]);
    });

    ($this->api)()->putJson("/api/v1/clients/{$client->id}", [
        'coa_action' => 'link_existing', 'account_id' => $clientAccount, 'record_version' => $client->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'CLIENT_ACCOUNT_TAKEN');

    ($this->api)()->postJson('/api/v1/suppliers', [
        'operating_unit_id' => $this->unitA->id, 'name' => 'Misrata Wood',
        'coa_action' => 'link_existing', 'account_id' => $supplierAccount,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id')->assertJsonPath('code', 'SUPPLIER_ACCOUNT_TAKEN');

    expect($client->fresh()->account_id)->not->toBe($clientAccount)
        ->and(Supplier::where('name', 'Misrata Wood')->exists())->toBeFalse();
});

test('the account migration adds both indexes on clean data', function () {
    $migration = ($this->migration)('2026_11_01_110200_add_unique_account_to_clients_and_suppliers.php');
    $migration->down();

    $clientAccount = app(CoaLinkService::class)->provisionNextSubAccount('122', 'عميل - One');
    $supplierAccount = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Vendor One');
    Client::factory()->create(['operating_unit_id' => $this->unitA->id, 'account_id' => $clientAccount]);
    Client::factory()->create(['operating_unit_id' => $this->unitA->id]);
    Client::factory()->create(['operating_unit_id' => $this->unitA->id]);
    Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'account_id' => $supplierAccount]);
    Supplier::factory()->create(['operating_unit_id' => $this->unitA->id]);

    $migration->up();

    $second = Client::factory()->create(['operating_unit_id' => $this->unitA->id]);
    expect(fn () => DB::table('clients')->where('id', $second->id)->update(['account_id' => $clientAccount]))
        ->toThrow(QueryException::class);
});

test('the account migration refuses shared accounts, naming them, and leaves the schema unchanged', function () {
    $migration = ($this->migration)('2026_11_01_110200_add_unique_account_to_clients_and_suppliers.php');
    $migration->down();

    $clientAccount = app(CoaLinkService::class)->provisionNextSubAccount('122', 'عميل - Shared');
    $supplierAccount = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Shared Vendor');
    $clientCode = DB::table('accounts')->where('id', $clientAccount)->value('account_code');
    $supplierCode = DB::table('accounts')->where('id', $supplierAccount)->value('account_code');

    $amal = Client::factory()->create([
        'operating_unit_id' => $this->unitA->id, 'account_id' => $clientAccount,
        'entity_id' => Entity::factory()->create(['name' => 'Amal Client'])->id,
    ]);
    $noor = Client::factory()->create([
        'operating_unit_id' => $this->unitA->id, 'account_id' => $clientAccount,
        'entity_id' => Entity::factory()->create(['name' => 'Noor Client'])->id,
    ]);
    // Two live suppliers in different units (merge is refused across units).
    Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Vendor In A', 'account_id' => $supplierAccount]);
    Supplier::factory()->create(['operating_unit_id' => $this->unitB->id, 'name' => 'Vendor In B', 'account_id' => $supplierAccount]);

    try {
        $migration->up();
        $this->fail('The migration should refuse shared accounts.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain($clientCode)
            ->toContain("Amal Client [{$amal->id}]")
            ->toContain("Noor Client [{$noor->id}]")
            ->toContain($supplierCode)
            ->toContain('Vendor In A')
            ->toContain('(Unit A)')
            ->toContain('Vendor In B')
            ->toContain('(Unit B)')
            ->toContain('link another account')
            ->toContain('merge suppliers of the same operating unit');
    }

    // No index was added and no row was touched: the data is still shared.
    expect(DB::table('clients')->where('account_id', $clientAccount)->count())->toBe(2)
        ->and(DB::table('suppliers')->where('account_id', $supplierAccount)->count())->toBe(2);

    $third = Client::factory()->create(['operating_unit_id' => $this->unitA->id]);
    DB::table('clients')->where('id', $third->id)->update(['account_id' => $clientAccount]);
    expect(DB::table('clients')->where('account_id', $clientAccount)->count())->toBe(3);
});

test('a deleted supplier sharing an account with a live one does not block the account migration', function () {
    $migration = ($this->migration)('2026_11_01_110200_add_unique_account_to_clients_and_suppliers.php');
    $migration->down();

    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Shared Vendor');
    $live = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Live Vendor', 'account_id' => $accountId]);
    Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Gone Vendor', 'account_id' => $accountId])->delete();

    $migration->up();

    // The deleted supplier keeps its account_id; a second live owner is refused.
    expect(DB::table('suppliers')->where('account_id', $accountId)->count())->toBe(2);
    $other = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Other Vendor']);
    expect(fn () => DB::table('suppliers')->where('id', $other->id)->update(['account_id' => $accountId]))
        ->toThrow(QueryException::class);
    expect($live->fresh()->account_id)->toBe($accountId);
});

test('merging two suppliers that share an account clears the conflict for the account migration', function () {
    $migration = ($this->migration)('2026_11_01_110200_add_unique_account_to_clients_and_suppliers.php');
    $migration->down();

    $accountId = app(CoaLinkService::class)->provisionNextSubAccount('21', 'Shared Vendor');
    $keep = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Amal Trading', 'account_id' => $accountId]);
    $duplicate = Supplier::factory()->create(['operating_unit_id' => $this->unitA->id, 'name' => 'Amal Trading Co', 'account_id' => $accountId]);

    ($this->api)()->postJson("/api/v1/suppliers/{$duplicate->id}/merge", ['target_id' => $keep->id])->assertOk();

    $migration->up();

    expect(Supplier::find($keep->id)->account_id)->toBe($accountId)
        ->and(Supplier::find($duplicate->id))->toBeNull();
});
