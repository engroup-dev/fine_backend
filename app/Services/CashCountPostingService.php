<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PosShift;
use App\Support\LedgerAccounts;
use App\Support\TreasuryAccounts;
use Illuminate\Database\Eloquent\Model;

/**
 * Rule TRS-06: a drawer count that differs from the expected cash by 0.005 or
 * more posts the difference — a shortage DR 625 (عجز الخزائن) / CR treasury,
 * a surplus DR treasury / CR 44 (زيادة الخزائن). Shared by the POS daily
 * close and the cashier shift close (FR-SLREP-008).
 */
final class CashCountPostingService
{
    public function __construct(private readonly AccountingService $accountingService) {}

    /**
     * Post the count difference against the drawer, or nothing when it
     * balances.
     *
     * @throws SalesRuleException when the drawer is not linked to the ledger
     */
    public function post(
        OperatingUnit $unit,
        CashAccount $drawer,
        float $expected,
        float $counted,
        Model $source,
        string $date,
        ?string $userId = null,
    ): ?JournalEntry {
        $diff = round($counted - $expected, 4);

        if (abs($diff) < 0.005) {
            return null;
        }

        $drawer->loadMissing('account');
        $treasuryCode = TreasuryAccounts::codeFor($drawer);
        $diffAmount = abs($diff);

        $lines = $diff < 0
            ? [
                ['account_code' => LedgerAccounts::CASH_SHORTAGE, 'debit' => $diffAmount, 'operating_unit_id' => $unit->id],
                ['account_code' => $treasuryCode, 'credit' => $diffAmount, 'operating_unit_id' => $unit->id],
            ]
            : [
                ['account_code' => $treasuryCode, 'debit' => $diffAmount, 'operating_unit_id' => $unit->id],
                ['account_code' => LedgerAccounts::CASH_OVERAGE, 'credit' => $diffAmount, 'operating_unit_id' => $unit->id],
            ];

        $description = $source instanceof PosShift
            ? "POS shift {$source->number} close difference: {$date}"
            : "POS daily close difference: {$date}";

        return $this->accountingService->postJournal(
            description: $description,
            lines: $lines,
            sourceType: class_basename($source),
            sourceId: (string) $source->getKey(),
            companyId: $unit->company_id ?? Company::query()->value('id'),
            isManual: false,
            userId: $userId,
            entryDate: $date,
        );
    }
}
