<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'standard_hours' => (float) $this->standard_hours,
            'overtime_multiplier' => (float) $this->overtime_multiplier,
            'start_time' => $this->start_time !== null ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time !== null ? substr((string) $this->end_time, 0, 5) : null,
            'record_version' => $this->record_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
