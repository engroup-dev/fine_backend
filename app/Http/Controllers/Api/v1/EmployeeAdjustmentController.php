<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EmployeeAdjustmentStatus;
use App\Enums\PayItemKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\EmployeeAdjustmentResource;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\User;
use App\Services\EmployeePayItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * W5-03 (HR-13): one-off rewards and deductions for a payroll period. HR
 * enters and edits drafts; approval, and removing an approved one, need
 * approve-pay-adjustments (owner, accounting manager, HR manager).
 */
final class EmployeeAdjustmentController extends Controller
{
    private const RELATIONS = ['employee.entity', 'creator:id,name', 'approvedBy:id,name'];

    public function __construct(private readonly EmployeePayItemService $payItems) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['nullable', Rule::enum(EmployeeAdjustmentStatus::class)],
            'kind' => ['nullable', Rule::enum(PayItemKind::class)],
        ]);

        // Unit-scoped through the employee.
        $query = EmployeeAdjustment::query()
            ->whereIn('employee_id', Employee::withTrashed()->pluck('id'))
            ->with(self::RELATIONS)
            ->orderByDesc('period')
            ->latest();

        foreach (['employee_id', 'period', 'status', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return EmployeeAdjustmentResource::collection(
            $query->paginate(min($request->integer('per_page', 25), 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'kind' => ['required', Rule::enum(PayItemKind::class)],
            'code' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $adjustment = $this->payItems->createAdjustment($validated, $request->user());

        return (new EmployeeAdjustmentResource($adjustment->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $id): EmployeeAdjustmentResource
    {
        $adjustment = $this->find($id);

        $validated = $request->validate([
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'kind' => ['sometimes', Rule::enum(PayItemKind::class)],
            'code' => ['sometimes', 'string', 'max:64'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'reason' => ['sometimes', 'string', 'max:500'],
            'record_version' => ['sometimes', 'integer'],
        ]);

        $adjustment = $this->payItems->updateAdjustment($adjustment, $validated);

        return new EmployeeAdjustmentResource($adjustment->load(self::RELATIONS));
    }

    public function approve(Request $request, string $id): EmployeeAdjustmentResource
    {
        $adjustment = $this->payItems->approveAdjustment($this->find($id), $request->user());

        return new EmployeeAdjustmentResource($adjustment->load(self::RELATIONS));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $adjustment = $this->find($id);

        if ($adjustment->status === EmployeeAdjustmentStatus::Approved && ! self::isApprover($request->user())) {
            return response()->json([
                'message' => 'Only an approve-pay-adjustments holder can remove an approved adjustment.',
                'code' => 'PAY_ADJUSTMENT_DELETE_FORBIDDEN',
            ], 403);
        }

        $this->payItems->deleteAdjustment($adjustment);

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Unit-scoped through the employee.
     */
    private function find(string $id): EmployeeAdjustment
    {
        $adjustment = EmployeeAdjustment::query()->findOrFail($id);

        Employee::withTrashed()->findOrFail($adjustment->employee_id);

        return $adjustment;
    }

    private static function isApprover(User $user): bool
    {
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return true;
        }

        return ($user->hasRole('accounting-manager') || $user->hasRole('hr-manager'))
            && $user->hasPermission('approve-pay-adjustments');
    }
}
