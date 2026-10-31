<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-03 (HR-13): where payroll credits a deduction, from the deduction
 * type's `fields.account_role`. Withheld for a third party (social security,
 * tax) it is a liability (CR 225); kept by the company (absence, lateness,
 * penalties) it reduces wage expense (CR 57). Advance recovery is neither:
 * it credits 1215 from the advance schedule (HR-12).
 */
enum DeductionAccountRole: string
{
    case Liability = 'liability';
    case ExpenseReduction = 'expense_reduction';

    public function label(): string
    {
        return match ($this) {
            self::Liability => 'التزام (استقطاعات مستحقة)',
            self::ExpenseReduction => 'تخفيض مصروف الرواتب',
        };
    }

    /**
     * The account a deduction with this role credits.
     */
    public function accountCode(): string
    {
        return match ($this) {
            self::Liability => '225',
            self::ExpenseReduction => '57',
        };
    }
}
