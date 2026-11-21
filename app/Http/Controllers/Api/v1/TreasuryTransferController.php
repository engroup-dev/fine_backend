<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\TreasuryTransferResource;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\TreasuryTransfer;
use App\Services\TreasuryTransferService;
use App\Support\CompanyClock;
use App\Support\CurrentUnitContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 08 Wave 2 / TRS-03: Transfers between treasuries (التحويلات بين الخزائن).
 */
final class TreasuryTransferController extends Controller
{
    public function __construct(
        private readonly TreasuryTransferService $transferService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TreasuryTransfer::with([
            'fromCashAccount',
            'toCashAccount',
            'createdBy',
            'decidedBy',
            'journalEntry',
        ]);

        // A company-wide approver must see pending transfers from every unit,
        // not only the unit pinned in the top bar.
        if ($request->boolean('company_wide') && $request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }

        if ($request->filled('operating_unit_id') && $request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('cash_account_id')) {
            $cashAccountId = $request->query('cash_account_id');
            $query->where(function ($q) use ($cashAccountId): void {
                $q->where('from_cash_account_id', $cashAccountId)
                    ->orWhere('to_cash_account_id', $cashAccountId);
            });
        }

        if ($request->filled('from')) {
            $query->where('transfer_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $toNext = Carbon::parse($request->query('to'))->addDay()->toDateString();
            $query->where('transfer_date', '<', $toNext);
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('transfer_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $transfers = $query->latest('transfer_date')->latest('created_at')->paginate($request->integer('per_page', 25));

        return TreasuryTransferResource::collection($transfers);
    }

    public function show(Request $request, string $id): TreasuryTransferResource
    {
        $transfer = $this->visibleTo($request)->with([
            'fromCashAccount',
            'toCashAccount',
            'createdBy',
            'decidedBy',
            'journalEntry',
        ])->findOrFail($id);

        return new TreasuryTransferResource($transfer);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can create transfers.',
                'code' => 'TRANSFER_FORBIDDEN',
            ], 403);
        }

        $isCompanyWide = $user->hasCompanyWideRole();

        $validated = $request->validate([
            'from_cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'to_cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'fx_rate' => ['nullable', 'numeric', 'min:0.00000001'],
            'transfer_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'notes' => ['nullable', 'string', 'max:500'],
            'operating_unit_id' => [
                $isCompanyWide ? 'required' : 'sometimes',
                'nullable',
                'uuid',
                'exists:operating_units,id',
            ],
        ]);

        $operatingUnitId = $isCompanyWide
            ? $validated['operating_unit_id']
            : (app(CurrentUnitContext::class)->getUnitId()
                ?? $user->roles()->whereNotNull('user_roles.operating_unit_id')->value('user_roles.operating_unit_id'));

        if ($operatingUnitId === null) {
            return response()->json([
                'message' => 'Operating unit is required.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $data = array_merge($validated, [
            'operating_unit_id' => $operatingUnitId,
        ]);

        $transfer = $this->transferService->create($data, $user);

        return (new TreasuryTransferResource(
            $transfer->load([
                'fromCashAccount',
                'toCashAccount',
                'createdBy',
                'decidedBy',
                'journalEntry',
            ])
        ))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasPermission('approve-treasury')) {
            return response()->json([
                'message' => 'Only an accounting manager, the owner, or an authorized treasury officer can approve transfers.',
                'code' => 'TRANSFER_APPROVAL_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'fx_rate' => ['nullable', 'numeric', 'min:0.00000001'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->visibleTo($request)->findOrFail($id);

        $fxRate = isset($validated['fx_rate']) && is_numeric($validated['fx_rate'])
            ? (float) $validated['fx_rate']
            : null;
        $note = $validated['note'] ?? null;

        $approved = $this->transferService->approve($transfer, $user, $fxRate, $note);

        return (new TreasuryTransferResource(
            $approved->load([
                'fromCashAccount',
                'toCashAccount',
                'createdBy',
                'decidedBy',
                'journalEntry',
            ])
        ))->response();
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasPermission('approve-treasury')) {
            return response()->json([
                'message' => 'Only an accounting manager, the owner, or an authorized treasury officer can reject transfers.',
                'code' => 'TRANSFER_APPROVAL_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $transfer = $this->visibleTo($request)->findOrFail($id);

        $rejected = $this->transferService->reject($transfer, $user, $validated['note']);

        return (new TreasuryTransferResource(
            $rejected->load([
                'fromCashAccount',
                'toCashAccount',
                'createdBy',
                'decidedBy',
                'journalEntry',
            ])
        ))->response();
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $transfer = $this->visibleTo($request)->findOrFail($id);

        if ($transfer->created_by_user_id !== $user->id && ! $user->hasRole('owner')) {
            return response()->json([
                'message' => 'Only the transfer creator or the owner can cancel this transfer.',
                'code' => 'TRANSFER_CANCEL_FORBIDDEN',
            ], 403);
        }

        $cancelled = $this->transferService->cancel($transfer, $user);

        return (new TreasuryTransferResource(
            $cancelled->load([
                'fromCashAccount',
                'toCashAccount',
                'createdBy',
                'decidedBy',
                'journalEntry',
            ])
        ))->response();
    }

    /**
     * Company-wide roles act on transfers of any unit; everyone else stays
     * inside the unit context.
     *
     * @return Builder<TreasuryTransfer>
     */
    private function visibleTo(Request $request): Builder
    {
        $query = TreasuryTransfer::query();

        if ($request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class);
        }

        return $query;
    }
}
