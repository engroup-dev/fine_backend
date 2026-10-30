<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\TreasuryVoucher;
use App\Models\User;
use App\Support\AccountTree;
use App\Support\CompanyClock;
use App\Support\SalesAccounts;
use App\Support\TreasuryAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 08 Wave 2 / Rule TRS-02: Receipt and payment vouchers (سندات القبض والصرف).
 * A voucher posts once; cancelling reverses it.
 */
final class TreasuryVoucherService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * Create and post a receipt or payment voucher.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerRuleException
     */
    public function create(array $data, User $user): TreasuryVoucher
    {
        return DB::transaction(function () use ($data, $user): TreasuryVoucher {
            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0) {
                throw new LedgerRuleException('A voucher amount must be greater than zero.', 'INVALID_AMOUNT');
            }

            $kind = $data['kind'] instanceof TreasuryVoucherKind
                ? $data['kind']
                : TreasuryVoucherKind::from((string) ($data['kind'] ?? ''));

            $cashAccountId = $data['cash_account_id'] ?? null;
            $cashAccount = $cashAccountId !== null
                ? CashAccount::with('account')->lockForUpdate()->find($cashAccountId)
                : null;

            if ($cashAccount === null) {
                throw new LedgerRuleException('Choose the treasury the money comes from or goes into.', 'TREASURY_REQUIRED');
            }

            $treasuryCode = TreasuryAccounts::codeFor($cashAccount);

            $voucherDate = isset($data['voucher_date']) && trim((string) $data['voucher_date']) !== ''
                ? (string) $data['voucher_date']
                : CompanyClock::today();

            if (Carbon::parse($voucherDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The voucher date cannot be in the future.', 'VOUCHER_DATE_IN_FUTURE');
            }

            $operatingUnitId = $data['operating_unit_id'] ?? null;
            if ($operatingUnitId === null) {
                throw new LedgerRuleException('Operating unit is required.', 'OPERATING_UNIT_REQUIRED');
            }

            $companyId = $data['company_id'] ?? Company::query()->value('id');
            if ($companyId === null) {
                throw new LedgerRuleException('No company exists to post a voucher against.', 'NO_COMPANY');
            }

            $treasuryCurrency = strtoupper(trim((string) $cashAccount->currency));
            $isForeign = $treasuryCurrency !== 'LYD';

            if ($isForeign) {
                $fxRate = isset($data['fx_rate']) && is_numeric($data['fx_rate'])
                    ? round((float) $data['fx_rate'], 8)
                    : null;

                if ($fxRate === null || $fxRate <= 0) {
                    throw new LedgerRuleException('An exchange rate greater than zero is required for foreign currency vouchers.', 'FX_RATE_REQUIRED');
                }

                $amountLyd = round($amount * $fxRate, 4);
            } else {
                $fxRate = null;
                $amountLyd = $amount;
            }

            $clientId = $data['client_id'] ?? null;
            $client = null;
            $employee = null;

            if ($clientId !== null) {
                $client = Client::with('account')->lockForUpdate()->find($clientId);
                if ($client === null) {
                    throw new LedgerRuleException('Client not found.', 'CLIENT_NOT_FOUND');
                }

                $counterAccountCode = SalesAccounts::receivableFor($client);
                $counterAccount = Account::where('account_code', $counterAccountCode)->firstOrFail();

                // Client collection reduces the receivable balance; client refund/payment increases it.
                if ($kind === TreasuryVoucherKind::Receipt) {
                    $client->current_balance = round((float) $client->current_balance - $amountLyd, 4);
                } else {
                    $client->current_balance = round((float) $client->current_balance + $amountLyd, 4);
                }
                $client->save();
            } else {
                $counterAccountCode = isset($data['counter_account_code']) ? trim((string) $data['counter_account_code']) : null;
                if ($counterAccountCode === null || $counterAccountCode === '') {
                    throw new LedgerRuleException('A counter account is required when no client is specified.', 'COUNTER_ACCOUNT_REQUIRED');
                }

                $counterAccount = Account::where('account_code', $counterAccountCode)->first();
                if ($counterAccount === null) {
                    throw new LedgerRuleException("Counter account {$counterAccountCode} does not exist.", 'ACCOUNT_NOT_FOUND');
                }

                $isTreasury = in_array($counterAccount->id, AccountTree::subtreeIds('121'), true)
                    || CashAccount::where('account_id', $counterAccount->id)->exists();

                if ($isTreasury) {
                    throw new LedgerRuleException(
                        "Account {$counterAccountCode} is a treasury account; use a treasury transfer instead.",
                        'VOUCHER_COUNTER_IS_TREASURY',
                    );
                }

                // W5-00: unit-scoped lookup, so a unit user cannot name another unit's
                // employee. A deleted employee is found so a repayment can still post;
                // postJournal refuses a new advance to them and a unit mismatch.
                $employeeId = $data['employee_id'] ?? null;
                if ($employeeId !== null) {
                    $employee = Employee::withTrashed()->lockForUpdate()->find($employeeId);
                    if ($employee === null) {
                        throw new LedgerRuleException('Employee not found.', 'EMPLOYEE_NOT_FOUND');
                    }
                }
            }

            $year = (int) Carbon::parse($voucherDate)->format('Y');
            $docType = $kind === TreasuryVoucherKind::Receipt
                ? DocumentNumberService::RECEIPT_VOUCHER
                : DocumentNumberService::PAYMENT_VOUCHER;

            $voucherNumber = $this->documentNumbers->next($docType, $year);

            $description = trim((string) ($data['description'] ?? ''));
            if ($description === '') {
                throw new LedgerRuleException('A description is required.', 'DESCRIPTION_REQUIRED');
            }

            $payee = isset($data['payee']) && trim((string) $data['payee']) !== ''
                ? trim((string) $data['payee'])
                : null;

            $memo = $payee !== null
                ? Str::limit("{$payee} - {$description}", 250)
                : Str::limit($description, 250);

            $entryDescription = $kind === TreasuryVoucherKind::Receipt
                ? "سند قبض {$voucherNumber}: {$description}"
                : "سند صرف {$voucherNumber}: {$description}";

            $treasuryLine = [
                'account_code' => $treasuryCode,
                'operating_unit_id' => $operatingUnitId,
                'memo' => $memo,
            ];
            if ($isForeign) {
                $treasuryLine['currency'] = $treasuryCurrency;
                $treasuryLine['original_amount'] = $amount;
                $treasuryLine['fx_rate'] = $fxRate;
            }

            $counterLine = [
                'account_code' => $counterAccountCode,
                'operating_unit_id' => $operatingUnitId,
                'memo' => $memo,
                'client_id' => $client?->id,
                // W5-00: an employee advances counter account (1215) names the employee.
                'employee_id' => $employee?->id,
            ];
            if ($isForeign) {
                $counterLine['currency'] = $treasuryCurrency;
                $counterLine['original_amount'] = $amount;
                $counterLine['fx_rate'] = $fxRate;
            }

            if ($kind === TreasuryVoucherKind::Receipt) {
                $treasuryLine['debit'] = $amountLyd;
                $counterLine['credit'] = $amountLyd;
                $lines = [$treasuryLine, $counterLine];
            } else {
                $counterLine['debit'] = $amountLyd;
                $treasuryLine['credit'] = $amountLyd;
                $lines = [$counterLine, $treasuryLine];
            }

            $voucherId = (string) Str::uuid();

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDescription,
                lines: $lines,
                sourceType: 'TreasuryVoucher',
                sourceId: $voucherId,
                companyId: $companyId,
                isManual: false,
                userId: $user->id,
                entryDate: $voucherDate,
            );

            return TreasuryVoucher::create([
                'id' => $voucherId,
                'company_id' => $companyId,
                'operating_unit_id' => $operatingUnitId,
                'voucher_number' => $voucherNumber,
                'kind' => $kind,
                'cash_account_id' => $cashAccount->id,
                'counter_account_code' => $counterAccountCode,
                'client_id' => $client?->id,
                'amount' => $amount,
                'currency' => $treasuryCurrency,
                'fx_rate' => $fxRate,
                'amount_lyd' => $amountLyd,
                'voucher_date' => $voucherDate,
                'payee' => $payee,
                'description' => $description,
                'status' => TreasuryVoucherStatus::Posted,
                'journal_entry_id' => $journalEntry->id,
                'created_by_user_id' => $user->id,
                'record_version' => 1,
            ]);
        });
    }

    /**
     * Cancel an existing posted voucher and reverse its journal entry.
     *
     * @throws LedgerRuleException
     */
    public function cancel(TreasuryVoucher $v, string $reason, User $user): TreasuryVoucher
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new LedgerRuleException('A cancellation reason is required.', 'CANCEL_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($v, $reason, $user): TreasuryVoucher {
            $voucher = TreasuryVoucher::whereKey($v->getKey())->lockForUpdate()->firstOrFail();

            if ($voucher->status !== TreasuryVoucherStatus::Posted) {
                throw new LedgerRuleException(
                    "Voucher {$voucher->voucher_number} cannot be cancelled because it is in status {$voucher->status->value}.",
                    'VOUCHER_ALREADY_CANCELLED',
                );
            }

            $cancelEntry = $this->accountingService->reverseEntry(
                $voucher->journalEntry,
                CompanyClock::today(),
                $reason,
                $user->id,
            );

            if ($voucher->client_id !== null) {
                $client = Client::whereKey($voucher->client_id)->lockForUpdate()->first();
                if ($client !== null) {
                    if ($voucher->kind === TreasuryVoucherKind::Receipt) {
                        $client->current_balance = round((float) $client->current_balance + (float) $voucher->amount_lyd, 4);
                    } else {
                        $client->current_balance = round((float) $client->current_balance - (float) $voucher->amount_lyd, 4);
                    }
                    $client->save();
                }
            }

            $voucher->status = TreasuryVoucherStatus::Cancelled;
            $voucher->cancel_journal_entry_id = $cancelEntry->id;
            $voucher->cancel_reason = $reason;
            $voucher->cancelled_by_user_id = $user->id;
            $voucher->cancelled_at = now();
            $voucher->save();

            return $voucher;
        });
    }
}
