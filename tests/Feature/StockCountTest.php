<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\FiscalCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W4-01 (decision #13): physical stock counts, and the stock back doors closed.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Count Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);
    seedUnitAccounts($this->unit);

    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Main WH']);
    $this->otherWarehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Back WH']);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };
    $this->counter = $makeUser('inventory-manager', $this->unit->id);
    $this->accountant = $makeUser('accounting-manager', null);
    $this->owner = $makeUser('owner', null);

    $this->raw = InventoryItem::create(['name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter', 'cost_price' => 7]);
    $this->sofa = InventoryItem::create(['name' => 'Sofa', 'code' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece']);
    $this->foam = InventoryItem::create(['name' => 'Block D25', 'code' => 'BLK-D25', 'item_type' => 'foam_block', 'unit_of_measure' => 'each']);

    $this->lot = fn (InventoryItem $item, float $quantity, float $unitCost, string $status = 'available', ?Warehouse $warehouse = null) => StockLot::create([
        'inventory_item_id' => $item->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        'lot_number' => 'LOT-'.$item->code.'-'.uniqid(), 'quantity' => $quantity, 'unit_cost' => $unitCost, 'status' => $status,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->open = fn (array $body = []) => ($this->as)($this->counter)
        ->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->warehouse->id, ...$body])
        ->assertCreated()
        ->json();

    $this->lineFor = fn (array $count, string $lotId) => collect($count['lines'])->firstWhere('stock_lot_id', $lotId);

    $this->enter = fn (array $count, array $lines) => ($this->as)($this->counter)
        ->putJson("/api/v1/stock-counts/{$count['id']}/lines", ['lines' => $lines]);

    $this->submit = fn (array $count) => ($this->as)($this->counter)
        ->postJson("/api/v1/stock-counts/{$count['id']}/submit")
        ->assertOk()
        ->json();

    $this->approve = fn (array $count, ?User $user = null) => ($this->as)($user ?? $this->accountant)
        ->postJson("/api/v1/stock-counts/{$count['id']}/approve");

    $this->sum = fn (string $countId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_type', 'StockCount')->where('source_document_id', $countId))
        ->where('account_id', Account::where('account_code', $code)->value('id'))
        ->sum($side);
});

test('account 45 is a revenue leaf for stock count surpluses', function () {
    $account = Account::where('account_code', '45')->sole();

    expect($account->type)->toBe('revenue')
        ->and(Account::where('parent_account_id', $account->id)->exists())->toBeFalse();
});

test('a count snapshots available and quarantined lots with stock in the warehouse', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $quarantined = ($this->lot)($this->sofa, 2, 100, 'quarantined');
    ($this->lot)($this->raw, 0, 5, 'consumed');
    ($this->lot)($this->raw, 4, 5, 'available', $this->otherWarehouse);

    $count = ($this->open)();

    expect($count['number'])->toStartWith('CNT-2026-')
        ->and($count['status'])->toBe('draft')
        ->and($count['lines'])->toHaveCount(2)
        ->and(collect($count['lines'])->pluck('stock_lot_id')->sort()->values()->all())
        ->toBe(collect([$fabric->id, $quarantined->id])->sort()->values()->all())
        ->and((float) ($this->lineFor)($count, $fabric->id)['system_qty'])->toBe(10.0);
});

test('reserved lots are excluded from the count and listed', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $reserved = ($this->lot)($this->foam, 1, 300, 'reserved');

    $count = ($this->open)();

    expect(collect($count['lines'])->pluck('stock_lot_id')->all())->toBe([$fabric->id])
        ->and(collect($count['reserved_lots'])->pluck('id')->all())->toBe([$reserved->id]);

    ($this->as)($this->counter)->getJson("/api/v1/stock-counts/{$count['id']}")
        ->assertOk()
        ->assertJsonPath('reserved_lots.0.id', $reserved->id);
});

