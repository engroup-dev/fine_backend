<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollService $payrollService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PayrollRun::withCount('payslips')->orderByDesc('period');

        if ($request->filled('period')) {
            $query->where('period', $request->query('period'));
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requirePayrollRole($request)) {
            return $denied;
        }

        $request->validate(['period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        try {
            $run = $this->payrollService->open($request->input('period'), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYROLL_RUN'], 422);
        }

        return response()->json($run, 201);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePayrollRole($request)) {
            return $denied;
        }

        $run = PayrollRun::findOrFail($id);

        try {
            $run = $this->payrollService->calculate($run);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYROLL_CALCULATION'], 422);
        }

        return response()->json($run);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, PayrollRunStatus::PendingApproval, requireAccounting: false);
    }

    /**
     * §9.4: the approval gate belongs to accounting, not HR.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, PayrollRunStatus::Approved, requireAccounting: true);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, PayrollRunStatus::Paid, requireAccounting: false);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $request->validate([
            // Required to post; the service refuses a missing treasury after the
            // state check, so a wrong-stage post still reports the stage.
            'cash_account_id' => ['nullable', 'uuid', 'exists:cash_accounts,id'],
        ]);

        return $this->transition($request, $id, PayrollRunStatus::Posted, requireAccounting: true);
    }

    public function payslips(string $id): JsonResponse
    {
        $run = PayrollRun::findOrFail($id);

        return response()->json([
            'data' => $run->payslips()->with(['employee.entity', 'operatingUnit'])->get(),
        ]);
    }

    public function showPayslip(string $id): JsonResponse
    {
        return response()->json(
            Payslip::with(['employee.entity', 'operatingUnit', 'payrollRun', 'journalEntry'])->findOrFail($id)
        );
    }

    /**
     * HR-07: deductions editable only during review; net recalculated
     * server-side.
     */
    public function setDeductions(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePayrollRole($request)) {
            return $denied;
        }

        $payslip = Payslip::findOrFail($id);

        $validated = $request->validate([
            'deductions' => 'present|array',
            'deductions.*.type' => 'required|string|max:50',
            'deductions.*.amount' => 'required|numeric|min:0',
            // HR-12: echoed back on payroll's advance lines, which are kept as calculated.
            'deductions.*.locked' => 'sometimes|boolean',
            'deductions.*.installment_id' => 'sometimes|nullable|string',
        ]);

        try {
            $payslip = $this->payrollService->setDeductions($payslip, $validated['deductions']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_DEDUCTIONS'], 422);
        }

        return response()->json($payslip->load('employee.entity'));
    }

    private function transition(Request $request, string $id, PayrollRunStatus $target, bool $requireAccounting): JsonResponse
    {
        $denied = $requireAccounting
            ? $this->requireAccountingRole($request)
            : $this->requirePayrollRole($request);

        if ($denied) {
            return $denied;
        }

        $run = PayrollRun::findOrFail($id);

        try {
            $run = $this->payrollService->transition($run, $target, $request->user()->id, $request->input('cash_account_id'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYROLL_TRANSITION'], 422);
        }

        return response()->json($run->load('payslips.employee.entity'));
    }

    /**
     * HR-10: payroll is run by HR or accounting; approval and posting are
     * accounting-only. The owner passes everywhere.
     */
    private function requirePayrollRole(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('owner') || $user->hasRole('accounting-manager') || $user->hasRole('hr-manager')) {
            return null;
        }

        return response()->json([
            'message' => 'Only HR, accounting or the owner can work on payroll.',
            'code' => 'PAYROLL_FORBIDDEN',
        ], 403);
    }

    private function requireAccountingRole(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('owner') || $user->hasRole('accounting-manager')) {
            return null;
        }

        return response()->json([
            'message' => 'Only an accounting manager or the owner can approve or post payroll.',
            'code' => 'PAYROLL_APPROVAL_FORBIDDEN',
        ], 403);
    }
}
