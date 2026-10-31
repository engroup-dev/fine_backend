<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\TreasuryVoucherKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\TreasuryVoucherResource;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\TreasuryVoucher;
use App\Services\TreasuryVoucherService;
use App\Support\CompanyClock;
use App\Support\CurrentUnitContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Phase 08 Wave 2 / TRS-02: Receipt and payment vouchers (سندات القبض والصرف).
 */
final class TreasuryVoucherController extends Controller
{
    public function __construct(
        private readonly TreasuryVoucherService $voucherService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TreasuryVoucher::with([
            'cashAccount',
            'client.entity',
            'employee.entity',
            'counterAccount',
            'journalEntry',
            'cancelEntry',
            'createdBy',
            'cancelledBy',
        ]);

        if ($request->filled('operating_unit_id') && $request->user()?->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->query('cash_account_id'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('from')) {
            $query->where('voucher_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $toNext = Carbon::parse($request->query('to'))->addDay()->toDateString();
            $query->where('voucher_date', '<', $toNext);
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('voucher_number', 'like', "%{$search}%")
                    ->orWhere('payee', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $vouchers = $query->latest('voucher_date')->latest('created_at')->paginate($request->integer('per_page', 25));

        return TreasuryVoucherResource::collection($vouchers);
    }

    public function show(string $id): TreasuryVoucherResource
    {
        $voucher = TreasuryVoucher::with([
            'cashAccount',
            'client.entity',
            'employee.entity',
            'counterAccount',
            'journalEntry',
            'cancelEntry',
            'createdBy',
            'cancelledBy',
        ])->findOrFail($id);

        return new TreasuryVoucherResource($voucher);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can create vouchers.',
                'code' => 'VOUCHER_FORBIDDEN',
            ], 403);
        }

        $isCompanyWide = $user->hasCompanyWideRole();

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(TreasuryVoucherKind::class)],
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id', 'prohibits:client_id'],
            'counter_account_code' => ['required_without:client_id', 'nullable', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'fx_rate' => ['nullable', 'numeric', 'min:0.00000001'],
            'voucher_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'payee' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:500'],
            'operating_unit_id' => [
                $isCompanyWide ? 'required' : 'sometimes',
                'nullable',
                'uuid',
                'exists:operating_units,id',
            ],
        ]);

        // Company-wide users pick the unit; everyone else posts into the unit they are working in.
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

        $voucher = $this->voucherService->create($data, $user);

        return (new TreasuryVoucherResource(
            $voucher->load([
                'cashAccount',
                'client.entity',
                'employee.entity',
                'counterAccount',
                'journalEntry',
                'createdBy',
            ])
        ))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager')) {
            return response()->json([
                'message' => 'Only an accounting manager or the owner can cancel vouchers.',
                'code' => 'VOUCHER_CANCEL_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $voucher = TreasuryVoucher::findOrFail($id);

        $cancelled = $this->voucherService->cancel($voucher, $validated['reason'], $user);

        return (new TreasuryVoucherResource(
            $cancelled->load([
                'cashAccount',
                'client.entity',
                'employee.entity',
                'counterAccount',
                'journalEntry',
                'cancelEntry',
                'createdBy',
                'cancelledBy',
            ])
        ))->response();
    }
}
