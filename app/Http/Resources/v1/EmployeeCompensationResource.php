<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmployeeCompensationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'pay_type' => $this->pay_type->value ?? $this->pay_type,
            'monthly_salary' => $this->monthly_salary !== null ? (float) $this->monthly_salary : null,
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'daily_rate' => $this->daily_rate !== null ? (float) $this->daily_rate : null,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
