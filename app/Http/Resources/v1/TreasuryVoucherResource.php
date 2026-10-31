<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 08 Wave 2 / TRS-02: API representation of a receipt or payment voucher.
 */
final class TreasuryVoucherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kind = $this->kind instanceof TreasuryVoucherKind ? $this->kind : TreasuryVoucherKind::tryFrom((string) $this->kind);
        $status = $this->status instanceof TreasuryVoucherStatus ? $this->status : TreasuryVoucherStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'operating_unit_id' => $this->operating_unit_id,
            'voucher_number' => $this->voucher_number,
            'kind' => $kind?->value ?? $this->kind,
            'kind_label' => $kind?->label(),
            'status' => $status?->value ?? $this->status,
            'status_label' => $status?->label(),
            'cash_account_id' => $this->cash_account_id,
            'counter_account_code' => $this->counter_account_code,
            'client_id' => $this->client_id,
            'employee_id' => $this->employee_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'fx_rate' => $this->fx_rate !== null ? (float) $this->fx_rate : null,
            'amount_lyd' => (float) $this->amount_lyd,
            'voucher_date' => $this->voucher_date?->toDateString() ?? $this->voucher_date,
            'payee' => $this->payee,
            'description' => $this->description,
            'journal_entry_id' => $this->journal_entry_id,
            'cancel_journal_entry_id' => $this->cancel_journal_entry_id,
            'cancel_reason' => $this->cancel_reason,
            'created_by_user_id' => $this->created_by_user_id,
            'cancelled_by_user_id' => $this->cancelled_by_user_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'record_version' => (int) $this->record_version,
            'cash_account' => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'currency' => $this->cashAccount->currency,
            ] : null,
            'client' => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->entity?->name ?? $this->client->name ?? null,
            ] : null,
            // W5-02: the employee on an advances (1215) voucher, for the list and the print.
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->entity?->name,
                'employee_number' => $this->employee->employee_number,
            ] : null,
            'counter_account' => [
                'code' => $this->counter_account_code,
                'name' => $this->counterAccount?->name,
            ],
            'journal_entry' => $this->journalEntry ? [
                'id' => $this->journalEntry->id,
                'reference' => $this->journalEntry->reference,
            ] : null,
            'cancel_entry' => $this->cancelEntry ? [
                'id' => $this->cancelEntry->id,
                'reference' => $this->cancelEntry->reference,
            ] : null,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'created_by_name' => $this->createdBy?->name,
            'cancelled_by' => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->name,
            ] : null,
            'cancelled_by_name' => $this->cancelledBy?->name,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
