<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ChequeStatus;
use App\Http\Controllers\Concerns\ListsAllRows;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ChequeResource;
use App\Models\Cheque;
use App\Models\Scopes\OperatingUnitScope;
use App\Services\ChequeService;
use App\Support\CompanyClock;
use App\Support\CurrentUnitContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 08 Wave 2 / TRS-05: Customer cheques (الشيكات الواردة).
 */
final class ChequeController extends Controller
{
    use ListsAllRows;

    public function __construct(
        private readonly ChequeService $chequeService,
    ) {}

    /**
     * Paged (per_page, default 25, clamped to 1..100). `all=1` returns every
     * row the same filters and unit scope match, as one page of the same
     * paginator shape, up to `fine.export_all_limit` rows (more is 422
     * EXPORT_TOO_LARGE). The desktop CSV export uses it (W6-04).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Cheque::with([
            'client.entity',
            'supplier.account',
            'counterAccount',
            'depositCashAccount',
            'createdBy',
        ]);

        if ($request->filled('operating_unit_id') && $request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->query('direction'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('due_from')) {
            $query->where('due_date', '>=', $request->query('due_from'));
        }

        if ($request->filled('due_to')) {
            $query->where('due_date', '<=', $request->query('due_to'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function (Builder $q) use ($search): void {
                $q->where('cheque_no', 'like', "%{$search}%")
                    ->orWhere('cheque_number_internal', 'like', "%{$search}%")
                    ->orWhere('bank_name', 'like', "%{$search}%")
                    ->orWhere('drawer_name', 'like', "%{$search}%")
                    ->orWhere('payee_name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('due_date', 'asc')->orderBy('created_at', 'asc');

        $cheques = $this->paginateOrAll($request, $query, min(max($request->integer('per_page', 25), 1), 100));

        return ChequeResource::collection($cheques);
    }

    public function summary(Request $request): JsonResponse
    {
        $direction = (string) $request->query('direction', 'incoming');
        $query = Cheque::query()->where('direction', $direction);

        if ($request->filled('operating_unit_id') && $request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        $today = CompanyClock::today();
        $next7Days = Carbon::parse($today)->addDays(7)->toDateString();

        $byStatus = [];
        foreach (ChequeStatus::cases() as $case) {
            $q = (clone $query)->where('status', $case->value);
            $count = (int) $q->count();
            $total = round((float) $q->sum('amount'), 4);
            $byStatus[$case->value] = [
                'count' => $count,
                'total' => $total,
            ];
        }

        $activeStatus = $direction === 'outgoing'
            ? ChequeStatus::Issued
            : ChequeStatus::InPortfolio;

        $due7Query = (clone $query)
            ->where('status', $activeStatus->value)
            ->where('due_date', '>=', $today)
            ->where('due_date', '<=', $next7Days);
        $dueWithin7Days = [
            'count' => (int) $due7Query->count(),
            'total' => round((float) $due7Query->sum('amount'), 4),
        ];

        $overdueQuery = (clone $query)
            ->where('status', $activeStatus->value)
            ->where('due_date', '<', $today);
        $overdue = [
            'count' => (int) $overdueQuery->count(),
            'total' => round((float) $overdueQuery->sum('amount'), 4),
        ];

        return response()->json([
            'data' => [
                'by_status' => $byStatus,
                'due_within_7_days' => $dueWithin7Days,
                'overdue' => $overdue,
            ],
        ]);
    }

    public function show(Request $request, string $id): ChequeResource
    {
        $query = Cheque::with([
            'client.entity',
            'supplier.account',
            'counterAccount',
            'depositCashAccount',
            'createdBy',
            'events.journalEntry',
            'events.user',
        ]);

        if ($request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }

        $cheque = $query->findOrFail($id);

        return new ChequeResource($cheque);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'cheque_no' => ['required', 'string', 'max:50'],
            'bank_name' => ['required', 'string', 'max:100'],
            'drawer_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:3'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date'],
            'received_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'is_certified' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'operating_unit_id' => ['sometimes', 'nullable', 'uuid', 'exists:operating_units,id'],
        ]);

        $isCompanyWide = $user->hasCompanyWideRole();
        $operatingUnitId = $isCompanyWide && ! empty($validated['operating_unit_id'])
            ? $validated['operating_unit_id']
            : (app(CurrentUnitContext::class)->getUnitId()
                ?? $user->roles()->whereNotNull('user_roles.operating_unit_id')->value('user_roles.operating_unit_id')
                ?? $validated['operating_unit_id'] ?? null);

        $data = array_merge($validated, [
            'operating_unit_id' => $operatingUnitId,
        ]);

        $cheque = $this->chequeService->receive($data, $user);

        return (new ChequeResource(
            $cheque->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response()->setStatusCode(201);
    }

    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'cheque_no' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'due_date' => ['required', 'date'],
            'issue_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'supplier_id' => ['sometimes', 'nullable', 'uuid', 'exists:suppliers,id'],
            'counter_account_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'operating_unit_id' => ['sometimes', 'nullable', 'uuid', 'exists:operating_units,id'],
        ]);

        $isCompanyWide = $user->hasCompanyWideRole();
        $operatingUnitId = $isCompanyWide && ! empty($validated['operating_unit_id'])
            ? $validated['operating_unit_id']
            : (app(CurrentUnitContext::class)->getUnitId()
                ?? $user->roles()->whereNotNull('user_roles.operating_unit_id')->value('user_roles.operating_unit_id')
                ?? $validated['operating_unit_id'] ?? null);

        $data = array_merge($validated, [
            'operating_unit_id' => $operatingUnitId,
        ]);

        $cheque = $this->chequeService->issue($data, $user);

        return (new ChequeResource(
            $cheque->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response()->setStatusCode(201);
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $query = Cheque::query();
        if ($user->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }
        $cheque = $query->findOrFail($id);

        $updated = $this->chequeService->deposit(
            $cheque,
            $validated['cash_account_id'],
            $validated['date'] ?? null,
            $user,
            $validated['note'] ?? null,
        );

        return (new ChequeResource(
            $updated->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response();
    }

    public function clear(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'cash_account_id' => ['sometimes', 'nullable', 'uuid', 'exists:cash_accounts,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $query = Cheque::query();
        if ($user->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }
        $cheque = $query->findOrFail($id);

        if ($cheque->direction === 'outgoing') {
            $updated = $this->chequeService->clearIssued(
                $cheque,
                $validated['date'] ?? null,
                $user,
                $validated['note'] ?? null,
            );
        } else {
            $updated = $this->chequeService->clear(
                $cheque,
                $validated['date'] ?? null,
                $user,
                $validated['cash_account_id'] ?? null,
                $validated['note'] ?? null,
            );
        }

        return (new ChequeResource(
            $updated->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response();
    }

    public function bounce(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
        ]);

        $query = Cheque::query();
        if ($user->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }
        $cheque = $query->findOrFail($id);

        $updated = $this->chequeService->bounce(
            $cheque,
            $validated['date'] ?? null,
            $validated['reason'],
            $user,
        );

        return (new ChequeResource(
            $updated->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response();
    }

    public function returnToDrawer(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $query = Cheque::query();
        if ($user->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }
        $cheque = $query->findOrFail($id);

        $updated = $this->chequeService->returnToDrawer(
            $cheque,
            $validated['date'] ?? null,
            $validated['note'] ?? null,
            $user,
        );

        return (new ChequeResource(
            $updated->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response();
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can manage cheques.',
                'code' => 'CHEQUE_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $query = Cheque::query();
        if ($user->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }
        $cheque = $query->findOrFail($id);

        $updated = $this->chequeService->cancelIssued(
            $cheque,
            $validated['reason'],
            $user,
        );

        return (new ChequeResource(
            $updated->load([
                'client.entity',
                'supplier.account',
                'counterAccount',
                'depositCashAccount',
                'createdBy',
                'events.journalEntry',
                'events.user',
            ])
        ))->response();
    }
}
