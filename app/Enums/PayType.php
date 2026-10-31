<?php

declare(strict_types=1);

namespace App\Enums;

enum PayType: string
{
    case Hourly = 'hourly';
    case Monthly = 'monthly';
    case PieceRate = 'piece_rate';

    /** W5-04 (HR-14): paid per attended day (half a day for a half day) at the daily rate. */
    case Daily = 'daily';
}