test('approval posts shortages to waste and surpluses to 45, with adjustment movements', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $sofa = ($this->lot)($this->sofa, 3, 100);

    $count = ($this->open)();
    ($this->enter)($count, [
        ['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 8],
        ['id' => ($this->lineFor)($count, $sofa->id)['id'], 'counted_qty' => 4],
    ])->assertOk()->assertJsonPath('status', 'counting');

    $submitted = ($this->submit)($count);
    expect($submitted['summary']['shortage_value'])->toEqual(10)
        ->and($submitted['summary']['surplus_value'])->toEqual(100);

    $approved = ($this->approve)($count)->assertOk()->json();

    expect($approved['status'])->toBe('approved')
        ->and($approved['approved_by_user_id'])->toBe($this->accountant->id)
        ->and((float) $fabric->fresh()->quantity)->toBe(8.0)
        ->and((float) $sofa->fresh()->quantity)->toBe(4.0);

    // Shortage DR 52 / CR 111 (raw); surplus DR 1134 (finished good) / CR 45.
    expect(($this->sum)($count['id'], '52', 'debit'))->toBe(10.0)
        ->and(($this->sum)($count['id'], '111', 'credit'))->toBe(10.0)
        ->and(($this->sum)($count['id'], '1134', 'debit'))->toBe(100.0)
        ->and(($this->sum)($count['id'], '45', 'credit'))->toBe(100.0);

    $entry = JournalEntry::findOrFail($approved['journal_entry_id']);
    expect($entry->entry_date->toDateString())->toBe('2026-10-20')
        ->and(JournalEntry::where('source_document_type', 'StockCount')->count())->toBe(1);

    $movements = InventoryMovement::where('reference_document_type', 'StockCount')->where('reference_id', $count['id'])->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->unique()->all())->toBe(['adjustment'])
        ->and((float) $movements->firstWhere('stock_lot_id', $fabric->id)->quantity_delta)->toBe(-2.0)
        ->and($movements->firstWhere('stock_lot_id', $fabric->id)->from_warehouse_id)->toBe($this->warehouse->id)
        ->and((float) $movements->firstWhere('stock_lot_id', $sofa->id)->quantity_delta)->toBe(1.0)
        ->and($movements->firstWhere('stock_lot_id', $sofa->id)->to_warehouse_id)->toBe($this->warehouse->id);
});

test('a lot counted down to zero is consumed', function () {
    $block = ($this->lot)($this->foam, 1, 300);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $block->id)['id'], 'counted_qty' => 0]])->assertOk();
    ($this->submit)($count);
    ($this->approve)($count)->assertOk();

    $fresh = $block->fresh();
    expect((float) $fresh->quantity)->toBe(0.0)
        ->and($fresh->status)->toBe('consumed')
        ->and(($this->sum)($count['id'], '1131', 'credit'))->toBe(300.0)
        ->and(($this->sum)($count['id'], '52', 'debit'))->toBe(300.0);
});

test('a count with no differences approves without a journal entry', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 10]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count)->assertOk()->assertJsonPath('journal_entry_id', null);
    expect(InventoryMovement::where('reference_document_type', 'StockCount')->count())->toBe(0);
});

test('found stock is costed from the newest lot of the item in the warehouse', function () {
    $older = ($this->lot)($this->sofa, 1, 90);
    $this->travel(1)->minutes();
    $newer = ($this->lot)($this->sofa, 1, 120, 'consumed');
    $newer->update(['quantity' => 0]);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $older->id)['id'], 'counted_qty' => 1]])->assertOk();

    $withFound = ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->sofa->id, 'counted_qty' => 2, 'note' => 'behind the curtain',
    ])->assertCreated()->json();

    $found = collect($withFound['lines'])->firstWhere('is_found', true);
    expect((float) $found['unit_cost'])->toBe(120.0)
        ->and((float) $found['variance_qty'])->toBe(2.0)
        ->and((float) $found['variance_value'])->toBe(240.0);

    ($this->submit)($count);
    $approved = ($this->approve)($count)->assertOk()->json();

    $line = collect($approved['lines'])->firstWhere('is_found', true);
    $newLot = StockLot::findOrFail($line['new_stock_lot_id']);

    expect((float) $newLot->quantity)->toBe(2.0)
        ->and((float) $newLot->unit_cost)->toBe(120.0)
        ->and($newLot->warehouse_id)->toBe($this->warehouse->id)
        ->and($newLot->status)->toBe('available')
        ->and(InventoryMovement::where('stock_lot_id', $newLot->id)->where('movement_type', 'adjustment')->sum('quantity_delta'))->toEqual(2)
        ->and(($this->sum)($count['id'], '1134', 'debit'))->toBe(240.0)
        ->and(($this->sum)($count['id'], '45', 'credit'))->toBe(240.0);
});

test('found stock falls back to the item cost price, else is refused', function () {
    $count = ($this->open)();

    $withFound = ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->raw->id, 'counted_qty' => 3,
    ])->assertCreated()->json();

    expect((float) collect($withFound['lines'])->firstWhere('is_found', true)['unit_cost'])->toBe(7.0);

    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->sofa->id, 'counted_qty' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'COUNT_FOUND_COST_UNKNOWN');
});

