<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesOrderLine extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_ITEM = 'item';

    public const TYPE_BUNDLE = 'bundle';

    protected $fillable = [
        'sales_order_id',
        'line_type',
        'description',
        'position',
        'inventory_item_id',
        'stock_lot_id',
        'bundle_id',
        'quantity',
        'length_m',
        'width_m',
        'height_m',
        'list_price',
        'unit_price',
        'discount_amount',
        'unit_cost_actual',
    ];

    protected $attributes = [
        'line_type' => self::TYPE_ITEM,
        'discount_amount' => 0,
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'list_price' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'unit_cost_actual' => 'decimal:4',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * What a sold bundle actually contains, defined after checkout.
     */
    public function components(): HasMany
    {
        return $this->hasMany(SaleBundleComponent::class)->orderBy('position');
    }

    public function isBundle(): bool
    {
        return $this->line_type === self::TYPE_BUNDLE;
    }

    public function lineTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price - (float) $this->discount_amount, 4);
    }
}
