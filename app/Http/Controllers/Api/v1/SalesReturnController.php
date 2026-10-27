<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales returns raised against an original sale (FR-INV-048, BR-INV-013).
 */
final class SalesReturnController extends Controller
{
    public function __construct(private readonly SalesReturnService $returnService) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::with([
            'order:id,order_number,walk_in_name,buyer_type,client_id',
            'client.entity:id,name',
            'cashAccount:id,name,kind',
            'createdBy:id,name',
        ])->latest();

        if (filled($request->query('client_id'))) {
            $query->where('client_id', $request->query('client_id'));
        }

        if (filled($request->query('from'))) {
            $query->whereDate('return_date', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('return_date', '<=', $request->query('to'));
        }

        if (filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%")
                        ->orWhere('walk_in_name', 'like', "%{$search}%"))
                    ->orWhereHas('client.entity', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            SalesReturn::with([
                'lines.orderLine:id,sales_order_id,line_type,description,inventory_item_id,quantity,unit_price,discount_amount',
                'lines.orderLine.inventoryItem:id,name,code',
                'order:id,order_number,walk_in_name,walk_in_phone,buyer_type,client_id,total_amount,returned_amount,amount_paid',
                'client.entity:id,name',
                'cashAccount:id,name,kind',
                'createdBy:id,name',
                'journalEntry:id,reference,entry_date',
            ])->findOrFail($id)
        );
    }

    /**
     * What each line of a sale can still be returned.
     */
    public function returnable(string $id): JsonResponse
    {
        $sale = SalesOrder::findOrFail($id);

        return response()->json([
            'sales_order_id' => $sale->id,
            'order_number' => $sale->order_number,
            'refundable_amount' => round((float) $sale->amount_paid, 4),
            'lines' => $this->returnService->returnable($sale),
        ]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.sales_order_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.condition' => ['required', Rule::in(['restock', 'damaged'])],
            'refund_method' => ['required', Rule::in(['cash', 'bank', 'credit'])],
            'cash_account_id' => [Rule::requiredIf(fn () => in_array($request->input('refund_method'), ['cash', 'bank'], true)), 'nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = SalesOrder::findOrFail($id);

        return response()->json($this->returnService->create($sale, $validated, $request->user()), 201);
    }
}
