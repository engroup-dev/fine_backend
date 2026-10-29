<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W4-01: a physical stock count's lifecycle. A draft becomes `counting` when
 * the first quantity is entered; approval and cancellation are terminal.
 */
enum StockCountStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Counting, self::Cancelled],
            self::Counting => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Cancelled],
            self::Approved => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Quantities can still be entered and found stock added.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Counting;
    }

    /**
     * Not yet approved or cancelled: the warehouse is still being counted.
     */
    public function isOpen(): bool
    {
        return $this !== self::Approved && $this !== self::Cancelled;
    }
}
