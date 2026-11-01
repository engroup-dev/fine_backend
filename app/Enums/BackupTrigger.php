<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W6-03: how a backup was started — by the owner (endpoint or a hand-run
 * backup:run) or by the daily schedule.
 */
enum BackupTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'يدوية',
            self::Scheduled => 'مجدولة',
        };
    }
}
