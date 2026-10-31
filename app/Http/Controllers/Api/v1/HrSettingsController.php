<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company-wide HR settings: the annual leave entitlement the employee card
 * measures the leave balance against (default 30 days), and the advance
 * limits (HR-12, decision #14): one advance at most this multiple of the
 * monthly base salary (default 1), and a monthly recovery at most this
 * percentage of net pay (default 50).
 */
final class HrSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(Company::query()->firstOrFail()));
    }

    /**
     * HR-10: HR policy is set by HR or the owner.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('hr-manager')) {
            return response()->json([
                'message' => 'Only HR or the owner can change HR settings.',
                'code' => 'HR_SETTINGS_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'annual_leave_days' => ['required_without_all:advance_max_salary_multiple,advance_max_deduction_pct', 'integer', 'min:0', 'max:366'],
            'advance_max_salary_multiple' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'advance_max_deduction_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $company = Company::query()->firstOrFail();
        $company->update($validated);

        return response()->json($this->payload($company));
    }

    /**
     * @return array{annual_leave_days: int, advance_max_salary_multiple: float, advance_max_deduction_pct: float}
     */
    private function payload(Company $company): array
    {
        return [
            'annual_leave_days' => (int) ($company->annual_leave_days ?? 30),
            'advance_max_salary_multiple' => (float) ($company->advance_max_salary_multiple ?? 1),
            'advance_max_deduction_pct' => (float) ($company->advance_max_deduction_pct ?? 50),
        ];
    }
}
