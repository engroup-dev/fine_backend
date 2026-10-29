<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Exceptions\LedgerRuleException;
use App\Http\Controllers\Controller;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\StockLotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockLotController extends Controller
{
    public function __construct(public StockLotService $stockLotService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'grade', 'warehouse_id', 'category_id',
            'production_batch_id', 'inventory_item_id', 'attrs', 'block_type',
        ]);
        $perPage = $request->integer('per_page', 15);

        $lots = $this->stockLotService->getFilteredLots($filters, $perPage);

        return response()->json($lots);
    }

    public function availableForCutting(Request $request): JsonResponse
    {
        $minVolume = $request->has('min_volume_m3') ? (float) $request->query('min_volume_m3') : null;
        $grade = $request->query('grade');
        $perPage = $request->integer('per_page', 15);

        $blocks = $this->stockLotService->getAvailableForCutting($minVolume, $grade, $perPage);

        return response()->json($blocks);
    }

    /**
     * Available foam blocks for a specific inventory item, scoped to the
     * caller's operating unit. Drives the POS / showroom block picker.
     */
    public function availableFoamBlocks(Request $request): JsonResponse
    {
        $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'grade' => ['nullable', 'string', 'in:standard,acceptable_variant,defective_usable,reject'],
            'min_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $blocks = $this->stockLotService->getAvailableFoamBlocks(
            (string) $request->query('inventory_item_id'),
            $request->query('grade'),
            $request->has('min_volume_m3') ? (float) $request->query('min_volume_m3') : null,
            $request->integer('per_page', 25),
        );

        return response()->json($blocks);
    }

    /**
     * Goods intake — the only manual path by which quantities enter stock.
     * It records the INV-06 movement and posts the value to the ledger
     * according to its source.
     */
    public function intake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'warehouse_id' => ['required', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'lot_number' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'container_quantity' => ['nullable', 'numeric', 'min:0'],
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'primary_uom' => ['nullable', 'string', 'max:50'],
            'secondary_uom' => ['nullable', 'string', 'max:50'],
            'save_as_item_default' => ['nullable', 'boolean'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'in:opening_balance,purchase_cash,purchase_credit,import_receipt'],
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id', 'required_if:source,import_receipt'],
            'cash_account_id' => ['nullable', 'uuid', 'exists:cash_accounts,id', 'required_if:source,purchase_cash'],
            'attribute_values' => ['nullable', 'array'],
        ]);

        $lot = $this->stockLotService->intake($validated);

        return response()->json($lot, 201);
    }

    public function show(string $id): JsonResponse
    {
        $stockLot = StockLot::with(['inventoryItem.category', 'warehouse'])->findOrFail($id);

        return response()->json($stockLot);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $stockLot = StockLot::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => ['prohibited'],
            'quantity' => ['prohibited'],
            'unit_cost' => ['prohibited'],
            'container_quantity' => ['nullable', 'numeric', 'min:0'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'pressure' => ['nullable', 'integer', 'min:1'],
            'block_type' => ['nullable', 'string', 'in:block,separator,head,scrap'],
            'grade' => ['sometimes', 'string', 'in:standard,acceptable_variant,defective_usable,reject'],
            'status' => ['sometimes', 'string', 'in:available,reserved,consumed,quarantined'],
            'attribute_values' => ['nullable', 'array'],
            'record_version' => ['required', 'integer'],
        ], [
            'warehouse_id.prohibited' => 'Moving a lot to another warehouse must go through the transfer endpoint, so an inventory movement is recorded.',
            'quantity.prohibited' => 'Stock quantity cannot be modified directly via update; use inventory movements or stock adjustments.',
            'unit_cost.prohibited' => 'Stock unit cost cannot be modified directly via update.',
        ]);

        $this->ensureStatusChangeAllowed($stockLot, $validated['status'] ?? null);

        $stockLot->update($validated);

        return response()->json($stockLot->load(['inventoryItem.category', 'warehouse']));
    }

    /**
     * INV-06 (W4-01): a lot's status may only move between available and
     * quarantined here. Reserving, consuming or releasing a lot moves stock
     * or value, so it happens only in the flows that record a movement and
     * post the journal (cutter orders, sales, stock counts).
     *
     * @throws LedgerRuleException
     */
    private function ensureStatusChangeAllowed(StockLot $stockLot, ?string $status): void
    {
        if ($status === null || $status === $stockLot->status) {
            return;
        }

        $editable = ['available', 'quarantined'];

        if (! in_array($stockLot->status, $editable, true) || ! in_array($status, $editable, true)) {
            throw new LedgerRuleException(
                "A lot's status can only be switched between available and quarantined here; {$stockLot->status} → {$status} must go through its own flow.",
                'LOT_STATUS_CHANGE_NOT_ALLOWED',
                ['from' => $stockLot->status, 'to' => $status],
            );
        }
    }

    public function processCutRemnant(Request $request, string $id): JsonResponse
    {
        $parentLot = StockLot::with(['inventoryItem.category', 'warehouse'])->findOrFail($id);

        $validated = $request->validate([
            'remnant_action' => ['required', 'string', 'in:restock_remnant,convert_to_byproduct'],
            'remnant_dimensions' => ['required_if:remnant_action,restock_remnant', 'array'],
            'remnant_dimensions.length_m' => ['required_if:remnant_action,restock_remnant', 'numeric', 'min:0.01'],
            'remnant_dimensions.width_m' => ['required_if:remnant_action,restock_remnant', 'numeric', 'min:0.01'],
            'remnant_dimensions.height_m' => ['required_if:remnant_action,restock_remnant', 'numeric', 'min:0.01'],
            'byproduct_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->stockLotService->processCutRemnant(
            $parentLot,
            $validated['remnant_action'],
            $validated['remnant_dimensions'] ?? null,
            isset($validated['byproduct_weight_kg']) ? (float) $validated['byproduct_weight_kg'] : null
        );

        return response()->json($result);
    }
}
