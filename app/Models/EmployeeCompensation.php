<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayType;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-11: one version of an employee's pay terms. A change is a new row, never
 * an edit of the terms, so pay already calculated can always be traced to the
 * terms in force at the time. A mistaken version dated after the last frozen
 * payroll period may be removed; the opening row only moves with the hire date.
 */
final class EmployeeCompensation extends Model
{
    use Auditable, HasUuids;

    /** Set explicitly: the inflector treats "compensation" as uncountable. */
    protected $table = 'employee_compensations';

    /** Reason stamped on the first row, written at hire or by the backfill. */
    public const OPENING_REASON = 'opening';

    protected $fillable = [
        'employee_id',
        'pay_type',
        'monthly_salary',
        'hourly_rate',
        'daily_rate',
        'effective_from',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'monthly_salary' => 'decimal:4',
            'hourly_rate' => 'decimal:4',
            'daily_rate' => 'decimal:4',
            'effective_from' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
