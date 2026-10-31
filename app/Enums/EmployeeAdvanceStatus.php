<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-02: requested → approved → paid → settled; a request may be cancelled
 * until it is paid out. Settled and cancelled are terminal.
 */
enum EmployeeAdvanceStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'مطلوبة',
            self::Approved => 'معتمدة',
            self::Paid => 'مصروفة',
            self::Settled => 'مسددة',
            self::Cancelled => 'ملغاة',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Cancelled],
            self::Approved => [self::Paid, self::Cancelled],
            self::Paid => [self::Settled],
            self::Settled => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Still counts toward the employee's monthly recovery limit.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Paid], true);
    }
}
