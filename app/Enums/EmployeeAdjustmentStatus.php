<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * W5-03 (HR-13): a one-off reward or deduction is entered as a draft and
 * counts in payroll only once approved.
 */
enum EmployeeAdjustmentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Approved => 'معتمدة',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Approved],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
