<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a client's credit limit, written by ClientController::update.
 * Append-only: rows are never edited or removed through the API.
 */
final class ClientCreditLimitChange extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'client_id',
        'old_limit',
        'new_limit',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_limit' => 'decimal:4',
            'new_limit' => 'decimal:4',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
