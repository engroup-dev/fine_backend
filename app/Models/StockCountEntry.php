<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a counted quantity on a stock count line (append-only).
 *
 * Decision #13: the approver is none of the people who entered a quantity.
 * A line keeps only its latest counter, so a later re-save would hide an
 * earlier one; this trail keeps them all.
 */
final class StockCountEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'stock_count_id',
        'stock_count_line_id',
        'user_id',
        'counted_qty',
    ];

    protected $casts = [
        'counted_qty' => 'decimal:4',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StockCountLine::class, 'stock_count_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
