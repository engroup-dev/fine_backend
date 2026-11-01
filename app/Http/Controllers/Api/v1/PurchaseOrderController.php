<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Concerns\ListsAllRows;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StorePurchaseOrderRequest;
use App\Http\Requests\v1\UpdatePurchaseOrderRequest;
use App\Http\Resources\v1\PurchaseOrderResource;
use App\Models\FxRate;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Rules\LeafAccount;
use App\Services\PurchaseOrderStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PurchaseOrderController extends Controller
{
    use ListsAllRows;

    /**
     * Procurement-sensible item types — items used to source a purchase order.
     * Foam blocks, finished goods, slices, etc. are produced internally and
     * cannot be procured via an import order.
     */
    private const PROCUREMENT_ITEM_TYPES = [
        'raw_material',
        'packaging',
        'barrel',
        'pallet',
    ];

    public function __construct(
        private readonly PurchaseOrderStateService $stateService
    ) {}

    /**
     * At most per_page rows (default 100, max 500). `all=1` lifts that limit
     * and returns every row the same filters and unit scope match, in the same
     * shape, up to `fine.export_all_limit` rows (more is 422
     * EXPORT_TOO_LARGE). The desktop CSV export uses it (W6-04).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::with([
            'supplier',
            'paymentRequests',
            'landedCostLines',
            'goodsReceipt',
            'items.inventoryItem',
            'paymentSourceAccount',
            'arrivedWarehouse',
            'destinationWarehouse',
        ]);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        // ACC-03 / Wave 5: tab split between foreign (FX, land-cost) and
        // local (LYD, no FX). The `kind` query param filters by the
        // discriminator column on purchase_orders. Accepts `foreign` or
        // `local`; anything else is ignored.
        $kindFilter = $request->query('kind');
        if (in_array($kindFilter, ['foreign', 'local'], true)) {
            $query->where('kind', $kindFilter);
        }

        $query->latest();

        if ($request->boolean('all')) {
            return PurchaseOrderResource::collection($this->allRows($query));
        }

        // Cap the response size to keep the renderer's main thread responsive.
        // Clients pass `?per_page=N` to override (max 500); default 100.
        $perPage = (int) $request->query('per_page', 100);
        $perPage = max(1, min($perPage, 500));
        $query->limit($perPage);

        return PurchaseOrderResource::collection($query->get());
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Wave 5: kind discriminator defaults to 'foreign' (legacy behaviour).
        // Local orders are explicit — the FE passes `kind=local`.
        $kind = ($data['kind'] ?? null) === 'local' ? 'local' : 'foreign';

        // Wave 5: for local POs, currency is locked to LYD (the supplier-side
        // currency for domestic procurement). For foreign, the existing
        // snapshot logic still applies.
        if ($kind === 'local') {
            $data['currency'] = 'LYD';
        }

        // PROC-06: the booked FX estimate is captured when the order is
        // booked, not reconstructed later. Completion posts FX gain/loss
        // against this snapshot (phase-02 §2.8).
        if (! isset($data['booked_fx_rate'])) {
            $data['booked_fx_rate'] = $this->snapshotBookedFxRate(
                $data['currency'] ?? 'USD',
                $data['operating_unit_id'],
            );
        }

        return DB::transaction(function () use ($request, $data, $kind) {
            $currency = $data['currency'] ?? 'USD';

            // When line items are supplied, derive the header aggregates from
            // them so the existing completion / goods-receipt / landed-cost
            // flows continue to work unchanged.
            $headerQuantity = $data['quantity'] ?? null;
            $headerUnitPrice = $data['negotiated_price'] ?? null;

            if ($request->has('items')) {
                $items = $this->validateAndPrepareItems($request->input('items'), $currency);

                $headerQuantity = array_sum(array_column($items, 'quantity'));
                $headerUnitPrice = array_sum(array_map(
                    fn ($line) => $line['quantity'] * $line['unit_price'],
                    $items,
                ));
            }

            $order = PurchaseOrder::create([
                'operating_unit_id' => $data['operating_unit_id'],
                'supplier_id' => $data['supplier_id'],
                'currency' => $currency,
                'kind' => $kind,
                'negotiated_price' => $headerUnitPrice ?? 0,
                'quantity' => $headerQuantity ?? 0,
                'booked_fx_rate' => $data['booked_fx_rate'] ?? null,
                'destination_warehouse_id' => $data['destination_warehouse_id'] ?? null,
            ]);

            if ($request->has('items')) {
                foreach ($items as $line) {
                    PurchaseOrderItem::create($line + ['purchase_order_id' => $order->id]);
                }
            }

            return (new PurchaseOrderResource($order->load([
                'supplier',
                'items.inventoryItem',
            ])))->response()->setStatusCode(201);
        });
    }

    public function update(UpdatePurchaseOrderRequest $request, string $id): JsonResponse|PurchaseOrderResource
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return response()->json([
                'message' => 'Items can only be edited when the import order is in draft status.',
                'code' => 'ORDER_NOT_IN_DRAFT',
            ], 422);
        }

        $items = $this->validateAndPrepareItems($request->input('items'), $order->currency);

        return DB::transaction(function () use ($order, $items, $request) {
            $order->items()->delete();

            foreach ($items as $line) {
                PurchaseOrderItem::create($line + ['purchase_order_id' => $order->id]);
            }

            $headerQuantity = array_sum(array_column($items, 'quantity'));
            $headerUnitPrice = array_sum(array_map(
                fn ($line) => $line['quantity'] * $line['unit_price'],
                $items,
            ));

            $attributes = [
                'quantity' => $headerQuantity,
                'negotiated_price' => $headerUnitPrice,
            ];

            if ($request->filled('supplier_id')) {
                $attributes['supplier_id'] = $request->input('supplier_id');
            }

            if ($request->has('destination_warehouse_id')) {
                $attributes['destination_warehouse_id'] = $request->input('destination_warehouse_id');
            }

            $order->update($attributes);

            return new PurchaseOrderResource($order->fresh([
                'supplier',
                'items.inventoryItem',
            ]));
        });
    }

    /**
     * Ensure every referenced inventory item is procurement-eligible (i.e.
     * not foam block / finished good / etc.). Reuses the validated uuids
     * already produced by the FormRequest.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: float|int|string, unit_price: float|int|string}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function validateAndPrepareItems(array $lines, string $currency): array
    {
        $ids = array_column($lines, 'inventory_item_id');
        $items = InventoryItem::whereIn('id', $ids)->get()->keyBy('id');

        $prepared = [];
        foreach ($lines as $line) {
            $item = $items[$line['inventory_item_id']] ?? null;
            if (! $item) {
                // FormRequest already validated exists — this is defense in depth.
                abort(422, 'Unknown inventory item: '.$line['inventory_item_id']);
            }
            if (! in_array($item->item_type, self::PROCUREMENT_ITEM_TYPES, true)) {
                abort(response()->json([
                    'message' => "Item \"{$item->name}\" is type \"{$item->item_type}\" which is not procurement-eligible.",
                    'code' => 'INVALID_ITEM_TYPE',
                ], 422));
            }

            $prepared[] = [
                'inventory_item_id' => $item->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'currency' => $currency,
            ];
        }

        return $prepared;
    }

    /**
     * Wave 5 (local flow): atomic per-line batch receive.
     *
     * Body:
     *   {
     *     "items": [
     *       {"id": "<purchase_order_item_uuid>", "received_quantity": 80},
     *       {"id": "<another_uuid>", "received_quantity": 120}
     *     ]
     *   }
     *
     * Behaviour:
     *   - Atomic: every line validates or the whole batch is rolled back.
     *   - `received_quantity` per line is clamped against its prior value
     *     (no rolling back).
     *   - When every line is fully received, the order transitions
     *     approved -> received. Partial receipts stay in `approved`.
     *   - Local purchase orders only. Foreign POs use the existing
     *     transition -> receive_goods path.
     */
    public function receive(Request $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|uuid',
            'items.*.received_quantity' => 'required|numeric|min:0',
        ]);

        try {
            $this->stateService->receiveItems($order, $validated['items']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_PURCHASE_ORDER_OPERATION',
            ], 422);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        return response()->json([
            'message' => 'Items received successfully.',
            'data' => new PurchaseOrderResource($order->fresh([
                'supplier',
                'paymentRequests.bankHold',
                'landedCostLines',
                'goodsReceipt',
                'items.inventoryItem',
                'arrivedWarehouse', 'destinationWarehouse',
            ])),
        ]);
    }

    /**
     * Wave 5 (local flow): approve a draft local purchase order.
     * Manager sign-off — no journal posting, just a state transition.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        try {
            $this->stateService->approve($order);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_PURCHASE_ORDER_OPERATION',
            ], 422);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        return response()->json([
            'message' => 'Purchase order approved.',
            'data' => new PurchaseOrderResource($order->fresh([
                'supplier',
                'paymentRequests.bankHold',
                'landedCostLines',
                'goodsReceipt',
                'items.inventoryItem',
                'arrivedWarehouse', 'destinationWarehouse',
            ])),
        ]);
    }

    /**
     * Wave 5 (local flow): record payment on a received local order.
     * Marks the order paid; auto-closes when fully received + paid.
     */
    public function payLocal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'payment_source_account_id' => ['required', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true)],
        ]);

        $order = PurchaseOrder::findOrFail($id);

        try {
            $this->stateService->payLocal($order, $validated['payment_source_account_id']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_PURCHASE_ORDER_OPERATION',
            ], 422);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        return response()->json([
            'message' => 'Local order marked as paid.',
            'data' => new PurchaseOrderResource($order->fresh([
                'supplier',
                'paymentRequests.bankHold',
                'landedCostLines',
                'goodsReceipt',
                'items.inventoryItem',
                'arrivedWarehouse', 'destinationWarehouse',
                'paymentSourceAccount',
            ])),
        ]);
    }

    private function snapshotBookedFxRate(string $currency, string $operatingUnitId): ?string
    {
        $functionalCurrency = OperatingUnit::query()
            ->find($operatingUnitId)?->company?->default_currency ?? 'LYD';

        if ($currency === $functionalCurrency) {
            return '1';
        }

        return FxRate::query()
            ->where('from_currency', $currency)
            ->where('to_currency', $functionalCurrency)
            ->latest('captured_at')
            ->value('rate');
    }

    public function show(string $id): PurchaseOrderResource
    {
        $order = PurchaseOrder::with([
            'supplier',
            'paymentRequests.bankHold',
            'landedCostLines',
            'goodsReceipt',
            'items.inventoryItem',
            'paymentSourceAccount',
        ])->findOrFail($id);

        return new PurchaseOrderResource($order);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        // receive_goods: if no explicit warehouse is sent but we already
        // recorded one on arrival, default to it so the operator doesn't
        // have to re-pick the same warehouse.
        if ($request->input('action') === 'receive_goods'
            && ! $request->filled('warehouse_id')
            && $order->arrived_warehouse_id
        ) {
            $request->merge(['warehouse_id' => $order->arrived_warehouse_id]);
        }

        $request->validate([
            'action' => 'required|string|in:pending_payment,select_route,execute_payment,shipment,arrive_port,arrived_at_warehouse,transport_warehouse,receive_goods,complete',
            'route' => 'required_if:action,select_route|string|in:bank,market',
            'amount_requested' => 'required_if:action,select_route|numeric|min:0.0001',
            'held_amount_lyd' => 'required_if:route,bank|nullable|numeric|min:0.0001',
            'invoice_ref' => 'nullable|string',
            'fx_rate_used' => 'required_if:action,execute_payment,required_without:exact_amount_used_lyd|nullable|numeric|min:0.000001',
            'exact_amount_used_lyd' => 'nullable|numeric|min:0',
            'bank_reference' => 'nullable|string',
            'extra_allocation_note' => 'nullable|string|max:500',
            'payment_source_account_id' => ['required_if:action,execute_payment', 'nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true)],
            'warehouse_id' => ['required_if:action,arrived_at_warehouse,receive_goods', 'nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'received_qty' => 'required_if:action,receive_goods|nullable|numeric|min:0.0001',
            'condition_notes' => 'nullable|string',
        ]);

        $action = $request->input('action');

        $user = $request->user();
        $isAuthorized = $user && (
            $user->hasRole('owner')
            || $user->hasRole('admin')
            || $user->hasRole('accounting-manager')
            || $user->hasRole('treasury-officer')
            || $user->hasRole('procurement-manager')
            || $user->hasRole('hr-manager')
            || $user->hasRole('inventory-manager')
            || $user->hasRole('foam-manager')
            || $user->hasRole('cutter-manager')
            || $user->hasRole('furniture-manager')
            || $user->hasRole('store-manager')
            || $user->hasRole('unit_manager')
            || $user->hasRole('manager')
        );

        if (in_array($action, ['select_route', 'execute_payment'], true) && ! $isAuthorized) {
            return response()->json([
                'message' => 'Only managers and finance officers can execute financial transitions.',
                'code' => 'FINANCE_ONLY_TRANSITION',
            ], 403);
        }

        try {
            match ($action) {
                'pending_payment' => $this->stateService->transitionToPendingPayment($order),
                'select_route' => $this->stateService->selectPaymentRoute(
                    $order,
                    PaymentRoute::from($request->input('route')),
                    (float) $request->input('amount_requested'),
                    $request->filled('held_amount_lyd') ? (float) $request->input('held_amount_lyd') : null,
                    $request->input('invoice_ref')
                ),
                'execute_payment' => $this->executePaymentForOrder($order, $request),
                'shipment' => $this->stateService->confirmShipment($order),
                'arrive_port' => $this->stateService->arriveAtPort($order),
                'arrived_at_warehouse' => $this->stateService->arriveAtWarehouse(
                    $order,
                    $request->input('warehouse_id')
                ),
                'transport_warehouse' => $this->stateService->transportToWarehouse($order),
                'receive_goods' => $this->stateService->receiveGoods(
                    $order,
                    $request->input('warehouse_id'),
                    (float) $request->input('received_qty'),
                    $request->input('condition_notes')
                ),
                'complete' => $this->stateService->completeOrder($order),
            };
        } catch (InvalidArgumentException $e) {
            // Wrong-state guards throw InvalidStateTransitionException and
            // render 422 globally; this catches the input-shaped refusals
            // (missing hold amount, unconfirmed cost lines, unpostable
            // completion) so they are 422s too, not 500s.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_IMPORT_ORDER_OPERATION',
            ], 422);
        }

        return response()->json([
            'message' => 'Transition applied successfully.',
            'data' => new PurchaseOrderResource($order->fresh([
                'supplier',
                'paymentRequests.bankHold',
                'landedCostLines',
                'goodsReceipt',
                'items.inventoryItem',
                'arrivedWarehouse', 'destinationWarehouse',
                'paymentSourceAccount',
            ])),
        ]);
    }

    private function executePaymentForOrder(PurchaseOrder $order, Request $request): void
    {
        $paymentRequest = $order->paymentRequests()
            ->where('status', PaymentRequestStatus::Pending)
            ->first();

        if (! $paymentRequest) {
            throw new InvalidArgumentException('No pending payment request found for this import order.');
        }

        // FX-04 / FX-24: variance is measured in LYD against the booked
        // expectation using the effective settled (LYD wins if supplied),
        // not the rate difference. The note is required when actual settled
        // deviates from booked by more than the tolerance band.
        $fxRateUsed = $request->filled('fx_rate_used') ? (float) $request->input('fx_rate_used') : null;
        $exactUsed = $request->filled('exact_amount_used_lyd') ? (float) $request->input('exact_amount_used_lyd') : null;

        ['effective_settled' => $effectiveSettled] =
            $this->stateService->deriveEffectiveValues($paymentRequest, $fxRateUsed, $exactUsed);

        $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';
        $varianceLyd = $this->stateService->varianceVsBooked($order, $effectiveSettled, $functionalCurrency);
        $tolerance = PurchaseOrderStateService::fxToleranceLyd();

        if ($varianceLyd !== null && abs($varianceLyd) > $tolerance && blank($request->input('extra_allocation_note'))) {
            throw ValidationException::withMessages([
                'extra_allocation_note' => sprintf(
                    'سبب التكلفة الإضافية مطلوب عند فرق %.2f دينار عن السعر المرجعي.',
                    $varianceLyd,
                ),
            ]);
        }

        $this->stateService->executePayment(
            $paymentRequest,
            $fxRateUsed,
            $exactUsed,
            $request->input('bank_reference'),
            $request->input('extra_allocation_note'),
            $request->input('payment_source_account_id'),
        );
    }
}
