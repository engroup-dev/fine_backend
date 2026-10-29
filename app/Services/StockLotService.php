<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientComponentStockException;
use App\Exceptions\LedgerRuleException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Support\InventoryAccounts;
use App\Support\TreasuryAccounts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StockLotService
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * Goods intake: purchased material arriving, or an opening balance.
     *
     * The one UI path by which quantities enter the system by hand — and it
     * enters properly: the lot is created, the INV-06 movement is recorded,
     * and the value reaches the ledger (ACC-02) according to where it came
     * from. "inventory" is the item type's account (INV-11, InventoryAccounts):
     *
     *   opening_balance  DR inventory / CR 3100 Retained Earnings
     *   purchase_cash    DR inventory / CR 1200 Cash and Bank
     *   purchase_credit  DR inventory / CR 2100 Accounts Payable
     *   import_receipt   no journal — PurchaseOrder.Complete already posts the
     *                    landed value; this only materialises the physical lots
     *
     * @param array{inventory_item_id: string, warehouse_id: string, lot_number?: string|null,
     *     quantity: float, unit_cost: float, source: string, purchase_order_id?: string|null,
     *     cash_account_id?: string|null,
     *     attribute_values?: array<string, mixed>|null} $data
     */
    public function intake(array $data): StockLot
    {
        return DB::transaction(function () use ($data) {
            $item = InventoryItem::findOrFail($data['inventory_item_id']);
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $attributeValues = $data['attribute_values'] ?? [];
            if (! is_array($attributeValues)) {
                $attributeValues = [];
            }

            $rawLot = trim((string) ($data['lot_number'] ?? ''));
            if ($rawLot === '') {
                $lotNumber = $this->generateUniqueLotNumber($item, $data['source'], $data['purchase_order_id'] ?? null);
            } else {
                [$lotNumber, $originalVendorLot] = $this->resolveUniqueLotNumber($rawLot, $item);
                if ($originalVendorLot !== null && ! isset($attributeValues['vendor_lot_number'])) {
                    $attributeValues['vendor_lot_number'] = $originalVendorLot;
                }
            }

            $capacity = null;
            if (isset($data['container_capacity']) && (float) $data['container_capacity'] > 0) {
                $capacity = (float) $data['container_capacity'];
            } elseif ($item->container_capacity && (float) $item->container_capacity > 0) {
                $capacity = (float) $item->container_capacity;
            }

            $containerQuantity = null;
            if (isset($data['container_quantity']) && (float) $data['container_quantity'] > 0) {
                $containerQuantity = round((float) $data['container_quantity'], 4);
            } elseif ($capacity !== null && $capacity > 0) {
                $containerQuantity = round((float) $data['quantity'] / $capacity, 4);
            } else {
                $containerQuantity = 1.0;
            }

            if ($capacity !== null && ! isset($attributeValues['container_capacity'])) {
                $attributeValues['container_capacity'] = round($capacity, 4);
            }

            $lot = StockLot::create([
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'lot_number' => $lotNumber,
                'quantity' => round((float) $data['quantity'], 4),
                'container_quantity' => $containerQuantity,
                'unit_cost' => round((float) $data['unit_cost'], 4),
                'attribute_values' => ! empty($attributeValues) ? $attributeValues : null,
                'status' => 'available',
            ]);

            $itemUpdates = [];
            if ($capacity !== null && (! empty($data['save_as_item_default']) || $item->container_capacity === null)) {
                $itemUpdates['container_capacity'] = round($capacity, 4);
            }
            if (! empty($data['primary_uom']) && empty($item->primary_uom)) {
                $itemUpdates['primary_uom'] = $data['primary_uom'];
            }
            if (! empty($data['secondary_uom']) && empty($item->secondary_uom)) {
                $itemUpdates['secondary_uom'] = $data['secondary_uom'];
            }
            if (! empty($itemUpdates)) {
                $item->update($itemUpdates);
            }

            $isImportReceipt = $data['source'] === 'import_receipt';

            InventoryMovement::create([
                'operating_unit_id' => $warehouse->operating_unit_id,
                'stock_lot_id' => $lot->id,
                'to_warehouse_id' => $warehouse->id,
                'sku' => $item->code,
                'movement_type' => 'intake',
                'quantity_delta' => round((float) $data['quantity'], 4),
                'unit_cost' => round((float) $data['unit_cost'], 4),
                'reason' => $data['source'],
                'reference_document_type' => $isImportReceipt && isset($data['purchase_order_id']) ? 'PurchaseOrder' : null,
                'reference_id' => $isImportReceipt ? ($data['purchase_order_id'] ?? null) : null,
            ]);

            $value = round((float) $data['quantity'] * (float) $data['unit_cost'], 4);

            if (! $isImportReceipt && $value > 0) {
                $creditAccount = match ($data['source']) {
                    'opening_balance' => '31', // Retained Earnings
                    'purchase_cash' => TreasuryAccounts::codeForId($data['cash_account_id'] ?? null),
                    'purchase_credit' => '21', // Accounts Payable
                    default => throw new InvalidArgumentException("Unknown intake source \"{$data['source']}\"."),
                };

                $memo = ! empty($item->primary_uom) && ! empty($item->secondary_uom)
                    ? "{$data['quantity']} {$item->secondary_uom} ({$containerQuantity} {$item->primary_uom}) × {$data['unit_cost']}"
                    : "{$data['quantity']} × {$data['unit_cost']}";

                $this->accountingService->postJournal(
                    "Stock intake: {$item->code} lot {$lotNumber} ({$data['source']})",
                    [
                        [
                            'account_code' => InventoryAccounts::forItem($item), // INV-11
                            'debit' => $value,
                            'operating_unit_id' => $warehouse->operating_unit_id,
                            'memo' => $memo,
                        ],
                        [
                            'account_code' => $creditAccount,
                            'credit' => $value,
                            'operating_unit_id' => $warehouse->operating_unit_id,
                        ],
                    ],
                    'StockLot',
                    $lot->id,
                    $warehouse->operatingUnit?->company_id,
                );
            }

            return $lot->load(['inventoryItem.category', 'warehouse']);
        });
    }

    /**
     * Build filtered StockLot query including dynamic JSON attribute filters.
     */
    public function getFilteredLots(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = StockLot::with(['inventoryItem.category', 'warehouse']);

        if (isset($filters['status']) && filled($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['grade']) && filled($filters['grade'])) {
            $query->where('grade', $filters['grade']);
        }

        if (isset($filters['warehouse_id']) && filled($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['production_batch_id']) && filled($filters['production_batch_id'])) {
            $query->where('production_batch_id', $filters['production_batch_id']);
        }

        if (isset($filters['inventory_item_id']) && filled($filters['inventory_item_id'])) {
            $query->where('inventory_item_id', $filters['inventory_item_id']);
        }

        if (isset($filters['category_id']) && filled($filters['category_id'])) {
            $query->whereHas('inventoryItem', function (Builder $b) use ($filters): void {
                $b->where('category_id', $filters['category_id']);
            });
        }

        if (isset($filters['block_type']) && filled($filters['block_type'])) {
            $query->where('block_type', $filters['block_type']);
        }

        // Apply dynamic JSON attribute filters: ?attrs[pressure_kpa][gte]=35
        if (isset($filters['attrs']) && is_array($filters['attrs'])) {
            foreach ($filters['attrs'] as $key => $condition) {
                $safeKey = str_replace("'", '', (string) $key);
                if (is_array($condition)) {
                    foreach ($condition as $op => $val) {
                        if ($val === null || $val === '') {
                            continue;
                        }
                        if ($op === 'gte') {
                            $query->whereRaw("CAST(json_extract(attribute_values, '$.{$safeKey}') AS NUMERIC) >= ?", [(float) $val]);
                        } elseif ($op === 'lte') {
                            $query->whereRaw("CAST(json_extract(attribute_values, '$.{$safeKey}') AS NUMERIC) <= ?", [(float) $val]);
                        } elseif ($op === 'eq') {
                            $query->whereRaw("json_extract(attribute_values, '$.{$safeKey}') = ?", [$val]);
                        }
                    }
                } else {
                    if ($condition !== null && $condition !== '') {
                        $query->whereRaw("json_extract(attribute_values, '$.{$safeKey}') = ?", [$condition]);
                    }
                }
            }
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Draw a measured amount out of a lot, recovering any container it empties.
     *
     * `quantity` and `container_quantity` drift apart on purpose. Taking 15L from
     * a lot of five full 40L barrels leaves 185L across *still five* barrels — one
     * of them open. Deriving the container count from the volume would report
     * 4.625 barrels, which is meaningless: the open barrel still occupies floor
     * space and still has to be counted.
     *
     * The container count is therefore stored, not computed. This updates it using
     * ceil(quantity / capacity) as the best estimate during an automated draw, but
     * the column stays authoritative so a physical recount or a damaged container
     * sticks.
     *
     * @return array{lot: StockLot, emptied: int, drawn: float}
     */
    public function drawFromLot(
        StockLot $lot,
        float $drawQuantity,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): array {
        if ($drawQuantity <= 0) {
            throw new InvalidArgumentException('Draw quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($lot, $drawQuantity, $reason, $referenceType, $referenceId): array {
            $locked = StockLot::whereKey($lot->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'available') {
                throw new InvalidArgumentException(
                    "Lot {$locked->lot_number} is {$locked->status} and cannot be drawn from."
                );
            }

            $onHand = (float) $locked->quantity;

            if ($drawQuantity > $onHand) {
                throw new InvalidArgumentException(
                    "Cannot draw {$drawQuantity} from lot {$locked->lot_number}; only {$onHand} on hand."
                );
            }

            $item = $locked->inventoryItem;
            $containersBefore = (int) ceil((float) $locked->container_quantity);
            $quantityAfter = round($onHand - $drawQuantity, 4);

            $containersAfter = $containersBefore;

            if ($item !== null && $item->tracksContainers()) {
                $capacity = (float) $item->container_capacity;
                $containersAfter = (int) ceil($quantityAfter / $capacity);
            }

            $emptied = max(0, $containersBefore - $containersAfter);

            $locked->quantity = $quantityAfter;
            $locked->container_quantity = $containersAfter;

            if ($quantityAfter === 0.0) {
                $locked->status = 'consumed';
            }

            $locked->save();

            $unitId = $locked->warehouse?->operating_unit_id;

            InventoryMovement::create([
                'operating_unit_id' => $unitId,
                'stock_lot_id' => $locked->id,
                'from_warehouse_id' => $locked->warehouse_id,
                'sku' => $item?->code ?? 'ITEM',
                'movement_type' => 'issue',
                'quantity_delta' => -$drawQuantity,
                'unit_cost' => (float) $locked->unit_cost,
                'reason' => $reason,
                'reference_document_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Empties are physical assets that come back off the floor, so they
            // re-enter stock rather than vanishing. They arrive at zero cost: the
            // barrel's cost was already carried by the chemical it held.
            if ($emptied > 0 && $item?->empty_container_item_id !== null) {
                $emptyLot = StockLot::create([
                    'inventory_item_id' => $item->empty_container_item_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'lot_number' => 'EMPTY-'.$locked->lot_number.'-'.now()->format('YmdHis').'-'.$emptied,
                    'quantity' => $emptied,
                    'container_quantity' => $emptied,
                    'unit_cost' => 0,
                    'status' => 'available',
                ]);

                InventoryMovement::create([
                    'operating_unit_id' => $unitId,
                    'stock_lot_id' => $emptyLot->id,
                    'to_warehouse_id' => $locked->warehouse_id,
                    'sku' => $item->emptyContainerItem?->code ?? 'EMPTY-CONTAINER',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $emptied,
                    'unit_cost' => 0,
                    'reason' => 'empty_container_recovered',
                    'reference_document_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }

            return [
                'lot' => $locked->fresh(['inventoryItem', 'warehouse']),
                'emptied' => $emptied,
                'drawn' => $drawQuantity,
            ];
        });
    }

    /**
     * Query available serialized foam blocks for cutter selection based on minimum volume and grade.
     */
    public function getAvailableForCutting(
        ?float $minVolumeM3 = null,
        ?string $grade = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = StockLot::with(['inventoryItem.category', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', function (Builder $b): void {
                $b->where('item_type', 'foam_block');
            });

        if ($minVolumeM3 !== null && $minVolumeM3 > 0) {
            $query->where('volume_m3', '>=', $minVolumeM3);
        }

        if ($grade !== null && $grade !== '') {
            $query->where('grade', $grade);
        }

        return $query->orderBy('volume_m3', 'asc')->paginate($perPage);
    }

    /**
     * Available foam blocks for POS / showroom pick-and-pack. Filters by
     * inventory_item_id, grade, and minimum volume; excludes blocks already
     * earmarked for cutting.
     */
    public function getAvailableFoamBlocks(
        string $inventoryItemId,
        ?string $grade = null,
        ?float $minVolumeM3 = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = StockLot::with(['inventoryItem', 'warehouse'])
            ->where('status', 'available')
            ->where('inventory_item_id', $inventoryItemId)
            ->whereDoesntHave('cutterConsumption')
            ->orderBy('volume_m3', 'asc');

        if ($grade !== null && $grade !== '') {
            $query->where('grade', $grade);
        }

        if ($minVolumeM3 !== null && $minVolumeM3 > 0) {
            $query->where('volume_m3', '>=', $minVolumeM3);
        }

        return $query->paginate($perPage);
    }

    /**
     * Process cutter operator completion decision for a consumed foam block lot.
     * Option C: Restock remnant block with manually specified dimensions (L x W x H) OR convert to byproduct fill.
     */
    public function processCutRemnant(
        StockLot $parentLot,
        string $remnantAction,
        ?array $remnantDimensions = null,
        ?float $byproductWeightKg = null
    ): array {
        return DB::transaction(function () use ($parentLot, $remnantAction, $remnantDimensions, $byproductWeightKg) {
            // Lock the parent so the remnant revision counter cannot race.
            $parentLot = StockLot::whereKey($parentLot->getKey())->lockForUpdate()->firstOrFail();

            // Mark parent block lot as consumed
            $parentLot->update(['status' => 'consumed']);

            // INV-06: consuming the parent block is an inventory event and must
            // leave a movement, not just a status change.
            InventoryMovement::create([
                'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                'stock_lot_id' => $parentLot->id,
                'from_warehouse_id' => $parentLot->warehouse_id,
                'sku' => $parentLot->inventoryItem?->code ?? 'FOAM-BLOCK',
                'movement_type' => 'consumption',
                'quantity_delta' => -1,
                'unit_cost' => (float) $parentLot->unit_cost,
                'reason' => 'cutter_consumption',
                'reference_document_type' => 'StockLot',
                'reference_id' => $parentLot->id,
            ]);

            $remnantLot = null;
            $byproductMovement = null;

            if ($remnantAction === 'restock_remnant') {
                if (! $remnantDimensions || ! isset($remnantDimensions['length_m'], $remnantDimensions['width_m'], $remnantDimensions['height_m'])) {
                    throw new InvalidArgumentException('Remnant dimensions (length, width, height) are required when restocking remnant block.');
                }

                // Per-parent revision counter. The previous implementation appended
                // time(), which collides for two remnants cut in the same second.
                $revision = StockLot::withTrashed()
                    ->where('remnant_of_lot_id', $parentLot->id)
                    ->count() + 1;

                $remnantLot = StockLot::create([
                    'inventory_item_id' => $parentLot->inventory_item_id,
                    'warehouse_id' => $parentLot->warehouse_id,
                    'lot_number' => $parentLot->lot_number.'-R'.$revision,
                    'remnant_of_lot_id' => $parentLot->id,
                    'pressure' => $parentLot->pressure,
                    'quantity' => 1.0,
                    'length_m' => (float) $remnantDimensions['length_m'],
                    'width_m' => (float) $remnantDimensions['width_m'],
                    'height_m' => (float) $remnantDimensions['height_m'],
                    'unit_cost' => $parentLot->unit_cost,
                    'grade' => $parentLot->grade,
                    'status' => 'available',
                    'production_batch_id' => $parentLot->production_batch_id,
                ]);

                // The remnant is new stock, so it enters through a movement too.
                InventoryMovement::create([
                    'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                    'stock_lot_id' => $remnantLot->id,
                    'to_warehouse_id' => $remnantLot->warehouse_id,
                    'sku' => $parentLot->inventoryItem?->code ?? 'FOAM-BLOCK',
                    'movement_type' => 'production_output',
                    'quantity_delta' => 1,
                    'unit_cost' => (float) $remnantLot->unit_cost,
                    'reason' => 'cutter_remnant_restock',
                    'reference_document_type' => 'StockLot',
                    'reference_id' => $parentLot->id,
                ]);
            }

            if ($byproductWeightKg !== null && $byproductWeightKg > 0) {
                $byproductMovement = InventoryMovement::create([
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                    'to_warehouse_id' => $parentLot->warehouse_id,
                    'sku' => 'BYPRODUCT-FILL',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $byproductWeightKg,
                    'reason' => "Cutter byproduct fill from block {$parentLot->lot_number}",
                    'reference_document_type' => 'StockLot',
                    'reference_id' => $parentLot->id,
                ]);
            }

            return [
                'parent_lot' => $parentLot->fresh(['inventoryItem', 'warehouse']),
                'remnant_lot' => $remnantLot ? $remnantLot->fresh(['inventoryItem', 'warehouse']) : null,
                'byproduct_movement' => $byproductMovement,
            ];
        });
    }

    /**
     * Move stock of one item between two warehouses of the SAME operating
     * unit — never across units (that's `InternalRestockController`'s job,
     * a separate approval-gated flow that posts the value between the
     * units). The unit check is made here, not left to request validation:
     * `ExistsInCurrentUnit` passes anything when no unit is selected (a
     * company-wide role editing a draft without a unit header), and a lot
     * moved across units with no entry would leave each unit's lot accounts
     * off by its value (INV-11).
     *
     * Draws FIFO from available lots in the source warehouse (mirrors
     * `SalesOrderService::drawFromUnit`), splitting each lot drawn into a new
     * lot in the destination warehouse so per-lot cost/grade/dimensions are
     * preserved. Purely a physical move within one unit — no ledger entry.
     *
     * @return array{transferred_quantity: float, lots: array<int, StockLot>}
     */
    public function transferBetweenWarehouses(
        string $inventoryItemId,
        string $fromWarehouseId,
        string $toWarehouseId,
        float $quantity,
        ?string $reason = null,
        ?string $referenceDocumentType = null,
        ?string $referenceId = null,
    ): array {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses must be different.');
        }

        return DB::transaction(function () use ($inventoryItemId, $fromWarehouseId, $toWarehouseId, $quantity, $reason, $referenceDocumentType, $referenceId) {
            $item = InventoryItem::findOrFail($inventoryItemId);
            $fromWarehouse = Warehouse::findOrFail($fromWarehouseId);
            $toWarehouse = Warehouse::findOrFail($toWarehouseId);

            if ($fromWarehouse->operating_unit_id !== $toWarehouse->operating_unit_id) {
                throw new LedgerRuleException(
                    "{$fromWarehouse->name} and {$toWarehouse->name} belong to different operating units; move stock between units with an internal restock or an internal sale.",
                    'TRANSFER_CROSS_UNIT',
                    ['from_warehouse_id' => $fromWarehouse->id, 'to_warehouse_id' => $toWarehouse->id],
                );
            }

            $lots = StockLot::where('inventory_item_id', $item->id)
                ->where('warehouse_id', $fromWarehouse->id)
                ->where('status', 'available')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $available = (float) $lots->sum('quantity');

            if ($available < $quantity) {
                throw new InsufficientComponentStockException(
                    "Stock cannot cover the transfer — {$item->code}: need {$quantity}, have {$available} in {$fromWarehouse->name}."
                );
            }

            $remaining = $quantity;
            $newLots = [];

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min((float) $lot->quantity, $remaining);
                $remaining = round($remaining - $take, 4);

                $lot->quantity = round((float) $lot->quantity - $take, 4);
                if ((float) $lot->quantity <= 0) {
                    $lot->quantity = 0;
                    $lot->status = 'consumed';
                }
                $lot->save();

                InventoryMovement::create([
                    'operating_unit_id' => $fromWarehouse->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'from_warehouse_id' => $fromWarehouse->id,
                    'sku' => $item->code,
                    'movement_type' => 'transfer',
                    'quantity_delta' => -$take,
                    'unit_cost' => (float) $lot->unit_cost,
                    'reason' => $reason ?? 'internal_warehouse_transfer',
                    'reference_document_type' => $referenceDocumentType,
                    'reference_id' => $referenceId,
                ]);

                [$lotNumber] = $this->resolveUniqueLotNumber($lot->lot_number.'-XFER');

                $newLot = StockLot::create([
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $toWarehouse->id,
                    'lot_number' => $lotNumber,
                    'quantity' => $take,
                    'length_m' => $lot->length_m,
                    'width_m' => $lot->width_m,
                    'height_m' => $lot->height_m,
                    'weight_kg' => $lot->weight_kg,
                    'unit_cost' => $lot->unit_cost,
                    'grade' => $lot->grade,
                    'block_type' => $lot->block_type,
                    'attribute_values' => $lot->attribute_values,
                    'status' => 'available',
                ]);

                InventoryMovement::create([
                    'operating_unit_id' => $toWarehouse->operating_unit_id,
                    'stock_lot_id' => $newLot->id,
                    'to_warehouse_id' => $toWarehouse->id,
                    'sku' => $item->code,
                    'movement_type' => 'transfer',
                    'quantity_delta' => $take,
                    'unit_cost' => (float) $newLot->unit_cost,
                    'reason' => $reason ?? 'internal_warehouse_transfer',
                    'reference_document_type' => $referenceDocumentType,
                    'reference_id' => $referenceId,
                ]);

                $newLots[] = $newLot->fresh(['inventoryItem', 'warehouse']);
            }

            return [
                'transferred_quantity' => $quantity,
                'lots' => $newLots,
            ];
        });
    }

    /**
     * Lot numbers are unique across every unit (and soft-deleted lots keep
     * theirs), so the probes skip the unit scope and SoftDeletes alike.
     */
    public function generateUniqueLotNumber(InventoryItem $item, string $source, ?string $purchaseOrderId = null): string
    {
        $skuPart = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $item->code) ?: 'ITEM';
        $datePart = now()->format('Ymd');

        if ($source === 'import_receipt' && $purchaseOrderId) {
            $order = PurchaseOrder::find($purchaseOrderId);
            $orderRef = $order?->order_number ?? substr($purchaseOrderId, 0, 8);
            $base = "IMP-{$orderRef}-{$skuPart}";
        } else {
            $base = "LOT-{$skuPart}-{$datePart}";
        }

        $candidate = "{$base}-01";
        $seq = 1;
        while (StockLot::withoutGlobalScopes()->where('lot_number', $candidate)->exists()) {
            $seq++;
            $candidate = sprintf('%s-%02d', $base, $seq);
        }

        return $candidate;
    }

    /**
     * @return array{0: string, 1: string|null} [resolvedLotNumber, originalVendorLot]
     */
    public function resolveUniqueLotNumber(string $lotNumber, ?InventoryItem $item = null): array
    {
        $raw = trim($lotNumber);
        if ($raw === '') {
            return [$this->generateUniqueLotNumber($item ?? new InventoryItem, 'default'), null];
        }

        if (! StockLot::withoutGlobalScopes()->where('lot_number', $raw)->exists()) {
            return [$raw, null];
        }

        // Collision: auto-suffix and preserve original vendor lot
        $seq = 1;
        $candidate = sprintf('%s-%02d', $raw, $seq);
        while (StockLot::withoutGlobalScopes()->where('lot_number', $candidate)->exists()) {
            $seq++;
            $candidate = sprintf('%s-%02d', $raw, $seq);
        }

        return [$candidate, $raw];
    }
}
