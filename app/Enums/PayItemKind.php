<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-03 (HR-13): a pay item or adjustment either adds to gross pay (an
 * allowance-types code) or is deducted from it (a deduction-types code).
 */
enum PayItemKind: string
{
    case Addition = 'addition';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Addition => 'استحقاق',
            self::Deduction => 'استقطاع',
        };
    }

    /**
     * The reference-lookup category the item's code comes from.
     */
    public function lookupCategory(): string
    {
        return match ($this) {
            self::Addition => 'allowance-types',
            self::Deduction => 'deduction-types',
        };
    }
}
