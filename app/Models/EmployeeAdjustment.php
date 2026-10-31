<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeAdjustmentStatus;
use App\Enums\PayItemKind;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W5-03 (HR-13): a one-off reward or deduction for one payroll period.
 * Payroll applies it only once approved. Written only by
 * EmployeePayItemService. Unit-scoped through its employee.
 */
final class EmployeeAdjustment extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'period',
        'kind',
        'code',
        'amount',
        'reason',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PayItemKind::class,
            'status' => EmployeeAdjustmentStatus::class,
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
