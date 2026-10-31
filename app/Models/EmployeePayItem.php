<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayItemCalculation;
use App\Enums\PayItemKind;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W5-03 (HR-13): a standing allowance or deduction, applied by payroll in
 * every period it is in force (effective_from..effective_to, while active).
 * Written only by EmployeePayItemService. Unit-scoped through its employee.
 */
final class EmployeePayItem extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'code',
        'kind',
        'calc',
        'value',
        'effective_from',
        'effective_to',
        'active',
        'notes',
        'created_by',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PayItemKind::class,
            'calc' => PayItemCalculation::class,
            'value' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'active' => 'boolean',
            'record_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed()->withoutGlobalScope(OperatingUnitScope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * In force on some day of the period [$start, $end] (Y-m-d strings).
     */
    public function coversRange(string $start, string $end): bool
    {
        $from = $this->effective_from->toDateString();
        $to = $this->effective_to?->toDateString();

        return $from <= $end && ($to === null || $to >= $start);
    }
}
