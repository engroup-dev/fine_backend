<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W5-06: a company-wide client group. New clients take its default credit
 * limit and payment terms; the POS starts a sale for its clients on its
 * default price tier (still switchable). Later edits to a group never
 * change clients already registered.
 */
final class ClientGroup extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    public const array PRICE_TIERS = ['retail', 'wholesale'];

    protected $fillable = [
        'code',
        'name',
        'default_credit_limit',
        'default_payment_terms_days',
        'default_price_tier',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'default_credit_limit' => 'decimal:4',
            'default_payment_terms_days' => 'integer',
            'record_version' => 'integer',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
