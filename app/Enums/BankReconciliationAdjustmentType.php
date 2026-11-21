<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\LedgerAccounts;

/**
 * TRS-04: items on the bank statement that are not in the books yet. Each one
 * is posted as a receipt or payment voucher on the bank while reconciling.
 */
enum BankReconciliationAdjustmentType: string
{
    case BankCharge = 'bank_charge';
    case BankInterest = 'bank_interest';
    case DirectDeposit = 'direct_deposit';
    case DirectDebit = 'direct_debit';
    case OtherIn = 'other_in';
    case OtherOut = 'other_out';

    public function label(): string
    {
        return match ($this) {
            self::BankCharge => 'عمولات ومصاريف بنكية',
            self::BankInterest => 'فوائد بنكية',
            self::DirectDeposit => 'إيداع مباشر في الحساب',
            self::DirectDebit => 'خصم مباشر من الحساب',
            self::OtherIn => 'تسوية أخرى (إضافة)',
            self::OtherOut => 'تسوية أخرى (خصم)',
        };
    }

    /**
     * Money into the bank is a receipt voucher, money out a payment voucher.
     */
    public function voucherKind(): TreasuryVoucherKind
    {
        return match ($this) {
            self::BankInterest, self::DirectDeposit, self::OtherIn => TreasuryVoucherKind::Receipt,
            self::BankCharge, self::DirectDebit, self::OtherOut => TreasuryVoucherKind::Payment,
        };
    }

    /**
     * The counter account used when the user does not pick one.
     */
    public function defaultCounterAccountCode(): ?string
    {
        return match ($this) {
            self::BankCharge => LedgerAccounts::BANK_CHARGES,
            self::BankInterest => LedgerAccounts::BANK_INTEREST,
            default => null,
        };
    }
}
