<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EmployeeAdvanceStatus;
use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Enums\PayType;
use App\Exceptions\LedgerRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreEmployeeRequest;
use App\Http\Resources\v1\EmployeeCompensationResource;
use App\Http\Resources\v1\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Entity;
use App\Models\Scopes\OperatingUnitScope;
use App\Services\EmployeeCompensationService;
use App\Services\EntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EmployeeController extends Controller
{
    /**
     * Display a listing of employees.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Employee::with(['entity', 'operatingUnit', 'compensations']);

        // Unit drill-down is only honoured for company-wide roles; a unit
        // caller's listing stays inside their ambient unit scope. Lifting only
        // the unit scope keeps SoftDeletes intact.
        if ($request->has('operating_unit_id') && $request->user()->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        // W5-02: deleted employees too, so an advance they still owe can be
        // settled (receipt vouchers and repayments only; see deleted_at).
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        return EmployeeResource::collection($query->latest()->get());
    }

    /**
     * Store a newly created employee.
     */
    public function store(StoreEmployeeRequest $request, EntityService $entityService, EmployeeCompensationService $compensations): JsonResponse
    {
        if (($request->filled('bank_name') || $request->filled('bank_account')) && ! EmployeeResource::holdsPayRole($request->user())) {
            return $this->bankDetailsForbidden();
        }

        $this->assertContractDates($request->only('hire_date', 'contract_start', 'contract_end', 'probation_end', 'termination_date'));

        $employee = DB::transaction(function () use ($request, $entityService, $compensations) {
            $entityId = $request->entity_id;

            if (! $entityId) {
                $name = $request->input('name');
                if (! $name && ($request->first_name || $request->last_name)) {
                    $name = trim(($request->first_name ?? '').' '.($request->last_name ?? ''));
                }
                if (! $name) {
                    $name = 'Employee '.$request->job_title;
                }

                $entity = $entityService->createEntityForDomainModel(
                    roleType: EntityRoleType::Employee,
                    attributes: [
                        'name' => $name,
                        'entity_type' => $request->input('entity_type', EntityType::Individual),
                        'tax_number' => $request->tax_number,
                    ],
                    operatingUnitId: $request->operating_unit_id
                );
                $entityId = $entity->id;
            } else {
                $entity = Entity::findOrFail($entityId);
                $entityService->ensureEntityRole($entity, EntityRoleType::Employee, $request->operating_unit_id);
            }

            $employee = Employee::create([
                'entity_id' => $entityId,
                'employee_number' => $request->employee_number,
                'operating_unit_id' => $request->operating_unit_id,
                'job_title' => $request->job_title,
                'labor_role' => $request->labor_role,
                'pay_type' => $request->pay_type,
                'monthly_salary' => $request->monthly_salary,
                'hourly_rate' => $request->hourly_rate,
                'hire_date' => $request->hire_date,
                'contract_start' => $request->contract_start,
                'contract_end' => $request->contract_end,
                'probation_end' => $request->probation_end,
                'termination_date' => $request->termination_date,
                'bank_name' => $request->bank_name,
                'bank_account' => $request->bank_account,
                'status' => $request->input('status', 'active'),
            ]);

            // HR-11: the hiring terms open the pay history.
            $compensations->recordOpening($employee, $request->user()?->id);

            return $employee->load(['entity', 'operatingUnit', 'compensations']);
        });

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified employee.
     */
    public function show(string $id): EmployeeResource
    {
        $employee = Employee::with(['entity', 'operatingUnit', 'compensations'])->findOrFail($id);

        return new EmployeeResource($employee);
    }

    /**
     * Update the specified employee.
     */
    public function attendance(Request $request, string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        return response()->json(
            $employee->attendances()->orderByDesc('work_date')->paginate($request->integer('per_page', 31))
        );
    }

    public function payslips(Request $request, string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        return response()->json(
            $employee->payslips()->with('payrollRun:id,period,status')
                ->latest()->paginate($request->integer('per_page', 24))
        );
    }

    /**
     * Edits the employee card. Pay terms are not edited here: HR-11 keeps them
     * as dated history, changed through POST /employees/{id}/compensations.
     */
    public function update(Request $request, string $id, EmployeeCompensationService $compensations): EmployeeResource|JsonResponse
    {
        $employee = Employee::findOrFail($id);

        // Bank details decide where salary is paid: only the roles that may see
        // them may change them. Sending back the stored value is not a change.
        foreach (['bank_name', 'bank_account'] as $field) {
            if ($request->exists($field)
                && (string) $request->input($field) !== (string) $employee->getAttribute($field)
                && ! EmployeeResource::holdsPayRole($request->user())) {
                return $this->bankDetailsForbidden();
            }
        }

        $payTermsMessage = 'Pay type and pay rates change through a dated pay change (POST /employees/{id}/compensations), not by editing the employee.';

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('employees', 'employee_number')->ignore($employee->id)],
            'job_title' => 'sometimes|required|string|max:150',
            'labor_role' => 'sometimes|nullable|string|max:100',
            'pay_type' => 'prohibited',
            'monthly_salary' => 'prohibited',
            'hourly_rate' => 'prohibited',
            'hire_date' => 'sometimes|required|date',
            'contract_start' => 'sometimes|nullable|date',
            'contract_end' => 'sometimes|nullable|date',
            'probation_end' => 'sometimes|nullable|date',
            'termination_date' => 'sometimes|nullable|date',
            'bank_name' => 'sometimes|nullable|string|max:150',
            'bank_account' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|required|string',
            'record_version' => 'required|integer',
        ], [
            'pay_type.prohibited' => $payTermsMessage,
            'monthly_salary.prohibited' => $payTermsMessage,
            'hourly_rate.prohibited' => $payTermsMessage,
        ]);

        $this->assertContractDates([
            'hire_date' => $request->exists('hire_date') ? $request->input('hire_date') : $employee->hire_date?->toDateString(),
            'contract_start' => $request->exists('contract_start') ? $request->input('contract_start') : $employee->contract_start?->toDateString(),
            'contract_end' => $request->exists('contract_end') ? $request->input('contract_end') : $employee->contract_end?->toDateString(),
            'probation_end' => $request->exists('probation_end') ? $request->input('probation_end') : $employee->probation_end?->toDateString(),
            'termination_date' => $request->exists('termination_date') ? $request->input('termination_date') : $employee->termination_date?->toDateString(),
        ]);

        $previousHireDate = $employee->hire_date->toDateString();

        try {
            DB::transaction(function () use ($request, $employee, $compensations, $previousHireDate) {
                // W5-02 (HR-12): an advance lives in the employee's unit (its 1215
                // payout, payroll recovery and repayments must match it), so the
                // unit cannot change while one is open. The employee row is locked
                // first, as EmployeeAdvanceService::request() does, so a request
                // cannot slip in between this check and the move.
                $current = Employee::query()->lockForUpdate()->findOrFail($employee->id);

                if ($request->filled('operating_unit_id') && $request->input('operating_unit_id') !== $current->operating_unit_id) {
                    $openAdvanceIds = EmployeeAdvance::withoutGlobalScopes()
                        ->whereNull('deleted_at')
                        ->where('employee_id', $employee->id)
                        ->whereIn('status', [EmployeeAdvanceStatus::Requested, EmployeeAdvanceStatus::Approved, EmployeeAdvanceStatus::Paid])
                        ->pluck('id')
                        ->all();

                    if ($openAdvanceIds !== []) {
                        throw new LedgerRuleException(
                            'The employee has an open advance or loan; settle or cancel it before moving them to another unit.',
                            'EMPLOYEE_HAS_OPEN_ADVANCE',
                            ['employee_advance_ids' => $openAdvanceIds],
                        );
                    }
                }

                $employee->update($request->only(
                    'operating_unit_id',
                    'employee_number',
                    'job_title',
                    'labor_role',
                    'hire_date',
                    'contract_start',
                    'contract_end',
                    'probation_end',
                    'termination_date',
                    'bank_name',
                    'bank_account',
                    'status',
                    'record_version'
                ));

                // HR-11: the pay history starts at the hire date.
                $compensations->moveOpening($employee, $previousHireDate);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_COMPENSATION'], 422);
        }

        return new EmployeeResource($employee->load(['entity', 'operatingUnit', 'compensations']));
    }

    /**
     * HR-11: the employee's pay terms history, newest first.
     */
    public function compensations(string $id): AnonymousResourceCollection
    {
        $employee = Employee::findOrFail($id);

        return EmployeeCompensationResource::collection(
            $employee->compensations()->reorder('effective_from', 'desc')->with('creator:id,name')->get()
        );
    }

    /**
     * HR-11 / HR-10: records a dated pay change. Pay terms are set by HR,
     * accounting or the owner only.
     */
    public function storeCompensation(Request $request, string $id, EmployeeCompensationService $compensations): JsonResponse
    {
        $user = $request->user();

        if (! EmployeeResource::holdsPayRole($user)) {
            return $this->compensationForbidden();
        }

        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'pay_type' => ['required', Rule::enum(PayType::class)->except([PayType::PieceRate])],
            'monthly_salary' => ['nullable', 'required_if:pay_type,'.PayType::Monthly->value, 'numeric', 'min:0'],
            // An hourly employee with no labor role has no role rate to fall
            // back on; without a personal rate the next payroll run would be
            // refused for everyone.
            'hourly_rate' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('pay_type') === PayType::Hourly->value && $employee->labor_role === null),
                'numeric',
                'min:0',
            ],
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'pay_type.enum' => 'The pay type must be monthly or hourly; piece-rate pay is not supported.',
            'hourly_rate.required' => 'An hourly employee without a labor role needs a personal hourly rate.',
        ]);

        try {
            $compensation = $compensations->record($employee, $validated, $user->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_COMPENSATION'], 422);
        }

        return (new EmployeeCompensationResource($compensation->load('creator:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Soft delete the specified employee.
     */
    public function destroy(string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    /**
     * HR-11 / HR-10: removes a mistaken pay change that no frozen payroll
     * covers yet. A correction is a removal followed by the right change.
     */
    public function destroyCompensation(Request $request, string $id, string $compensationId, EmployeeCompensationService $compensations): JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->compensationForbidden();
        }

        $employee = Employee::findOrFail($id);

        try {
            $compensations->remove($employee, $compensationId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_COMPENSATION'], 422);
        }

        return response()->json(['message' => 'Pay change removed.']);
    }

    private function compensationForbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Only HR, accounting or the owner can change pay.',
            'code' => 'COMPENSATION_FORBIDDEN',
        ], 403);
    }

    private function bankDetailsForbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Only HR, accounting or the owner can set bank details.',
            'code' => 'BANK_DETAILS_FORBIDDEN',
        ], 403);
    }

    /**
     * Contract end and probation end cannot fall before the contract start,
     * and the termination date cannot fall before the hire date or the
     * contract start.
     *
     * @param  array{hire_date?: string|null, contract_start?: string|null, contract_end?: string|null, probation_end?: string|null, termination_date?: string|null}  $dates
     */
    private function assertContractDates(array $dates): void
    {
        $errors = [];

        $notBefore = fn (?string $value, ?string $floor): bool => $value !== null && $floor !== null
            && Carbon::parse($value)->startOfDay()->lt(Carbon::parse($floor)->startOfDay());

        foreach (['contract_end', 'probation_end'] as $field) {
            if ($notBefore($dates[$field] ?? null, $dates['contract_start'] ?? null)) {
                $errors[$field] = "The {$field} cannot be before the contract start.";
            }
        }

        if ($notBefore($dates['termination_date'] ?? null, $dates['hire_date'] ?? null)) {
            $errors['termination_date'] = 'The termination date cannot be before the hire date.';
        } elseif ($notBefore($dates['termination_date'] ?? null, $dates['contract_start'] ?? null)) {
            $errors['termination_date'] = 'The termination date cannot be before the contract start.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
