<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * W6-03: a database backup. Company-wide (not unit-scoped); `file` is the
 * name on the private backups disk and is never taken from a request — the
 * download endpoint finds the row by id.
 */
final class Backup extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'file',
        'size_bytes',
        'checksum',
        'driver',
        'status',
        'error',
        'trigger',
        'user_id',
        'started_at',
        'completed_at',
        'verified_at',
        'verify_error',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'trigger' => BackupTrigger::class,
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'record_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
