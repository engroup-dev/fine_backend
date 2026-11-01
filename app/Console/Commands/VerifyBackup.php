<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * W6-03: restore test. Checks a backup against its sha256, restores it into a
 * scratch database and runs an integrity check there; the result is recorded
 * on the backup (verified_at / verify_error). Defaults to the newest completed
 * backup. Never touches the live database.
 */
final class VerifyBackup extends Command
{
    protected $signature = 'backup:verify {backup? : The backup id (defaults to the newest completed backup)}';

    protected $description = 'Restore a backup into a scratch database and check its integrity';

    public function handle(BackupService $backups): int
    {
        $id = $this->argument('backup');

        $backup = $id !== null
            ? (Str::isUuid($id) ? Backup::query()->find($id) : null)
            : Backup::query()->where('status', BackupStatus::Completed)->latest()->first();

        if ($backup === null) {
            $this->error($id !== null ? "No backup {$id}." : 'There is no completed backup to verify.');

            return self::FAILURE;
        }

        try {
            $backups->verify($backup);
        } catch (BackupException $e) {
            $this->error("{$e->errorCode}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup {$backup->id} ({$backup->file}) restored and passed the integrity check.");

        return self::SUCCESS;
    }
}
