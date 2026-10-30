<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'leave_type' => ['nullable', Rule::enum(LeaveType::class)],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $query = LeaveRequest::with(['employee.entity', 'decidedBy'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('leave_type')) {
            $query->where('leave_type', $request->query('leave_type'));
        }

        // Leave counted in a year is leave that starts in it.
        if ($request->filled('year')) {
            $year = $request->integer('year');
            $query->whereDate('start_date', '>=', sprintf('%04d-01-01', $year))
                ->whereDate('start_date', '<=', sprintf('%04d-12-31', $year));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'leave_type' => 'required|string|in:annual,sick,unpaid,other',
            'reason' => 'nullable|string|max:255',
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);

        $leave = LeaveRequest::create([
            ...$validated,
            'operating_unit_id' => $employee->operating_unit_id,
            'status' => LeaveRequestStatus::Pending,
        ]);

        return response()->json($leave->load('employee.entity'), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, LeaveRequestStatus::Approved);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, LeaveRequestStatus::Rejected);
    }

    /**
     * HR-09: a decision is taken once, by a named approver.
     */
    private function decide(Request $request, string $id, LeaveRequestStatus $decision): JsonResponse
    {
        $leave = LeaveRequest::findOrFail($id);

        if ($leave->status !== LeaveRequestStatus::Pending) {
            return response()->json([
                'message' => 'This request has already been decided.',
                'code' => 'LEAVE_ALREADY_DECIDED',
            ], 422);
        }

        $request->validate(['notes' => 'nullable|string|max:255']);

        $leave->update([
            'status' => $decision,
            'decided_by_user_id' => $request->user()->id,
            'decided_at' => now(),
            'decision_notes' => $request->input('notes'),
        ]);

        return response()->json($leave->refresh()->load(['employee.entity', 'decidedBy']));
    }
}
