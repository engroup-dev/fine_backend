<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W5-04 (HR-14): a company-wide work shift. Its standard hours are the
 * overtime hourly base of daily pay (daily rate / standard hours), and its
 * overtime multiplier applies to every employee assigned to it.
 */
final class WorkShift extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'standard_hours',
        'overtime_multiplier',
        'start_time',
        'end_time',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'standard_hours' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
            'record_version' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
