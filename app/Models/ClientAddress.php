<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One delivery address of a client. Unit scope comes through the client:
 * the controller resolves the (unit-scoped) client first, then its addresses.
 * Sales keep a text snapshot, so editing or removing an address never
 * changes a delivery note already printed.
 */
final class ClientAddress extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'client_id',
        'label',
        'city',
        'address',
        'phone',
        'is_default',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'record_version' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The text a sale keeps: label, city, address and phone, as printed on
     * the delivery note.
     */
    public function snapshot(): string
    {
        $parts = array_filter(
            [$this->label, $this->city, $this->address, $this->phone],
            fn (?string $part): bool => $part !== null && trim($part) !== '',
        );

        return implode(' - ', array_map('trim', $parts));
    }
}
