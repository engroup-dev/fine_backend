<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\StockLot;
use App\Support\ReferenceGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class InventoryItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::with(['category']);

        if ($request->has('category_id') && filled($request->query('category_id'))) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->has('item_type') && filled($request->query('item_type'))) {
            $query->where('item_type', $request->query('item_type'));
        }

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // The POS picker shows what is on this unit's shelves.
        if ($request->boolean('with_stock')) {
            $query->withSum(['stockLots as available_quantity' => fn ($q) => $q->where('status', 'available')], 'quantity');
        }

        return response()->json($query->orderBy('name')->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        if (empty($request->input('item_type')) && $request->filled('category_id')) {
            $category = ItemCategory::find($request->input('category_id'));
            if ($category && ! empty($category->item_type)) {
                $request->merge(['item_type' => $category->item_type]);
            }
        }

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            // W4-03: a code may not equal another item's barcode or any lot
            // number. The scan lookup goes barcode, then item code, then lot
            // number, so either clash would make one of them unreachable.
            'code' => ['required', 'string', 'max:100', 'unique:inventory_items,code', ...self::codeCrossRules()],
            // W4-03: optional and unique. Printable ASCII only, the Code128 set B
            // range the desktop label renderer encodes. It may not equal another
            // item's code or any lot number, so a scan never shadows one of them.
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[\x20-\x7E]+$/', 'unique:inventory_items,barcode', ...self::barcodeCrossRules()],
            'item_type' => ['required', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['required', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            // Same column names as StockLot's own length_m/width_m/height_m,
            // which carries them per physical lot instead of per catalog item.
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            // Product price tiers
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['sometimes', 'string', 'in:unit,m3'],
        ], self::scanCodeMessages());

        $item = InventoryItem::create($validated);

        return response()->json($item->load(['category']), 201);
    }

    /**
     * W4-03: resolves a scanned or typed code. Exact match, in order: an item
     * barcode, then an item code, then a lot number. Items are company-wide; a
     * lot resolves only inside the caller's unit (StockLot's warehouse scope).
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);
        $code = $validated['code'];

        $item = InventoryItem::with(['category'])->where('barcode', $code)->first()
            ?? InventoryItem::with(['category'])->where('code', $code)->first();

        if ($item !== null) {
            return response()->json(['type' => 'item', 'item' => $item]);
        }

        $lot = StockLot::with(['warehouse'])->where('lot_number', $code)->first();
        $lotItem = $lot !== null ? InventoryItem::with(['category'])->find($lot->inventory_item_id) : null;

        if ($lot !== null && $lotItem !== null) {
            return response()->json(['type' => 'lot', 'item' => $lotItem, 'lot' => $lot]);
        }

        return response()->json([
            'message' => "No item or lot matches the code {$code}.",
            'code' => 'CODE_NOT_FOUND',
        ], 404);
    }

    public function show(string $id): JsonResponse
    {
        $item = InventoryItem::with(['category'])->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        // W4-03: the cross-column checks (see store) run only when the value
        // changes, so an item whose existing code or barcode was later matched
        // by a new lot number can still be edited.
        $codeCrossRules = $request->has('code') && $request->input('code') !== $item->code
            ? self::codeCrossRules($id)
            : [];
        $barcodeCrossRules = $request->filled('barcode') && $request->input('barcode') !== $item->barcode
            ? self::barcodeCrossRules($id)
            : [];

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100', "unique:inventory_items,code,{$id}", ...$codeCrossRules],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[\x20-\x7E]+$/', "unique:inventory_items,barcode,{$id}", ...$barcodeCrossRules],
            'item_type' => ['sometimes', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['sometimes', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['sometimes', 'string', 'in:unit,m3'],
        ], self::scanCodeMessages());

        $item->update($validated);

        return response()->json($item->load(['category']));
    }

    /**
     * W4-03: an item code may not equal another item's barcode or any lot
     * number. Like barcodeCrossRules, both checks query the tables directly.
     *
     * @return array<int, Unique>
     */
    private static function codeCrossRules(?string $ignoreItemId = null): array
    {
        return [
            Rule::unique('inventory_items', 'barcode')->ignore($ignoreItemId),
            Rule::unique('stock_lots', 'lot_number'),
        ];
    }

    /**
     * W4-03: a barcode may not equal another item's code or any lot number.
     * Both checks query the tables directly, so soft-deleted rows and lots in
     * other units (hidden by StockLot's warehouse scope) count too.
     *
     * @return array<int, Unique>
     */
    private static function barcodeCrossRules(?string $ignoreItemId = null): array
    {
        return [
            Rule::unique('inventory_items', 'code')->ignore($ignoreItemId),
            Rule::unique('stock_lots', 'lot_number'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function scanCodeMessages(): array
    {
        return [
            'code.unique' => 'This code is already used as another item\'s code or barcode, or as a lot number.',
            'barcode.unique' => 'This barcode is already used as another item\'s barcode or code, or as a lot number.',
        ];
    }

    public function destroy(string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        // BR-ITM-005: an item still held in stock or named on a document cannot
        // be deleted — the stock and the paperwork would point at nothing.
        $inUse = DB::table('stock_lots')->where('inventory_item_id', $item->id)->where('quantity', '>', 0)->exists()
            ? 'stock_lots'
            : ReferenceGuard::firstReference([
                'sales_order_lines' => 'inventory_item_id',
                'quotation_lines' => 'inventory_item_id',
                'purchase_order_items' => 'inventory_item_id',
                'bundle_items' => 'inventory_item_id',
                'warehouse_transfer_lines' => 'inventory_item_id',
                'internal_restock_request_lines' => 'inventory_item_id',
                'assembly_orders' => 'finished_item_id',
                'assembly_order_lines' => 'inventory_item_id',
            ], $item->id);

        if ($inUse !== null) {
            return response()->json([
                'message' => "Item {$item->code} is still in use ({$inUse}) and cannot be deleted. Deactivate it instead.",
                'code' => 'ITEM_IN_USE',
                'referenced_by' => $inUse,
            ], 422);
        }

        $item->delete();

        return response()->json(['message' => 'Inventory item soft deleted.']);
    }
}
