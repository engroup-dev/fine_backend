<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentFrequency;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * W5-08 (decision #6): the optional installment schedule of one receivable
 * sale. Written only by InstallmentPlanService; it moves no money.
 */
final class InstallmentPlan extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'sales_order_id',
        'client_id',
        'financed_amount',
        'down_payment',
        'opening_paid',
        'opening_returned',
        'installment_count',
        'frequency',
        'first_due_date',
        'created_by',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => InstallmentFrequency::class,
            'financed_amount' => 'decimal:4',
            'down_payment' => 'decimal:4',
            'opening_paid' => 'decimal:4',
            'opening_returned' => 'decimal:4',
            'installment_count' => 'integer',
            'first_due_date' => 'date:Y-m-d',
            'record_version' => 'integer',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InstallmentLine::class)->orderBy('number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
