<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sales return, pointing back at the original sales order line.
 */
final class SalesReturnLine extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'sales_return_id',
        'sales_order_line_id',
        'quantity',
        'amount',
        'condition',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'amount' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }
}
