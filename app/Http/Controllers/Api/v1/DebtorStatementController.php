<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\DebtorStatementRequest;
use App\Services\ReceivablesLedgerService;
use App\Support\CurrentUnitContext;
use App\Support\DebtorStatementFilters;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

final class DebtorStatementController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly ReceivablesLedgerService $ledger,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(DebtorStatementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $clientIds = array_values($validated['client_ids'] ?? []);
        $clientGroupId = $validated['client_group_id'] ?? null;

        $filters = new DebtorStatementFilters(
            from: Carbon::parse($validated['from'])->toDateString(),
            to: Carbon::parse($validated['to'])->toDateString(),
            operatingUnitId: $this->resolveReportUnitId($request, $this->unitContext),
            clientIds: $clientIds,
            statuses: array_values($validated['statuses'] ?? []),
            city: $validated['city'] ?? null,
            minBalance: isset($validated['min_balance']) ? (float) $validated['min_balance'] : null,
            // Selecting specific clients or a client group excludes the unassigned bucket.
            includeUnassigned: $clientIds === [] && $clientGroupId === null && $request->boolean('include_unassigned'),
            clientGroupId: $clientGroupId,
        );

        return response()->json($this->ledger->debtorStatements($filters));
    }
}
