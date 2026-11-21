<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Plain copy of the SQLite database taken by update_backend.bat right before
 * `migrate`. Unlike backup:run it needs no `backups` table, so it works on a
 * database that is still missing migrations. `VACUUM INTO` also folds in the
 * WAL, which a file copy of database.sqlite alone would miss.
 */
final class SnapshotDatabase extends Command
{
    private const KEEP = 10;

    protected $signature = 'db:snapshot';

    protected $description = 'Write a consistent copy of the SQLite database to storage/app/pre-update-snapshots';

    public function handle(): int
    {
        /** @var Connection $connection */
        $connection = DB::connection(config('backups.connection') ?: null);

        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('db:snapshot only supports SQLite; use backup:run for this database.');

            return self::FAILURE;
        }

        $dir = self::directory();
        File::ensureDirectoryExists($dir);

        $path = $dir.DIRECTORY_SEPARATOR.'database-'.Carbon::now()->format('Ymd-His').'.sqlite';
        if (is_file($path)) {
            $this->error("Snapshot {$path} already exists.");

            return self::FAILURE;
        }

        $connection->statement('VACUUM INTO ?', [$path]);

        if (! is_file($path) || filesize($path) === 0) {
            $this->error('VACUUM INTO produced no file.');

            return self::FAILURE;
        }

        $this->pruneOldSnapshots($dir);
        $this->line($path);

        return self::SUCCESS;
    }

    public static function directory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'pre-update-snapshots');
    }

    private function pruneOldSnapshots(string $dir): void
    {
        $snapshots = glob($dir.DIRECTORY_SEPARATOR.'database-*.sqlite') ?: [];
        rsort($snapshots);

        foreach (array_slice($snapshots, self::KEEP) as $old) {
            File::delete($old);
        }
    }
}
