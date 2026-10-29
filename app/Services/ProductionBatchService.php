<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionBatchStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\InventoryMovement;
use App\Models\ItemStockLevel;
use App\Models\MaterialRequest;
use App\Models\ProductionBatch;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\StockLot;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionBatchService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly MaterialResolutionService $materialResolution,
    ) {}

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
            $scrapIndex = StockLot::withTrashed()
                ->where('production_batch_id', $locked->id)
                ->whereNull('sequence_in_batch')
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
