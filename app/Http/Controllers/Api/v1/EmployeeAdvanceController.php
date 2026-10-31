<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EmployeeAdvanceStatus;
use App\Enums\EmployeeAdvanceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\EmployeeAdvanceResource;
use App\Models\EmployeeAdvance;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\EmployeeAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * W5-02 (decision #14, HR-12): salary advances and loans. HR (manage-hr)
 * requests and cancels a request; approval, cancelling an approval, payout
 * and cash repayment need approve-advances (owner, accounting manager, HR
 * manager).
 */
final class EmployeeAdvanceController extends Controller
{
    private const RELATIONS = [
        'employee.entity',
        'cashAccount:id,name,currency',
        'payoutEntry:id,reference',
        'requestedBy:id,name',
        'approvedBy:id,name',
        'installmentRows',
    ];

    public function __construct(private readonly EmployeeAdvanceService $advances) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(EmployeeAdvanceStatus::class)],
            'type' => ['nullable', Rule::enum(EmployeeAdvanceType::class)],
        ]);

        $query = EmployeeAdvance::with(self::RELATIONS)->latest();

        foreach (['employee_id', 'status', 'type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return EmployeeAdvanceResource::collection(
            $query->paginate(min($request->integer('per_page', 25), 100))
        );
    }

    public function show(string $id): EmployeeAdvanceResource
    {
        $advance = EmployeeAdvance::with(self::RELATIONS)->findOrFail($id);

        // The payout and every cash repayment, posted against this advance.
        $advance->setRelation('entries', JournalEntry::query()
            ->where('source_document_type', 'EmployeeAdvance')
            ->where('source_document_id', $advance->id)
            ->with('lines')
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get());

        return new EmployeeAdvanceResource($advance);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(EmployeeAdvanceType::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'installments' => ['required', 'integer', 'min:1', 'max:120'],
            'start_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $advance = $this->advances->request($validated, $request->user());

        return (new EmployeeAdvanceResource($advance->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(Request $request, string $id): EmployeeAdvanceResource
    {
        $advance = $this->advances->approve(EmployeeAdvance::findOrFail($id), $request->user());

        return new EmployeeAdvanceResource($advance->load(self::RELATIONS));
    }

    public function pay(Request $request, string $id): EmployeeAdvanceResource
    {
        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
        ]);

        $advance = $this->advances->payout(EmployeeAdvance::findOrFail($id), $validated['cash_account_id'], $request->user());

        return new EmployeeAdvanceResource($advance->load(self::RELATIONS));
    }

    public function repay(Request $request, string $id): EmployeeAdvanceResource
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
        ]);

        $advance = $this->advances->repay(
            EmployeeAdvance::findOrFail($id),
            (float) $validated['amount'],
            $validated['cash_account_id'],
            $request->user(),
        );

        return new EmployeeAdvanceResource($advance->load(self::RELATIONS));
    }

    /**
     * HR cancels a request; undoing an approval needs an approver, the same
     * roles and permission as the approve route.
     */
    public function cancel(Request $request, string $id): EmployeeAdvanceResource|JsonResponse
    {
        $advance = EmployeeAdvance::findOrFail($id);
        $mayCancelApproved = self::isApprover($request->user());

        if ($advance->status === EmployeeAdvanceStatus::Approved && ! $mayCancelApproved) {
            return response()->json([
                'message' => 'Only an approve-advances holder can cancel an approved advance.',
                'code' => 'ADVANCE_CANCEL_FORBIDDEN',
            ], 403);
        }

        $advance = $this->advances->cancel($advance, $mayCancelApproved);

        return new EmployeeAdvanceResource($advance->load(self::RELATIONS));
    }

    private static function isApprover(User $user): bool
    {
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return true;
        }

        return ($user->hasRole('accounting-manager') || $user->hasRole('hr-manager'))
            && $user->hasPermission('approve-advances');
    }
}
