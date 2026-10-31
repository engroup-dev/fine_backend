<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Services\SalesMarginReportService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SalesMarginReportController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly SalesMarginReportService $reports,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
            'group_by' => 'sometimes|nullable|string|in:item,category,client,client_group,sale',
            'client_group_id' => 'sometimes|nullable|uuid',
        ]);

        return response()->json($this->reports->salesMargin(
            $request->query('from'),
            $request->query('to'),
            $request->query('group_by') ?: 'item',
            $this->resolveUnitId($request),
            $request->query('client_group_id') ?: null,
        ));
    }

    private function resolveUnitId(Request $request): ?string
    {
        return $this->resolveReportUnitId($request, $this->unitContext);
    }
}
