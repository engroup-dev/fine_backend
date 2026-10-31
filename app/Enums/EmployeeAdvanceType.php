<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-02: an advance (سلفة) or a loan (قرض). Both are paid from a treasury and
 * recovered by payroll installments under the same limits (HR-12).
 */
enum EmployeeAdvanceType: string
{
    case Advance = 'advance';
    case Loan = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'سلفة',
            self::Loan => 'قرض',
        };
    }
}
