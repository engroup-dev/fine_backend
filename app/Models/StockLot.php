<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\SerializedQuantityException;
use App\Models\Scopes\WarehouseOperatingUnitScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockLot extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'inventory_item_id',
        'warehouse_id',
        'bin_location',
        'lot_number',
        'sequence_in_batch',
        'pressure',
        'remnant_of_lot_id',
        'source_stock_lot_id',
        'quantity',
        'container_quantity',
        'length_m',
        'width_m',
        'height_m',
        'volume_m3',
        'weight_kg',
        'unit_cost',
        'grade',
        'block_type',
        'status',
        'attribute_values',
        'production_batch_id',
        'source_block_length_m',
        'source_block_width_m',
        'source_block_height_m',
        'record_version',
    ];

    protected $casts = [
        'sequence_in_batch' => 'integer',
        'pressure' => 'integer',
        'quantity' => 'decimal:4',
        'container_quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'volume_m3' => 'decimal:4',
        'weight_kg' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'attribute_values' => 'array',
        'record_version' => 'integer',
        'source_block_length_m' => 'decimal:4',
        'source_block_width_m' => 'decimal:4',
        'source_block_height_m' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        // Stock lots have no operating_unit_id of their own; they belong to a unit
        // only through their warehouse.
        static::addGlobalScope(new WarehouseOperatingUnitScope);

        static::saving(function (StockLot $stockLot): void {
            if ($stockLot->length_m !== null && $stockLot->width_m !== null && $stockLot->height_m !== null) {
                $stockLot->volume_m3 = round(
                    (float) $stockLot->length_m * (float) $stockLot->width_m * (float) $stockLot->height_m,
                    4
                );
            }

            $stockLot->guardSerializedQuantity();
        });
    }

    /**
     * INV-02: foam blocks are individually serialized, so a block lot always
     * represents exactly one block.
     *
     * Enforced on the model rather than in a form request so every write path is
     * covered — controllers, services, seeders and future callers alike. A lot
     * drawn down to zero is allowed, since consumption legitimately empties it.
     */
    public function guardSerializedQuantity(): void
    {
        if ($this->inventory_item_id === null) {
            return;
        }

        $itemType = $this->relationLoaded('inventoryItem')
            ? $this->inventoryItem?->item_type
            : InventoryItem::withTrashed()->whereKey($this->inventory_item_id)->value('item_type');

        if ($itemType !== 'foam_block') {
            return;
        }

        $quantity = (float) $this->quantity;

        if ($quantity !== 1.0 && $quantity !== 0.0) {
            throw new SerializedQuantityException(
                "A foam block lot must have a quantity of 1; got {$quantity}. Each block is its own lot."
            );
        }
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function remnantOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remnant_of_lot_id');
    }

    /**
     * The block this piece was cut from. Set on cutter-output StockLots so a
     * finished piece can always be traced back to its source dimensions and lot.
     */
    public function sourceBlock(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_stock_lot_id');
    }

    /**
     * A block can only be cut once, so this is one-to-one. Used to keep already
     * consumed blocks out of the cutter's selection list.
     */
    public function cutterConsumption(): HasOne
    {
        return $this->hasOne(FoamBlockConsumption::class);
    }
}
