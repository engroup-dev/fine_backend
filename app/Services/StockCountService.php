<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryEventType;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockCountStatus;
use App\Exceptions\InventoryAccountNotLinkedException;
use App\Exceptions\LedgerRuleException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\ItemStockLevel;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountLine;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyClock;
use App\Support\InventoryAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Physical stock counts (W4-01, owner decision #13).
 *
 * - A count snapshots the available and quarantined lots holding stock in one
 *   warehouse (optionally one category). Lots reserved by a cutter order carry
 *   their value in 1122, so they are left out and listed (`reserved_lots`).
 *   Lots of a production batch that is not closed yet carry no cost (their
 *   value is still in work in process), so they are left out and listed too
 *   (`excluded_lots`).
 * - System quantities are the lots' own quantities (seeded lots may have no
 *   intake movement to sum).
 * - The count is taken between opening and submitting. Stock that moves in
 *   that window makes the counted figure ambiguous (before or after the
 *   move?): a movement on a counted lot, a new lot of a counted item in the
 *   warehouse, or a movement on any other lot of a counted item there (goods
 *   returned into a consumed lot) refuses approval (COUNT_LOT_MOVED) and the
 *   count is redone.
 *   What moves after submission is applied on top: each difference is a delta
 *   on the lot's current quantity, which must equal the snapshot plus the
 *   movements since submission.
 * - In a category count, found stock must be an item of that category or one
 *   below it (COUNT_FOUND_OUT_OF_SCOPE).
 * - Found stock of an item that has a lot in the warehouse left out of the
 *   count (an open-batch block, a lot released since) may be that very lot,
 *   so approval refuses it (COUNT_FOUND_UNCOUNTED_LOT). Found stock is costed
 *   from the newest costed lot (never an open-batch or zero-cost lot), else a
 *   positive cost price, else refused.
 * - On approval each difference moves its lot through an `adjustment`
 *   movement (INV-06); found stock becomes a new lot. One journal entry dated
 *   the count date posts shortages DR the unit's waste account / CR the lot's
 *   account (INV-11) and surpluses DR the lot's account / CR 45, at the lots'
 *   cost at approval (written back onto the lines). The count date lies
 *   between the day the count was opened and today, so the entry never lands
 *   in a period before the snapshot.
 * - The approver holds `approve-stock-counts` and is not the person who
 *   opened the count, submitted it, or entered any of its quantities (read
 *   from the append-only `stock_count_entries`, so a later re-save by someone
 *   else cannot hide an earlier counter).
 * - Blind mode is fixed when the count is opened. It hides quantities on the
 *   count itself only; the stock lot and summary endpoints still show them.
 */
final class StockCountService
{
    /** Lot statuses a count snapshots. */
    private const COUNTABLE_STATUSES = ['available', 'quarantined'];

    /** Revenue leaf credited with approved surpluses (أرباح فروقات الجرد). */
    public const SURPLUS_ACCOUNT = '45';

