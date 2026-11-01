<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\RefusesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Models\OperatingUnit;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Support\CodedValidation;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    use RefusesUniqueViolations;

    /** W6-06: identifies the per-(unit, parent) name indexes in a violation. */
    private const NAME_INDEX_MARKERS = ['warehouses_unit_top_level_name_unique', 'warehouses_unit_parent_name_unique', 'warehouses.name'];

    /** W6-06: the refusal code for a name a sibling warehouse already has. */
    public const NAME_TAKEN_CODE = 'WAREHOUSE_NAME_TAKEN';

    public function __construct(public CurrentUnitContext $unitContext) {}

    /**
     * Warehouses available to the current operating unit.
     *
     * Scoping is handled by the BelongsToOperatingUnit global scope on the model,
     * so no explicit unit filter is applied here.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Warehouse::whereNull('parent_id')->with('children')->orderBy('name')->get()
        );
    }

    /**
     * Warehouses belonging to a specific operating unit (admin view).
     */
    public function forOperatingUnit(string $operatingUnitId): JsonResponse
    {
        $unit = OperatingUnit::withTrashed()->findOrFail($operatingUnitId);

        $warehouses = Warehouse::withoutGlobalScopes()
            ->where('operating_unit_id', $unit->id)
            ->orderBy('name')
            ->get();

        return response()->json($warehouses);
    }

    /**
     * Create a warehouse directly for a specific operating unit (admin action).
     */
    public function storeForOperatingUnit(Request $request, string $operatingUnitId): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($operatingUnitId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        $this->assertNameAvailable($validated['name'], $unit->id, null);

        $warehouse = $this->refuseUniqueViolation(fn () => Warehouse::create([
            'operating_unit_id' => $unit->id,
            'name' => $validated['name'],
            'is_internal_unit' => $validated['is_internal_unit'] ?? false,
        ]), self::NAME_INDEX_MARKERS, 'name', self::nameTakenMessage(null), self::NAME_TAKEN_CODE);

        return response()->json($warehouse, 201);
    }

    public function store(Request $request): JsonResponse
    {
        // W6-06: the unit comes from the X-Operating-Unit-ID context (or the
        // parent), never from the body. A body operating_unit_id is refused
        // rather than ignored, so a stale client gets a clear error instead
        // of a warehouse silently created in a different unit.
        $validated = $request->validate([
            'operating_unit_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'is_internal_unit' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'parent warehouse')],
            'location_type' => ['nullable', 'string', 'max:32'],
        ], [
            'operating_unit_id.prohibited' => 'Send the operating unit in the X-Operating-Unit-ID header, not in the request body.',
        ]);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = Warehouse::withoutGlobalScopes()->findOrFail($validated['parent_id']);

            if ($parent->parent_id !== null) {
                return response()->json([
                    'message' => 'A sub-warehouse cannot itself have sub-warehouses.',
                    'code' => 'NESTED_SUB_WAREHOUSE_NOT_ALLOWED',
                ], 422);
            }
        }

        // A sub-warehouse always inherits its parent's unit — it is never
        // independently assigned one.
        $unitId = $parent?->operating_unit_id ?? $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit (X-Operating-Unit-ID) before creating a warehouse.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $this->assertNameAvailable($validated['name'], $unitId, $parent?->id);

        $warehouse = $this->refuseUniqueViolation(fn () => Warehouse::create([
            'operating_unit_id' => $unitId,
            'parent_id' => $parent?->id,
            'name' => $validated['name'],
            'is_internal_unit' => $validated['is_internal_unit'] ?? false,
            'location_type' => $validated['location_type'] ?? null,
        ]), self::NAME_INDEX_MARKERS, 'name', self::nameTakenMessage($parent?->id), self::NAME_TAKEN_CODE);

        return response()->json($warehouse, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Warehouse::withoutGlobalScopes()->with('operatingUnit')->findOrFail($id));
    }

    /**
     * What's currently in this warehouse, grouped by item, with a weighted
     * average unit cost across its available lots.
     */
    public function stockSummary(string $id): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);

        $rows = StockLot::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_lots.inventory_item_id')
            ->where('stock_lots.warehouse_id', $warehouse->id)
            ->where('stock_lots.status', 'available')
            ->groupBy(
                'stock_lots.inventory_item_id',
                'inventory_items.name',
                'inventory_items.code',
                'inventory_items.unit_of_measure',
            )
            ->selectRaw('stock_lots.inventory_item_id as inventory_item_id')
            ->selectRaw('inventory_items.name as item_name')
            ->selectRaw('inventory_items.code as item_code')
            ->selectRaw('inventory_items.unit_of_measure as uom')
            ->selectRaw('SUM(stock_lots.quantity) as total_quantity')
            ->selectRaw('SUM(stock_lots.quantity * stock_lots.unit_cost) as total_value')
            ->selectRaw('COUNT(*) as lots_count')
            ->orderBy('inventory_items.name')
            ->get()
            ->map(function ($row): array {
                $totalQuantity = (float) $row->total_quantity;
                $totalValue = round((float) $row->total_value, 4);

                return [
                    'inventory_item_id' => $row->inventory_item_id,
                    'item_name' => $row->item_name,
                    'item_code' => $row->item_code,
                    'uom' => $row->uom,
                    'total_quantity' => $totalQuantity,
                    'avg_unit_cost' => $totalQuantity > 0 ? round($totalValue / $totalQuantity, 4) : 0.0,
                    'total_value' => $totalValue,
                    'lots_count' => (int) $row->lots_count,
                ];
            });

        return response()->json([
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'rows' => $rows,
            'total_value' => round((float) $rows->sum('total_value'), 4),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_internal_unit' => ['nullable', 'boolean'],
            'location_type' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        if (isset($validated['name']) && $validated['name'] !== $warehouse->name) {
            $this->assertNameAvailable($validated['name'], $warehouse->operating_unit_id, $warehouse->parent_id, $warehouse->id);
        }

        $this->refuseUniqueViolation(
            fn () => $warehouse->update($validated),
            self::NAME_INDEX_MARKERS,
            'name',
            self::nameTakenMessage($warehouse->parent_id),
            self::NAME_TAKEN_CODE,
        );

        return response()->json($warehouse);
    }

    /**
     * W6-06: a name is unique among the warehouses that share its unit and
     * parent (top-level warehouses per unit, sub-warehouses per parent),
     * matching the partial unique indexes on the table.
     *
     * @throws ValidationException (code WAREHOUSE_NAME_TAKEN) when a sibling already has the name.
     */
    private function assertNameAvailable(string $name, string $operatingUnitId, ?string $parentId, ?string $ignoreId = null): void
    {
        $taken = Warehouse::withoutGlobalScopes()
            ->where('operating_unit_id', $operatingUnitId)
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw CodedValidation::exception(self::NAME_TAKEN_CODE, ['name' => self::nameTakenMessage($parentId)]);
        }
    }

    private static function nameTakenMessage(?string $parentId): string
    {
        return $parentId === null
            ? 'A warehouse with this name already exists in this operating unit.'
            : 'A sub-warehouse with this name already exists under this warehouse.';
    }

    public function destroy(string $id): JsonResponse
    {
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($id);

        // Sub-warehouses must be removed before their parent.
        if ($warehouse->children()->exists()) {
            return response()->json([
                'message' => 'Delete this warehouse\'s sub-warehouses first.',
                'code' => 'WAREHOUSE_HAS_CHILDREN',
            ], 422);
        }

        // Stock lots are located by warehouse; removing one out from under live
        // stock would orphan it.
        if (StockLot::where('warehouse_id', $warehouse->id)->exists()) {
            return response()->json([
                'message' => 'Cannot delete a warehouse that still holds stock lots.',
                'code' => 'WAREHOUSE_NOT_EMPTY',
            ], 422);
        }

        // An operating unit must retain at least one top-level warehouse for
        // operational workflows — sub-warehouses don't count toward this, and
        // deleting one never trips this guard.
        if ($warehouse->parent_id === null) {
            $totalWarehouses = Warehouse::withoutGlobalScopes()
                ->where('operating_unit_id', $warehouse->operating_unit_id)
                ->whereNull('parent_id')
                ->count();

            if ($totalWarehouses <= 1) {
                return response()->json([
                    'message' => 'Cannot delete the only warehouse of an operating unit.',
                    'code' => 'CANNOT_DELETE_LAST_WAREHOUSE',
                ], 422);
            }
        }

        $warehouse->delete();

        return response()->json(['message' => 'Warehouse deleted.']);
    }
}
