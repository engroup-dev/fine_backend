<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-08: an installment's status is never stored — it is derived each time
 * from the sale's collections and returns (InstallmentPlanService::schedule).
 * The value is the stable machine code; label() is what the desktop shows.
 */
enum InstallmentLineStatus: string
{
    /** Not yet due and nothing paid towards it. */
    case Upcoming = 'upcoming';

    /** Not yet due, part of it paid. */
    case Partial = 'partial';

    /** Past its due date with something still to pay (it may be part paid). */
    case Overdue = 'overdue';

    case Paid = 'paid';

    /** Wiped out by goods returned on the sale (returns cut the last installments first). */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'قادم',
            self::Partial => 'مدفوع جزئياً',
            self::Overdue => 'متأخر',
            self::Paid => 'مدفوع',
            self::Cancelled => 'ملغى بالمرتجع',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Upcoming, self::Partial, self::Overdue], true);
    }
}
