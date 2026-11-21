<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Services\TreasuryOpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CashAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'account_code' => $this->account->account_code,
                'name' => $this->account->name,
            ] : null),
            'currency' => $this->currency,
            // Creation-time opening figure; the real balance is ledger_balance (TRS-01).
            'balance' => (float) $this->balance,
            // TRS-01: whether the opening balance above has reached the ledger.
            'has_opening_entry' => app(TreasuryOpeningBalanceService::class)->hasOpening($this->resource),
            // Attached by TreasuryBalanceService::attach() in one query for the whole list.
            'ledger_balance' => $this->when($this->resource->ledger_balance !== null, fn () => $this->resource->ledger_balance),
            'currency_balances' => $this->when($this->resource->currency_balances !== null, fn () => $this->resource->currency_balances),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
