<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W6-03: a backup row is written once its outcome is known: completed (the
 * file is stored) or failed. Both are final. There is no "running" status: a
 * row written before the dump would land in the dump unfinished, so a run in
 * progress is marked by BackupService's cache lock alone.
 */
enum BackupStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'مكتملة',
            self::Failed => 'فاشلة',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return [];
    }
}
