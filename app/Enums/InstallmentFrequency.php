<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-08 (decision #6): installments fall due once a month. The value is the
 * stable machine code; label() is what the desktop shows.
 */
enum InstallmentFrequency: string
{
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'شهري',
        };
    }
}
