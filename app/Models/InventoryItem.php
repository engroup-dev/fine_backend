<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class InventoryItem extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'code',
        'item_type',
        'unit_of_measure',
        'primary_uom',
        'secondary_uom',
        'container_capacity',
        'empty_container_item_id',
        'default_attributes',
        'length_m',
        'width_m',
        'height_m',
        'volume_m3',
        'cost_price',
        'purchase_price',
        'selling_price',
        'wholesale_price',
        'min_price',
        'price_basis',
    ];

    protected $casts = [
        'default_attributes' => 'array',
        'container_capacity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'volume_m3' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'purchase_price' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'wholesale_price' => 'decimal:4',
        'min_price' => 'decimal:4',
    ];

    protected $attributes = [
        'price_basis' => 'unit',
    ];

    protected static function booted(): void
    {
        self::saving(function (InventoryItem $item): void {
            if ($item->length_m !== null && $item->width_m !== null && $item->height_m !== null) {
                $item->volume_m3 = round(
                    (float) $item->length_m * (float) $item->width_m * (float) $item->height_m,
                    4
                );
            }
        });
    }

    /**
     * The item representing this product's empty container, credited back to
     * stock when one drains.
     */
    public function emptyContainerItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'empty_container_item_id');
    }

    public function tracksContainers(): bool
    {
        return $this->container_capacity !== null && (float) $this->container_capacity > 0;
    }

    /**
     * The suggested selling price for a quantity of this item — the POS
     * starting price, always editable by the cashier.
     *
     * A 'unit' item prices per piece. An 'm3' item prices per cubic metre of
     * the given size, falling back to the catalog item's own dimensions.
     * Returns null when the item has no price, or an m3 item has no size.
     */
    public function priceFor(float $quantity, ?float $lengthM = null, ?float $widthM = null, ?float $heightM = null): ?float
    {
        return $this->amountForRate($this->selling_price, $quantity, $lengthM, $widthM, $heightM);
    }

    /**
     * The suggested wholesale selling price for a quantity of this item.
     */
    public function wholesalePriceFor(float $quantity, ?float $lengthM = null, ?float $widthM = null, ?float $heightM = null): ?float
    {
        return $this->amountForRate($this->wholesale_price, $quantity, $lengthM, $widthM, $heightM);
    }

    /**
     * The minimum selling price for a quantity of this item (FR-IVT-025, BR-PRC-003).
     */
    public function minPriceFor(float $quantity, ?float $lengthM = null, ?float $widthM = null, ?float $heightM = null): ?float
    {
        return $this->amountForRate($this->min_price, $quantity, $lengthM, $widthM, $heightM);
    }

    /**
     * The catalog cost for a quantity of this item on the same basis as selling_price (BR-PRC-003).
     */
    public function costFor(float $quantity, ?float $lengthM = null, ?float $widthM = null, ?float $heightM = null): ?float
    {
        return $this->amountForRate($this->cost_price, $quantity, $lengthM, $widthM, $heightM);
    }

    private function amountForRate(float|int|string|null $rate, float $quantity, ?float $lengthM, ?float $widthM, ?float $heightM): ?float
    {
        if ($rate === null) {
            return null;
        }

        $numericRate = (float) $rate;

        if ($this->price_basis !== 'm3') {
            return round($numericRate * $quantity, 4);
        }

        $volume = ($lengthM !== null && $widthM !== null && $heightM !== null)
            ? $lengthM * $widthM * $heightM
            : ($this->volume_m3 !== null ? (float) $this->volume_m3 : null);

        if ($volume === null) {
            return null;
        }

        return round($numericRate * $volume * $quantity, 4);
    }

    /**
     * Physical lots of this item. StockLot's warehouse scope keeps it to the
     * caller's unit.
     */
    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }
}
