<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\BankReconciliationAdjustmentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\BankReconciliationResource;
use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Services\BankReconciliationService;
use App\Support\CompanyClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Phase 08 Wave 2 / TRS-04: Bank reconciliation controller (التسوية المصرفية).
 */
final class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BankReconciliation::with([
            'cashAccount',
            'createdBy',
            'finishedBy',
        ]);

        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->query('cash_account_id'));
        }

        $reconciliations = $query->latest('statement_date')->latest('created_at')->get();

        $data = $reconciliations->map(function (BankReconciliation $rec) use ($request): array {
            $arr = (new BankReconciliationResource($rec))->toArray($request);
            $arr['summary'] = $this->service->summary($rec);

            return $arr;
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $rec = BankReconciliation::with([
            'cashAccount',
            'createdBy',
            'finishedBy',
        ])->findOrFail($id);

        return $this->showPayload($rec, $request, 200);
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'statement_date' => ['required', 'date', 'before_or_equal:'.CompanyClock::today()],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $cashAccount = CashAccount::findOrFail($validated['cash_account_id']);

        $rec = $this->service->start(
            $cashAccount,
            (string) $validated['statement_date'],
            (float) $validated['statement_balance'],
            $request->user(),
            $validated['notes'] ?? null,
        );

        return $this->showPayload($rec, $request, 201);
    }

    public function toggleLines(Request $request, string $id): JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'line_ids' => ['required', 'array'],
            'line_ids.*' => ['required', 'uuid'],
            'cleared' => ['required', 'boolean'],
        ]);

        $rec = BankReconciliation::findOrFail($id);

        $this->service->toggle($rec, $validated['line_ids'], (bool) $validated['cleared']);

        return $this->showPayload($rec->fresh(), $request, 200);
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $rec = BankReconciliation::findOrFail($id);

        $finished = $this->service->finish($rec, $request->user());

        return $this->showPayload($finished, $request, 200);
    }

    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $rec = BankReconciliation::findOrFail($id);

        $this->service->discard($rec);

        return response()->noContent();
    }

    /**
     * TRS-04: post a statement item that is missing from the books (bank
     * charges, interest, direct deposits or debits) and clear it.
     */
    public function storeAdjustment(Request $request, string $id): JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'type' => ['required', Rule::enum(BankReconciliationAdjustmentType::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:250'],
            'counter_account_code' => ['nullable', 'string', 'max:20'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $rec = BankReconciliation::findOrFail($id);

        $this->service->addAdjustment($rec, $validated, $request->user());

        return $this->showPayload($rec->fresh(), $request, 201);
    }

    public function destroyAdjustment(Request $request, string $id, string $adjustmentId): JsonResponse
    {
        if ($forbidden = $this->checkMutationPermission($request)) {
            return $forbidden;
        }

        $rec = BankReconciliation::findOrFail($id);

        $this->service->removeAdjustment($rec, $adjustmentId, $request->user());

        return $this->showPayload($rec->fresh(), $request, 200);
    }

    private function checkMutationPermission(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->hasRole('owner') && ! $user->hasRole('admin') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer'))) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can perform this action.',
                'code' => 'RECONCILIATION_FORBIDDEN',
            ], 403);
        }

        return null;
    }

    private function showPayload(BankReconciliation $rec, Request $request, int $status = 200): JsonResponse
    {
        $rec->loadMissing([
            'cashAccount',
            'createdBy',
            'finishedBy',
        ]);

        return response()->json([
            'data' => [
                'reconciliation' => (new BankReconciliationResource($rec))->toArray($request),
                'summary' => $this->service->summary($rec),
                'lines' => $this->service->candidates($rec),
                'adjustments' => $this->service->adjustments($rec),
            ],
        ], $status);
    }
}