test('the approver cannot be the person who opened the count', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->as)($this->owner)->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->warehouse->id])->assertCreated()->json();
    ($this->as)($this->owner)->putJson("/api/v1/stock-counts/{$count['id']}/lines", [
        'lines' => [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 9]],
    ])->assertOk();
    ($this->as)($this->owner)->postJson("/api/v1/stock-counts/{$count['id']}/submit")->assertOk();

    ($this->approve)($count, $this->owner)->assertStatus(422)->assertJsonPath('code', 'COUNT_SELF_APPROVAL');

    expect((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and(StockCount::findOrFail($count['id'])->status->value)->toBe('submitted');

    ($this->approve)($count, $this->accountant)->assertOk();
});

test('approving needs approve-stock-counts', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 9]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count, $this->counter)->assertForbidden();

    expect($this->accountant->hasPermission('approve-stock-counts'))->toBeTrue()
        ->and($this->counter->hasPermission('approve-stock-counts'))->toBeFalse();
});

test('a count dated in a locked month cannot be approved', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    // Counted on the last day of September, approved in October.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'Africa/Tripoli'));
    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 6]])->assertOk();
    ($this->submit)($count);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    app(FiscalCalendar::class)->periodFor('2026-09-30', $this->company->id);
    $september = FiscalPeriod::query()->where('calendar_year', 2026)->where('calendar_month', 9)->sole();
    $this->actingAs($this->accountant)->postJson("/api/v1/fiscal-periods/{$september->id}/lock", ['include_earlier' => true])->assertOk();

    ($this->approve)($count)->assertStatus(422)->assertJsonPath('code', 'PERIOD_LOCKED');

    expect((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and(InventoryMovement::where('reference_document_type', 'StockCount')->count())->toBe(0)
        ->and(StockCount::findOrFail($count['id'])->status->value)->toBe('submitted');
});

test('a foam block lot counts 0 or 1', function () {
    $block = ($this->lot)($this->foam, 1, 300);
    $count = ($this->open)();
    $lineId = ($this->lineFor)($count, $block->id)['id'];

    ($this->enter)($count, [['id' => $lineId, 'counted_qty' => 2]])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_FOAM_BLOCK_QUANTITY');
    ($this->enter)($count, [['id' => $lineId, 'counted_qty' => 0.5]])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_FOAM_BLOCK_QUANTITY');

    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->foam->id, 'counted_qty' => 2,
    ])->assertStatus(422)->assertJsonPath('code', 'COUNT_FOAM_BLOCK_QUANTITY');

    ($this->enter)($count, [['id' => $lineId, 'counted_qty' => 1]])->assertOk();
});

test('a blind count hides system quantities until it is submitted', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)(['blind' => true]);
    $line = ($this->lineFor)($count, $fabric->id);

    expect($count['system_quantities_hidden'])->toBeTrue()
        ->and($line['system_qty'])->toBeNull()
        ->and($count['summary'])->not->toHaveKey('shortage_value');

    $sheet = ($this->as)($this->counter)->getJson("/api/v1/stock-counts/{$count['id']}/sheet")->assertOk()->json();
    expect($sheet['rows'][0]['system_qty'])->toBeNull()
        ->and($sheet['rows'][0]['lot_number'])->toBe($fabric->lot_number);

    ($this->enter)($count, [['id' => $line['id'], 'counted_qty' => 7]])->assertOk()->assertJsonPath('lines.0.variance_qty', null);

    $submitted = ($this->submit)($count);
    expect((float) ($this->lineFor)($submitted, $fabric->id)['system_qty'])->toBe(10.0)
        ->and((float) ($this->lineFor)($submitted, $fabric->id)['variance_qty'])->toBe(-3.0);
});

test('a count cannot be submitted with uncounted lines', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    ($this->lot)($this->sofa, 2, 100);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 10]])->assertOk();

    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/submit")
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_INCOMPLETE');
});

test('only one open count per warehouse, and a cancelled count frees it', function () {
    ($this->lot)($this->raw, 10, 5);
    $count = ($this->open)();

    ($this->as)($this->counter)->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->warehouse->id])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_ALREADY_OPEN');

    ($this->as)($this->accountant)->postJson("/api/v1/stock-counts/{$count['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

    ($this->enter)($count, [['id' => $count['lines'][0]['id'], 'counted_qty' => 1]])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_NOT_EDITABLE');

    ($this->open)();
});

