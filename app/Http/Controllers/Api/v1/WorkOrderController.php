<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\StoreWorkOrderRequest;
use App\Http\Requests\Api\v1\UpdateWorkOrderRequest;
use App\Models\WorkOrder;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WorkOrderController extends Controller
{
    /**
     * Display a listing of work orders.
     */
    public function index(Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

        $query = WorkOrder::query();

        if ($unitId) {
            $query->where('operating_unit_id', $unitId);
        }

        // Support filtering by status parameter (e.g. ?status=open or ?status=pending)
        if ($request->has('status') && filled($request->query('status'))) {
            $status = (string) $request->query('status');
            if ($status === 'open') {
                $query->whereIn('status', ['open', 'pending', 'draft']);
            } else {
                $query->where('status', $status);
            }
        }

        $workOrders = $query->latest()->paginate();

        return response()->json($workOrders);
    }

    /**
     * Store a newly created work order.
     */
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $unitId = $validated['operating_unit_id']
            ?? $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

        if (! $unitId) {
            return response()->json(['message' => 'Operating Unit context header or body field is required.'], 422);
        }

        $workOrder = WorkOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unitId,
            'product_sku' => $validated['product_sku'],
            'quantity' => $validated['quantity'],
            'status' => $validated['status'],
        ]);

        return response()->json($workOrder, 201);
    }

    /**
     * Display the specified work order.
     */
    public function show(string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        return response()->json($workOrder);
    }

    /**
     * Update the specified work order.
     */
    public function update(UpdateWorkOrderRequest $request, string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $workOrder->update($request->validated());

        return response()->json($workOrder);
    }

    /**
     * Remove the specified work order.
     */
    public function destroy(string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);
        $workOrder->delete();

        return response()->json(['message' => 'Work order deleted successfully.']);
    }
}