    private const EPSILON = 0.00001;

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly PeriodGuard $periodGuard,
        private readonly StockLotService $stockLots,
    ) {}

    /**
     * Open a count and snapshot the warehouse's countable lots.
     *
     * @param  array{warehouse_id: string, item_category_id?: string|null, count_date?: string|null, blind?: bool|null, notes?: string|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function create(array $data, User $user): StockCount
    {
        $warehouse = Warehouse::with('operatingUnit.company')->findOrFail($data['warehouse_id']);
        $unit = $warehouse->operatingUnit;
        $today = CompanyClock::today($unit);
        $countDate = $data['count_date'] ?? $today;

        if ($countDate > $today) {
            throw new LedgerRuleException('A stock count cannot be dated in the future.', 'COUNT_DATE_IN_FUTURE', ['count_date' => $countDate]);
        }

        $this->ensureNotBeforeSnapshot($countDate, $today);

        return DB::transaction(function () use ($warehouse, $data, $user, $countDate): StockCount {
            Warehouse::withoutGlobalScopes()->whereKey($warehouse->id)->lockForUpdate()->first();

            // Two open counts of one warehouse would apply the same difference twice.
            $open = StockCount::withoutGlobalScopes()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('status', [StockCountStatus::Draft->value, StockCountStatus::Counting->value, StockCountStatus::Submitted->value])
                ->whereNull('deleted_at')
                ->first();

            if ($open !== null) {
                throw new LedgerRuleException(
                    "Count {$open->number} of {$warehouse->name} is still open; approve or cancel it first.",
                    'COUNT_ALREADY_OPEN',
                    ['stock_count_id' => $open->id, 'number' => $open->number],
                );
            }

            $count = StockCount::create([
                'operating_unit_id' => $warehouse->operating_unit_id,
                'warehouse_id' => $warehouse->id,
                'item_category_id' => $data['item_category_id'] ?? null,
                'number' => $this->documentNumbers->next(DocumentNumberService::STOCK_COUNT, (int) substr($countDate, 0, 4)),
                'count_date' => $countDate,
                'status' => StockCountStatus::Draft,
                'blind' => (bool) ($data['blind'] ?? false),
                'created_by_user_id' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $lots = $this->lotsQuery($warehouse->id, $count->item_category_id, self::COUNTABLE_STATUSES)
                ->where(fn (Builder $query) => $query->whereNull('production_batch_id')->orWhereNotIn('production_batch_id', $this->openBatchIds()))
                ->orderBy('inventory_item_id')
                ->orderBy('lot_number')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'stock_lot_id' => $lot->id,
                    'inventory_item_id' => $lot->inventory_item_id,
                    'system_qty' => round((float) $lot->quantity, 4),
                    'unit_cost' => round((float) $lot->unit_cost, 4),
                ]);
            }

            return $count;
        });
    }

    /**
     * Save counted quantities (bulk). A foam block lot holds one block, so its
     * count is 0 or 1 (INV-02).
     *
     * @param  array<int, array{id: string, counted_qty?: float|int|string|null, note?: string|null}>  $rows
     *
     * @throws LedgerRuleException
     */
    public function enter(StockCount $count, array $rows, User $user): StockCount
    {
        return DB::transaction(function () use ($count, $rows, $user): StockCount {
            $locked = $this->lockEditable($count);
            $lines = $locked->lines()->get()->keyBy('id');
            $types = $this->itemTypes($lines->pluck('inventory_item_id')->all());

            foreach ($rows as $row) {
                /** @var StockCountLine|null $line */
                $line = $lines->get((string) ($row['id'] ?? ''));

                if ($line === null) {
                    throw new LedgerRuleException('This line does not belong to the count.', 'COUNT_LINE_NOT_FOUND', ['line_id' => $row['id'] ?? null]);
                }

                if (array_key_exists('counted_qty', $row)) {
                    $counted = $row['counted_qty'] === null ? null : round((float) $row['counted_qty'], 4);

                    if ($counted !== null) {
                        $this->ensureCountable($counted, $types[$line->inventory_item_id] ?? null, $line->id);
                    }

                    $previous = $line->counted_qty === null ? null : round((float) $line->counted_qty, 4);

                    // A grid re-saving rows it did not change is no new count.
                    if ($previous !== $counted) {
                        $this->applyCounted($line, $counted);
                        $line->counted_by_user_id = $counted === null ? null : $user->id;
                        $this->recordEntry($line, $user, $counted);
                    }
                }

                if (array_key_exists('note', $row)) {
                    $line->note = $row['note'];
                }

                $line->save();
            }

            $this->startCounting($locked);

            return $locked;
        });
    }

    /**
     * Add found stock: an item counted in the warehouse with no lot on record.
     * Its cost is the newest lot cost of that item in this warehouse, else the
     * item's cost price; with neither it cannot be valued and is refused.
     * Lots of an open production batch and zero-cost lots (scrap) carry no
     * actual cost yet, and a zero cost price is no cost either: found stock
     * valued at 0 would enter stock with no entry and later cost nothing.
     * A category count takes found stock of that category (or one below it)
     * only; anything else was never part of what is being counted.
     *
     * @param  array{inventory_item_id: string, counted_qty: float|int|string, note?: string|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function addFound(StockCount $count, array $data, User $user): StockCountLine
    {
        return DB::transaction(function () use ($count, $data, $user): StockCountLine {
            $locked = $this->lockEditable($count);
            $item = InventoryItem::findOrFail($data['inventory_item_id']);
            $counted = round((float) $data['counted_qty'], 4);

            if ($counted <= 0) {
                throw new LedgerRuleException('Found stock needs a counted quantity greater than zero.', 'INVALID_QUANTITY');
            }

            if ($locked->item_category_id !== null
                && ! in_array($item->category_id, $this->categoryWithDescendants((string) $locked->item_category_id), true)) {
                throw new LedgerRuleException(
                    "{$item->name} is outside this count's category, so it cannot be added as found stock.",
                    'COUNT_FOUND_OUT_OF_SCOPE',
                    ['inventory_item_id' => $item->id, 'item_category_id' => $locked->item_category_id],
                );
            }

            $this->ensureCountable($counted, $item->item_type, null);

            $unitCost = StockLot::withoutGlobalScopes()
                ->where('warehouse_id', $locked->warehouse_id)
                ->where('inventory_item_id', $item->id)
                ->whereNull('deleted_at')
                ->where('unit_cost', '>', 0)
                ->where(fn (Builder $query) => $query->whereNull('production_batch_id')->orWhereNotIn('production_batch_id', $this->openBatchIds()))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('unit_cost');

            if ($unitCost === null && $item->cost_price !== null && (float) $item->cost_price > 0) {
                $unitCost = $item->cost_price;
            }

            if ($unitCost === null) {
                throw new LedgerRuleException(
                    "{$item->name} has no costed lot in this warehouse and no cost price, so found stock of it cannot be valued.",
                    'COUNT_FOUND_COST_UNKNOWN',
                    ['inventory_item_id' => $item->id],
                );
            }

            $line = new StockCountLine([
                'stock_count_id' => $locked->id,
                'stock_lot_id' => null,
                'inventory_item_id' => $item->id,
                'system_qty' => 0,
                'unit_cost' => round((float) $unitCost, 4),
                'counted_by_user_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);
            $this->applyCounted($line, $counted);
            $line->save();
            $this->recordEntry($line, $user, $counted);

            $this->startCounting($locked);

            return $line->load('inventoryItem:id,name,code,item_type,unit_of_measure');
        });
    }

    /**
     * Hand the count over for approval; every line must be counted.
     *
     * @throws LedgerRuleException
     */
    public function submit(StockCount $count, User $user): StockCount
    {
        return DB::transaction(function () use ($count, $user): StockCount {
            $locked = $this->lock($count);
            $this->ensureTransition($locked, StockCountStatus::Submitted);

            $uncounted = $locked->lines()->whereNull('counted_qty')->count();

            if ($uncounted > 0) {
                throw new LedgerRuleException(
                    "{$uncounted} line(s) of the count have no counted quantity yet.",
                    'COUNT_INCOMPLETE',
                    ['uncounted_lines' => $uncounted],
                );
            }

            $locked->status = StockCountStatus::Submitted;
            $locked->submitted_by_user_id = $user->id;
            $locked->submitted_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Approve: adjust the lots, create found lots and post one entry.
     *
     * @throws LedgerRuleException|InventoryAccountNotLinkedException
     */
    public function approve(StockCount $count, User $user): StockCount
    {
        $enteredByApprover = StockCountEntry::query()
            ->where('stock_count_id', $count->id)
            ->where('user_id', $user->id)
            ->exists()
            || StockCountLine::query()
                ->where('stock_count_id', $count->id)
                ->where('counted_by_user_id', $user->id)
                ->exists();

        if ($user->id === $count->created_by_user_id || $user->id === $count->submitted_by_user_id || $enteredByApprover) {
            throw new LedgerRuleException(
                'A stock count must be approved by someone other than the person who opened, counted or submitted it.',
                'COUNT_SELF_APPROVAL',
            );
        }

        $unit = OperatingUnit::withoutGlobalScopes()->with('company')->findOrFail($count->operating_unit_id);
        $countDate = $count->count_date->toDateString();

        // ACC-11: refused up front, even when the count moves no value.
        $this->periodGuard->ensurePostable($countDate, $unit->company_id);

        return DB::transaction(function () use ($count, $user, $unit, $countDate): StockCount {
            $locked = $this->lock($count);
            $this->ensureTransition($locked, StockCountStatus::Approved);

            /** @var array<string, float> $shortage */
            $shortage = [];
            /** @var array<string, float> $surplus */
            $surplus = [];
            $firstItem = null;

            $lines = $locked->lines()->with('inventoryItem')->orderBy('created_at')->orderBy('id')->get();

            $this->ensureNothingMovedSinceSnapshot($locked, $lines);

            foreach ($lines as $line) {
                $variance = round((float) $line->variance_qty, 4);

                if (abs($variance) < self::EPSILON) {
                    continue;
                }

                $lot = $line->isFound()
                    ? $this->createFoundLot($locked, $line)
                    : $this->adjustLot($locked, $line, $variance);

                // Costing is actuals: the line records the cost the entry posts.
                $line->unit_cost = round((float) $lot->unit_cost, 4);
                $line->variance_value = round($variance * (float) $lot->unit_cost, 4);
                $line->save();

                InventoryMovement::create([
                    'operating_unit_id' => $locked->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'from_warehouse_id' => $variance < 0 ? $lot->warehouse_id : null,
                    'to_warehouse_id' => $variance > 0 ? $lot->warehouse_id : null,
                    'sku' => $line->inventoryItem?->code ?? 'ITEM',
                    'movement_type' => 'adjustment',
                    'quantity_delta' => $variance,
                    'unit_cost' => (float) $lot->unit_cost,
                    'reason' => 'stock_count',
                    'reference_document_type' => 'StockCount',
                    'reference_id' => $locked->id,
                ]);

                $value = round(abs($variance) * (float) $lot->unit_cost, 4);

                if ($value <= 0) {
                    continue;
                }

                $firstItem ??= $line->inventoryItem;
                $account = InventoryAccounts::forLot($lot); // INV-11

                if ($variance < 0) {
                    $shortage[$account] = round(($shortage[$account] ?? 0) + $value, 4);
                } else {
                    $surplus[$account] = round(($surplus[$account] ?? 0) + $value, 4);
                }
            }

            $entry = $shortage === [] && $surplus === []
                ? null
                : $this->postJournal($locked, $unit, $shortage, $surplus, $firstItem, $user, $countDate);

            $locked->status = StockCountStatus::Approved;
            $locked->approved_by_user_id = $user->id;
            $locked->approved_at = now();
            $locked->journal_entry_id = $entry?->id;
            $locked->save();

            return $locked;
        });
    }

    /**
     * @throws LedgerRuleException
     */
    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $locked = $this->lock($count);
            $this->ensureTransition($locked, StockCountStatus::Cancelled);

            $locked->status = StockCountStatus::Cancelled;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Header fields that may change while the count is still being entered.
     *
     * Blind mode is fixed at opening, or a counter could switch it off mid-count.
     *
     * @param  array{count_date?: string, notes?: string|null, record_version?: int}  $data
     *
     * @throws LedgerRuleException
     */
    public function update(StockCount $count, array $data): StockCount
    {
        if (isset($data['count_date'])) {
            $unit = OperatingUnit::withoutGlobalScopes()->with('company')->findOrFail($count->operating_unit_id);

            if ($data['count_date'] > CompanyClock::today($unit)) {
                throw new LedgerRuleException('A stock count cannot be dated in the future.', 'COUNT_DATE_IN_FUTURE', ['count_date' => $data['count_date']]);
            }

            $this->ensureNotBeforeSnapshot(
                $data['count_date'],
                $count->created_at->toImmutable()->setTimezone(CompanyClock::timezone($unit))->toDateString(),
            );
        }

        return DB::transaction(function () use ($count, $data): StockCount {
            $locked = $this->lockEditable($count);
            $locked->fill(array_intersect_key($data, array_flip(['count_date', 'notes', 'record_version'])));
            $locked->save();

            return $locked;
        });
    }

    /**
     * Only a draft can be deleted; anything further is cancelled instead.
     *
     * @throws LedgerRuleException
     */
    public function delete(StockCount $count): void
    {
        DB::transaction(function () use ($count): void {
            $locked = $this->lock($count);

            if ($locked->status !== StockCountStatus::Draft) {
                throw new LedgerRuleException('Only a draft count can be deleted; cancel it instead.', 'COUNT_STATUS_INVALID', ['status' => $locked->status->value]);
            }

            $locked->delete();
        });
    }

    /**
     * Lots in the warehouse reserved by a cutter order (or a sale): their value
     * sits in a work-in-progress account, so the count leaves them out.
     *
     * @return Collection<int, StockLot>
     */
    public function reservedLots(StockCount $count): Collection
    {
        return $this->lotsQuery($count->warehouse_id, $count->item_category_id, ['reserved'])
            ->with('inventoryItem:id,name,code,item_type,unit_of_measure')
            ->orderBy('lot_number')
            ->get(['id', 'inventory_item_id', 'warehouse_id', 'lot_number', 'quantity', 'unit_cost', 'status']);
    }

    /**
     * Lots in the warehouse that belong to a production batch not closed yet:
     * their cost is still in work in process, so the count leaves them out.
     * Listed so the counters see why a block on the floor is not on the sheet.
     *
     * @return list<array<string, mixed>>
     */
    public function excludedLots(StockCount $count): array
    {
        return $this->lotsQuery($count->warehouse_id, $count->item_category_id, self::COUNTABLE_STATUSES)
            ->whereIn('production_batch_id', $this->openBatchIds())
            ->with('inventoryItem:id,name,code,item_type,unit_of_measure')
            ->orderBy('lot_number')
            ->get(['id', 'inventory_item_id', 'warehouse_id', 'production_batch_id', 'lot_number', 'quantity', 'unit_cost', 'status'])
            ->map(fn (StockLot $lot): array => [...$lot->toArray(), 'reason' => 'open_production_batch'])
            ->all();
    }

    /**
     * The count as the API returns it. In a blind count the counters do not see
     * the system quantities (nor the differences) until it is submitted.
     *
     * @return array<string, mixed>
     */
    public function present(StockCount $count): array
    {
        $count->loadMissing([
            'warehouse:id,name,operating_unit_id',
            'category:id,name,code',
            'createdBy:id,name',
            'submittedBy:id,name',
            'approvedBy:id,name',
            'journalEntry:id,reference,entry_date',
            'lines.inventoryItem:id,name,code,item_type,unit_of_measure',
            'lines.stockLot:id,lot_number,bin_location,status,grade',
            'lines.newStockLot:id,lot_number',
        ]);

        $hidden = $this->hidesSystemQuantities($count);

        $lines = $count->lines
            ->sortBy(fn (StockCountLine $line): string => ($line->isFound() ? '1' : '0').($line->inventoryItem?->code ?? '').($line->stockLot?->lot_number ?? '').$line->created_at)
            ->values()
            ->map(fn (StockCountLine $line): array => $this->presentLine($line, $hidden))
            ->all();

        $summary = [
            'lines_total' => count($lines),
            'lines_counted' => $count->lines->whereNotNull('counted_qty')->count(),
            'found_lines' => $count->lines->filter(fn (StockCountLine $line): bool => $line->isFound())->count(),
        ];

        if (! $hidden) {
            $shortages = $count->lines->filter(fn (StockCountLine $line): bool => (float) $line->variance_qty < 0);
            $surpluses = $count->lines->filter(fn (StockCountLine $line): bool => (float) $line->variance_qty > 0);

            $summary += [
                'shortage_qty' => round((float) -$shortages->sum(fn ($l) => (float) $l->variance_qty), 4),
                'shortage_value' => round((float) -$shortages->sum(fn ($l) => (float) $l->variance_value), 4),
                'surplus_qty' => round((float) $surpluses->sum(fn ($l) => (float) $l->variance_qty), 4),
                'surplus_value' => round((float) $surpluses->sum(fn ($l) => (float) $l->variance_value), 4),
                'net_value' => round((float) $count->lines->sum(fn ($l) => (float) $l->variance_value), 4),
            ];
        }

        return [
            ...$count->withoutRelations()->toArray(),
            'warehouse' => $count->warehouse,
            'category' => $count->category,
            'created_by' => $count->createdBy,
            'submitted_by' => $count->submittedBy,
            'approved_by' => $count->approvedBy,
            'journal_entry' => $count->journalEntry,
            'system_quantities_hidden' => $hidden,
            'summary' => $summary,
            'lines' => $lines,
            'reserved_lots' => $this->reservedLots($count),
            'excluded_lots' => $this->excludedLots($count),
        ];
    }

    /**
     * Printable count sheet: the header and one row per line in counting order.
     *
     * @return array<string, mixed>
     */
    public function sheet(StockCount $count): array
    {
        $presented = $this->present($count);
        $unit = OperatingUnit::withoutGlobalScopes()->find($count->operating_unit_id, ['id', 'name']);

        return [
            'number' => $count->number,
            'count_date' => $count->count_date->toDateString(),
            'status' => $count->status->value,
            'blind' => $count->blind,
            'system_quantities_hidden' => $presented['system_quantities_hidden'],
            'operating_unit' => $unit,
            'warehouse' => $presented['warehouse'],
            'category' => $presented['category'],
            'notes' => $count->notes,
            'created_by' => $presented['created_by'],
            'printed_at' => CompanyClock::now($unit)->toDateTimeString(),
            'rows' => array_map(fn (array $line, int $index): array => [
                'no' => $index + 1,
                'line_id' => $line['id'],
                'item_code' => $line['item_code'],
                'item_name' => $line['item_name'],
                'unit_of_measure' => $line['unit_of_measure'],
                'lot_number' => $line['lot_number'],
                'bin_location' => $line['bin_location'],
                'is_found' => $line['is_found'],
                'system_qty' => $line['system_qty'],
                'counted_qty' => $line['counted_qty'],
                'variance_qty' => $line['variance_qty'],
                'note' => $line['note'],
            ], $presented['lines'], array_keys($presented['lines'])),
            'summary' => $presented['summary'],
            'reserved_lots' => $presented['reserved_lots'],
            'excluded_lots' => $presented['excluded_lots'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLine(StockCountLine $line, bool $hidden): array
    {
        return [
            'id' => $line->id,
            'stock_count_id' => $line->stock_count_id,
            'stock_lot_id' => $line->stock_lot_id,
            'inventory_item_id' => $line->inventory_item_id,
            'item_code' => $line->inventoryItem?->code,
            'item_name' => $line->inventoryItem?->name,
            'item_type' => $line->inventoryItem?->item_type,
            'unit_of_measure' => $line->inventoryItem?->unit_of_measure,
            'lot_number' => $line->stockLot?->lot_number,
            'bin_location' => $line->stockLot?->bin_location,
            'lot_status' => $line->stockLot?->status,
            'grade' => $line->stockLot?->grade,
            'is_found' => $line->isFound(),
            'system_qty' => $hidden ? null : $line->system_qty,
            'counted_qty' => $line->counted_qty,
            'unit_cost' => $hidden ? null : $line->unit_cost,
            'variance_qty' => $hidden ? null : $line->variance_qty,
            'variance_value' => $hidden ? null : $line->variance_value,
            'new_stock_lot_id' => $line->new_stock_lot_id,
            'new_lot_number' => $line->newStockLot?->lot_number,
            'note' => $line->note,
        ];
    }

    private function hidesSystemQuantities(StockCount $count): bool
    {
        return $count->blind && $count->status->isEditable();
    }

    /**
     * Lots of a warehouse with stock, in the given statuses, optionally one
     * category and its subcategories. Items are matched by id so a soft-deleted
     * item's lots count too.
     *
     * @param  list<string>  $statuses
     * @return Builder<StockLot>
     */
    private function lotsQuery(string $warehouseId, ?string $categoryId, array $statuses): Builder
    {
        return StockLot::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', $statuses)
            ->where('quantity', '>', 0)
            ->when($categoryId !== null, fn (Builder $query) => $query->whereIn(
                'inventory_item_id',
                InventoryItem::withTrashed()->whereIn('category_id', $this->categoryWithDescendants((string) $categoryId))->select('id'),
            ));
    }

    /**
     * The category and every category below it (categories nest).
     *
     * @return list<string>
     */
    private function categoryWithDescendants(string $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            $frontier = ItemCategory::withTrashed()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * The count was taken between opening (the snapshot) and submitting. Stock
     * that moved in that window leaves the counted figure ambiguous, so the
     * count is refused and redone. What moved after submission is fine: the
     * difference is applied as a delta on the lot's current quantity, which
     * must be the snapshot plus those later movements (anything else changed
     * the lot without a movement, and is refused too).
     *
     * Other lots of a counted or found item in the warehouse count as well: a
     * lot that arrived while counting, or an older lot the count does not hold
     * that moved while counting (goods returned into a consumed lot), may sit
     * inside a counted figure.
     *
     * Found stock of an item that still has a lot in the warehouse which the
     * count left out (an open-batch block, a lot released since) may be that
     * very lot counted again, so it is refused.
     *
     * @param  Collection<int, StockCountLine>  $lines
     *
     * @throws LedgerRuleException
     */
    private function ensureNothingMovedSinceSnapshot(StockCount $count, Collection $lines): void
    {
        $since = $count->created_at;
        $until = $count->submitted_at ?? now();
        $countedLines = $lines->reject(fn (StockCountLine $line): bool => $line->isFound());
        $lotIds = $countedLines->pluck('stock_lot_id')->filter()->values()->all();

        $lots = StockLot::withoutGlobalScopes()->whereKey($lotIds)->lockForUpdate()->get()->keyBy('id');

        $movedWhileCounting = InventoryMovement::withoutGlobalScopes()
            ->whereIn('stock_lot_id', $lotIds)
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $until)
            ->distinct()
            ->pluck('stock_lot_id')
            ->all();

        /** @var array<string, float> $netAfterSubmit */
        $netAfterSubmit = InventoryMovement::withoutGlobalScopes()
            ->whereIn('stock_lot_id', $lotIds)
            ->where('created_at', '>', $until)
            ->groupBy('stock_lot_id')
            ->selectRaw('stock_lot_id, SUM(quantity_delta) as net')
            ->pluck('net', 'stock_lot_id')
            ->map(fn ($net): float => (float) $net)
            ->all();

        $moved = [];

        foreach ($countedLines as $line) {
            $lot = $lots->get($line->stock_lot_id);

            if ($lot === null) {
                continue; // adjustLot reports a lot that is gone (COUNT_LOT_MISSING).
            }

            $expected = (float) $line->system_qty + ($netAfterSubmit[$lot->id] ?? 0.0);
            $drifted = abs((float) $lot->quantity - $expected) > self::EPSILON;

            if ($drifted || in_array($lot->id, $movedWhileCounting, true)) {
                $moved[$lot->id] = $lot->lot_number;
            }
        }

        // A lot of a counted or found item that arrived while counting may
        // already sit inside a counted figure (a tank dipped after an intake,
        // rolls on one shelf), or be the found stock itself.
        $itemIds = $lines->pluck('inventory_item_id')->unique()->values()->all();

        if ($itemIds !== []) {
            StockLot::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('warehouse_id', $count->warehouse_id)
                ->whereIn('inventory_item_id', $itemIds)
                ->whereNotIn('id', $lotIds)
                ->where('created_at', '>', $since)
                ->where('created_at', '<=', $until)
                ->get(['id', 'lot_number'])
                ->each(function (StockLot $lot) use (&$moved): void {
                    $moved[$lot->id] = $lot->lot_number;
                });

            // An older lot of such an item that the count does not hold and
            // that moved while counting: goods returned into a consumed lot
            // (available again), stock put back into a lot left out. Its units
            // may sit inside a counted figure, a surplus that is not there.
            StockLot::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('warehouse_id', $count->warehouse_id)
                ->whereIn('inventory_item_id', $itemIds)
                ->whereNotIn('id', $lotIds)
                ->whereIn('id', InventoryMovement::withoutGlobalScopes()
                    ->where('created_at', '>', $since)
                    ->where('created_at', '<=', $until)
                    ->select('stock_lot_id'))
                ->get(['id', 'lot_number'])
                ->each(function (StockLot $lot) use (&$moved): void {
                    $moved[$lot->id] = $lot->lot_number;
                });
        }

        if ($moved !== []) {
            throw new LedgerRuleException(
                'Stock moved while the count was being taken (lots '.implode(', ', $moved).'), so its differences no longer hold. Cancel the count and count again.',
                'COUNT_LOT_MOVED',
                ['lot_ids' => array_keys($moved), 'lot_numbers' => array_values($moved)],
            );
        }

        $foundItemIds = $lines->filter(fn (StockCountLine $line): bool => $line->isFound())->pluck('inventory_item_id')->unique()->values()->all();

        if ($foundItemIds === []) {
            return;
        }

        // Older lots the snapshot did not take that hold stock now: an
        // open-batch block, a lot reserved at the snapshot and released since.
        // Lots still reserved are listed on the count and stay out of it.
        $uncounted = StockLot::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('warehouse_id', $count->warehouse_id)
            ->whereIn('inventory_item_id', $foundItemIds)
            ->whereNotIn('id', $lotIds)
            ->where('created_at', '<=', $since)
            ->where('quantity', '>', 0)
            ->where('status', '!=', 'reserved')
            ->orderBy('lot_number')
            ->get(['id', 'lot_number']);

        if ($uncounted->isNotEmpty()) {
            throw new LedgerRuleException(
                'Found stock may be lot(s) '.$uncounted->implode('lot_number', ', ').' that the count left out (an open production batch, or released since). Close the batch or cancel the count and count again.',
                'COUNT_FOUND_UNCOUNTED_LOT',
                ['lot_ids' => $uncounted->pluck('id')->all(), 'lot_numbers' => $uncounted->pluck('lot_number')->all()],
            );
        }
    }

    /**
     * Production batches not closed yet: their lots are not costed.
     */
    private function openBatchIds(): QueryBuilder
    {
        return DB::table('production_batches')->where('status', '!=', ProductionBatchStatus::Closed->value)->select('id');
    }

    /**
     * Counted on the day the count opened or later: the snapshot holds that
     * day's quantities, so an earlier date would post them to a period whose
     * stock they do not describe.
     *
     * @throws LedgerRuleException
     */
    private function ensureNotBeforeSnapshot(string $countDate, string $openedOn): void
    {
        if ($countDate < $openedOn) {
            throw new LedgerRuleException(
                "A stock count opened on {$openedOn} cannot be dated earlier; its snapshot holds that day's quantities.",
                'COUNT_DATE_BEFORE_SNAPSHOT',
                ['count_date' => $countDate, 'opened_on' => $openedOn],
            );
        }
    }

    private function recordEntry(StockCountLine $line, User $user, ?float $counted): void
    {
        StockCountEntry::create([
            'stock_count_id' => $line->stock_count_id,
            'stock_count_line_id' => $line->id,
            'user_id' => $user->id,
            'counted_qty' => $counted,
        ]);
    }

    /**
     * @param  array<int, string>  $itemIds
     * @return array<string, string|null>
     */
    private function itemTypes(array $itemIds): array
    {
        return InventoryItem::withTrashed()->whereKey(array_unique($itemIds))->pluck('item_type', 'id')->all();
    }

    /**
     * INV-02: a foam block lot holds one block — a count of it is 0 or 1.
     *
     * @throws LedgerRuleException
     */
    private function ensureCountable(float $counted, ?string $itemType, ?string $lineId): void
    {
        if ($counted < 0) {
            throw new LedgerRuleException('A counted quantity cannot be negative.', 'INVALID_QUANTITY', ['line_id' => $lineId]);
        }

        if ($itemType === 'foam_block' && $counted !== 0.0 && $counted !== 1.0) {
            throw new LedgerRuleException(
                "A foam block lot holds one block, so it counts 0 or 1; got {$counted}. Add each found block as its own line.",
                'COUNT_FOAM_BLOCK_QUANTITY',
                ['line_id' => $lineId],
            );
        }
    }

    private function applyCounted(StockCountLine $line, ?float $counted): void
    {
        $line->counted_qty = $counted;

        if ($counted === null) {
            $line->variance_qty = null;
            $line->variance_value = null;

            return;
        }

        $variance = round($counted - (float) $line->system_qty, 4);
        $line->variance_qty = $variance;
        $line->variance_value = round($variance * (float) $line->unit_cost, 4);
    }

    /**
     * A shortage or surplus on a counted lot (unmoved since the snapshot, see
     * ensureNothingMovedSinceSnapshot). A lot that reaches 0 is consumed; one
     * coming back from 0 is available again.
     *
     * @throws LedgerRuleException
     */
    private function adjustLot(StockCount $count, StockCountLine $line, float $variance): StockLot
    {
        $lot = StockLot::withoutGlobalScopes()->with('inventoryItem')->whereKey($line->stock_lot_id)->lockForUpdate()->first();

        if ($lot === null || $lot->trashed() || $lot->warehouse_id !== $count->warehouse_id) {
            throw new LedgerRuleException('A counted lot is no longer in this warehouse.', 'COUNT_LOT_MISSING', ['line_id' => $line->id]);
        }

        if ($lot->status === 'reserved') {
            throw new LedgerRuleException(
                "Lot {$lot->lot_number} was reserved after the count began; release it or cancel the count.",
                'COUNT_LOT_RESERVED',
                ['line_id' => $line->id, 'lot_number' => $lot->lot_number],
            );
        }

        $newQty = round((float) $lot->quantity + $variance, 4);

        if ($newQty < -self::EPSILON) {
            throw new LedgerRuleException(
                "Lot {$lot->lot_number} now holds {$lot->quantity}; a shortage of ".abs($variance).' cannot be taken from it. Stock moved since the count began.',
                'COUNT_LOT_MOVED',
                ['line_id' => $line->id, 'lot_number' => $lot->lot_number, 'current_qty' => (float) $lot->quantity],
            );
        }

        $newQty = max(0.0, $newQty);
        $lot->quantity = $newQty;

        if ($newQty <= self::EPSILON) {
            $lot->quantity = 0;
            $lot->status = 'consumed';
        } elseif ($lot->status === 'consumed') {
            $lot->status = 'available';
        }

        $lot->save();

        return $lot;
    }

    /**
     * Found stock becomes its own lot at the line's cost.
     */
    private function createFoundLot(StockCount $count, StockCountLine $line): StockLot
    {
        $item = InventoryItem::withTrashed()->findOrFail($line->inventory_item_id);

        $lot = StockLot::create([
            'inventory_item_id' => $item->id,
            'warehouse_id' => $count->warehouse_id,
            'lot_number' => $this->stockLots->generateUniqueLotNumber($item, 'stock_count'),
            'quantity' => round((float) $line->counted_qty, 4),
            'unit_cost' => round((float) $line->unit_cost, 4),
            'status' => 'available',
            // W4-02: found stock lands on the item's bin in that warehouse.
            'bin_location' => ItemStockLevel::binFor($item->id, $count->warehouse_id),
        ]);
        $lot->setRelation('inventoryItem', $item);

        $line->new_stock_lot_id = $lot->id;
        $line->save();

        return $lot;
    }

    /**
     * One balanced entry for the whole count, dated the count date.
     *
     * @param  array<string, float>  $shortage  value per lot account
     * @param  array<string, float>  $surplus  value per lot account
     *
     * @throws InventoryAccountNotLinkedException
     */
    private function postJournal(
        StockCount $count,
        OperatingUnit $unit,
        array $shortage,
        array $surplus,
        ?InventoryItem $item,
        User $user,
        string $countDate,
    ): JournalEntry {
        $unitId = $count->operating_unit_id;
        $lines = [];

        $shortageTotal = round(array_sum($shortage), 4);

        if ($shortageTotal > 0) {
            $waste = $unit->accountFor(InventoryEventType::Waste)
                ?? throw new InventoryAccountNotLinkedException($item ?? new InventoryItem(['name' => '-', 'code' => '-']), $unit, InventoryEventType::Waste);

            $lines[] = ['account_code' => $waste->account_code, 'debit' => $shortageTotal, 'operating_unit_id' => $unitId, 'memo' => 'Stock count shortage'];

            foreach ($shortage as $account => $value) {
                $lines[] = ['account_code' => (string) $account, 'credit' => $value, 'operating_unit_id' => $unitId];
            }
        }

        $surplusTotal = round(array_sum($surplus), 4);

        if ($surplusTotal > 0) {
            foreach ($surplus as $account => $value) {
                $lines[] = ['account_code' => (string) $account, 'debit' => $value, 'operating_unit_id' => $unitId];
            }

            $lines[] = ['account_code' => self::SURPLUS_ACCOUNT, 'credit' => $surplusTotal, 'operating_unit_id' => $unitId, 'memo' => 'Stock count surplus'];
        }

        return $this->accountingService->postJournal(
            "Stock count {$count->number}",
            $lines,
            'StockCount',
            $count->id,
            $unit->company_id,
            userId: $user->id,
            entryDate: $countDate,
        );
    }

    private function lock(StockCount $count): StockCount
    {
        return StockCount::withoutGlobalScopes()->whereKey($count->getKey())->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws LedgerRuleException
     */
    private function lockEditable(StockCount $count): StockCount
    {
        $locked = $this->lock($count);

        if (! $locked->status->isEditable()) {
            throw new LedgerRuleException(
                "Count {$locked->number} is {$locked->status->value} and can no longer be changed.",
                'COUNT_NOT_EDITABLE',
                ['status' => $locked->status->value],
            );
        }

        return $locked;
    }

    private function startCounting(StockCount $count): void
    {
        if ($count->status === StockCountStatus::Draft) {
            $count->status = StockCountStatus::Counting;
            $count->save();
        }
    }

    /**
     * @throws LedgerRuleException
     */
    private function ensureTransition(StockCount $count, StockCountStatus $target): void
    {
        if (! $count->status->canTransitionTo($target)) {
            throw new LedgerRuleException(
                "Count {$count->number} is {$count->status->value} and cannot become {$target->value}.",
                'COUNT_STATUS_INVALID',
                ['status' => $count->status->value],
            );
        }
    }
}