test('a count is refused when the counted lot moved after the snapshot', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    $this->travel(1)->minutes();

    // Four sold after the snapshot; the shelf is then counted at 5 (one really missing).
    // Applying 5 - 10 to the 6 left would take the sale off twice.
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $fabric->id, 'from_warehouse_id' => $this->warehouse->id,
        'sku' => 'FAB-1', 'movement_type' => 'sale', 'quantity_delta' => -4, 'unit_cost' => 5, 'reason' => 'sale',
    ]);
    $fabric->refresh()->update(['quantity' => 6]);

    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 5]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_LOT_MOVED')
        ->assertJsonPath('lot_numbers', [$fabric->lot_number]);

    expect((float) $fabric->fresh()->quantity)->toBe(6.0)
        ->and(InventoryMovement::where('reference_document_type', 'StockCount')->count())->toBe(0)
        ->and(JournalEntry::where('source_document_type', 'StockCount')->count())->toBe(0)
        ->and(StockCount::findOrFail($count['id'])->status->value)->toBe('submitted');
});

test('a count is refused when the counted lot moved and came back after the snapshot', function () {
    $sofa = ($this->lot)($this->sofa, 3, 100);

    $count = ($this->open)();
    $this->travel(1)->minutes();

    foreach ([-1, 1] as $delta) {
        InventoryMovement::create([
            'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $sofa->id,
            'from_warehouse_id' => $delta < 0 ? $this->warehouse->id : null, 'to_warehouse_id' => $delta > 0 ? $this->warehouse->id : null,
            'sku' => 'SOFA-1', 'movement_type' => $delta < 0 ? 'sale' : 'sale_return', 'quantity_delta' => $delta, 'unit_cost' => 100, 'reason' => 'sale',
        ]);
    }

    ($this->enter)($count, [['id' => ($this->lineFor)($count, $sofa->id)['id'], 'counted_qty' => 2]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)->assertJsonPath('code', 'COUNT_LOT_MOVED');
    expect((float) $sofa->fresh()->quantity)->toBe(3.0);
});

test('found stock is refused when a lot of the item arrived after the snapshot', function () {
    $count = ($this->open)();
    $this->travel(1)->minutes();

    // Transferred in after the snapshot: counting it as found would double it.
    $arrived = ($this->lot)($this->sofa, 2, 100);

    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->sofa->id, 'counted_qty' => 2,
    ])->assertCreated();
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_LOT_MOVED')
        ->assertJsonPath('lot_ids', [$arrived->id]);
    expect(StockLot::where('inventory_item_id', $this->sofa->id)->count())->toBe(1);
});

test('approval posts at the lot cost of the day and writes it back onto the line', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 8]])->assertOk();
    ($this->submit)($count);

    // The lot's cost was revised after the snapshot (its quantity did not move).
    $fabric->refresh()->update(['unit_cost' => 6]);

    $approved = ($this->approve)($count)->assertOk()->json();
    $line = ($this->lineFor)($approved, $fabric->id);

    expect(($this->sum)($count['id'], '52', 'debit'))->toBe(12.0)
        ->and(($this->sum)($count['id'], '111', 'credit'))->toBe(12.0)
        ->and((float) $line['unit_cost'])->toBe(6.0)
        ->and((float) $line['variance_value'])->toBe(-12.0)
        ->and($approved['summary']['shortage_value'])->toEqual(12);
});

test('blocks of a production batch that is not closed are left out of the count', function () {
    $open = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 501, 'bun_width_m' => 2.4, 'status' => 'graded']);
    $closed = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 502, 'bun_width_m' => 2.4, 'status' => 'closed']);

    $block = fn (ProductionBatch $batch, float $cost) => StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $this->warehouse->id, 'production_batch_id' => $batch->id,
        'lot_number' => $batch->operation_number.'-01', 'sequence_in_batch' => 1, 'quantity' => 1, 'unit_cost' => $cost, 'status' => 'available',
    ]);
    $uncosted = $block($open, 0);
    $costed = $block($closed, 300);

    $count = ($this->open)();

    expect(collect($count['lines'])->pluck('stock_lot_id')->all())->toBe([$costed->id])
        ->and(collect($count['lines'])->pluck('stock_lot_id')->all())->not->toContain($uncosted->id);
});

