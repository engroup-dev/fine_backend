<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreasuryTransferStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Support\CompanyClock;
use App\Support\TreasuryAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 08 Wave 2 / Rule TRS-03: Transfers between treasuries (التحويلات بين الخزائن).
 * Internal transfers require dual-control approval unless initiated by the owner;
 * source and destination treasuries must share the same currency.
 */
final class TreasuryTransferService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly TreasuryBalanceService $balanceService,
    ) {}

    /**
     * Create a draft treasury transfer. Nothing posts at creation time.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerRuleException
     */
    public function create(array $data, User $user): TreasuryTransfer
    {
        return DB::transaction(function () use ($data, $user): TreasuryTransfer {
            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0) {
                throw new LedgerRuleException('A transfer amount must be greater than zero.', 'INVALID_AMOUNT');
            }

            $fromCashAccountId = $data['from_cash_account_id'] ?? null;
            $toCashAccountId = $data['to_cash_account_id'] ?? null;

            if ($fromCashAccountId === null || $toCashAccountId === null) {
                throw new LedgerRuleException('Both source and destination treasuries are required.', 'TREASURY_REQUIRED');
            }

            if ($fromCashAccountId === $toCashAccountId) {
                throw new LedgerRuleException('The source and destination treasuries cannot be the same.', 'TRANSFER_SAME_TREASURY');
            }

            $fromCashAccount = CashAccount::with(['account', 'operatingUnit'])->findOrFail($fromCashAccountId);
            $toCashAccount = CashAccount::with(['account', 'operatingUnit'])->findOrFail($toCashAccountId);

            // Both treasuries must be linked to ledger accounts.
            TreasuryAccounts::codeFor($fromCashAccount);
            TreasuryAccounts::codeFor($toCashAccount);

            $fromCurrency = strtoupper(trim((string) $fromCashAccount->currency));
            $toCurrency = strtoupper(trim((string) $toCashAccount->currency));

            if ($fromCurrency !== $toCurrency) {
                throw new LedgerRuleException(
                    'Transfers between treasuries must share the same currency; currency exchange is done through procurement FX.',
                    'TRANSFER_CURRENCY_MISMATCH',
                );
            }

            $transferDate = isset($data['transfer_date']) && trim((string) $data['transfer_date']) !== ''
                ? (string) $data['transfer_date']
                : CompanyClock::today();

            if (Carbon::parse($transferDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The transfer date cannot be in the future.', 'TRANSFER_DATE_IN_FUTURE');
            }

            $operatingUnitId = $data['operating_unit_id'] ?? null;
            if ($operatingUnitId === null) {
                throw new LedgerRuleException('Operating unit is required.', 'OPERATING_UNIT_REQUIRED');
            }

            $companyId = $data['company_id']
                ?? $fromCashAccount->operatingUnit?->company_id
                ?? Company::query()->value('id');

            if ($companyId === null) {
                throw new LedgerRuleException('No company exists to post a transfer against.', 'NO_COMPANY');
            }

            $isForeign = $fromCurrency !== 'LYD';
            $fxRate = null;
            if ($isForeign && isset($data['fx_rate']) && is_numeric($data['fx_rate'])) {
                $fxRate = round((float) $data['fx_rate'], 8);
                if ($fxRate <= 0) {
                    throw new LedgerRuleException(
                        'An exchange rate greater than zero is required for foreign currency transfers.',
                        'FX_RATE_REQUIRED',
                    );
                }
            }

            $year = (int) Carbon::parse($transferDate)->format('Y');
            $transferNumber = $this->documentNumbers->next(DocumentNumberService::TREASURY_TRANSFER, $year);

            return TreasuryTransfer::create([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'operating_unit_id' => $operatingUnitId,
                'transfer_number' => $transferNumber,
                'from_cash_account_id' => $fromCashAccount->id,
                'to_cash_account_id' => $toCashAccount->id,
                'amount' => $amount,
                'currency' => $fromCurrency,
                'fx_rate' => $fxRate,
                'amount_lyd' => null,
                'transfer_date' => $transferDate,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'status' => TreasuryTransferStatus::Draft,
                'created_by_user_id' => $user->id,
                'record_version' => 1,
            ]);
        });
    }

    /**
     * Approve a draft transfer and post the balanced journal entry to the general ledger.
     *
     * @throws LedgerRuleException
     */
    public function approve(TreasuryTransfer $transfer, User $user, ?float $fxRate = null, ?string $note = null): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $fxRate, $note): TreasuryTransfer {
            $locked = $this->lockTransfer($transfer);

            if ($locked->status !== TreasuryTransferStatus::Draft) {
                throw new LedgerRuleException("Transfer {$locked->transfer_number} is not pending approval.", 'TRANSFER_NOT_PENDING');
            }

            if ($locked->created_by_user_id === $user->id && ! $user->hasRole('owner')) {
                throw new LedgerRuleException('Transfers cannot be approved by their creator.', 'TRANSFER_SELF_APPROVAL');
            }

            $fromCashAccount = CashAccount::with('account')->lockForUpdate()->findOrFail($locked->from_cash_account_id);
            $toCashAccount = CashAccount::with('account')->lockForUpdate()->findOrFail($locked->to_cash_account_id);

            $fromCode = TreasuryAccounts::codeFor($fromCashAccount);
            $toCode = TreasuryAccounts::codeFor($toCashAccount);

            $balance = $this->balanceService->balanceFor($fromCashAccount);
            if ($locked->currency === 'LYD') {
                $available = round((float) ($balance['ledger_balance'] ?? 0.0), 4);
            } else {
                $currencyRow = collect($balance['currency_balances'] ?? [])
                    ->first(fn (array $cb): bool => strtoupper((string) ($cb['currency'] ?? '')) === strtoupper($locked->currency));
                $available = $currencyRow !== null ? round((float) $currencyRow['original_balance'], 4) : 0.0;
            }

            if ($available < (float) $locked->amount) {
                throw new LedgerRuleException(
                    "Insufficient balance in treasury {$fromCashAccount->name}. Available: {$available}, required: {$locked->amount}.",
                    'TRANSFER_INSUFFICIENT_BALANCE',
                    ['available' => $available],
                );
            }

            $isForeign = $locked->currency !== 'LYD';
            if ($isForeign) {
                $effectiveFxRate = $fxRate !== null && $fxRate > 0
                    ? round($fxRate, 8)
                    : ($locked->fx_rate !== null ? (float) $locked->fx_rate : null);

                if ($effectiveFxRate === null || $effectiveFxRate <= 0) {
                    throw new LedgerRuleException(
                        'An exchange rate greater than zero is required for foreign currency transfers.',
                        'FX_RATE_REQUIRED',
                    );
                }

                $amountLyd = round((float) $locked->amount * $effectiveFxRate, 4);
            } else {
                $effectiveFxRate = null;
                $amountLyd = round((float) $locked->amount, 4);
            }

            $description = "تحويل {$locked->transfer_number} من {$fromCashAccount->name} إلى {$toCashAccount->name}";

            $toLine = [
                'account_code' => $toCode,
                'operating_unit_id' => $locked->operating_unit_id,
                'debit' => $amountLyd,
                'credit' => 0.0,
                'memo' => $description,
            ];

            $fromLine = [
                'account_code' => $fromCode,
                'operating_unit_id' => $locked->operating_unit_id,
                'debit' => 0.0,
                'credit' => $amountLyd,
                'memo' => $description,
            ];

            if ($isForeign) {
                $toLine['currency'] = $locked->currency;
                $toLine['original_amount'] = (float) $locked->amount;
                $toLine['fx_rate'] = $effectiveFxRate;

                $fromLine['currency'] = $locked->currency;
                $fromLine['original_amount'] = (float) $locked->amount;
                $fromLine['fx_rate'] = $effectiveFxRate;
            }

            $journalEntry = $this->accountingService->postJournal(
                description: $description,
                lines: [$toLine, $fromLine],
                sourceType: 'TreasuryTransfer',
                sourceId: $locked->id,
                companyId: $locked->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $locked->transfer_date->toDateString(),
            );

            $locked->status = TreasuryTransferStatus::Approved;
            $locked->decided_by_user_id = $user->id;
            $locked->decided_at = now();
            $locked->decision_note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $locked->fx_rate = $effectiveFxRate;
            $locked->amount_lyd = $amountLyd;
            $locked->journal_entry_id = $journalEntry->id;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Reject a draft transfer. Nothing posts.
     *
     * @throws LedgerRuleException
     */
    public function reject(TreasuryTransfer $transfer, User $user, string $note): TreasuryTransfer
    {
        $note = trim($note);
        if ($note === '') {
            throw new LedgerRuleException('A rejection note is required.', 'REJECTION_NOTE_REQUIRED');
        }

        return DB::transaction(function () use ($transfer, $user, $note): TreasuryTransfer {
            $locked = $this->lockTransfer($transfer);

            if ($locked->status !== TreasuryTransferStatus::Draft) {
                throw new LedgerRuleException("Transfer {$locked->transfer_number} is not pending approval.", 'TRANSFER_NOT_PENDING');
            }

            $locked->status = TreasuryTransferStatus::Rejected;
            $locked->decided_by_user_id = $user->id;
            $locked->decided_at = now();
            $locked->decision_note = $note;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Cancel a draft transfer. Creator or owner only. Nothing posts.
     *
     * @throws LedgerRuleException
     */
    public function cancel(TreasuryTransfer $transfer, User $user): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $user): TreasuryTransfer {
            $locked = $this->lockTransfer($transfer);

            if ($locked->status !== TreasuryTransferStatus::Draft) {
                throw new LedgerRuleException("Transfer {$locked->transfer_number} is not pending approval.", 'TRANSFER_NOT_PENDING');
            }

            if ($locked->created_by_user_id !== $user->id && ! $user->hasRole('owner')) {
                throw new LedgerRuleException('Only the transfer creator or the owner can cancel this transfer.', 'TRANSFER_CANCEL_FORBIDDEN');
            }

            $locked->status = TreasuryTransferStatus::Cancelled;
            $locked->decided_by_user_id = $user->id;
            $locked->decided_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Re-read the transfer the controller already resolved and authorized. A
     * company-wide approver may act on another unit's transfer, so the unit
     * scope must not hide it here.
     */
    private function lockTransfer(TreasuryTransfer $transfer): TreasuryTransfer
    {
        return TreasuryTransfer::withoutGlobalScope(OperatingUnitScope::class)
            ->whereKey($transfer->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
