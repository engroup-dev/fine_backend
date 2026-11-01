<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BackupException;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * W6-03: backup retention — the newest backup of each of the last 14 days and
 * of each of the last 8 ISO weeks are kept (config/backups.php); the others
 * are deleted with their files.
 */
final class PruneBackups extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete backups outside the retention policy (14 daily + 8 weekly)';

    public function handle(BackupService $backups): int
    {
        try {
            $deleted = $backups->prune();
        } catch (BackupException $e) {
            $this->error("{$e->errorCode}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Pruned {$deleted} backup(s).");

        return self::SUCCESS;
    }
}
