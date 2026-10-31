<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\EmployeeResource;
use App\Http\Resources\v1\WorkShiftResource;
use App\Models\Employee;
use App\Models\WorkShift;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * W5-04 (HR-14): company-wide work shifts. Anyone in HR reads them; since a
 * shift sets overtime pay, only the roles that set pay (HR-10: HR manager,
 * accounting manager, owner) write them.
 */
final class WorkShiftController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkShiftResource::collection(WorkShift::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        $validated = $request->validate($this->rules());

        $shift = WorkShift::create($validated);

        return (new WorkShiftResource($shift->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $id, PayrollService $payroll): WorkShiftResource|JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        $shift = WorkShift::findOrFail($id);

        $validated = $request->validate([
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules()),
            'record_version' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($shift, $validated, $payroll): void {
            $shift->update($validated);

            // A calculated run paid this shift's overtime on the old terms.
            if ($shift->wasChanged(['overtime_multiplier', 'standard_hours'])) {
                $payroll->flagRunsWithOvertime(
                    Employee::withoutGlobalScopes()->where('work_shift_id', $shift->id)->pluck('id')->all(),
                );
            }
        });

        return new WorkShiftResource($shift->refresh());
    }

    /**
     * A shift still assigned to an employee is refused (WORK_SHIFT_IN_USE):
     * reassign them first. Trashed employees cannot be reassigned, so they do
     * not block; Employee::workShift() reads trashed shifts, so payroll keeps
     * paying them on this shift's terms and no one's overtime changes silently.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        return DB::transaction(function () use ($id) {
            $shift = WorkShift::query()->lockForUpdate()->findOrFail($id);

            $employeeIds = Employee::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('work_shift_id', $shift->id)
                ->pluck('id')
                ->all();

            if ($employeeIds !== []) {
                return response()->json([
                    'message' => 'The shift is assigned to employees; move them to another shift first.',
                    'code' => 'WORK_SHIFT_IN_USE',
                    'employee_ids' => $employeeIds,
                ], 422);
            }

            $shift->delete();

            return response()->json(['message' => 'Shift deleted.']);
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'standard_hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'overtime_multiplier' => ['numeric', 'min:1', 'max:10'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Only HR, accounting or the owner can change work shifts.',
            'code' => 'WORK_SHIFT_FORBIDDEN',
        ], 403);
    }
}
