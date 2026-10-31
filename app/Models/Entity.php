<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntityType;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Entity extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'entity_type',
        'tax_number',
        'user_id',
        'is_active',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => EntityType::class,
            'is_active' => 'boolean',
            'record_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(EntityRole::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EntityContact::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(EntityContact::class)->where('is_primary', true);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /**
     * W5-07: the entity's supplier record (vendor role), within the caller's
     * unit scope.
     */
    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where('entity_type', EntityType::Individual);
    }

    public function scopeOrganization(Builder $query): Builder
    {
        return $query->where('entity_type', EntityType::Organization);
    }

    public function scopeWithRole(Builder $query, string $roleType): Builder
    {
        return $query->whereHas('roles', function (Builder $q) use ($roleType) {
            $q->where('role_type', $roleType);
        });
    }
}
