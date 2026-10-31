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
     * actually drawn, amount_paid only ever moves through SaleCheckoutService
     * (payments) and SalesReturnService (cash/bank refunds), and returned_amount
     * only through SalesReturnService.
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
        'delivery_address_id',
        'delivery_address',
        'channel',
        'price_tier',
        'status',
        'fulfillment_status',
        'payment_method',
        'cash_account_id',
        'pos_shift_id',
        'notes',
        'sold_by_user_id',
        'price_override_by_user_id',
        'price_override_reason',
        'record_version',
    ];

    protected $attributes = [
        'price_tier' => 'retail',
    ];

    protected $casts = [
        'status' => SalesOrderStatus::class,
        'fulfillment_status' => SaleFulfillmentStatus::class,
        'gross_amount' => 'decimal:4',
        'invoice_discount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'returned_amount' => 'decimal:4',
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

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(ClientAddress::class, 'delivery_address_id')->withTrashed();
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

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function priceOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_override_by_user_id');
    }

    public function creditApprovalRequest(): HasOne
    {
        return $this->hasOne(CreditApprovalRequest::class);
    }

    /**
     * W5-08: the optional installment schedule of a receivable sale.
     */
    public function installmentPlan(): HasOne
    {
        return $this->hasOne(InstallmentPlan::class);
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
        return max(0.0, round((float) $this->total_amount - (float) ($this->returned_amount ?? 0) - (float) $this->amount_paid, 4));
    }

    public function isReceivable(): bool
    {
        return $this->payment_method === 'receivable';
    }
}
