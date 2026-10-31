<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Services\PartnerReportService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartnerReportController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly PartnerReportService $reports,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function balances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:clients,suppliers',
            'as_of' => 'sometimes|nullable|date',
            'client_group_id' => 'sometimes|nullable|uuid',
            'operating_unit_id' => 'sometimes|nullable|uuid',
            'company_wide' => 'sometimes|boolean',
        ]);

        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        if ($validated['type'] === 'clients') {
            return response()->json($this->reports->clientBalances(
                $request->query('as_of'),
                $unitId,
                $validated['client_group_id'] ?? null,
            ));
        }

        return response()->json($this->reports->supplierBalances(
            $request->query('as_of'),
            $unitId,
        ));
    }

    public function statement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'party_type' => 'required|in:client,supplier',
            'party_id' => 'required|uuid',
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
            'operating_unit_id' => 'sometimes|nullable|uuid',
            'company_wide' => 'sometimes|boolean',
        ]);

        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        if ($validated['party_type'] === 'client') {
            return response()->json($this->reports->clientStatement(
                $validated['party_id'],
                $request->query('from'),
                $request->query('to'),
                $unitId,
            ));
        }

        return response()->json($this->reports->supplierStatement(
            $validated['party_id'],
            $request->query('from'),
            $request->query('to'),
            $unitId,
        ));
    }
}
