<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\CutterWorkOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderLine;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\CutterWorkOrderService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CutterWorkOrderController extends Controller
{
    public function __construct(
        public CutterWorkOrderService $cutterService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = CutterWorkOrder::with(['client', 'lines', 'blocks.stockLot'])
            ->withCount('lines')
            ->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if ($request->boolean('internal_only')) {
            $query->whereNull('client_id');
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Optional: the server numbers the order (CWO-2026-00001) when omitted.
            'order_number' => ['nullable', 'string', 'max:100', 'unique:cutter_work_orders,order_number'],
            // Null means an internal order from another unit (no credit check).
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'notes' => ['nullable', 'string'],
            // CUT-block-sale: the precut block the customer is buying. Selected
            // at creation; price + dimensions are locked in at this moment.
            'stock_lot_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(StockLot::class, 'stock lot')],
            // W4-06: where the cut pieces, remnants and fill land (a warehouse
            // of the order's unit); the first block's warehouse when omitted.
            'output_warehouse_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a cutter work order.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $block = $validated['stock_lot_id'] ?? null
            ? StockLot::with('inventoryItem')->findOrFail($validated['stock_lot_id'])
            : null;

        try {
            $order = $this->cutterService->createWithBlock(
                [...array_filter($validated, fn ($value) => $value !== null), 'operating_unit_id' => $unitId],
                $block,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_BLOCK_STATE',
            ], 422);
        }

        return response()->json(
            $order->load(['client', 'lines', 'blocks.stockLot']),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            CutterWorkOrder::with([
                'client',
                'lines.consumptions.stockLot',
                'lines.outputItem',
                // The sale a line is cut for lives in the selling unit — read it past the cutter's own scope.
                'lines.saleComponent.line.salesOrder' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'order_number', 'client_id', 'operating_unit_id']),
                'blocks.stockLot',
                'byproductYields',
            ])->findOrFail($id)
        );
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(CutterWorkOrderStatus::class)],
            // W4-06: usable remnants are registered with the completion, each
            // optionally naming the cut block it came from (else the first).
            'remnants' => ['nullable', 'array', 'max:50', 'prohibited_unless:status,'.CutterWorkOrderStatus::Completed->value],
            'remnants.*.length_m' => ['required', 'numeric', 'min:0.01'],
            'remnants.*.width_m' => ['required', 'numeric', 'min:0.01'],
            'remnants.*.height_m' => ['required', 'numeric', 'min:0.01'],
            'remnants.*.stock_lot_id' => ['nullable', 'uuid'],
            'output_warehouse_id' => ['nullable', 'uuid', 'prohibited_unless:status,'.CutterWorkOrderStatus::Completed->value, new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
        ]);

        $updated = $this->cutterService->transition(
            $order,
            CutterWorkOrderStatus::from($validated['status']),
            array_values($validated['remnants'] ?? []),
            $validated['output_warehouse_id'] ?? null,
        );

        return response()->json($updated->load(['client', 'lines', 'byproductYields']));
    }

    public function storeLine(Request $request, string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);
        $this->ensureLinesEditable($order);

        $validated = $request->validate([
            'requested_spec' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'output_inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
        ]);

        $line = $order->lines()->create($validated);

        return response()->json($line, 201);
    }

    /**
     * Assign the bounding box that will actually be cut (CUT-01).
     */
    public function assignTemplate(Request $request, string $lineId): JsonResponse
    {
        $line = CutterWorkOrderLine::whereHas('cutterWorkOrder')->with('cutterWorkOrder')->findOrFail($lineId);
        $this->ensureLinesEditable($line->cutterWorkOrder);

        $validated = $request->validate([
            'template_length_m' => ['required', 'numeric', 'gt:0'],
            'template_width_m' => ['required', 'numeric', 'gt:0'],
            'template_height_m' => ['required', 'numeric', 'gt:0'],
            'output_inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
        ]);

        $line->update($validated);

        return response()->json($line->fresh());
    }

    /**
     * Blocks the manager may pick from. Filtered, never chosen (CUT-02).
     */
    public function availableBlocks(Request $request, string $lineId): JsonResponse
    {
        $line = CutterWorkOrderLine::findOrFail($lineId);

        return response()->json(
            $this->cutterService->availableBlocksFor($line, $request->integer('per_page', 25))
        );
    }

    public function selectBlock(Request $request, string $lineId): JsonResponse
    {
        $line = CutterWorkOrderLine::with('cutterWorkOrder')->findOrFail($lineId);

        $validated = $request->validate([
            'stock_lot_id' => ['required', 'uuid', new ExistsInCurrentUnit(StockLot::class, 'stock lot')],
        ]);

        $block = StockLot::with('inventoryItem')->findOrFail($validated['stock_lot_id']);

        $consumption = $this->cutterService->selectBlock($line, $block);

        return response()->json($consumption->load('stockLot'), 201);
    }

    /**
     * CUT-04: a weight is always required. Zero says someone looked and found
     * nothing; an absent record says nobody checked.
     */
    public function recordWeighIn(Request $request, string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);

        $validated = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:0'],
            // Only a byproduct fill item may receive the fill lot; its share books to 1133.
            'byproduct_inventory_item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('item_type', 'byproduct_fill')->whereNull('deleted_at')],
            'warehouse_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
        ]);

        $yield = $this->cutterService->recordWeighIn(
            $order,
            (float) $validated['weight_kg'],
            $validated['byproduct_inventory_item_id'] ?? null,
            $validated['warehouse_id'] ?? null,
            $request->user(),
        );

        return response()->json($yield->load('stockLot'), 201);
    }

    public function byproductYields(string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);

        return response()->json($order->byproductYields()->with(['stockLot', 'weighedBy'])->get());
    }

    /**
     * CUT-block-sale: list of available foam blocks for the create-order picker.
     * Distinct from the per-line availableBlocks endpoint, which sizes results
     * against a template — here we just need a filterable catalog.
     */
    public function availableFoamBlocks(Request $request): JsonResponse
    {
        return response()->json(
            $this->cutterService->availableFoamBlocks($request->integer('per_page', 50))
        );
    }

    public function attachBlock(Request $request, string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);

        $validated = $request->validate([
            'stock_lot_id' => ['required', 'uuid', new ExistsInCurrentUnit(StockLot::class, 'stock lot')],
        ]);

        $block = StockLot::with('inventoryItem')->findOrFail($validated['stock_lot_id']);

        try {
            $updated = $this->cutterService->attachBlock($order, $block);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_BLOCK_STATE',
            ], 422);
        }

        return response()->json($updated);
    }

    /**
     * Release a reserved block. With several blocks attached, name it by
     * stock_lot_id.
     */
    public function detachBlock(Request $request, string $id): JsonResponse
    {
        $order = CutterWorkOrder::findOrFail($id);

        $validated = $request->validate([
            'stock_lot_id' => ['nullable', 'uuid'],
        ]);

        try {
            $updated = $this->cutterService->detachBlock($order, $validated['stock_lot_id'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'BLOCK_REQUIRED',
            ], 422);
        }

        return response()->json($updated);
    }

    /**
     * The cutter's job sheet — sizes and quantities, no prices.
     */
    public function jobSheet(string $id): JsonResponse
    {
        return response()->json($this->cutterService->jobSheet(CutterWorkOrder::findOrFail($id)));
    }

    /**
     * Lines are the cutting instructions; once production starts they are
     * what is being cut and cannot change.
     */
    private function ensureLinesEditable(CutterWorkOrder $order): void
    {
        if (! in_array($order->status, [CutterWorkOrderStatus::Requested, CutterWorkOrderStatus::Confirmed], true)) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} is {$order->status->value}; its lines can no longer change."
            );
        }
    }
}
