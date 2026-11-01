<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\MaterialRequestResource;
use App\Models\CutterWorkOrder;
use App\Models\MaterialRequest;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Services\MaterialResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class MaterialRequestController extends Controller
{
    public function __construct(private readonly MaterialResolutionService $materialResolution) {}

    /**
     * Newest first, paged. The id breaks created_at ties (rows made in the
     * same second), so walking the pages never skips or repeats a row (W6-04).
     */
    public function index(Request $request): JsonResponse
    {
        $query = MaterialRequest::with(['inventoryItem'])
            ->latest()
            ->orderByDesc('id');

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        if (filled($request->query('fulfilling_module'))) {
            $query->where('fulfilling_module', $request->query('fulfilling_module'));
        }
        if (filled($request->query('requested_for_type')) && filled($request->query('requested_for_id'))) {
            $query->where('requested_for_type', $request->query('requested_for_type'))
                ->where('requested_for_id', $request->query('requested_for_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            MaterialRequest::with('inventoryItem')->findOrFail($id)
        );
    }

    public function start(string $id): JsonResponse
    {
        $request = MaterialRequest::findOrFail($id);

        return response()->json(
            new MaterialRequestResource($this->materialResolution->markInProgress($request))
        );
    }

    public function fulfill(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'fulfilled_by_type' => ['required', 'string', 'in:cutter_work_order,production_batch,procurement_request'],
            'fulfilled_by_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string'],
        ]);

        $modelClass = match ($validated['fulfilled_by_type']) {
            'cutter_work_order' => CutterWorkOrder::class,
            'production_batch' => ProductionBatch::class,
            'procurement_request' => PurchaseOrder::class,
            default => throw new InvalidArgumentException('Unknown fulfilled_by_type'),
        };

        $fulfillingModel = $modelClass::findOrFail($validated['fulfilled_by_id']);
        $mr = MaterialRequest::findOrFail($id);

        return response()->json(
            new MaterialRequestResource($this->materialResolution->markFulfilled($mr, $fulfillingModel))
        );
    }

    public function cancel(string $id): JsonResponse
    {
        $request = MaterialRequest::findOrFail($id);

        return response()->json(
            new MaterialRequestResource($this->materialResolution->cancel($request))
        );
    }
}
