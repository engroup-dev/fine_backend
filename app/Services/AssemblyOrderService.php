<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssemblyOrderStatus;
use App\Enums\ProductionBatchStatus;
use App\Exceptions\LedgerRuleException;
use App\Exceptions\OptimisticLockConflictException;
use App\Models\AssemblyOrder;
use App\Models\AssemblyOrderLine;
use App\Models\Bundle;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemStockLevel;
use App\Models\OperatingUnit;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryAccounts;
use App\Support\LotSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Furniture assembly orders (W4-07, owner decision #12).
 *
 * - An order names a finished item (type furniture_finished_good), a quantity,
 *   an output warehouse (which sets the order's unit) and its components.
 *   Components come from a bundle template (bundle_items, suggested quantity
 *   × order quantity) or are entered by hand; a line may name the lot to draw
 *   from, or leave it to completion. A line may carry a piece size (copied
 *   from bundle_items, or entered by hand): it then draws only lots of that
 *   size, in any orientation, within half a centimetre (LotSize).
 * - A draft reserves nothing, so cancelling it releases nothing.
 * - Completion draws every component from available lots of the order's unit
 *   through `consumption` movements (INV-06): a line with a lot draws from
 *   that lot, a line without one draws oldest matching lot first (sized lines
 *   before unsized ones) and is split into one line per lot drawn. Lots of a foam batch that is not closed carry no cost
 *   yet, so they are never drawn. Any shortfall refuses the whole completion
 *   (INSUFFICIENT_STOCK) before anything moves; the report names each short
 *   component (and size) and each hand-picked lot that cannot cover its
 *   lines.
 * - Costing is actuals: each line records its lot's unit cost, the finished
 *   lot (FG-<number>) enters the output warehouse through a
 *   `production_output` movement at Σ line cost / quantity, and one entry
 *   posts DR the finished item's account (1134) / CR each component lot's
 *   account (INV-11, InventoryAccounts::forLot). When the cost does not divide
 *   evenly, one unit goes into its own lot (FG-<number>-2) carrying the
 *   rounding remainder, so the lots sum exactly to the 1134 debit.
 */
final class AssemblyOrderService
{
    /** Movement and journal source type. */
    public const SOURCE_TYPE = 'AssemblyOrder';

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly StockLotService $stockLots,
    ) {}

    /**
     * Open a draft order, from a bundle template or with hand-entered lines.
     *
     * @param  array{finished_item_id: string, quantity: float|int|string, output_warehouse_id: string, bundle_id?: string|null, notes?: string|null, lines?: array<int, array{inventory_item_id: string, quantity: float|int|string, stock_lot_id?: string|null, length_m?: float|int|string|null, width_m?: float|int|string|null, height_m?: float|int|string|null}>|null}  $data
     *
     * @throws LedgerRuleException
     */
    public function create(array $data, User $user): AssemblyOrder
    {
        $finished = InventoryItem::findOrFail($data['finished_item_id']);

        if ($finished->item_type !== 'furniture_finished_good') {
            throw new LedgerRuleException(
                "{$finished->name} is not a finished furniture item; an assembly order produces furniture_finished_good items only.",
                'ASSEMBLY_NOT_FINISHED_GOOD',
                ['item_type' => $finished->item_type],
            );
        }

        $quantity = round((float) $data['quantity'], 4);

        if ($quantity <= 0) {
            throw new LedgerRuleException('An assembly order must produce at least 0.0001.', 'INVALID_QUANTITY');
        }

        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($data['output_warehouse_id']);
        $bundle = isset($data['bundle_id']) ? Bundle::with('items')->findOrFail($data['bundle_id']) : null;

        $rows = $data['lines'] ?? null;

        if (($rows === null || $rows === []) && $bundle !== null) {
            $rows = $bundle->items
                ->map(fn ($item): array => [
                    'inventory_item_id' => $item->inventory_item_id,
                    'quantity' => round((float) $item->suggested_quantity * $quantity, 4),
                    'stock_lot_id' => null,
                    'length_m' => $item->length_m,
                    'width_m' => $item->width_m,
                    'height_m' => $item->height_m,
                ])
                ->all();
        }

        if ($rows === null || $rows === []) {
            throw new LedgerRuleException('An assembly order needs at least one component.', 'ASSEMBLY_NO_COMPONENTS');
        }

        foreach ($rows as $row) {
            $this->guardLine($row, $finished, $warehouse->operating_unit_id);
        }

        return DB::transaction(function () use ($data, $user, $finished, $quantity, $warehouse, $bundle, $rows): AssemblyOrder {
            $order = AssemblyOrder::create([
                'operating_unit_id' => $warehouse->operating_unit_id,
                'number' => $this->documentNumbers->next(DocumentNumberService::ASSEMBLY_ORDER),
                'finished_item_id' => $finished->id,
                'quantity' => $quantity,
                'output_warehouse_id' => $warehouse->id,
                'status' => AssemblyOrderStatus::Draft,
                'bundle_id' => $bundle?->id,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            foreach (array_values($rows) as $position => $row) {
                $size = self::rowSize($row);

                AssemblyOrderLine::create([
                    'assembly_order_id' => $order->id,
                    'inventory_item_id' => $row['inventory_item_id'],
                    'stock_lot_id' => $row['stock_lot_id'] ?? null,
                    'quantity' => round((float) $row['quantity'], 4),
                    'length_m' => $size[0] ?? null,
                    'width_m' => $size[1] ?? null,
                    'height_m' => $size[2] ?? null,
                    'position' => $position,
                ]);
            }

            return $order;
        });
    }

    /**
     * Consume the components and produce the finished lot.
     *
     * @throws LedgerRuleException
     */
    public function complete(AssemblyOrder $order, User $user, ?int $recordVersion = null): AssemblyOrder
    {
        return DB::transaction(function () use ($order, $user, $recordVersion): AssemblyOrder {
            $locked = $this->lock($order);
            $this->ensureVersion($locked, $recordVersion);
            $this->ensureTransition($locked, AssemblyOrderStatus::Completed);

            // The product is re-checked: it may have been archived or retyped
            // since the draft, and its account (1134) follows its type.
            $finishedItem = InventoryItem::find($locked->finished_item_id);

            if ($finishedItem === null) {
                throw new LedgerRuleException(
                    "The product of order {$locked->number} has been deleted; it cannot be assembled.",
                    'ASSEMBLY_ITEM_DELETED',
                    ['finished_item_id' => $locked->finished_item_id],
                );
            }

            if ($finishedItem->item_type !== 'furniture_finished_good') {
                throw new LedgerRuleException(
                    "{$finishedItem->name} is no longer a finished furniture item; an assembly order produces furniture_finished_good items only.",
                    'ASSEMBLY_NOT_FINISHED_GOOD',
                    ['item_type' => $finishedItem->item_type],
                );
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new LedgerRuleException("Order {$locked->number} has no components.", 'ASSEMBLY_NO_COMPONENTS');
            }

            $unitId = $locked->operating_unit_id;
            $itemIds = $lines->pluck('inventory_item_id')->unique()->values()->all();

            /** @var array<string, Collection<int, StockLot>> $lotsByItem oldest first, locked */
            $lotsByItem = [];
            /** @var array<string, float> $remainingByLot */
            $remainingByLot = [];

            foreach ($itemIds as $itemId) {
                $lotsByItem[$itemId] = $this->availableLotsFor((string) $itemId, $unitId);

                foreach ($lotsByItem[$itemId] as $lot) {
                    $remainingByLot[$lot->id] = round((float) $lot->quantity, 4);
                }
            }

            /** @var array<string, float> $pickedByLot quantity hand-picked from each lot */
            $pickedByLot = [];

            // Hand-picked lots first: they are taken whatever their age.
            foreach ($lines as $line) {
                if ($line->stock_lot_id === null) {
                    continue;
                }

                $lot = $lotsByItem[$line->inventory_item_id]->firstWhere('id', $line->stock_lot_id);

                if ($lot === null) {
                    $gone = StockLot::withoutGlobalScopes()->find($line->stock_lot_id);

                    throw new LedgerRuleException(
                        'Lot '.($gone?->lot_number ?? $line->stock_lot_id).' is no longer available stock of this component in the unit.',
                        'ASSEMBLY_LOT_REJECTED',
                        ['line_id' => $line->id, 'stock_lot_id' => $line->stock_lot_id],
                    );
                }

                if (! LotSize::matches($line->size(), $lot)) {
                    throw new LedgerRuleException(
                        "Lot {$lot->lot_number} is not the size of this component.",
                        'ASSEMBLY_LOT_SIZE_MISMATCH',
                        ['line_id' => $line->id, 'stock_lot_id' => $lot->id],
                    );
                }

                $quantity = round((float) $line->quantity, 4);
                $remainingByLot[$lot->id] = round($remainingByLot[$lot->id] - $quantity, 4);
                $pickedByLot[$lot->id] = round(($pickedByLot[$lot->id] ?? 0) + $quantity, 4);
            }

            // Then the open lines, oldest matching lot first. Sized lines go
            // before unsized ones so an unsized line cannot take the only lot
            // of the size another line needs.
            /** @var array<string, list<array{lot: StockLot, quantity: float}>> $draws by line id */
            $draws = [];
            /** @var array<string, array{inventory_item_id: string, size: array{0: float, 1: float, 2: float}|null, required: float, unmet: float}> $wanted */
            $wanted = [];

            $openLines = $lines
                ->filter(fn (AssemblyOrderLine $line): bool => $line->stock_lot_id === null)
                ->sortBy(fn (AssemblyOrderLine $line): int => $line->size() === null ? 1 : 0)
                ->values();

            foreach ($openLines as $line) {
                $size = $line->size();
                $quantity = round((float) $line->quantity, 4);
                $remaining = $quantity;
                $draws[$line->id] = [];

                foreach ($lotsByItem[$line->inventory_item_id] as $lot) {
                    if ($remaining <= 0) {
                        break;
                    }

                    if (! LotSize::matches($size, $lot)) {
                        continue;
                    }

                    $take = round(min($remainingByLot[$lot->id], $remaining), 4);

                    if ($take <= 0) {
                        continue;
                    }

                    $remainingByLot[$lot->id] = round($remainingByLot[$lot->id] - $take, 4);
                    $remaining = round($remaining - $take, 4);
                    $draws[$line->id][] = ['lot' => $lot, 'quantity' => $take];
                }

                $key = $line->inventory_item_id.'|'.($size === null ? '' : implode('x', $size));
                $wanted[$key] ??= ['inventory_item_id' => $line->inventory_item_id, 'size' => $size, 'required' => 0.0, 'unmet' => 0.0];
                $wanted[$key]['required'] = round($wanted[$key]['required'] + $quantity, 4);
                $wanted[$key]['unmet'] = round($wanted[$key]['unmet'] + $remaining, 4);
            }

            $this->refuseShortfalls($locked, $wanted, $pickedByLot, $remainingByLot, $lotsByItem);

            $totalCost = 0.0;
            /** @var array<string, float> $creditByAccount */
            $creditByAccount = [];
            $position = (int) $lines->max('position') + 1;

            foreach ($lines as $line) {
                if ($line->stock_lot_id !== null) {
                    $planned = [['lot' => $lotsByItem[$line->inventory_item_id]->firstWhere('id', $line->stock_lot_id), 'quantity' => round((float) $line->quantity, 4)]];
                } else {
                    $planned = $draws[$line->id];
                }

                // A line that spans lots becomes one line per lot.
                foreach ($planned as $index => $draw) {
                    $target = $line;

                    if ($index > 0) {
                        $target = AssemblyOrderLine::create([
                            'assembly_order_id' => $locked->id,
                            'inventory_item_id' => $line->inventory_item_id,
                            'quantity' => $draw['quantity'],
                            'length_m' => $line->length_m,
                            'width_m' => $line->width_m,
                            'height_m' => $line->height_m,
                            'position' => $position++,
                        ]);
                    }

                    $target->stock_lot_id = $draw['lot']->id;
                    $target->quantity = $draw['quantity'];

                    $cost = $this->drawLine($locked, $target, $draw['lot'], $draw['quantity']);
                    $totalCost = round($totalCost + $cost['value'], 4);
                    $creditByAccount[$cost['account']] = round(($creditByAccount[$cost['account']] ?? 0) + $cost['value'], 4);
                }
            }

            $quantity = round((float) $locked->quantity, 4);
            $bin = ItemStockLevel::binFor($finishedItem->id, $locked->output_warehouse_id);
            $outputs = self::finishedLots('FG-'.$locked->number, $quantity, $totalCost);

            $finishedLot = null;

            foreach ($outputs as $output) {
                $lot = StockLot::create([
                    'inventory_item_id' => $finishedItem->id,
                    'warehouse_id' => $locked->output_warehouse_id,
                    'bin_location' => $bin,
                    'lot_number' => $output['lot_number'],
                    'quantity' => $output['quantity'],
                    'unit_cost' => $output['unit_cost'],
                    'status' => 'available',
                ]);

                InventoryMovement::create([
                    'operating_unit_id' => $unitId,
                    'stock_lot_id' => $lot->id,
                    'to_warehouse_id' => $locked->output_warehouse_id,
                    'sku' => $finishedItem->code ?? 'ITEM',
                    'movement_type' => 'production_output',
                    'quantity_delta' => $output['quantity'],
                    'unit_cost' => $output['unit_cost'],
                    'reason' => 'furniture_assembled',
                    'reference_document_type' => self::SOURCE_TYPE,
                    'reference_id' => $locked->id,
                ]);

                $finishedLot ??= $lot;
            }

            $entry = null;

            // A zero-cost build moves no value, and postJournal refuses an empty entry.
            if ($totalCost > 0) {
                $journalLines = [[
                    'account_code' => InventoryAccounts::forItem($finishedItem), // 1134
                    'debit' => $totalCost,
                    'operating_unit_id' => $unitId,
                    'memo' => "{$finishedItem->code} — {$finishedLot->lot_number}",
                ]];

                foreach ($creditByAccount as $account => $amount) {
                    if ($amount <= 0) {
                        continue;
                    }

                    $journalLines[] = [
                        'account_code' => (string) $account,
                        'credit' => $amount,
                        'operating_unit_id' => $unitId,
                    ];
                }

                $entry = $this->accountingService->postJournal(
                    "Furniture assembly order {$locked->number}: {$quantity} {$finishedItem->code} assembled",
                    $journalLines,
                    self::SOURCE_TYPE,
                    $locked->id,
                    OperatingUnit::withoutGlobalScopes()->whereKey($unitId)->value('company_id'),
                    false,
                    $user->id,
                );
            }

            if ($recordVersion !== null) {
                $locked->record_version = $recordVersion;
            }

            $locked->status = AssemblyOrderStatus::Completed;
            $locked->total_cost = $totalCost;
            $locked->finished_stock_lot_id = $finishedLot->id;
            $locked->journal_entry_id = $entry?->id;
            $locked->completed_by_user_id = $user->id;
            $locked->completed_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Cancel a draft. Nothing was reserved, so nothing is released.
     *
     * @throws LedgerRuleException
     */
    public function cancel(AssemblyOrder $order, ?int $recordVersion = null): AssemblyOrder
    {
        return DB::transaction(function () use ($order, $recordVersion): AssemblyOrder {
            $locked = $this->lock($order);
            $this->ensureVersion($locked, $recordVersion);
            $this->ensureTransition($locked, AssemblyOrderStatus::Cancelled);

            if ($recordVersion !== null) {
                $locked->record_version = $recordVersion;
            }

            $locked->status = AssemblyOrderStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * The order as the API returns it, with its cost breakdown.
     *
     * @return array<string, mixed>
     */
    public function present(AssemblyOrder $order): array
    {
        $order->loadMissing([
            'finishedItem:id,name,code,item_type,unit_of_measure',
            'outputWarehouse:id,name,operating_unit_id',
            'bundle:id,name',
            'finishedStockLot:id,lot_number,quantity,unit_cost,status,warehouse_id',
            'journalEntry:id,reference,entry_date',
            'createdBy:id,name',
            'completedBy:id,name',
            'lines.inventoryItem:id,name,code,item_type,unit_of_measure',
            'lines.stockLot:id,lot_number,quantity,unit_cost,status,warehouse_id,bin_location',
        ]);

        $lines = $order->lines
            ->map(fn (AssemblyOrderLine $line): array => [
                ...$line->withoutRelations()->toArray(),
                'inventory_item' => $line->inventoryItem,
                'stock_lot' => $line->stockLot,
                'total_cost' => $line->unit_cost === null ? null : round((float) $line->quantity * (float) $line->unit_cost, 4),
            ])
            ->all();

        $total = $order->total_cost === null ? null : round((float) $order->total_cost, 4);

        // Every lot the order produced (two when one unit carries the rounding remainder).
        $finishedLots = StockLot::withoutGlobalScopes()
            ->whereIn('id', InventoryMovement::withoutGlobalScopes()
                ->where('reference_document_type', self::SOURCE_TYPE)
                ->where('reference_id', $order->id)
                ->where('movement_type', 'production_output')
                ->select('stock_lot_id'))
            ->orderBy('lot_number')
            ->get(['id', 'lot_number', 'quantity', 'unit_cost', 'status', 'warehouse_id']);

        return [
            ...$order->withoutRelations()->toArray(),
            'finished_item' => $order->finishedItem,
            'output_warehouse' => $order->outputWarehouse,
            'bundle' => $order->bundle,
            'finished_stock_lot' => $order->finishedStockLot,
            'finished_stock_lots' => $finishedLots,
            'journal_entry' => $order->journalEntry,
            'created_by' => $order->createdBy,
            'completed_by' => $order->completedBy,
            'lines' => $lines,
            'summary' => [
                'lines_total' => count($lines),
                'total_cost' => $total,
                'unit_cost' => $total === null ? null : round($total / (float) $order->quantity, 4),
            ],
        ];
    }

    /**
     * Draw one line's quantity from one lot: the `consumption` movement (via
     * drawFromLot, which also handles containers) and the line's actual cost.
     *
     * @return array{value: float, account: string}
     */
    private function drawLine(AssemblyOrder $order, AssemblyOrderLine $line, StockLot $lot, float $quantity): array
    {
        $unitCost = round((float) $lot->unit_cost, 4);

        $this->stockLots->drawFromLot(
            $lot,
            $quantity,
            'furniture_assembly',
            self::SOURCE_TYPE,
            $order->id,
            'consumption',
        );

        $line->unit_cost = $unitCost;
        $line->save();

        return [
            'value' => round($quantity * $unitCost, 4),
            'account' => InventoryAccounts::forLot($lot), // INV-11
        ];
    }

    /**
     * Available lots of an item in the unit's warehouses, oldest first, locked.
     * Lots of a foam batch that is not closed are left out: their cost is
     * still in work in process.
     *
     * @return Collection<int, StockLot>
     */
    private function availableLotsFor(string $itemId, string $unitId): Collection
    {
        return $this->drawableLots($unitId)
            ->with('inventoryItem')
            ->where('inventory_item_id', $itemId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lots an order of the unit can draw: available, holding stock, in one of
     * the unit's warehouses, and not of a foam batch that is not closed.
     *
     * @return Builder<StockLot>
     */
    private function drawableLots(string $unitId): Builder
    {
        return StockLot::withoutGlobalScopes()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNull('deleted_at')
            ->whereIn('warehouse_id', Warehouse::withoutGlobalScopes()->where('operating_unit_id', $unitId)->select('id'))
            ->where(fn (Builder $query) => $query->whereNull('production_batch_id')->orWhereNotIn(
                'production_batch_id',
                DB::table('production_batches')->where('status', '!=', ProductionBatchStatus::Closed->value)->select('id'),
            ));
    }

    /**
     * Refuse the completion, before anything moves, when stock cannot cover
     * it: each component (and size) the open lines cannot fill, and each
     * hand-picked lot asked for more than it holds.
     *
     * @param  array<string, array{inventory_item_id: string, size: array{0: float, 1: float, 2: float}|null, required: float, unmet: float}>  $wanted
     * @param  array<string, float>  $pickedByLot
     * @param  array<string, float>  $remainingByLot
     * @param  array<string, Collection<int, StockLot>>  $lotsByItem
     *
     * @throws LedgerRuleException
     */
    private function refuseShortfalls(AssemblyOrder $order, array $wanted, array $pickedByLot, array $remainingByLot, array $lotsByItem): void
    {
        $shortfalls = [];
        $phrases = [];

        foreach ($wanted as $want) {
            if ($want['unmet'] <= 0) {
                continue;
            }

            $code = InventoryItem::withTrashed()->whereKey($want['inventory_item_id'])->value('code');
            $available = round($want['required'] - $want['unmet'], 4);
            $sizeLabel = $want['size'] === null ? '' : ' '.implode('×', array_map(fn (float $d): string => (string) $d, $want['size']));

            $shortfalls[] = [
                'inventory_item_id' => $want['inventory_item_id'],
                'code' => $code,
                'stock_lot_id' => null,
                'lot_number' => null,
                'length_m' => $want['size'][0] ?? null,
                'width_m' => $want['size'][1] ?? null,
                'height_m' => $want['size'][2] ?? null,
                'required' => $want['required'],
                'available' => $available,
            ];
            $phrases[] = "{$code}{$sizeLabel}: need {$want['required']}, have {$available}";
        }

        foreach ($pickedByLot as $lotId => $picked) {
            if ($remainingByLot[$lotId] >= 0) {
                continue;
            }

            $lot = collect($lotsByItem)->flatten(1)->firstWhere('id', $lotId);
            $held = round((float) $lot->quantity, 4);

            $shortfalls[] = [
                'inventory_item_id' => $lot->inventory_item_id,
                'code' => $lot->inventoryItem?->code,
                'stock_lot_id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'length_m' => null,
                'width_m' => null,
                'height_m' => null,
                'required' => $picked,
                'available' => $held,
            ];
            $phrases[] = "lot {$lot->lot_number} ({$lot->inventoryItem?->code}): {$picked} picked, holds only {$held}";
        }

        if ($shortfalls === []) {
            return;
        }

        throw new LedgerRuleException(
            "Stock cannot cover the components of order {$order->number} — ".implode('، ', $phrases).'.',
            'INSUFFICIENT_STOCK',
            ['shortfalls' => $shortfalls],
        );
    }

    /**
     * The finished lot(s) for a build of $quantity costing $totalCost. When
     * the cost does not divide evenly, the main lot takes a unit cost rounded
     * down and the remainder rides on one unit of its own, so the lots sum
     * exactly to the value posted to 1134 (the convention of
     * ProductionBatchService and CutterWorkOrderService) and neither cost is
     * ever negative. Rounding down keeps the remainder at least one unit cost.
     * For a fractional quantity the main lot's unit cost steps down until its
     * value is a whole ten-thousandth. A build of one piece or less is a
     * single lot: a fraction of a piece cannot split, so its value rounds.
     *
     * Amounts are worked in ten-thousandths (the 4 dp of the stock columns).
     *
     * @return list<array{lot_number: string, quantity: float, unit_cost: float}>
     */
    private static function finishedLots(string $lotNumber, float $quantity, float $totalCost): array
    {
        $scale = 10000;
        $totalUnits = (int) round($totalCost * $scale);
        $quantityUnits = (int) round($quantity * $scale);
        // Rounded to 6 dp first so a float just under a whole ten-thousandth is not floored a step too far.
        $unitUnits = (int) floor(round($totalCost / $quantity * $scale, 6));

        if ($quantity <= 1 || self::lotValueUnits($quantityUnits, $unitUnits) === $totalUnits) {
            $unitCost = $quantity <= 1 ? round($totalCost / $quantity, 4) : $unitUnits / $scale;

            return [['lot_number' => $lotNumber, 'quantity' => $quantity, 'unit_cost' => $unitCost]];
        }

        $mainUnits = $quantityUnits - $scale;
        $step = intdiv($scale, self::gcd($mainUnits, $scale));
        $unitUnits = intdiv($unitUnits, $step) * $step;
        $remainderUnits = $totalUnits - (int) self::lotValueUnits($mainUnits, $unitUnits);

        return [
            ['lot_number' => $lotNumber, 'quantity' => $mainUnits / $scale, 'unit_cost' => $unitUnits / $scale],
            ['lot_number' => $lotNumber.'-2', 'quantity' => 1.0, 'unit_cost' => $remainderUnits / $scale],
        ];
    }

    /**
     * The value, in ten-thousandths, of a lot of $quantityUnits at
     * $unitCostUnits (both in ten-thousandths), or null when it is not a whole
     * ten-thousandth.
     */
    private static function lotValueUnits(int $quantityUnits, int $unitCostUnits): ?int
    {
        $divisor = self::gcd($quantityUnits, 10000);
        $step = intdiv(10000, $divisor);

        if ($unitCostUnits % $step !== 0) {
            return null;
        }

        return intdiv($quantityUnits, $divisor) * intdiv($unitCostUnits, $step);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }

    /**
     * A row's piece size, or null unless all three dimensions are given.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function rowSize(array $row): ?array
    {
        return LotSize::of($row['length_m'] ?? null, $row['width_m'] ?? null, $row['height_m'] ?? null);
    }

    /**
     * A line names a component other than the product itself, a positive
     * quantity and, optionally, a lot the order could draw: available stock of
     * that component in the order's unit, of the line's size if it has one.
     *
     * @param  array{inventory_item_id: string, quantity: float|int|string, stock_lot_id?: string|null, length_m?: float|int|string|null, width_m?: float|int|string|null, height_m?: float|int|string|null}  $row
     *
     * @throws LedgerRuleException
     */
    private function guardLine(array $row, InventoryItem $finished, string $unitId): void
    {
        if ($row['inventory_item_id'] === $finished->id) {
            throw new LedgerRuleException('A product cannot be a component of itself.', 'ASSEMBLY_COMPONENT_IS_PRODUCT');
        }

        if (round((float) $row['quantity'], 4) <= 0) {
            throw new LedgerRuleException('A component quantity must be at least 0.0001.', 'INVALID_QUANTITY');
        }

        $lotId = $row['stock_lot_id'] ?? null;

        if ($lotId === null) {
            return;
        }

        $lot = $this->drawableLots($unitId)
            ->where('inventory_item_id', $row['inventory_item_id'])
            ->whereKey($lotId)
            ->first();

        if ($lot === null) {
            throw new LedgerRuleException(
                'A chosen lot is not available stock of this component in the order\'s unit.',
                'ASSEMBLY_LOT_REJECTED',
                ['stock_lot_id' => $lotId],
            );
        }

        if (! LotSize::matches(self::rowSize($row), $lot)) {
            throw new LedgerRuleException(
                "Lot {$lot->lot_number} is not the size of this component.",
                'ASSEMBLY_LOT_SIZE_MISMATCH',
                ['stock_lot_id' => $lotId],
            );
        }
    }

    private function lock(AssemblyOrder $order): AssemblyOrder
    {
        return AssemblyOrder::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * A stale record_version is refused before anything is drawn (409).
     *
     * @throws OptimisticLockConflictException
     */
    private function ensureVersion(AssemblyOrder $order, ?int $recordVersion): void
    {
        if ($recordVersion !== null && $recordVersion !== (int) $order->record_version) {
            throw new OptimisticLockConflictException;
        }
    }

    /**
     * @throws LedgerRuleException
     */
    private function ensureTransition(AssemblyOrder $order, AssemblyOrderStatus $target): void
    {
        if (! $order->status->canTransitionTo($target)) {
            throw new LedgerRuleException(
                "Order {$order->number} is {$order->status->value} and cannot be {$target->value}.",
                'ASSEMBLY_STATUS_INVALID',
                ['status' => $order->status->value],
            );
        }
    }
}