test('a category count takes in its subcategories', function () {
    $parent = ItemCategory::create(['name' => 'Raw materials', 'code' => 'RAW']);
    $child = ItemCategory::create(['name' => 'Fabrics', 'code' => 'RAW-FAB', 'parent_id' => $parent->id]);
    $grandchild = ItemCategory::create(['name' => 'Velvet', 'code' => 'RAW-FAB-V', 'parent_id' => $child->id]);
    $other = ItemCategory::create(['name' => 'Finished', 'code' => 'FIN']);

    $this->raw->update(['category_id' => $child->id]);
    $velvet = InventoryItem::create(['name' => 'Velvet', 'code' => 'VEL-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter', 'category_id' => $grandchild->id]);
    $this->sofa->update(['category_id' => $other->id]);

    $fabric = ($this->lot)($this->raw, 10, 5);
    $velvetLot = ($this->lot)($velvet, 4, 9);
    $sofa = ($this->lot)($this->sofa, 2, 100);

    $count = ($this->open)(['item_category_id' => $parent->id]);

    expect(collect($count['lines'])->pluck('stock_lot_id')->sort()->values()->all())
        ->toBe(collect([$fabric->id, $velvetLot->id])->sort()->values()->all())
        ->and(collect($count['lines'])->pluck('stock_lot_id')->all())->not->toContain($sofa->id);
});

test('blind mode cannot be switched off once the count is open', function () {
    ($this->lot)($this->raw, 10, 5);
    $count = ($this->open)(['blind' => true]);

    ($this->as)($this->counter)->putJson("/api/v1/stock-counts/{$count['id']}", ['blind' => false])
        ->assertStatus(422)->assertJsonValidationErrors('blind');

    $shown = ($this->as)($this->counter)->getJson("/api/v1/stock-counts/{$count['id']}")->assertOk()->json();
    expect($shown['blind'])->toBeTrue()
        ->and($shown['system_quantities_hidden'])->toBeTrue()
        ->and($shown['lines'][0]['system_qty'])->toBeNull();

    ($this->as)($this->counter)->putJson("/api/v1/stock-counts/{$count['id']}", ['notes' => 'Aisle 3 first'])
        ->assertOk()->assertJsonPath('notes', 'Aisle 3 first')->assertJsonPath('blind', true);
});

test('the approver cannot be the person who entered the quantities', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $sofa = ($this->lot)($this->sofa, 2, 100);

    $count = ($this->open)();
    ($this->as)($this->owner)->putJson("/api/v1/stock-counts/{$count['id']}/lines", [
        'lines' => [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 9]],
    ])->assertOk();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $sofa->id)['id'], 'counted_qty' => 2]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count, $this->owner)->assertStatus(422)->assertJsonPath('code', 'COUNT_SELF_APPROVAL');
    expect((float) $fabric->fresh()->quantity)->toBe(10.0);

    ($this->approve)($count, $this->accountant)->assertOk();
    expect((float) $fabric->fresh()->quantity)->toBe(9.0);
});

test('the approver cannot be the person who added found stock', function () {
    $count = ($this->open)();
    ($this->as)($this->owner)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->raw->id, 'counted_qty' => 3,
    ])->assertCreated();
    ($this->submit)($count);

    ($this->approve)($count, $this->owner)->assertStatus(422)->assertJsonPath('code', 'COUNT_SELF_APPROVAL');
});

test('the surplus account migration adds 45 on a database without it', function () {
    Account::where('account_code', '45')->delete();

    $migration = require database_path('migrations/2026_10_29_120100_add_stock_count_surplus_account.php');
    $migration->up();

    $account = Account::where('account_code', '45')->sole();
    expect($account->type)->toBe('revenue')
        ->and($account->name)->toBe('أرباح فروقات الجرد')
        ->and(Account::find($account->parent_account_id)?->account_code)->toBe('4')
        ->and(Account::where('parent_account_id', $account->id)->exists())->toBeFalse();
});

test('the surplus account migration never repurposes a 45 that means something else', function () {
    Account::where('account_code', '45')->sole()->update(['name' => 'إيرادات أخرى', 'type' => 'expense']);

    $migration = require database_path('migrations/2026_10_29_120100_add_stock_count_surplus_account.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Account 45 already exists');
    expect(Account::where('account_code', '45')->sole()->name)->toBe('إيرادات أخرى');
});

