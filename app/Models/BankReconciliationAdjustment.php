<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankReconciliationAdjustmentType;
use App\Models\Scopes\OperatingUnitScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TRS-04: a statement item posted from a bank reconciliation, through a voucher.
 */
final class BankReconciliationAdjustment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'bank_reconciliation_id',
        'treasury_voucher_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => BankReconciliationAdjustmentType::class,
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    /**
     * Treasuries are company-wide, so the voucher may belong to another unit
     * than the one pinned in the caller's top bar.
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(TreasuryVoucher::class, 'treasury_voucher_id')
            ->withoutGlobalScope(OperatingUnitScope::class);
    }
}
