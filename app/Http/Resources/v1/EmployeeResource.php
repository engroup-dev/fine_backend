<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Models\EmployeeCompensation;
use App\Models\User;
use App\Support\CompanyClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmployeeResource extends JsonResource
{
    /** Roles that see and set the employee's bank details (the roles that set pay, HR-10). */
    public const BANK_DETAIL_ROLES = ['owner', 'hr-manager', 'accounting-manager'];

    public function toArray(Request $request): array
    {
        $terms = $this->termsInForce($request);

        return [
            'id' => $this->id,
            'entity_id' => $this->entity_id,
            'employee_number' => $this->employee_number,
            'operating_unit_id' => $this->operating_unit_id,
            'job_title' => $this->job_title,
            'labor_role' => $this->labor_role,
            'pay_type' => $terms->pay_type->value ?? $terms->pay_type,
            'monthly_salary' => $terms->monthly_salary !== null ? (float) $terms->monthly_salary : null,
            'hourly_rate' => $terms->hourly_rate !== null ? (float) $terms->hourly_rate : null,
            'daily_rate' => $terms->daily_rate !== null ? (float) $terms->daily_rate : null,
            'work_shift_id' => $this->work_shift_id,
            'work_shift' => $this->whenLoaded('workShift', fn () => $this->workShift ? new WorkShiftResource($this->workShift) : null),
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'contract_start' => $this->contract_start?->format('Y-m-d'),
            'contract_end' => $this->contract_end?->format('Y-m-d'),
            'probation_end' => $this->probation_end?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            $this->mergeWhen(self::holdsPayRole($request->user()), fn () => [
                'bank_name' => $this->bank_name,
                'bank_account' => $this->bank_account,
            ]),
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * HR-11: the pay terms in force today. With the pay history loaded they
     * come from it, so a future-dated change shows from its effective date
     * even before the daily sync (hr:sync-pay-terms) has updated the row.
     */
    private function termsInForce(Request $request): object
    {
        if (! $this->relationLoaded('compensations') || $this->compensations->isEmpty()) {
            return $this->resource;
        }

        $today = $request->attributes->get('company_today');

        if ($today === null) {
            $today = CompanyClock::today();
            $request->attributes->set('company_today', $today);
        }

        $history = $this->compensations->sortBy(fn (EmployeeCompensation $version) => $version->effective_from->toDateString());

        return $history->last(fn (EmployeeCompensation $version) => $version->effective_from->toDateString() <= $today)
            ?? $history->first();
    }

    /**
     * Whether the user holds one of the roles that set pay and bank details.
     */
    public static function holdsPayRole(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach (self::BANK_DETAIL_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
