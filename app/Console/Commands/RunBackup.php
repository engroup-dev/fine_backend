<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\BackupException;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * W6-03: take a database backup to the private backups disk. Scheduled
 * daily at 02:00 (routes/console.php) with --scheduled; a hand run is
 * recorded as manual.
 */
final class RunBackup extends Command
{
    protected $signature = 'backup:run {--scheduled : Record the backup as started by the schedule}';

    protected $description = 'Dump the database, gzip it and store it with its sha256 on the backups disk';

    public function handle(BackupService $backups): int
    {
        $trigger = $this->option('scheduled') ? BackupTrigger::Scheduled : BackupTrigger::Manual;

        try {
            $backup = $backups->run($trigger);
        } catch (BackupException $e) {
            $this->error("{$e->errorCode}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($backup->status !== BackupStatus::Completed) {
            $this->error("Backup {$backup->id} failed: {$backup->error}");

            return self::FAILURE;
        }

        $this->info("Backup {$backup->id} written: {$backup->file} ({$backup->size_bytes} bytes, sha256 {$backup->checksum}).");

        return self::SUCCESS;
    }
}
