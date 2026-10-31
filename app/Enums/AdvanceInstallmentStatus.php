<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-02: an installment stays pending until it is fully recovered (by payroll
 * or a cash repayment). A pending installment from an earlier period is still
 * due in the next payroll, so a shortfall carries forward.
 */
enum AdvanceInstallmentStatus: string
{
    case Pending = 'pending';
    case Recovered = 'recovered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'مستحق',
            self::Recovered => 'محصّل',
            self::Cancelled => 'ملغى',
        };
    }
}
