<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W4-07: a furniture assembly order's lifecycle. A draft reserves nothing;
 * completion consumes the components and produces the finished lot, and both
 * completion and cancellation are terminal.
 */
enum AssemblyOrderStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Completed, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
