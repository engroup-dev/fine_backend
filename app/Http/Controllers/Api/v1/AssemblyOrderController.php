<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AssemblyOrder;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\AssemblyOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Furniture assembly orders (W4-07, decision #12): list, open (from a bundle
 * template or by hand), show with its cost breakdown, complete or cancel.
 */
final class AssemblyOrderController extends Controller
{
    public function __construct(private readonly AssemblyOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssemblyOrder::with([
            'finishedItem:id,name,code,item_type,unit_of_measure',
            'outputWarehouse:id,name',
            'bundle:id,name',
            'finishedStockLot:id,lot_number',
            'createdBy:id,name',
            'completedBy:id,name',
        ])->withCount('lines')->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('finished_item_id'))) {
            $query->where('finished_item_id', $request->query('finished_item_id'));
        }

        if (filled($request->query('from'))) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        if (filled($request->query('search'))) {
            $query->where('number', 'like', '%'.$request->query('search').'%');
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'finished_item_id' => ['required', 'uuid', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'output_warehouse_id' => ['required', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'bundle_id' => ['nullable', 'uuid', Rule::exists('bundles', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required_without:bundle_id', 'array'],
            'lines.*.inventory_item_id' => ['required', 'uuid', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.stock_lot_id' => ['nullable', 'uuid'],
            // A size is all three dimensions or none: a partial size would be dropped and draw any piece.
            'lines.*.length_m' => ['nullable', 'numeric', 'gt:0', 'required_with:lines.*.width_m,lines.*.height_m'],
            'lines.*.width_m' => ['nullable', 'numeric', 'gt:0', 'required_with:lines.*.length_m,lines.*.height_m'],
            'lines.*.height_m' => ['nullable', 'numeric', 'gt:0', 'required_with:lines.*.length_m,lines.*.width_m'],
        ]);

        $order = $this->orders->create($validated, $request->user());

        return response()->json($this->orders->present($order->fresh()), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->orders->present(AssemblyOrder::findOrFail($id)));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['record_version' => ['sometimes', 'integer']]);

        $order = $this->orders->complete(AssemblyOrder::findOrFail($id), $request->user(), $validated['record_version'] ?? null);

        return response()->json($this->orders->present($order->fresh()));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['record_version' => ['sometimes', 'integer']]);

        $order = $this->orders->cancel(AssemblyOrder::findOrFail($id), $validated['record_version'] ?? null);

        return response()->json($this->orders->present($order->fresh()));
    }
}
