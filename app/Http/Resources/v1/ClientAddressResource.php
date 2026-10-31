<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClientAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'label' => $this->label,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_default' => (bool) $this->is_default,
            'snapshot' => $this->snapshot(),
            'record_version' => $this->record_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