test('the permission migration grants approve-stock-counts to an existing accounting-manager role', function () {
    $role = Role::where('slug', 'accounting-manager')->sole();
    $permId = Permission::where('slug', 'approve-stock-counts')->value('id');
    if ($permId !== null) {
        $role->permissions()->detach($permId);
    }

    expect($role->permissions()->pluck('slug'))->not->toContain('approve-stock-counts');

    $migration = require database_path('migrations/2026_10_29_120200_seed_approve_stock_counts_permission.php');
    $migration->up();

    expect($role->permissions()->pluck('slug'))->toContain('approve-stock-counts');
});

test('the stock back doors are closed', function () {
    // No bare movement store, no work-order complete.
    ($this->as)($this->owner)->postJson('/api/v1/inventory-movements', [
        'sku' => 'FAB-1', 'quantity_delta' => 100, 'reason' => 'manual',
    ])->assertStatus(405);
    expect(InventoryMovement::count())->toBe(0);

    $lot = ($this->lot)($this->raw, 10, 5);

    // A lot cannot be consumed, reserved or released by a plain update.
    ($this->as)($this->owner)->putJson("/api/v1/stock-lots/{$lot->id}", ['status' => 'consumed', 'record_version' => $lot->record_version])
        ->assertStatus(422)->assertJsonPath('code', 'LOT_STATUS_CHANGE_NOT_ALLOWED');
    ($this->as)($this->owner)->putJson("/api/v1/stock-lots/{$lot->id}", ['status' => 'reserved', 'record_version' => $lot->record_version])
        ->assertStatus(422)->assertJsonPath('code', 'LOT_STATUS_CHANGE_NOT_ALLOWED');

    // available <-> quarantined stays allowed.
    ($this->as)($this->owner)->putJson("/api/v1/stock-lots/{$lot->id}", ['status' => 'quarantined', 'record_version' => $lot->fresh()->record_version])
        ->assertOk()->assertJsonPath('status', 'quarantined');
    ($this->as)($this->owner)->putJson("/api/v1/stock-lots/{$lot->id}", ['status' => 'available', 'record_version' => $lot->fresh()->record_version])
        ->assertOk()->assertJsonPath('status', 'available');

    $reserved = ($this->lot)($this->foam, 1, 300, 'reserved');
    ($this->as)($this->owner)->putJson("/api/v1/stock-lots/{$reserved->id}", ['status' => 'available', 'record_version' => $reserved->record_version])
        ->assertStatus(422)->assertJsonPath('code', 'LOT_STATUS_CHANGE_NOT_ALLOWED');
    expect($reserved->fresh()->status)->toBe('reserved');
});

test('a count cannot be dated before the day it was opened', function () {
    ($this->lot)($this->raw, 10, 5);

    ($this->as)($this->counter)->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->warehouse->id, 'count_date' => '2026-08-01'])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_DATE_BEFORE_SNAPSHOT');

    $count = ($this->open)();
    ($this->as)($this->counter)->putJson("/api/v1/stock-counts/{$count['id']}", ['count_date' => '2026-10-19'])
        ->assertStatus(422)->assertJsonPath('code', 'COUNT_DATE_BEFORE_SNAPSHOT');

    // Counted over two days: the later day is fine.
    $this->travel(1)->days();
    ($this->as)($this->counter)->putJson("/api/v1/stock-counts/{$count['id']}", ['count_date' => '2026-10-21'])
        ->assertOk()->assertJsonPath('count_date', fn ($date) => str_starts_with((string) $date, '2026-10-21'));
});

test('stock that moves after submission is applied on top of the difference', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 8]])->assertOk();
    ($this->submit)($count);

    // Three sold while the count waited for approval.
    $this->travel(1)->minutes();
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $fabric->id, 'from_warehouse_id' => $this->warehouse->id,
        'sku' => 'FAB-1', 'movement_type' => 'sale', 'quantity_delta' => -3, 'unit_cost' => 5, 'reason' => 'sale',
    ]);
    $fabric->refresh()->update(['quantity' => 7]);
    $this->travel(1)->minutes();

    ($this->approve)($count)->assertOk();

    expect((float) $fabric->fresh()->quantity)->toBe(5.0)
        ->and(($this->sum)($count['id'], '52', 'debit'))->toBe(10.0);
});

test('a lot changed after submission without a movement still refuses approval', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 8]])->assertOk();
    ($this->submit)($count);
    $this->travel(1)->minutes();
    $fabric->refresh()->update(['quantity' => 7]);

    ($this->approve)($count)->assertStatus(422)->assertJsonPath('code', 'COUNT_LOT_MOVED');
});

