<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\CashAccountResource;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\OperatingUnit;
use App\Models\SalesOrder;
use App\Services\SaleCheckoutService;
use App\Services\TreasuryBalanceService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The POS: the one place every sale happens — to a registered client or to
 * another operating unit, of inventory items and bundles.
 */
final class SaleController extends Controller
{
    public function __construct(
        private readonly SaleCheckoutService $checkoutService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with(['client.entity', 'buyerUnit', 'cashAccount'])
            ->withCount('lines')
            ->latest();

        foreach (['status', 'fulfillment_status', 'client_id', 'payment_method', 'buyer_type'] as $filter) {
            if (filled($request->query($filter))) {
                $query->where($filter, $request->query($filter));
            }
        }

        if (filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('walk_in_name', 'like', "%{$search}%")
                    ->orWhere('walk_in_phone', 'like', "%{$search}%")
                    ->orWhereHas('client.entity', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            });
        }

        if (filled($request->query('from'))) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    /**
     * Checkout. The server numbers the sale; a repeated client_request_id
     * returns the sale already made instead of selling twice.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_request_id' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid'],
            'buyer_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'buyer_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'walk_in_name' => ['nullable', 'string', 'max:100'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'receivable', 'split', 'cheque'])],
            'cash_account_id' => ['nullable', 'uuid'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...SaleCheckoutService::splitPaymentRules(),
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['nullable', Rule::in(['item', 'bundle'])],
            'lines.*.inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'lines.*.stock_lot_id' => ['nullable', 'uuid', 'exists:stock_lots,id'],
            'lines.*.bundle_id' => ['nullable', 'uuid', 'exists:bundles,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_m' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.width_m' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.height_m' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $unit = $this->unitContext->unit();

        if ($unit === null) {
            return response()->json([
                'message' => 'Select an operating unit before selling.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $sale = $this->checkoutService->checkout($unit, $validated, $request->user());

        return response()->json($sale, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            SalesOrder::with([
                'lines.inventoryItem',
                'lines.stockLot',
                'lines.bundle.items.inventoryItem',
                'lines.components.inventoryItem',
                'lines.components.allocations.stockLot',
                'client.entity.primaryContact',
                'buyerUnit',
                'cashAccount',
                'payments.cashAccount',
                'payments.cheque',
                'payments.receivedBy:id,name',
                'soldBy:id,name',
                'creditApprovalRequest.decidedBy:id,name',
            ])->findOrFail($id)
        );
    }

    /**
     * Collect money against a receivable sale into a chosen treasury.
     */
    public function collectPayment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'bank', 'cheque'])],
            'cash_account_id' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['cash', 'bank'], true)), 'nullable', 'uuid'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'cheque' => [Rule::requiredIf(fn () => $request->input('method') === 'cheque'), 'array'],
            'cheque.cheque_no' => [Rule::requiredIf(fn () => $request->input('method') === 'cheque'), 'string'],
            'cheque.bank_name' => [Rule::requiredIf(fn () => $request->input('method') === 'cheque'), 'string'],
            'cheque.drawer_name' => ['nullable', 'string'],
            'cheque.due_date' => [Rule::requiredIf(fn () => $request->input('method') === 'cheque'), 'date'],
            'cheque.is_certified' => ['nullable', 'boolean'],
        ]);

        $sale = SalesOrder::findOrFail($id);

        return response()->json($this->checkoutService->collectPayment(
            $sale,
            (float) $validated['amount'],
            $validated['method'],
            $validated['cash_account_id'] ?? null,
            $request->user(),
            isset($validated['tendered_amount']) ? (float) $validated['tendered_amount'] : null,
            $validated['cheque'] ?? null,
        ));
    }

    /**
     * The treasuries and bank accounts any POS can receive money into
     * (company-wide). They are the chart's cash (1211xx) and bank (1212xx)
     * sub-accounts — the same ones purchase payments leave from — so each
     * ledger account is given its treasury record on first sight. Unlinked
     * records are listed too, flagged, so the counter can explain why they
     * cannot be picked.
     */
    public function paymentAccounts(Request $request): AnonymousResourceCollection
    {
        $this->syncTreasuriesFromChart();

        $query = CashAccount::with('account')->orderBy('name');

        if (filled($request->query('kind'))) {
            $query->where('kind', $request->query('kind'));
        }

        $treasuries = $query->get();
        app(TreasuryBalanceService::class)->attach($treasuries);

        return CashAccountResource::collection($treasuries);
    }

    /**
     * Give every cash/bank leaf account of the chart a treasury record.
     */
    private function syncTreasuriesFromChart(): void
    {
        $unitId = app(CurrentUnitContext::class)->id() ?? OperatingUnit::query()->value('id');

        if ($unitId === null) {
            return;
        }

        $known = CashAccount::query()->whereNotNull('account_id')->pluck('account_id')->all();

        Account::query()
            ->where('type', 'asset')
            ->whereNotIn('id', $known)
            ->where(fn ($q) => $q->where('account_code', 'like', '1211%')->orWhere('account_code', 'like', '1212%'))
            ->whereRaw('length(account_code) > 4')
            ->get()
            ->each(fn (Account $account) => CashAccount::create([
                'operating_unit_id' => $unitId,
                'name' => $account->name,
                'kind' => str_starts_with($account->account_code, '1212') ? CashAccount::KIND_BANK : CashAccount::KIND_CASH,
                'account_id' => $account->id,
                'currency' => 'LYD',
            ]));
    }

    /**
     * Structured invoice data (SALE-10). Printing is a desktop concern; the
     * figures here are the invoice. A bundle prints as one line with its name.
     */
    public function invoice(string $id): JsonResponse
    {
        $sale = SalesOrder::with([
            'lines.inventoryItem', 'lines.stockLot', 'client.entity.primaryContact',
            'buyerUnit', 'operatingUnit', 'cashAccount', 'soldBy:id,name',
        ])->findOrFail($id);

        if (! $sale->status->isSold()) {
            return response()->json([
                'message' => 'An invoice exists only for a completed checkout.',
                'code' => 'NOT_SOLD',
            ], 422);
        }

        return response()->json([
            'invoice_number' => 'INV-'.$sale->order_number,
            'sale_number' => $sale->order_number,
            'date' => $sale->created_at?->toIso8601String(),
            'seller' => $sale->operatingUnit?->name,
            'sold_by' => $sale->soldBy?->name,
            'buyer' => $sale->client?->entity?->name ?? $sale->buyerUnit?->name ?? $sale->walk_in_name,
            'buyer_phone' => $sale->client?->entity?->primaryContact?->phone ?? $sale->walk_in_phone,
            'buyer_type' => $sale->buyer_type,
            'payment_method' => $sale->payment_method,
            'cash_account' => $sale->cashAccount?->name,
            'lines' => $sale->lines->map(fn ($line) => [
                'line_type' => $line->line_type,
                'description' => $line->description ?? $line->inventoryItem?->name,
                'sku' => $line->isBundle() ? null : $line->inventoryItem?->code,
                'quantity' => (float) $line->quantity,
                'length_m' => $line->length_m !== null ? (float) $line->length_m : null,
                'width_m' => $line->width_m !== null ? (float) $line->width_m : null,
                'height_m' => $line->height_m !== null ? (float) $line->height_m : null,
                'unit_price' => (float) $line->unit_price,
                'discount_amount' => (float) $line->discount_amount,
                'line_total' => $line->lineTotal(),
                'lot_number' => $line->stockLot?->lot_number,
            ])->values(),
            'gross_amount' => (float) $sale->gross_amount,
            'invoice_discount' => (float) $sale->invoice_discount,
            'total_amount' => (float) $sale->total_amount,
            'amount_paid' => (float) $sale->amount_paid,
            'outstanding' => $sale->outstanding(),
            'status' => $sale->status->value,
            'fulfillment_status' => $sale->fulfillment_status?->value,
        ]);
    }
}
