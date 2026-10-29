<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\ProductionBatchService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionBatchController extends Controller
{
    /**
     * W4-05: issued chemical rows with what the batch page shows per line.
     */
    private const MATERIAL_RELATIONS = [
        'materials',
        'materials.inventoryItem:id,code,name,unit_of_measure,primary_uom,container_capacity',
        'materials.stockLot:id,lot_number,warehouse_id',
        'materials.issuedBy:id,name',
    ];

    public function __construct(
        public ProductionBatchService $productionBatchService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Counted separately: scrap lots are stock but not blocks, so folding them
        // into one number would overstate a run's block output.
        $query = ProductionBatch::with(['operatingUnit', 'requestedByClient'])
            ->withCount(['blocks', 'scrapLots']);

        if ($unitId = $this->unitContext->getUnitId()) {
            $query->where('operating_unit_id', $unitId);
        }

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(
            $query->orderByDesc('operation_number')->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `unique` deliberately spans soft-deleted rows — a deleted batch may
            // still have labelled blocks in the yard.
            'operation_number' => ['required', 'integer', 'min:1', 'unique:production_batches,operation_number'],
            // FOAM-01: the machine physically cannot pour wider than 2.4m.
            'bun_width_m' => ['required', 'numeric', 'min:0.001', 'max:'.ProductionBatch::MAX_BUN_WIDTH_M],
            'requested_by_client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'formula_params' => ['nullable', 'array'],
            'status' => ['nullable', Rule::enum(ProductionBatchStatus::class)],
            'material_cost' => ['nullable', 'numeric', 'min:0'],
            'confirm_non_sequential' => ['nullable', 'boolean'],
        ]);

        // The unit comes only from the request context, which ScopeOperatingUnit has
        // already validated against the user's roles. Accepting it in the body would
        // let a unit-scoped user create a batch inside a unit they cannot reach.
        //
        // Company-wide roles (Owner) carry no unit of their own, so they must name one
        // via the header — otherwise operating_unit_id would be null against a NOT NULL
        // column and surface as a 500 rather than something the operator can act on.
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a production batch. Company-wide roles must send an X-Operating-Unit-ID header naming the unit that ran the pour.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $entered = (int) $validated['operation_number'];
        $expected = $this->productionBatchService->nextExpectedOperationNumber();

        // Warn, never block: legitimate gaps happen, and a hard rule would only
        // train operators to work around the system. But an unconfirmed gap is
        // far more likely to be a transposition typo than a real skip.
        if ($entered !== $expected && ! $request->boolean('confirm_non_sequential')) {
            return response()->json([
                'message' => "Last operation was {$expected}, you entered {$entered}. Confirm to continue.",
                'code' => 'NON_SEQUENTIAL_OPERATION_NUMBER',
                'expected_operation_number' => $expected,
                'entered_operation_number' => $entered,
            ], 422);
        }

        $batch = ProductionBatch::create([
            ...$validated,
            'operating_unit_id' => $unitId,
        ]);

        return response()->json($batch->load(['operatingUnit', 'requestedByClient']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $batch = ProductionBatch::with(['operatingUnit', 'requestedByClient', ...self::MATERIAL_RELATIONS])
            ->withCount(['blocks', 'scrapLots'])
            ->findOrFail($id);

        return response()->json($batch);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $validated = $request->validate([
            'operation_number' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('production_batches', 'operation_number')->ignore($batch->id),
            ],
            'bun_width_m' => ['sometimes', 'numeric', 'min:0.001', 'max:'.ProductionBatch::MAX_BUN_WIDTH_M],
            'requested_by_client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'formula_params' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(ProductionBatchStatus::class)],
            'material_cost' => ['sometimes', 'numeric', 'min:0'],
            'record_version' => ['required', 'integer'],
        ]);

        // Once a block is registered its label is printed and in the yard, so the
        // operation number it encodes can no longer move.
        if (array_key_exists('operation_number', $validated)
            && (int) $validated['operation_number'] !== (int) $batch->operation_number
            && $batch->stockLots()->exists()) {
            return response()->json([
                'message' => 'Operation number cannot change once blocks are registered — their printed labels already encode it.',
                'code' => 'OPERATION_NUMBER_IMMUTABLE',
            ], 422);
        }

        // W4-05: once chemicals are issued, the material cost is their summed
        // actual cost and cannot be typed over. Resending the current value
        // (a whole-form save) changes nothing and is let through.
        if (array_key_exists('material_cost', $validated) && $batch->materials()->exists()) {
            if (abs((float) $validated['material_cost'] - (float) $batch->material_cost) >= 0.00005) {
                return response()->json([
                    'message' => 'Material cost is computed from the issued chemicals once any are issued; issue or remove materials instead of entering it.',
                    'code' => 'MATERIAL_COST_FROM_ISSUES',
                ], 422);
            }

            unset($validated['material_cost']);
        }

        // A status change must go through the lifecycle (cost apportionment and
        // the closing journal live there), never a plain column write. Both run
        // in one transaction so a rejected transition leaves no half-saved edit.
        $targetStatus = array_key_exists('status', $validated)
            ? ProductionBatchStatus::from($validated['status'])
            : null;
        unset($validated['status']);

        $batch = DB::transaction(function () use ($batch, $validated, $targetStatus): ProductionBatch {
            $batch->update($validated);

            if ($targetStatus !== null && $targetStatus !== $batch->status) {
                return $this->productionBatchService->transition($batch, $targetStatus);
            }

            return $batch;
        });

        return response()->json($batch->load(['operatingUnit', 'requestedByClient'])->loadCount(['blocks', 'scrapLots']));
    }

    /**
     * Advance the batch one step along its lifecycle (Phase 04 §4.4).
     */
    public function transition(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProductionBatchStatus::class)],
        ]);

        $updated = $this->productionBatchService->transition(
            $batch,
            ProductionBatchStatus::from($validated['status'])
        );

        return response()->json($updated->load(['operatingUnit', 'requestedByClient']));
    }

    public function destroy(string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        // Blocks carry printed labels encoding this batch's operation number, so a
        // batch with output cannot be withdrawn. (Soft-deleted batches still hold
        // their operation number against reuse — see ProductionBatchService.)
        if ($batch->stockLots()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a batch that has registered blocks — their printed labels reference it.',
                'code' => 'BATCH_HAS_BLOCKS',
            ], 422);
        }

        // W4-05: issued chemicals drew their lots down and sit in 1121. A
        // deleted batch never closes and its rows can no longer be removed, so
        // that value would be stranded; they must be returned first.
        if ($batch->materials()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a batch that has issued chemicals — remove the issued materials first so they return to their lots.',
                'code' => 'BATCH_HAS_MATERIALS',
            ], 422);
        }

        $batch->delete();

        return response()->json(['message' => 'Production batch soft deleted.']);
    }

    /**
     * Register a run's output from its production report.
     *
     * Groups mirror the paper sheet (dimensions + count); each block group is
     * expanded into individually labelled lots.
     */
    public function registerBlocks(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        // Blocks are keyed in from the completed sheet after grading, so the batch
        // must have reached that point in its lifecycle.
        if (! $batch->status->acceptsBlockRegistration()) {
            return response()->json([
                'message' => "Blocks can only be registered once operation {$batch->operation_number} is ready for grading; it is currently {$batch->status->value}.",
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.kind' => ['required', 'string', 'in:block,separator,head,scrap'],
            'groups.*.block_type' => ['nullable', 'string', 'in:block,separator,head,scrap'],
            'groups.*.count' => ['required', 'integer', 'min:1'],
            'groups.*.length_m' => ['required', 'numeric', 'min:0.001'],
            'groups.*.height_m' => ['required', 'numeric', 'min:0.001'],
            'groups.*.pressure' => [
                'required_if:groups.*.kind,block',
                'nullable',
                'integer',
                'min:1',
            ],
            // Scrap now enters stock too, so it needs an item and a location just
            // like a block does.
            'groups.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'groups.*.warehouse_id' => ['required', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'groups.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'groups.*.grade' => ['nullable', 'string', 'in:standard,acceptable_variant,defective_usable,reject'],
            'groups.*.color' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->productionBatchService->registerBlocks($batch, $validated['groups']);

        return response()->json([
            'batch' => $result['batch'],
            'blocks' => $result['blocks'],
            'blocks_created' => count($result['blocks']),
            'scrap_lots' => $result['scrap_lots'],
            'scrap_lots_created' => count($result['scrap_lots']),
            'scrap_volume_m3' => $result['scrap_volume_m3'],
        ], 201);
    }

    /**
     * W4-05: issue chemicals to the batch, drawn FIFO from the unit's lots.
     */
    public function issueMaterials(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            // The service keeps 4 decimals; anything smaller would round to zero.
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $updated = $this->productionBatchService->issueMaterials($batch, $validated['lines'], $request->user()?->id);

        return response()->json(
            $updated->load(['operatingUnit', 'requestedByClient', ...self::MATERIAL_RELATIONS])->loadCount(['blocks', 'scrapLots']),
            201,
        );
    }

    /**
     * W4-05: remove an issued row; its quantity returns to the lot.
     */
    public function removeMaterial(Request $request, string $id, string $line): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $updated = $this->productionBatchService->removeMaterial($batch, $line, $request->user()?->id);

        return response()->json(
            $updated->load(['operatingUnit', 'requestedByClient', ...self::MATERIAL_RELATIONS])->loadCount(['blocks', 'scrapLots'])
        );
    }
}