test('a new lot of a counted item that arrived while counting refuses approval', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    $this->travel(1)->minutes();
    // Rolls received onto the same shelf, then the shelf is counted whole.
    $arrived = ($this->lot)($this->raw, 4, 5);

    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 14]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_LOT_MOVED')
        ->assertJsonPath('lot_ids', [$arrived->id]);
    expect((float) $fabric->fresh()->quantity)->toBe(10.0);
});

test('a sales return into a consumed lot of a counted item while counting refuses approval', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);
    $consumed = ($this->lot)($this->raw, 0, 5, 'consumed');

    $count = ($this->open)();
    expect(collect($count['lines'])->pluck('stock_lot_id')->all())->toBe([$fabric->id]);
    $this->travel(1)->minutes();

    // Three meters returned into the consumed lot they were sold from (as
    // SalesReturnService::restock does), shelved with the rest and counted whole.
    $consumed->refresh()->update(['quantity' => 3, 'status' => 'available']);
    InventoryMovement::create([
        'operating_unit_id' => $this->unit->id, 'stock_lot_id' => $consumed->id, 'to_warehouse_id' => $this->warehouse->id,
        'sku' => 'FAB-1', 'movement_type' => 'sale_return', 'quantity_delta' => 3, 'unit_cost' => 5, 'reason' => 'sales_return',
    ]);

    ($this->enter)($count, [['id' => ($this->lineFor)($count, $fabric->id)['id'], 'counted_qty' => 13]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_LOT_MOVED')
        ->assertJsonPath('lot_ids', [$consumed->id]);
    expect((float) $fabric->fresh()->quantity)->toBe(10.0)
        ->and(InventoryMovement::where('reference_document_type', 'StockCount')->count())->toBe(0)
        ->and(JournalEntry::where('source_document_type', 'StockCount')->count())->toBe(0);
});

test('a category count refuses found stock outside its category', function () {
    $parent = ItemCategory::create(['name' => 'Raw materials', 'code' => 'RAW']);
    $child = ItemCategory::create(['name' => 'Fabrics', 'code' => 'RAW-FAB', 'parent_id' => $parent->id]);
    $other = ItemCategory::create(['name' => 'Finished', 'code' => 'FIN']);

    $this->raw->update(['category_id' => $child->id]);
    $this->sofa->update(['category_id' => $other->id, 'cost_price' => 100]);
    $loose = InventoryItem::create(['name' => 'Thread', 'code' => 'THR-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter', 'cost_price' => 1]);

    $count = ($this->open)(['item_category_id' => $parent->id]);
    $found = fn (InventoryItem $item) => ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $item->id, 'counted_qty' => 2,
    ]);

    $found($this->sofa)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_FOUND_OUT_OF_SCOPE')
        ->assertJsonPath('inventory_item_id', $this->sofa->id);
    $found($loose)->assertStatus(422)->assertJsonPath('code', 'COUNT_FOUND_OUT_OF_SCOPE');

    // An item of a subcategory is in scope.
    $found($this->raw)->assertCreated();
    expect(StockCount::findOrFail($count['id'])->lines()->whereNull('stock_lot_id')->pluck('inventory_item_id')->all())
        ->toBe([$this->raw->id]);
});

test('open-batch blocks are listed, and found stock of their item is refused', function () {
    $this->foam->update(['cost_price' => 250]);
    $batch = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 601, 'bun_width_m' => 2.4, 'status' => 'graded']);
    $openBlock = StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $this->warehouse->id, 'production_batch_id' => $batch->id,
        'lot_number' => '601-01', 'sequence_in_batch' => 1, 'quantity' => 1, 'unit_cost' => 0, 'status' => 'available',
    ]);

    $count = ($this->open)();
    expect($count['lines'])->toBeEmpty()
        ->and(collect($count['excluded_lots'])->pluck('id')->all())->toBe([$openBlock->id])
        ->and($count['excluded_lots'][0]['reason'])->toBe('open_production_batch');

    $sheet = ($this->as)($this->counter)->getJson("/api/v1/stock-counts/{$count['id']}/sheet")->assertOk()->json();
    expect(collect($sheet['excluded_lots'])->pluck('id')->all())->toBe([$openBlock->id]);

    // The counter sees the block on the floor and adds it as found.
    $withFound = ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->foam->id, 'counted_qty' => 1,
    ])->assertCreated()->json();
    expect((float) collect($withFound['lines'])->firstWhere('is_found', true)['unit_cost'])->toBe(250.0);
    ($this->submit)($count);

    ($this->approve)($count)->assertStatus(422)
        ->assertJsonPath('code', 'COUNT_FOUND_UNCOUNTED_LOT')
        ->assertJsonPath('lot_ids', [$openBlock->id]);
    expect(StockLot::where('inventory_item_id', $this->foam->id)->count())->toBe(1)
        ->and(InventoryMovement::where('reference_document_type', 'StockCount')->count())->toBe(0);
});

