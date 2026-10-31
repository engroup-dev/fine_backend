<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'account_code' => $this->account->account_code,
                'name' => $this->account->name,
            ] : null),
            'name' => $this->name,
            'contact' => $this->contact,
            'default_currency' => $this->default_currency,
            'address' => $this->address,
            // W5-07: the supplier's party; the tax number lives on the entity.
            'entity_id' => $this->entity_id,
            'tax_number' => $this->relationLoaded('entity') ? $this->entity?->tax_number : null,
            'record_version' => $this->record_version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
