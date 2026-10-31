<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-03 (HR-13): a standing pay item is a fixed amount per period or a
 * percentage of the period's base (base salary + attendance pay).
 */
enum PayItemCalculation: string
{
    case Fixed = 'fixed';
    case PercentOfBase = 'percent_of_base';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'مبلغ ثابت',
            self::PercentOfBase => 'نسبة من الأساسي',
        };
    }
}
