<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionBatchStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionBatch extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * FOAM-01: the foam machine's physical pour width. A run cannot exceed it,
     * and every observed production report shows 2.4.
     */
    public const MAX_BUN_WIDTH_M = 2.4;

    /**
     * `next_sequence` and `scrap_volume_m3` are deliberately excluded: both are
     * derived from block registration and are only ever written by
     * ProductionBatchService, never by client input.
     */
    protected $fillable = [
        'operating_unit_id',
        'requested_by_client_id',
        'operation_number',
        'bun_width_m',
        'formula_params',
        'status',
        'material_cost',
        'record_version',
    ];

    protected $casts = [
        'operation_number' => 'integer',
        'bun_width_m' => 'decimal:3',
        'formula_params' => 'array',
        'status' => ProductionBatchStatus::class,
        'material_cost' => 'decimal:4',
        'scrap_volume_m3' => 'decimal:4',
        'next_sequence' => 'integer',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function requestedByClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'requested_by_client_id');
    }

    /**
     * W4-05: chemicals issued to the run, one row per lot drawn. Their summed
     * total_cost is the batch's material_cost.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionBatchMaterial::class);
    }

    /**
     * Every lot the run produced — serialized blocks and zero-cost scrap alike.
     */
    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    /**
     * Serialized blocks only. Scrap lots carry no sequence, and must be excluded
     * from anything that reasons about blocks: cost apportionment would otherwise
     * dilute cost onto scrap, and the grading guard would flag scrap as unmeasured.
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(StockLot::class)->whereNotNull('sequence_in_batch');
    }

    /**
     * Scrap registered on the run. Other unsequenced lots carry the batch too
     * and are not scrap: cutter remnants (remnant_of_lot_id) and warehouse
     * transfer copies (source_stock_lot_id, W4-04), which keep the batch for
     * lineage.
     */
    public function scrapLots(): HasMany
    {
        return $this->hasMany(StockLot::class)
            ->whereNull('sequence_in_batch')
            ->whereNull('remnant_of_lot_id')
            ->whereNull('source_stock_lot_id');
    }
}
