<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Physical stock counts (W4-01, decision #13): open, enter, add found stock,
 * submit, approve (approve-stock-counts) or cancel; plus the printable sheet.
 */
final class StockCountController extends Controller
{
    public function __construct(private readonly StockCountService $counts) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockCount::with([
            'warehouse:id,name',
            'category:id,name',
            'createdBy:id,name',
            'submittedBy:id,name',
            'approvedBy:id,name',
        ])->withCount('lines')->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('warehouse_id'))) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if (filled($request->query('from'))) {
            $query->whereDate('count_date', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('count_date', '<=', $request->query('to'));
        }

        if (filled($request->query('search'))) {
            $query->where('number', 'like', '%'.$request->query('search').'%');
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'item_category_id' => ['nullable', 'uuid', Rule::exists(ItemCategory::class, 'id')->whereNull('deleted_at')],
            'count_date' => ['nullable', 'date_format:Y-m-d'],
            'blind' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $count = $this->counts->create($validated, $request->user());

        return response()->json($this->counts->present($count->fresh()), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->counts->present(StockCount::findOrFail($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $count = StockCount::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => ['prohibited'],
            'item_category_id' => ['prohibited'],
            'count_date' => ['sometimes', 'date_format:Y-m-d'],
            'blind' => ['prohibited'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'record_version' => ['sometimes', 'integer'],
        ], [
            'warehouse_id.prohibited' => 'The warehouse of a count cannot change; cancel it and open a new count.',
            'item_category_id.prohibited' => 'The category of a count cannot change; cancel it and open a new count.',
            'blind.prohibited' => 'Blind mode is fixed when the count is opened; cancel it and open a new count.',
        ]);

        $count = $this->counts->update($count, $validated);

        return response()->json($this->counts->present($count->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->counts->delete(StockCount::findOrFail($id));

        return response()->json(['message' => 'Stock count deleted.']);
    }

    /**
     * Save counted quantities in bulk.
     */
    public function updateLines(Request $request, string $id): JsonResponse
    {
        $count = StockCount::findOrFail($id);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.counted_qty' => ['present', 'nullable', 'numeric', 'min:0'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $count = $this->counts->enter($count, $validated['lines'], $request->user());

        return response()->json($this->counts->present($count->fresh()));
    }

    /**
     * Add found stock (an item counted with no lot on record).
     */
    public function addFound(Request $request, string $id): JsonResponse
    {
        $count = StockCount::findOrFail($id);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'counted_qty' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->counts->addFound($count, $validated, $request->user());

        return response()->json($this->counts->present($count->fresh()), 201);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $count = $this->counts->submit(StockCount::findOrFail($id), $request->user());

        return response()->json($this->counts->present($count->fresh()));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $count = $this->counts->approve(StockCount::findOrFail($id), $request->user());

        return response()->json($this->counts->present($count->fresh()));
    }

    public function cancel(string $id): JsonResponse
    {
        $count = $this->counts->cancel(StockCount::findOrFail($id));

        return response()->json($this->counts->present($count->fresh()));
    }

    /**
     * Printable count sheet data.
     */
    public function sheet(string $id): JsonResponse
    {
        return response()->json($this->counts->sheet(StockCount::findOrFail($id)));
    }
}
