<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Exceptions\SalesRuleException;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\DocumentNumberService;
use App\Services\SaleCheckoutService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Priced previews the client takes home and comes back with. No stock or
 * ledger effect; converting one runs a normal POS checkout.
 */
final class QuotationController extends Controller
{
    private const LINE_COLUMNS = [
        'position', 'line_type', 'description', 'inventory_item_id', 'bundle_id',
        'quantity', 'length_m', 'width_m', 'height_m', 'unit_price', 'discount_amount',
    ];

    public function __construct(
        private readonly SaleCheckoutService $checkoutService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Quotation::with(['client.entity', 'convertedSale:id,order_number'])
            ->withCount('lines')
            ->latest();

        $status = $request->query('status');

        if ($status === Quotation::STATUS_EXPIRED) {
            $query->where('status', Quotation::STATUS_OPEN)->whereDate('valid_until', '<', today());
        } elseif ($status === Quotation::STATUS_OPEN) {
            $query->where('status', Quotation::STATUS_OPEN)->whereDate('valid_until', '>=', today());
        } elseif (filled($status)) {
            $query->where('status', $status);
        }

        if (filled($request->query('client_id'))) {
            $query->where('client_id', $request->query('client_id'));
        }

        if (filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhereHas('client.entity', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'uuid'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'price_tier' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...$this->lineRules(required: true),
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before quoting.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        if (Client::whereKey($validated['client_id'])->doesntExist()) {
            throw new SalesRuleException('The client is not registered in this operating unit.', 'CLIENT_NOT_FOUND');
        }

        $priceTier = (string) ($validated['price_tier'] ?? 'retail');
        $lines = $this->checkoutService->normalizeLines($validated['lines'], priceTier: $priceTier);
        $gross = round(array_sum(array_map(fn (array $l): float => $l['quantity'] * $l['unit_price'], $lines)), 4);
        $subtotal = round(array_sum(array_map(fn (array $l): float => $l['quantity'] * $l['unit_price'] - $l['discount_amount'], $lines)), 4);
        $invoiceDiscount = round((float) ($validated['invoice_discount'] ?? 0), 4);

        if ($invoiceDiscount < 0 || $invoiceDiscount > $subtotal + 0.0001) {
            throw new SalesRuleException(
                "Invoice discount {$invoiceDiscount} exceeds subtotal {$subtotal}.",
                'DISCOUNT_INVALID',
            );
        }

        $total = round($subtotal - $invoiceDiscount, 4);

        $quotation = DB::transaction(function () use ($validated, $unitId, $priceTier, $lines, $gross, $invoiceDiscount, $total, $request): Quotation {
            $quotation = Quotation::create([
                'operating_unit_id' => $unitId,
                'quotation_number' => $this->documentNumbers->next(DocumentNumberService::QUOTATION),
                'client_id' => $validated['client_id'],
                'valid_until' => $validated['valid_until'] ?? today()->addDays(14)->toDateString(),
                'price_tier' => $priceTier,
                'gross_amount' => $gross,
                'invoice_discount' => $invoiceDiscount,
                'total_amount' => $total,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $quotation->lines()->create(array_intersect_key($line, array_flip(self::LINE_COLUMNS)));
            }

            return $quotation;
        });

        return response()->json($this->load($quotation), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->load(Quotation::findOrFail($id)));
    }

    public function cancel(string $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);

        if ($quotation->status !== Quotation::STATUS_OPEN) {
            throw new SalesRuleException("Quotation {$quotation->quotation_number} is {$quotation->status}.", 'QUOTATION_NOT_OPEN');
        }

        $quotation->update(['status' => Quotation::STATUS_CANCELLED]);

        return response()->json($this->load($quotation));
    }

    /**
     * The client came back: sell the quotation through a normal checkout.
     * The counter may adjust the lines (prices, quantities) before selling.
     */
    public function convert(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'client_request_id' => ['nullable', 'uuid'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'receivable', 'split'])],
            'cash_account_id' => ['nullable', 'uuid'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'price_tier' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'price_override' => ['nullable', 'array'],
            'price_override.login' => ['required_with:price_override', 'string'],
            'price_override.password' => ['required_with:price_override', 'string'],
            'price_override.reason' => ['required_with:price_override', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            ...SaleCheckoutService::splitPaymentRules(),
            ...$this->lineRules(required: false),
        ]);

        $quotation = Quotation::with('lines')->findOrFail($id);

        // A retried conversion returns the sale it already made.
        if ($quotation->status === Quotation::STATUS_CONVERTED
            && ($validated['client_request_id'] ?? null) !== null
            && ($sale = SalesOrder::find($quotation->converted_sales_order_id))?->client_request_id === $validated['client_request_id']) {
            return response()->json($sale->load([
                'lines.inventoryItem',
                'lines.bundle',
                'client.entity',
                'cashAccount',
                'creditApprovalRequest',
                'payments.cashAccount',
                'payments.cheque',
                'payments.receivedBy:id,name',
                'priceOverrideBy:id,name',
            ]));
        }

        if ($quotation->status !== Quotation::STATUS_OPEN) {
            throw new SalesRuleException("Quotation {$quotation->quotation_number} is {$quotation->status}.", 'QUOTATION_NOT_OPEN');
        }

        if ($quotation->isExpired()) {
            throw new SalesRuleException("Quotation {$quotation->quotation_number} expired on {$quotation->valid_until->toDateString()}.", 'QUOTATION_EXPIRED');
        }

        $lines = $validated['lines'] ?? $quotation->lines->map(
            fn ($line) => array_intersect_key($line->toArray(), array_flip(self::LINE_COLUMNS))
        )->all();

        $invoiceDiscount = array_key_exists('invoice_discount', $validated) && $validated['invoice_discount'] !== null
            ? $validated['invoice_discount']
            : $quotation->invoice_discount;

        $priceTier = $validated['price_tier'] ?? $quotation->price_tier ?? 'retail';

        $sale = DB::transaction(function () use ($quotation, $validated, $lines, $invoiceDiscount, $priceTier, $request): SalesOrder {
            $sale = $this->checkoutService->checkout($this->unitContext->unit(), [
                'client_id' => $quotation->client_id,
                'payment_method' => $validated['payment_method'],
                'cash_account_id' => $validated['cash_account_id'] ?? null,
                'tendered_amount' => $validated['tendered_amount'] ?? null,
                'invoice_discount' => $invoiceDiscount,
                'price_tier' => $priceTier,
                'price_override' => $validated['price_override'] ?? null,
                'client_request_id' => $validated['client_request_id'] ?? null,
                'quotation_id' => $quotation->id,
                'notes' => $validated['notes'] ?? $quotation->notes,
                'lines' => $lines,
                'payments' => $validated['payments'] ?? null,
            ], $request->user());

            $quotation->update([
                'status' => Quotation::STATUS_CONVERTED,
                'converted_sales_order_id' => $sale->id,
            ]);

            return $sale;
        });

        return response()->json($sale, 201);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function lineRules(bool $required): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'lines.*.line_type' => ['nullable', Rule::in(['item', 'bundle'])],
            'lines.*.inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'lines.*.bundle_id' => ['nullable', 'uuid', 'exists:bundles,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_m' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.width_m' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.height_m' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    private function load(Quotation $quotation): Quotation
    {
        return $quotation->fresh([
            'lines.inventoryItem:id,name,code',
            'lines.bundle:id,name',
            'client.entity.primaryContact',
            'operatingUnit:id,name',
            'convertedSale:id,order_number',
            'createdBy:id,name',
        ]);
    }
}
