<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionBatchStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\LedgerRuleException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemStockLevel;
use App\Models\JournalEntry;
use App\Models\MaterialRequest;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMaterial;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\StockLot;
use App\Support\InventoryAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionBatchService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly MaterialResolutionService $materialResolution,
        private readonly StockLotService $stockLotService,
    ) {}

    /** Journal source of an issued material row (one entry per row, reversed on removal). */
    public const MATERIAL_SOURCE_TYPE = 'ProductionBatchMaterial';

    /**
     * W4-05 (decision #11): issue chemicals to a batch that is not closed.
     *
     * Each line draws FIFO (oldest lot first) across the item's available lots
     * in the batch unit's warehouses, through StockLotService::drawFromLot as a
     * `consumption` movement, so open barrels and recovered empties are handled
     * there. Every lot drawn becomes one row at that lot's actual cost, and its
     * value moves into work in process:
     *
     *   DR 1121 WIP — Foam Production / CR the lot's account (INV-11: 111)
     *
     * Sufficiency is checked for the whole request before anything is drawn,
     * so a short chemical refuses the issue rather than leaving it half done.
     * The batch's material_cost becomes the sum of its issued rows.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: float|int|string}>  $lines
     */
    public function issueMaterials(ProductionBatch $batch, array $lines, ?string $userId = null): ProductionBatch
    {
        if ($lines === []) {
            throw new LedgerRuleException('An issue needs at least one material line.', 'INVALID_QUANTITY');
        }

        return DB::transaction(function () use ($batch, $lines, $userId): ProductionBatch {
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $this->guardOpenForMaterials($locked);

            $required = [];
            foreach ($lines as $line) {
                $quantity = round((float) $line['quantity'], 4);

                if ($quantity <= 0) {
                    throw new LedgerRuleException('An issued quantity must be at least 0.0001.', 'INVALID_QUANTITY');
                }

                $required[$line['inventory_item_id']] = round(($required[$line['inventory_item_id']] ?? 0) + $quantity, 4);
            }

            $this->guardRawMaterials(array_map('strval', array_keys($required)));

            /** @var array<string, Collection<int, StockLot>> $lotsByItem */
            $lotsByItem = [];
            $shortfalls = [];

            foreach ($required as $itemId => $quantity) {
                $lotsByItem[$itemId] = $this->availableLotsFor((string) $itemId, $locked->operating_unit_id);
                $available = round((float) $lotsByItem[$itemId]->sum('quantity'), 4);

                if ($available < $quantity) {
                    $item = InventoryItem::withTrashed()->find($itemId);
                    $shortfalls[] = [
                        'inventory_item_id' => $itemId,
                        'code' => $item?->code,
                        'required' => $quantity,
                        'available' => $available,
                    ];
                }
            }

            if ($shortfalls !== []) {
                $names = implode('، ', array_map(
                    fn (array $s): string => "{$s['code']}: need {$s['required']}, have {$s['available']}",
                    $shortfalls,
                ));

                throw new LedgerRuleException(
                    "Stock cannot cover the issue to operation {$locked->operation_number} — {$names}.",
                    'INSUFFICIENT_STOCK',
                    ['shortfalls' => $shortfalls],
                );
            }

            /** @var array<string, float> $remainingByLot what this request has not yet taken from each lot */
            $remainingByLot = [];
            foreach ($lotsByItem as $lots) {
                foreach ($lots as $lot) {
                    $remainingByLot[$lot->id] = (float) $lot->quantity;
                }
            }

            foreach ($lines as $line) {
                $itemId = $line['inventory_item_id'];
                $remaining = round((float) $line['quantity'], 4);

                foreach ($lotsByItem[$itemId] as $lot) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = round(min($remainingByLot[$lot->id], $remaining), 4);
                    if ($take <= 0) {
                        continue;
                    }

                    $remainingByLot[$lot->id] = round($remainingByLot[$lot->id] - $take, 4);
                    $remaining = round($remaining - $take, 4);

                    $this->issueFromLot($locked, $lot, $take, $userId);
                }
            }

            $this->syncMaterialCost($locked);

            return $locked->fresh();
        });
    }

    /**
     * W4-05: remove an issued row from a batch that is not closed. The
     * quantity goes back to its lot (with the empty containers it had freed,
     * if any), and the row's journal entry is reversed (ACC-13).
     */
    public function removeMaterial(ProductionBatch $batch, string $materialId, ?string $userId = null): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $materialId, $userId): ProductionBatch {
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $this->guardOpenForMaterials($locked);

            $material = ProductionBatchMaterial::where('production_batch_id', $locked->id)
                ->whereKey($materialId)
                ->lockForUpdate()
                ->firstOrFail();

            $lot = StockLot::withoutGlobalScopes()->whereKey($material->stock_lot_id)->lockForUpdate()->firstOrFail();
            $item = InventoryItem::withTrashed()->find($lot->inventory_item_id);
            $quantity = (float) $material->quantity;
            $quantityAfter = round((float) $lot->quantity + $quantity, 4);
            $unitId = $locked->operating_unit_id;

            // An open barrel refilled by the return occupies a container again,
            // so the empty it had freed comes back off the empties stock.
            $containersBefore = (int) ceil((float) $lot->container_quantity);
            $containersAfter = $containersBefore;
            if ($item !== null && $item->tracksContainers()) {
                $containersAfter = max($containersBefore, (int) ceil($quantityAfter / (float) $item->container_capacity));
            }
            $refilled = $containersAfter - $containersBefore;

            if ($refilled > 0 && $item?->empty_container_item_id !== null) {
                $this->takeBackEmptyContainers($locked, $lot, $item, $refilled);
            }

            $lot->quantity = $quantityAfter;
            $lot->container_quantity = $containersAfter;
            if ($lot->status === 'consumed') {
                $lot->status = 'available';
            }
            $lot->save();

            InventoryMovement::create([
                'operating_unit_id' => $unitId,
                'stock_lot_id' => $lot->id,
                'to_warehouse_id' => $lot->warehouse_id,
                'sku' => $item?->code ?? 'ITEM',
                'movement_type' => 'consumption',
                'quantity_delta' => $quantity,
                'unit_cost' => (float) $material->unit_cost,
                'reason' => 'foam_material_returned',
                'reference_document_type' => 'ProductionBatch',
                'reference_id' => $locked->id,
            ]);

            $entry = JournalEntry::query()
                ->where('source_document_type', self::MATERIAL_SOURCE_TYPE)
                ->where('source_document_id', $material->id)
                ->whereNull('reversal_of_id')
                ->first();

            if ($entry !== null) {
                $this->accountingService->reverseEntry(
                    $entry,
                    null,
                    "إرجاع مادة من عملية الإسفنج {$locked->operation_number}",
                    $userId,
                );
            }

            $material->delete();

            $this->syncMaterialCost($locked);

            return $locked->fresh();
        });
    }

    /**
     * Available lots of an item in the unit's warehouses, oldest first, locked.
     *
     * @return Collection<int, StockLot>
     */
    private function availableLotsFor(string $itemId, string $unitId): Collection
    {
        // withoutGlobalScopes on both levels, as in StockIssueService: the lots
        // are the batch unit's, whatever unit the caller's context names.
        return StockLot::withoutGlobalScopes()
            ->where('inventory_item_id', $itemId)
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereHas('warehouse', fn ($query) => $query->withoutGlobalScopes()->where('operating_unit_id', $unitId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Draw one portion from one lot and record it: the row, the consumption
     * movement (via drawFromLot) and the DR 1121 / CR lot-account entry.
     */
    private function issueFromLot(ProductionBatch $batch, StockLot $lot, float $quantity, ?string $userId): ProductionBatchMaterial
    {
        $unitCost = round((float) $lot->unit_cost, 4);
        $totalCost = round($quantity * $unitCost, 4);

        $material = ProductionBatchMaterial::create([
            'production_batch_id' => $batch->id,
            'inventory_item_id' => $lot->inventory_item_id,
            'stock_lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'issued_by' => $userId,
        ]);

        $draw = $this->stockLotService->drawFromLot(
            $lot,
            $quantity,
            'foam_material_issue',
            'ProductionBatch',
            $batch->id,
            'consumption',
        );

        $material->inventory_movement_id = $draw['movement']->id;
        $material->save();

        // A zero-cost lot moves no value, and postJournal refuses an empty entry.
        if ($totalCost > 0) {
            $code = $draw['lot']->inventoryItem?->code ?? 'ITEM';

            $this->accountingService->postJournal(
                "Foam operation {$batch->operation_number}: {$quantity} {$code} issued",
                [
                    [
                        'account_code' => '1121', // WIP — Foam Production
                        'debit' => $totalCost,
                        'operating_unit_id' => $batch->operating_unit_id,
                        'memo' => "{$code} — {$lot->lot_number}",
                    ],
                    [
                        'account_code' => InventoryAccounts::forLot($lot),
                        'credit' => $totalCost,
                        'operating_unit_id' => $batch->operating_unit_id,
                        'memo' => "{$code} — {$lot->lot_number}",
                    ],
                ],
                self::MATERIAL_SOURCE_TYPE,
                $material->id,
                $batch->operatingUnit?->company_id,
                false,
                $userId,
            );
        }

        return $material;
    }

    /**
     * Put back the empties a returned quantity refills, oldest empties lot in
     * the lot's warehouse first. If they are no longer there, the return is
     * refused: the barrels would otherwise be counted twice.
     */
    private function takeBackEmptyContainers(ProductionBatch $batch, StockLot $lot, InventoryItem $item, int $count): void
    {
        $empties = StockLot::withoutGlobalScopes()
            ->where('inventory_item_id', $item->empty_container_item_id)
            ->where('warehouse_id', $lot->warehouse_id)
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = (int) floor((float) $empties->sum('quantity'));

        if ($available < $count) {
            throw new LedgerRuleException(
                "Returning this quantity refills {$count} container(s) of {$item->code}, but only {$available} empty container(s) are in stock in that warehouse to take back.",
                'EMPTY_CONTAINERS_MISSING',
                ['required' => $count, 'available' => $available],
            );
        }

        $remaining = $count;
        $emptyItem = InventoryItem::withTrashed()->find($item->empty_container_item_id);

        foreach ($empties as $empty) {
            if ($remaining <= 0) {
                break;
            }

            $take = (int) min(floor((float) $empty->quantity), $remaining);
            if ($take <= 0) {
                continue;
            }

            $remaining -= $take;

            $empty->quantity = round((float) $empty->quantity - $take, 4);
            $empty->container_quantity = max(0, round((float) $empty->container_quantity - $take, 4));
            if ((float) $empty->quantity <= 0) {
                $empty->quantity = 0;
                $empty->status = 'consumed';
            }
            $empty->save();

            InventoryMovement::create([
                'operating_unit_id' => $batch->operating_unit_id,
                'stock_lot_id' => $empty->id,
                'from_warehouse_id' => $empty->warehouse_id,
                'sku' => $emptyItem?->code ?? 'EMPTY-CONTAINER',
                'movement_type' => 'byproduct_yield',
                'quantity_delta' => -$take,
                'unit_cost' => 0,
                'reason' => 'empty_container_refilled',
                'reference_document_type' => 'ProductionBatch',
                'reference_id' => $batch->id,
            ]);
        }
    }

    /**
     * Only raw materials (the chemicals, whose lots sit in 111) are issued to
     * a batch. Foam blocks — this batch's own included — slices, finished
     * goods and containers are never drawn into 1121.
     *
     * @param  list<string>  $itemIds
     */
    private function guardRawMaterials(array $itemIds): void
    {
        $items = InventoryItem::withTrashed()->whereIn('id', $itemIds)->get(['id', 'code', 'item_type'])->keyBy('id');

        $refused = [];
        foreach ($itemIds as $itemId) {
            $item = $items->get($itemId);
            if ($item === null || $item->item_type !== 'raw_material') {
                $refused[] = ['inventory_item_id' => $itemId, 'code' => $item?->code, 'item_type' => $item?->item_type];
            }
        }

        if ($refused !== []) {
            $codes = implode('، ', array_map(fn (array $r): string => (string) ($r['code'] ?? $r['inventory_item_id']), $refused));

            throw new LedgerRuleException(
                "Only raw materials can be issued to a foam operation — {$codes} is not a raw material.",
                'MATERIAL_NOT_RAW',
                ['items' => $refused],
            );
        }
    }

    /**
     * Issued materials are fixed once the batch is closed: its cost has been
     * apportioned onto blocks and posted to finished goods.
     */
    private function guardOpenForMaterials(ProductionBatch $batch): void
    {
        if ($batch->status === ProductionBatchStatus::Closed) {
            throw new InvalidStateTransitionException(
                "Operation {$batch->operation_number} is closed; its materials can no longer change."
            );
        }
    }

    /**
     * W4-05: a batch with issued materials costs exactly what was issued, so
     * removing its last row leaves zero (a hand-entered cost is only for
     * batches that never had chemicals issued).
     */
    private function syncMaterialCost(ProductionBatch $batch): void
    {
        $batch->material_cost = round((float) $batch->materials()->sum('total_cost'), 4);
        $batch->save();
    }

    /**
     * Compose the human-readable block label: {sequence}-{pressure}-{operation}.
     *
     * This string is a label, never an identity. Every component is also stored
     * as its own column, and nothing may parse this back to recover data.
     */
    public function composeLotNumber(int $sequence, ?int $pressure, int $operationNumber): string
    {
        return sprintf('%03d-%d-%d', $sequence, $pressure ?? 0, $operationNumber);
    }

    /**
     * Scrap carries no block code — it is not serialized. It gets a plainly
     * distinguishable number so it can never be mistaken for a block on a label
     * or in a stock listing.
     */
    public function composeScrapLotNumber(ProductionBatch $batch, int $index): string
    {
        return sprintf('SCRAP-%d-%02d', $batch->operation_number, $index);
    }

    /**
     * The operation number the operator is expected to enter next.
     *
     * Counts soft-deleted batches: a deleted batch may still have labelled
     * blocks in the yard, so its number remains in physical circulation.
     *
     * Deliberately bypasses the operating-unit scope. Operation numbers are
     * globally unique (§3), so a unit-filtered MAX would let two units both
     * arrive at the same "next" number and collide on the unique index.
     */
    public function nextExpectedOperationNumber(): int
    {
        return ((int) ProductionBatch::withTrashed()
            ->withoutGlobalScope(OperatingUnitScope::class)
            ->max('operation_number')) + 1;
    }

    /**
     * Advance a batch one step along its lifecycle (Phase 04 §4.4).
     *
     * The lifecycle is strictly linear, so this only ever moves forward by one
     * state and rejects anything else — including no-op transitions to the
     * current state, which usually mean a double-submitted button.
     */
    public function transition(ProductionBatch $batch, ProductionBatchStatus $target): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $target): ProductionBatch {
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                $allowed = array_map(fn (ProductionBatchStatus $s) => $s->value, $current->allowedNext());

                throw new InvalidStateTransitionException(
                    $allowed === []
                        ? "Operation {$locked->operation_number} is closed and cannot change state."
                        : "Cannot move operation {$locked->operation_number} from {$current->value} to {$target->value}; expected ".implode(' or ', $allowed).'.'
                );
            }

            // W4-05: once chemicals are issued, the batch costs what was issued.
            if ($target === ProductionBatchStatus::Closed && $locked->materials()->exists()) {
                $locked->material_cost = round((float) $locked->materials()->sum('total_cost'), 4);
            }

            $this->guardTransition($locked, $target);

            $locked->status = $target;
            $locked->save();

            // FOAM-07: on close the run's material cost is spread across its
            // blocks by volume share, so a bigger block carries more of it.
            if ($target === ProductionBatchStatus::Closed) {
                $this->apportionMaterialCost($locked);
                $this->postClosingJournal($locked);
                $this->fulfillMaterialRequestsFor($locked);
            }

            return $locked->fresh();
        });
    }

    /**
     * Foam batches produce the blocks the furniture BOM asked for via material
     * requests. When the batch closes, mark those requests fulfilled so the
     * upstream furniture order can advance.
     */
    private function fulfillMaterialRequestsFor(ProductionBatch $batch): void
    {
        $requests = MaterialRequest::query()
            ->where('fulfilling_module', MaterialRequest::MODULE_FOAM)
            ->whereIn('status', [
                MaterialRequest::STATUS_PENDING,
                MaterialRequest::STATUS_IN_PROGRESS,
            ])
            ->get();

        foreach ($requests as $request) {
            $this->materialResolution->markFulfilled($request, $batch);
        }
    }

    /**
     * FOAM-07: spread the run's material cost across its blocks by volume share.
     *
     *   block_unit_cost = batch_material_cost × (block.volume_m3 / total_block_volume)
     *
     * Scrap volume is deliberately excluded from the denominator. Scrap is not
     * inventory and carries no cost, so including it would quietly under-cost
     * every real block and leave part of the material spend unattached to
     * anything.
     *
     * The final block absorbs the rounding remainder so the apportioned costs sum
     * back to the batch total exactly, rather than drifting a few dirhams.
     */
    public function apportionMaterialCost(ProductionBatch $batch): void
    {
        $materialCost = (float) $batch->material_cost;

        if ($materialCost <= 0.0) {
            return;
        }

        // Blocks only: scrap enters at zero cost and must stay out of the
        // denominator, or every real block would be under-costed.
        $blocks = $batch->blocks()->orderBy('sequence_in_batch')->get();
        $totalVolume = (float) $blocks->sum(fn (StockLot $lot) => (float) $lot->volume_m3);

        if ($blocks->isEmpty() || $totalVolume <= 0.0) {
            return;
        }

        $allocated = 0.0;
        $lastIndex = $blocks->count() - 1;

        foreach ($blocks as $index => $block) {
            if ($index === $lastIndex) {
                $cost = round($materialCost - $allocated, 4);
            } else {
                $cost = round($materialCost * ((float) $block->volume_m3 / $totalVolume), 4);
                $allocated += $cost;
            }

            $block->unit_cost = $cost;
            $block->save();
        }
    }

    /**
     * Phase 04 §4.6: on close the run's value moves out of work in process and
     * into finished goods.
     *
     *   DR  Finished Goods — the blocks' item type account (INV-11: 1131 for foam_block)
     *   CR  Work In Process — Foam Production
     *
     * Posted for the material cost actually apportioned onto blocks, not the
     * batch's raw material cost. Scrap carries none of it, so if a run produced
     * scrap the two differ — and finished goods must match what the blocks are
     * really carrying, or the ledger and the stock ledger drift apart.
     */
    private function postClosingJournal(ProductionBatch $batch): void
    {
        $blocks = $batch->blocks()->with('inventoryItem')->get();
        $apportioned = round((float) $blocks->sum(fn (StockLot $lot): float => (float) $lot->unit_cost), 4);

        if ($apportioned <= 0.0) {
            return;
        }

        $valueByAccount = [];
        foreach ($blocks as $block) {
            $account = InventoryAccounts::forLot($block);
            $valueByAccount[$account] = round(($valueByAccount[$account] ?? 0) + (float) $block->unit_cost, 4);
        }

        $lines = [];
        foreach ($valueByAccount as $account => $value) {
            if ($value > 0) {
                $lines[] = [
                    'account_code' => (string) $account,
                    'debit' => $value,
                    'operating_unit_id' => $batch->operating_unit_id,
                    'memo' => "{$blocks->count()} blocks",
                ];
            }
        }

        $lines[] = [
            'account_code' => '1121', // WIP — Foam Production
            'credit' => $apportioned,
            'operating_unit_id' => $batch->operating_unit_id,
        ];

        $this->accountingService->postJournal(
            "Foam operation {$batch->operation_number} closed",
            $lines,
            'ProductionBatch',
            $batch->id,
            $batch->operatingUnit?->company_id,
        );
    }

    /**
     * State-specific preconditions that go beyond ordering.
     */
    private function guardTransition(ProductionBatch $batch, ProductionBatchStatus $target): void
    {
        if ($target === ProductionBatchStatus::Closed && (float) $batch->material_cost <= 0) {
            // W4-05: issued rows fix the cost (it cannot be typed over), so a
            // batch whose chemicals all came from zero-cost lots says so
            // rather than asking for a cost the API would refuse.
            if ($batch->materials()->exists()) {
                throw new LedgerRuleException(
                    "Operation {$batch->operation_number} cannot close: its issued chemicals all came from lots with no recorded cost, so it has no material cost to carry onto its blocks. Remove the issued materials and enter the material cost by hand, then close.",
                    'MATERIAL_COST_ZERO_FROM_ISSUES',
                );
            }

            throw new InvalidStateTransitionException(
                "Operation {$batch->operation_number} cannot close without a material cost — enter it first so its blocks carry value."
            );
        }

        // FOAM-05: a batch cannot be declared graded while blocks are missing
        // their measured pressure, and cannot close with no output at all.
        if ($target === ProductionBatchStatus::Graded) {
            // Scrap is never graded, so it is excluded from both checks.
            $blockCount = $batch->blocks()->count();

            if ($blockCount === 0) {
                throw new InvalidStateTransitionException(
                    "Operation {$batch->operation_number} has no registered blocks to grade."
                );
            }

            $ungraded = $batch->blocks()->where('block_type', 'block')->whereNull('pressure')->count();

            if ($ungraded > 0) {
                throw new InvalidStateTransitionException(
                    "Operation {$batch->operation_number} still has {$ungraded} ungraded block(s)."
                );
            }
        }
    }

    /**
     * Register the output of a run from its production report.
     *
     * The paper sheet records blocks as dimension groups with counts, but every
     * block is individually labelled — so each group is expanded into `count`
     * serialized lots, each with its own sequence and printable code.
     *
     * Groups can be standard blocks, separators (فاصل), heads (بداية), or scrap (هدر).
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array{blocks: array<int, StockLot>, scrap_volume_m3: float, batch: ProductionBatch}
     */
    public function registerBlocks(ProductionBatch $batch, array $groups): array
    {
        return DB::transaction(function () use ($batch, $groups): array {
            // Reserve sequence ranges against a locked row so concurrent
            // registrations on the same batch cannot interleave.
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            $blocks = [];
            $scrapLots = [];
            $scrapVolume = 0.0;
            $width = (float) $locked->bun_width_m;

            // Scrap lots are numbered per batch, independently of block sequences.
            // Warehouse transfer copies keep the batch for lineage (W4-04) but
            // are not scrap, so they take no number.
            $scrapIndex = StockLot::withTrashed()
                ->where('production_batch_id', $locked->id)
                ->whereNull('sequence_in_batch')
                ->whereNull('source_stock_lot_id')
                ->count() + 1;

            foreach ($groups as $group) {
                $count = (int) $group['count'];
                $length = (float) $group['length_m'];
                $height = (float) $group['height_m'];
                $unitVolume = round($length * $width * $height, 4);
                $kind = $group['kind'] ?? 'block';
                $blockType = $group['block_type'] ?? (in_array($kind, ['separator', 'head', 'scrap'], true) ? $kind : 'block');
                // W4-02: output lands on the item's bin in that warehouse, if one is set.
                $bin = ItemStockLevel::binFor($group['inventory_item_id'], $group['warehouse_id']);

                if ($blockType === 'scrap' || $kind === 'scrap') {
                    $groupVolume = round($unitVolume * $count, 4);
                    $scrapVolume += $groupVolume;

                    // Scrap is real material and enters stock, but at zero cost:
                    // the run's whole material spend is carried by the blocks
                    // (see apportionMaterialCost). Standard byproduct treatment.
                    //
                    // It consumes no sequence number — it is not serialized, so it
                    // gets no block code and must not create gaps in the printed
                    // ones.
                    $scrapLot = StockLot::create([
                        'inventory_item_id' => $group['inventory_item_id'],
                        'warehouse_id' => $group['warehouse_id'],
                        'production_batch_id' => $locked->id,
                        'lot_number' => $this->composeScrapLotNumber($locked, $scrapIndex++),
                        // Quantity is total volume, not a piece count. Dimensions are
                        // deliberately left off: with count > 1 they describe one
                        // piece, which would contradict the total in quantity.
                        'quantity' => $groupVolume,
                        'unit_cost' => 0,
                        'block_type' => 'scrap',
                        'status' => 'available',
                        'bin_location' => $bin,
                    ]);

                    InventoryMovement::create([
                        'operating_unit_id' => $locked->operating_unit_id,
                        'stock_lot_id' => $scrapLot->id,
                        'to_warehouse_id' => $scrapLot->warehouse_id,
                        'sku' => $scrapLot->inventoryItem?->code ?? 'FOAM-SCRAP',
                        'movement_type' => 'byproduct_yield',
                        'quantity_delta' => $groupVolume,
                        'unit_cost' => 0,
                        'reason' => 'foam_run_scrap',
                        'reference_document_type' => 'ProductionBatch',
                        'reference_id' => $locked->id,
                    ]);

                    $scrapLots[] = $scrapLot;

                    continue;
                }

                if ($blockType === 'block' && (! isset($group['pressure']) || $group['pressure'] === '' || $group['pressure'] === null)) {
                    throw new InvalidArgumentException('Pressure is required for standard block groups; no code can be composed without it.');
                }

                $pressure = isset($group['pressure']) && $group['pressure'] !== '' && $group['pressure'] !== null ? (int) $group['pressure'] : null;
                $startSequence = (int) $locked->next_sequence;

                for ($offset = 0; $offset < $count; $offset++) {
                    $sequence = $startSequence + $offset;

                    $lot = StockLot::create([
                        'inventory_item_id' => $group['inventory_item_id'],
                        'warehouse_id' => $group['warehouse_id'],
                        'production_batch_id' => $locked->id,
                        'sequence_in_batch' => $sequence,
                        'pressure' => $pressure,
                        'block_type' => $blockType,
                        'lot_number' => $this->composeLotNumber($sequence, $pressure, (int) $locked->operation_number),
                        'quantity' => 1,
                        'length_m' => $length,
                        // Bun width is a machine setting held on the batch, copied down
                        // rather than re-entered per row.
                        'width_m' => $width,
                        'height_m' => $height,
                        'unit_cost' => $group['unit_cost'] ?? 0,
                        'grade' => $group['grade'] ?? 'standard',
                        'status' => 'available',
                        'attribute_values' => isset($group['color']) ? ['color' => $group['color']] : null,
                        'bin_location' => $bin,
                    ]);

                    // INV-06: stock only ever appears through a movement, so the
                    // block's arrival in inventory is recorded as production output
                    // traceable back to the batch that made it.
                    InventoryMovement::create([
                        'operating_unit_id' => $locked->operating_unit_id,
                        'stock_lot_id' => $lot->id,
                        'to_warehouse_id' => $lot->warehouse_id,
                        'sku' => $lot->inventoryItem?->code ?? 'FOAM-BLOCK',
                        'movement_type' => 'production_output',
                        'quantity_delta' => 1,
                        'unit_cost' => (float) $lot->unit_cost,
                        'reason' => 'foam_block_registered',
                        'reference_document_type' => 'ProductionBatch',
                        'reference_id' => $locked->id,
                    ]);

                    $blocks[] = $lot;
                }

                $locked->next_sequence = $startSequence + $count;
            }

            if ($scrapVolume > 0.0) {
                $locked->scrap_volume_m3 = round((float) $locked->scrap_volume_m3 + $scrapVolume, 4);
            }

            $locked->save();

            return [
                'blocks' => $blocks,
                'scrap_lots' => $scrapLots,
                'scrap_volume_m3' => round($scrapVolume, 4),
                'batch' => $locked->fresh(),
            ];
        });
    }
}