test('found stock is never costed from an open-batch or zero-cost lot', function () {
    $batch = ProductionBatch::create(['operating_unit_id' => $this->unit->id, 'operation_number' => 602, 'bun_width_m' => 2.4, 'status' => 'graded']);
    StockLot::create([
        'inventory_item_id' => $this->foam->id, 'warehouse_id' => $this->warehouse->id, 'production_batch_id' => $batch->id,
        'lot_number' => '602-01', 'sequence_in_batch' => 1, 'quantity' => 0, 'unit_cost' => 0, 'status' => 'consumed',
    ]);
    $sofaZero = ($this->lot)($this->sofa, 0, 0, 'consumed');

    $count = ($this->open)();

    // Foam has no cost price and only an uncosted block: refused.
    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->foam->id, 'counted_qty' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'COUNT_FOUND_COST_UNKNOWN');

    // A zero cost price is no cost either.
    $this->sofa->update(['cost_price' => 0]);
    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->sofa->id, 'counted_qty' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'COUNT_FOUND_COST_UNKNOWN');

    // With a cost price, the zero-cost lot is skipped for it.
    $this->sofa->update(['cost_price' => 80]);
    $withFound = ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->sofa->id, 'counted_qty' => 1,
    ])->assertCreated()->json();
    expect((float) collect($withFound['lines'])->firstWhere('is_found', true)['unit_cost'])->toBe(80.0)
        ->and($sofaZero->fresh()->status)->toBe('consumed');
});

test('a later re-save of an unchanged quantity does not hide who counted it', function () {
    $fabric = ($this->lot)($this->raw, 10, 5);

    $count = ($this->open)();
    $lineId = ($this->lineFor)($count, $fabric->id)['id'];

    ($this->as)($this->owner)->putJson("/api/v1/stock-counts/{$count['id']}/lines", [
        'lines' => [['id' => $lineId, 'counted_qty' => 9]],
    ])->assertOk();
    // The counter's grid saves every row back, this one unchanged.
    ($this->enter)($count, [['id' => $lineId, 'counted_qty' => 9]])->assertOk();
    ($this->submit)($count);

    ($this->approve)($count, $this->owner)->assertStatus(422)->assertJsonPath('code', 'COUNT_SELF_APPROVAL');

    // Even when the counter then changes it, the owner stays a counter.
    ($this->as)($this->accountant)->postJson("/api/v1/stock-counts/{$count['id']}/cancel")->assertOk();
    $again = ($this->open)();
    $againLine = ($this->lineFor)($again, $fabric->id)['id'];
    ($this->as)($this->owner)->putJson("/api/v1/stock-counts/{$again['id']}/lines", [
        'lines' => [['id' => $againLine, 'counted_qty' => 9]],
    ])->assertOk();
    ($this->enter)($again, [['id' => $againLine, 'counted_qty' => 8]])->assertOk();
    ($this->submit)($again);

    ($this->approve)($again, $this->owner)->assertStatus(422)->assertJsonPath('code', 'COUNT_SELF_APPROVAL');
    ($this->approve)($again, $this->accountant)->assertOk();
});

test('a found lot number skips one already taken in another unit', function () {
    $blueprint = UnitBlueprint::first();
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Factory', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $otherWarehouse = Warehouse::create(['operating_unit_id' => $otherUnit->id, 'name' => 'Factory WH']);
    StockLot::create([
        'inventory_item_id' => $this->raw->id, 'warehouse_id' => $otherWarehouse->id,
        'lot_number' => 'LOT-FAB-1-20261020-01', 'quantity' => 1, 'unit_cost' => 7, 'status' => 'available',
    ]);

    $count = ($this->open)();
    ($this->as)($this->counter)->postJson("/api/v1/stock-counts/{$count['id']}/found", [
        'inventory_item_id' => $this->raw->id, 'counted_qty' => 2,
    ])->assertCreated();
    ($this->submit)($count);

    $approved = ($this->approve)($count)->assertOk()->json();
    $line = collect($approved['lines'])->firstWhere('is_found', true);

    expect($line['new_lot_number'])->toBe('LOT-FAB-1-20261020-02');
});
