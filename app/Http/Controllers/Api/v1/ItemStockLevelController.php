<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ItemStockLevel;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\StockLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W4-02: per-warehouse min/max/reorder levels and bin of an item
 * (FR-STK-004, FR-STK-007), and the reorder report.
 */
final class ItemStockLevelController extends Controller
{
    public function __construct(private readonly StockLevelService $levels) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'inventory_item_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        $query = ItemStockLevel::with(['inventoryItem', 'warehouse']);

        if (filled($request->query('inventory_item_id'))) {
            $query->where('inventory_item_id', $request->query('inventory_item_id'));
        }

        if (filled($request->query('warehouse_id'))) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        return response()->json($query->orderBy('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'warehouse_id' => ['required', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            ...$this->levelRules(),
        ]);

        return response()->json($this->levels->create($validated), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(ItemStockLevel::with(['inventoryItem', 'warehouse'])->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $level = ItemStockLevel::findOrFail($id);

        $validated = $request->validate([
            'inventory_item_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            ...$this->levelRules(),
            'record_version' => ['required', 'integer'],
        ]);

        return response()->json($this->levels->update($level, $validated));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->levels->delete(ItemStockLevel::findOrFail($id));

        return response()->json(null, 204);
    }

    /**
     * GET /inventory/reorder-report: on hand against min/max per warehouse
     * and item, with the quantity to order (filters: warehouse, category).
     */
    public function reorderReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'category_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
        ]);

        return response()->json($this->levels->reorderReport($validated));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function levelRules(): array
    {
        return [
            'min_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'bin_location' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
