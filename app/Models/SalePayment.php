<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against a sale — at checkout, or later against a
 * receivable. Written only by SaleCheckoutService, always with its journal.
 */
final class SalePayment extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'sales_order_id',
        'client_id',
        'amount',
        'tendered_amount',
        'change_given',
        'method',
        'cash_account_id',
        'received_at',
        'received_by_user_id',
        'journal_entry_id',
        'cheque_id',
        'sales_return_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'tendered_amount' => 'decimal:4',
        'change_given' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }
}
