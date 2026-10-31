<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Enums\JournalEntryType;
use App\Models\AdvanceInstallment;
use App\Models\JournalEntry;
use App\Support\EmployeeAdvanceAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * W5-02 (HR-12): a salary advance or loan with its recovery schedule.
 */
final class EmployeeAdvanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schedule = $this->relationLoaded('installmentRows') ? $this->installmentRows : null;
        $advanceAccountIds = $this->relationLoaded('entries') ? EmployeeAdvanceAccounts::subtreeIds() : [];

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->entity?->name,
                'employee_number' => $this->employee->employee_number,
                'deleted_at' => $this->employee->deleted_at?->toIso8601String(),
            ] : null,
            'operating_unit_id' => $this->operating_unit_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => (float) $this->amount,
            'installments' => (int) $this->installments,
            'monthly_amount' => (float) $this->monthly_amount,
            'start_period' => $this->start_period,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'cash_account_id' => $this->cash_account_id,
            'cash_account' => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
            ] : null,
            'payout_entry_id' => $this->payout_entry_id,
            'payout_entry' => $this->payoutEntry ? [
                'id' => $this->payoutEntry->id,
                'reference' => $this->payoutEntry->reference,
            ] : null,
            'requested_by' => $this->requestedBy ? ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name] : null,
            'approved_by' => $this->approvedBy ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name] : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'recovered' => $schedule !== null
                ? round((float) $schedule->sum(fn (AdvanceInstallment $row) => (float) $row->recovered_amount), 4)
                : null,
            'outstanding' => $schedule !== null ? $this->resource->outstanding() : null,
            'schedule' => $schedule?->map(fn (AdvanceInstallment $row) => [
                'id' => $row->id,
                'period' => $row->period,
                'amount' => (float) $row->amount,
                'recovered_amount' => (float) $row->recovered_amount,
                'remaining' => $row->remaining(),
                'payslip_id' => $row->payslip_id,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
            ])->values(),
            'entries' => $this->when($this->relationLoaded('entries'), fn () => $this->entries->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'reference' => $entry->reference,
                'entry_date' => $entry->entry_date?->toDateString(),
                // A reversal keeps the source document, so it lists here too.
                'kind' => match (true) {
                    $entry->entry_type === JournalEntryType::Reversal || $entry->reversal_of_id !== null => 'reversal',
                    $entry->id === $this->payout_entry_id => 'payout',
                    default => 'repayment',
                },
                'amount' => round((float) $entry->lines
                    ->filter(fn ($line) => in_array((string) $line->account_id, $advanceAccountIds, true))
                    ->sum(fn ($line) => (float) $line->debit + (float) $line->credit), 4),
            ])->values()),
            // The payout was reversed in the ledger while the advance is still open: needs attention.
            'payout_reversed' => $this->when($this->relationLoaded('entries'), fn () => $this->payout_entry_id !== null
                && $this->entries->contains(fn (JournalEntry $entry) => $entry->reversal_of_id === $this->payout_entry_id)),
            'record_version' => (int) $this->record_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
