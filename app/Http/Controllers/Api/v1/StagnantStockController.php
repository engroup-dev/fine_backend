<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\StagnantStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W4-04: stagnant stock report — lots holding stock (available, reserved,
 * quarantined) idle for more than `days`.
 */
final class StagnantStockController extends Controller
{
    public function __construct(private readonly StagnantStockService $stagnantStock) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'warehouse_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'category_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
        ]);

        return response()->json($this->stagnantStock->report($validated));
    }
}
