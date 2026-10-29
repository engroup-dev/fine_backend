<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\WarehouseTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Rules\ExistsInCurrentUnit;
use App\Services\StockLotService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WarehouseTransferController extends Controller
{
    public function __construct(
        private readonly StockLotService $stockLotService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WarehouseTransfer::with(['fromWarehouse', 'toWarehouse', 'lines.inventoryItem']);

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('search'))) {
            $query->where('transfer_number', 'like', '%'.$request->query('search').'%');
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTransfer($request);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a warehouse transfer.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        if ($refusal = $this->crossUnitRefusal($validated['from_warehouse_id'], $validated['to_warehouse_id'], $unitId)) {
            return $refusal;
        }

        $transfer = DB::transaction(function () use ($validated, $unitId) {
            $transfer = WarehouseTransfer::create([
                'operating_unit_id' => $unitId,
                'transfer_number' => $validated['transfer_number'],
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'reason' => $validated['reason'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $transfer->lines()->create($line);
            }

            return $transfer;
        });

        return response()->json($transfer->fresh(['lines.inventoryItem', 'fromWarehouse', 'toWarehouse']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $transfer = WarehouseTransfer::with(['lines.inventoryItem', 'fromWarehouse', 'toWarehouse'])->findOrFail($id);

        return response()->json($transfer);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $transfer = WarehouseTransfer::findOrFail($id);

        if ($transfer->status !== WarehouseTransferStatus::Draft) {
            return response()->json([
                'message' => "Transfer {$transfer->transfer_number} is {$transfer->status->value} and can no longer be edited.",
                'code' => 'TRANSFER_NOT_DRAFT',
            ], 422);
        }

        $validated = $this->validateTransfer($request, sometimes: true);

        $refusal = $this->crossUnitRefusal(
            $validated['from_warehouse_id'] ?? $transfer->from_warehouse_id,
            $validated['to_warehouse_id'] ?? $transfer->to_warehouse_id,
            $transfer->operating_unit_id,
        );
        if ($refusal !== null) {
            return $refusal;
        }

        DB::transaction(function () use ($transfer, $validated): void {
            $transfer->update([
                'from_warehouse_id' => $validated['from_warehouse_id'] ?? $transfer->from_warehouse_id,
                'to_warehouse_id' => $validated['to_warehouse_id'] ?? $transfer->to_warehouse_id,
                'reason' => array_key_exists('reason', $validated) ? $validated['reason'] : $transfer->reason,
            ]);

            // Sync lines by full replacement — same pattern as
            // BundleController::update / ImportOrderController::update.
            $transfer->lines()->delete();
            foreach ($validated['lines'] as $line) {
                $transfer->lines()->create($line);
            }
        });

        return response()->json($transfer->fresh(['lines.inventoryItem', 'fromWarehouse', 'toWarehouse']));
    }

    /**
     * Executes the transfer: draws FIFO from the source warehouse and splits
     * into the destination for every line, all inside one transaction — a
     * mid-loop insufficient-stock failure rolls back every line already
     * moved in this same completion, not just the one that failed.
     */
    public function complete(string $id): JsonResponse
    {
        $transfer = WarehouseTransfer::with('lines')->findOrFail($id);

        if ($transfer->status !== WarehouseTransferStatus::Draft) {
            return response()->json([
                'message' => "Transfer {$transfer->transfer_number} is {$transfer->status->value} and cannot be completed.",
                'code' => 'TRANSFER_NOT_DRAFT',
            ], 422);
        }

        // A draft saved before this check existed may still point elsewhere.
        if ($refusal = $this->crossUnitRefusal($transfer->from_warehouse_id, $transfer->to_warehouse_id, $transfer->operating_unit_id)) {
            return $refusal;
        }

        DB::transaction(function () use ($transfer): void {
            foreach ($transfer->lines as $line) {
                $this->stockLotService->transferBetweenWarehouses(
                    $line->inventory_item_id,
                    $transfer->from_warehouse_id,
                    $transfer->to_warehouse_id,
                    (float) $line->quantity,
                    $transfer->reason,
                    'WarehouseTransfer',
                    $transfer->id,
                );
            }

            $transfer->update([
                'status' => WarehouseTransferStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        return response()->json($transfer->fresh(['lines.inventoryItem', 'fromWarehouse', 'toWarehouse']));
    }

    public function cancel(string $id): JsonResponse
    {
        $transfer = WarehouseTransfer::findOrFail($id);

        if ($transfer->status !== WarehouseTransferStatus::Draft) {
            return response()->json([
                'message' => "Transfer {$transfer->transfer_number} is {$transfer->status->value} and cannot be cancelled.",
                'code' => 'TRANSFER_NOT_DRAFT',
            ], 422);
        }

        $transfer->update(['status' => WarehouseTransferStatus::Cancelled]);

        return response()->json($transfer->fresh());
    }

    /**
     * INV-11 / unit scoping: both warehouses must belong to the transfer's
     * own unit. `ExistsInCurrentUnit` passes any warehouse when no unit is
     * selected (a company-wide role without a unit header), so without this
     * a draft could be pointed at another unit's warehouses — a transfer
     * owned by one unit moving another unit's stock, or a cross-unit move
     * with no entry. Stock moves between units through an internal restock
     * or an internal sale, which post the value.
     */
    private function crossUnitRefusal(string $fromWarehouseId, string $toWarehouseId, ?string $unitId): ?JsonResponse
    {
        $units = Warehouse::withoutGlobalScopes()
            ->whereKey([$fromWarehouseId, $toWarehouseId])
            ->pluck('operating_unit_id', 'id');

        if (($units[$fromWarehouseId] ?? null) === $unitId && ($units[$toWarehouseId] ?? null) === $unitId) {
            return null;
        }

        return response()->json([
            'message' => 'Both warehouses of a transfer must belong to its operating unit; move stock between units with an internal restock or an internal sale.',
            'code' => 'TRANSFER_CROSS_UNIT',
            'details' => ['from_warehouse_id' => $fromWarehouseId, 'to_warehouse_id' => $toWarehouseId, 'operating_unit_id' => $unitId],
        ], 422);
    }

    /** @return array{transfer_number?: string, from_warehouse_id?: string, to_warehouse_id?: string, reason?: ?string, lines: array<int, array{inventory_item_id: string, quantity: float}>} */
    private function validateTransfer(Request $request, bool $sometimes = false): array
    {
        $requiredRule = $sometimes ? 'sometimes' : 'required';

        $rules = [
            'from_warehouse_id' => [$requiredRule, 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'source warehouse')],
            'to_warehouse_id' => [$requiredRule, 'uuid', 'different:from_warehouse_id', new ExistsInCurrentUnit(Warehouse::class, 'destination warehouse')],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];

        // The number is fixed at creation — it's not part of the editable
        // surface once a draft exists, so it's never revalidated here.
        if (! $sometimes) {
            $rules['transfer_number'] = ['required', 'string', 'max:100', 'unique:warehouse_transfers,transfer_number'];
        }

        return $request->validate($rules);
    }
}
