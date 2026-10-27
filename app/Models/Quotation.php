<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced preview for a registered client. No stock or ledger effect;
 * converting it runs a normal POS checkout with its lines.
 */
final class Quotation extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'operating_unit_id',
        'quotation_number',
        'client_id',
        'valid_until',
        'status',
        'gross_amount',
        'invoice_discount',
        'total_amount',
        'converted_sales_order_id',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'gross_amount' => 'decimal:4',
        'invoice_discount' => 'decimal:4',
        'total_amount' => 'decimal:4',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $appends = ['effective_status'];

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('position');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->valid_until !== null
            && $this->valid_until->lt(today());
    }

    /**
     * Expiry is derived, never stored: an open quotation past its validity
     * date reads as expired.
     */
    protected function effectiveStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->isExpired() ? self::STATUS_EXPIRED : $this->status);
    }
}
