<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SalesOrder extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * Money fields are excluded: totals derive from lines, cost from the lots
     * actually drawn, and amount_paid only ever moves through SaleCheckoutService.
     */
    protected $fillable = [
        'operating_unit_id',
        'order_number',
        'client_request_id',
        'quotation_id',
        'buyer_type',
        'client_id',
        'buyer_unit_id',
        'walk_in_name',
        'walk_in_phone',
        'channel',
        'status',
        'fulfillment_status',
        'payment_method',
        'cash_account_id',
        'notes',
        'sold_by_user_id',
        'record_version',
    ];

    protected $casts = [
        'status' => SalesOrderStatus::class,
        'fulfillment_status' => SaleFulfillmentStatus::class,
        'total_amount' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('position');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function buyerUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'buyer_unit_id');
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    /**
     * The treasury or bank a cash/bank sale was received into.
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function creditApprovalRequest(): HasOne
    {
        return $this->hasOne(CreditApprovalRequest::class);
    }

    /**
     * SALE-03: only external client sales face the credit gate.
     */
    public function isCreditGated(): bool
    {
        return $this->buyer_type === 'client';
    }

    public function isInternal(): bool
    {
        return $this->buyer_type === 'internal_unit';
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->amount_paid, 4);
    }

    public function isReceivable(): bool
    {
        return $this->payment_method === 'receivable';
    }
}
