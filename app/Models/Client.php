<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Client extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'entity_id',
        'operating_unit_id',
        'credit_limit',
        'current_balance',
        'payment_terms_days',
        'account_id',
        'status',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'credit_limit' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'payment_terms_days' => 'integer',
            'record_version' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    public function creditLimitChanges(): HasMany
    {
        return $this->hasMany(ClientCreditLimitChange::class);
    }
}
