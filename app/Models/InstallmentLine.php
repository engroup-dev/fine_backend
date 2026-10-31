<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W5-08: one installment of a plan — its due date and scheduled amount. What
 * has been paid towards it and its status are derived, never stored
 * (InstallmentPlanService::schedule).
 */
final class InstallmentLine extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'installment_plan_id',
        'number',
        'due_date',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date:Y-m-d',
            'amount' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id')->withoutGlobalScopes();
    }
}
