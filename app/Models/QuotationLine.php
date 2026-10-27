<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuotationLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'quotation_id',
        'line_type',
        'description',
        'position',
        'inventory_item_id',
        'bundle_id',
        'quantity',
        'length_m',
        'width_m',
        'height_m',
        'unit_price',
        'discount_amount',
    ];

    protected $attributes = [
        'discount_amount' => 0,
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:4',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function lineTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price - (float) $this->discount_amount, 4);
    }
}
