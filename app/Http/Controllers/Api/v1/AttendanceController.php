<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
            'work_date' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|string|in:present,absent,leave,half_day',
            'employee_id' => 'sometimes|nullable|uuid|exists:employees,id',
            'search' => 'sometimes|nullable|string|max:100',
        ]);

        $query = Attendance::with('employee.entity')->orderByDesc('work_date')->orderByDesc('created_at');

        if ($request->filled('work_date')) {
            $query->whereDate('work_date', $request->query('work_date'));
        }

        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->query('to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee.entity', function ($eq) use ($search) {
                    $eq->where('name', 'like', "%{$search}%");
                })->orWhereHas('employee', function ($eq) use ($search) {
                    $eq->where('job_title', 'like', "%{$search}%");
                })->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    /**
     * Daily grid entry: one date, many employees, upserted in one call so a
     * supervisor can correct the sheet by simply saving it again.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => 'required|date|before_or_equal:today',
            'entries' => 'required|array|min:1',
            'entries.*.employee_id' => 'required|uuid|exists:employees,id',
            'entries.*.status' => 'required|string|in:present,absent,leave,half_day',
            'entries.*.hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'entries.*.overtime_hours' => 'sometimes|nullable|numeric|min:0|max:24',
            'entries.*.notes' => 'sometimes|nullable|string|max:255',
        ]);

        $saved = DB::transaction(function () use ($validated) {
            $result = [];

            foreach ($validated['entries'] as $index => $entry) {
                // Unit-scoped (trashed rows kept, as payroll still pays them): a
                // unit role cannot record attendance, and so pay, for another unit.
                $employee = Employee::withTrashed()->findOrFail($entry['employee_id']);

                // The date cast stores a midnight timestamp, so the lookup
                // must compare by date, not by the raw string.
                $existing = Attendance::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', $validated['work_date'])
                    ->first();

                $attributes = [
                    'operating_unit_id' => $employee->operating_unit_id,
                    'status' => $entry['status'],
                    'hours_worked' => $entry['hours_worked'] ?? 0,
                    'overtime_hours' => $this->resolveOvertime($entry, $entry['status'], $existing),
                    'notes' => $entry['notes'] ?? null,
                ];

                $this->assertDayHours(
                    $entry['status'],
                    (float) $attributes['hours_worked'],
                    $attributes['overtime_hours'],
                    "entries.{$index}.overtime_hours",
                );

                if ($existing !== null) {
                    $existing->update($attributes);
                    $result[] = $existing;
                } else {
                    $result[] = Attendance::create([
                        'employee_id' => $employee->id,
                        'work_date' => $validated['work_date'],
                        ...$attributes,
                    ]);
                }
            }

            return $result;
        });

        return response()->json([
            'message' => 'Attendance saved.',
            'count' => count($saved),
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'work_date' => 'required|date|before_or_equal:today',
            'status' => 'required|string|in:present,absent,leave,half_day',
            'hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'overtime_hours' => 'sometimes|nullable|numeric|min:0|max:24',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        // Unit-scoped: a unit role cannot record attendance for another unit.
        $employee = Employee::withTrashed()->findOrFail($validated['employee_id']);

        $defaultHours = match ($validated['status']) {
            'present' => 8.0,
            'half_day' => 4.0,
            default => 0.0,
        };

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $validated['work_date'])
            ->first();

        $attributes = [
            'operating_unit_id' => $employee->operating_unit_id,
            'status' => $validated['status'],
            'hours_worked' => array_key_exists('hours_worked', $validated) && $validated['hours_worked'] !== null
                ? (float) $validated['hours_worked']
                : $defaultHours,
            'overtime_hours' => $this->resolveOvertime($validated, $validated['status'], $attendance),
            'notes' => $validated['notes'] ?? null,
        ];

        $this->assertDayHours($attributes['status'], $attributes['hours_worked'], $attributes['overtime_hours'], 'overtime_hours');

        if ($attendance !== null) {
            $attendance->update($attributes);
        } else {
            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $validated['work_date'],
                ...$attributes,
            ]);
        }

        return response()->json($attendance->load('employee.entity'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $attendance = Attendance::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|required|string|in:present,absent,leave,half_day',
            'hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'overtime_hours' => 'sometimes|nullable|numeric|min:0|max:24',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        // A partial edit keeps the stored overtime when the field is not sent,
        // so turning a day with overtime into an absence or leave is refused
        // below rather than erasing the overtime silently.
        $status = $validated['status'] ?? $attendance->status->value;
        $validated['overtime_hours'] = array_key_exists('overtime_hours', $validated)
            ? (float) ($validated['overtime_hours'] ?? 0)
            : (float) $attendance->overtime_hours;

        $this->assertDayHours(
            $status,
            (float) ($validated['hours_worked'] ?? $attendance->hours_worked),
            $validated['overtime_hours'],
            'overtime_hours',
        );

        $attendance->update($validated);

        return response()->json($attendance->refresh()->load('employee.entity'));
    }

    public function destroy(string $id): JsonResponse
    {
        $attendance = Attendance::findOrFail($id);
        $attendance->delete();

        return response()->json(['message' => 'Attendance record deleted.']);
    }

    /**
     * W5-04 (HR-14): the overtime a save stores. A sent value (null = 0)
     * wins. Without one, a re-saved day keeps its recorded overtime (a client
     * that does not send the field, or a status or notes correction, must not
     * erase it), unless the day is no longer one at work (absent or leave),
     * which cannot carry overtime. A new day starts at 0.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolveOvertime(array $input, string $status, ?Attendance $existing): float
    {
        if (array_key_exists('overtime_hours', $input)) {
            return (float) ($input['overtime_hours'] ?? 0);
        }

        if ($existing === null || ! AttendanceStatus::from($status)->isPayable()) {
            return 0.0;
        }

        return (float) $existing->overtime_hours;
    }

    /**
     * W5-04 (HR-14): regular and overtime hours together fit in one day
     * (0-24 h), and overtime is only worked on a day at work (present or
     * half day): on an absence or leave it would never be paid.
     */
    private function assertDayHours(string $status, float $hoursWorked, float $overtimeHours, string $field): void
    {
        if (round($hoursWorked + $overtimeHours, 2) > 24) {
            throw ValidationException::withMessages([
                $field => 'Hours worked and overtime together cannot exceed 24 hours a day.',
            ]);
        }

        if ($overtimeHours > 0 && ! AttendanceStatus::from($status)->isPayable()) {
            throw ValidationException::withMessages([
                $field => 'Overtime can only be recorded on a day the employee was present (or worked a half day).',
            ]);
        }
    }
}
