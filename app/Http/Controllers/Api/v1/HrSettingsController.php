<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company-wide HR settings. Today only the annual leave entitlement the
 * employee card measures the leave balance against (default 30 days).
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
            'annual_leave_days' => ['required', 'integer', 'min:0', 'max:366'],
        ]);

        $company = Company::query()->firstOrFail();
        $company->update(['annual_leave_days' => (int) $validated['annual_leave_days']]);

        return response()->json($this->payload($company));
    }

    /**
     * @return array{annual_leave_days: int}
     */
    private function payload(Company $company): array
    {
        return ['annual_leave_days' => (int) ($company->annual_leave_days ?? 30)];
    }
}
