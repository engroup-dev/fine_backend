<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_id' => $this->entity_id,
            'operating_unit_id' => $this->operating_unit_id,
            'client_group_id' => $this->client_group_id,
            'client_group' => $this->whenLoaded('clientGroup', fn () => $this->clientGroup ? [
                'id' => $this->clientGroup->id,
                'code' => $this->clientGroup->code,
                'name' => $this->clientGroup->name,
                'default_price_tier' => $this->clientGroup->default_price_tier,
            ] : null),
            'credit_limit' => $this->credit_limit,
            'current_balance' => (float) ($this->current_balance ?? 0),
            'payment_terms_days' => $this->payment_terms_days,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'account_code' => $this->account->account_code,
                'name' => $this->account->name,
            ] : null),
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
