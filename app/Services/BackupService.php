<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\User;
use App\Support\CompanyClock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * W6-03: database backups (docs/phase-10-dashboard-deployment.md §10.8
 * "Backup & Recovery": daily dumps, restore tested monthly).
 *
 * - run(): SQLite `VACUUM INTO` a temporary file, or PostgreSQL `pg_dump -Fc`
 *   through the Process facade (an argument list, no shell; the password goes
 *   in PGPASSWORD, never on the command line). The dump is gzipped, hashed
 *   (sha256) after the archive is read back in full, and written to the
 *   private backups disk; a `backups` row records the outcome either way. The
 *   row is written only once the outcome is known, so no dump ever contains
 *   a row for an unfinished backup; the cache lock alone marks a run in
 *   progress.
 * - verify(): checks the stored file against its sha256, restores it into a
 *   scratch database (a temporary SQLite file, or the configured PostgreSQL
 *   scratch database via pg_restore) and runs an integrity check there. One
 *   restore test at a time (cache lock), and prune() waits its turn.
 * - prune(): keeps the newest backup of each of the last 14 days and of each
 *   of the last 8 ISO weeks (config/backups.php); deletes the rest, and
 *   sweeps backup files no row knows about once older than the retention window
 *   and files a deleted row left behind.
 * - Working directories (plain dumps) are removed when a run ends; ones a
 *   killed process left behind are swept by the next run() and prune().
 *
 * There is no in-app restore: restoring replaces the live database, so it is
 * an operator task done on the server from a downloaded file.
 */
final class BackupService
{
    private const LOCK = 'backups:run';

    /** Held by verify() and prune(): a file is never pruned mid restore test. */
    private const VERIFY_LOCK = 'backups:verify';

    /** Working directories: sys_get_temp_dir()/fine-backup-<16 alphanumerics>. */
    private const WORK_DIRECTORY_PREFIX = 'fine-backup-';

    private const WORK_DIRECTORY_PATTERN = '/^fine-backup-[A-Za-z0-9]{16}$/';

    /** Drivers a backup can be taken from. */
    private const DRIVERS = ['sqlite', 'pgsql'];

    /** A backup file name is always generated here; anything else is refused. */
    private const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * Take a backup now. A dump that fails is recorded as a failed row and
     * returned (not thrown), so the scheduler and the endpoint both see why.
     *
     * @throws BackupException BACKUP_IN_PROGRESS when another backup is running,
     *                         BACKUP_DRIVER_UNSUPPORTED for a driver other than sqlite/pgsql
     */
    public function run(BackupTrigger $trigger, ?User $user = null): Backup
    {
        $connection = $this->sourceConnection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new BackupException(
                "Backups support SQLite and PostgreSQL; this database uses {$driver}.",
                'BACKUP_DRIVER_UNSUPPORTED',
                ['driver' => $driver],
            );
        }

        $lock = Cache::lock(self::LOCK, $this->timeout() * 2);

        if (! $lock->get()) {
            throw new BackupException('A backup is already running; wait for it to finish.', 'BACKUP_IN_PROGRESS');
        }

        try {
            $this->sweepStaleWorkDirectories();

            $startedAt = Carbon::now();
            $file = $this->fileName($driver, $startedAt);
            $workDirectory = null;

            try {
                $workDirectory = $this->makeWorkDirectory();
                $dumpPath = $workDirectory.'/dump';
                $gzipPath = $workDirectory.'/dump.gz';

                if ($driver === 'sqlite') {
                    $this->dumpSqlite($connection, $dumpPath);
                } else {
                    $this->dumpPgsql($connection, $dumpPath);
                }

                $archive = $this->compress($dumpPath, $gzipPath);
                $this->storeFile($file, $gzipPath);

                return $this->record($file, $driver, $trigger, $user, $startedAt, BackupStatus::Completed, [
                    'size_bytes' => $archive['size'],
                    'checksum' => $archive['checksum'],
                ]);
            } catch (Throwable $e) {
                report($e);
                // A half-written file, or one whose row could not be saved.
                $this->deleteFileQuietly($file);

                return $this->record($file, $driver, $trigger, $user, $startedAt, BackupStatus::Failed, [
                    'error' => Str::limit($e->getMessage(), 2000),
                ]);
            } finally {
                if ($workDirectory !== null) {
                    $this->removeDirectory($workDirectory);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Restore a completed backup into a scratch database and check it. The
     * outcome is recorded on the row (verified_at, or verify_error).
     *
     * @throws BackupException BACKUP_NOT_COMPLETED, BACKUP_FILE_MISSING, BACKUP_VERIFY_IN_PROGRESS,
     *                         BACKUP_VERIFY_NOT_CONFIGURED, BACKUP_VERIFY_SCRATCH_IS_SOURCE,
     *                         BACKUP_VERIFY_FAILED (checksum mismatch, restore or integrity failure)
     */
    public function verify(Backup $backup): Backup
    {
        if ($backup->status !== BackupStatus::Completed) {
            throw new BackupException('Only a completed backup can be verified.', 'BACKUP_NOT_COMPLETED', ['backup_id' => $backup->id]);
        }

        $this->guardFileName($backup->file);

        // Two restores into the same PostgreSQL scratch database would drop
        // each other's objects and fail a sound backup.
        $lock = Cache::lock(self::VERIFY_LOCK, $this->timeout() * 2);

        if (! $lock->get()) {
            throw new BackupException('A restore test is already running; wait for it to finish.', 'BACKUP_VERIFY_IN_PROGRESS', ['backup_id' => $backup->id]);
        }

        try {
            return $this->verifyLocked($backup);
        } finally {
            $lock->release();
        }
    }

    private function verifyLocked(Backup $backup): Backup
    {
        if (! $this->disk()->exists($backup->file)) {
            throw new BackupException('The backup file is missing from the backups disk.', 'BACKUP_FILE_MISSING', ['backup_id' => $backup->id]);
        }

        try {
            $pgsqlTarget = $backup->driver === 'pgsql' ? $this->pgsqlVerifyTarget() : null;
        } catch (BackupException $e) {
            // A restore test that cannot run is recorded and logged, so the
            // owner's list shows it did not happen (not only the exit code).
            report($e);
            $this->recordVerification($backup, ['verified_at' => null, 'verify_error' => "{$e->errorCode}: {$e->getMessage()}"]);

            throw $e;
        }

        $workDirectory = null;

        try {
            $workDirectory = $this->makeWorkDirectory();
            $gzipPath = $workDirectory.'/backup.gz';
            $restorePath = $workDirectory.'/restore';

            $checksum = $this->copyFromDisk($backup->file, $gzipPath);

            if (! hash_equals((string) $backup->checksum, $checksum)) {
                throw new RuntimeException('The file does not match its recorded sha256 checksum.');
            }

            $this->gunzip($gzipPath, $restorePath);

            if ($backup->driver === 'sqlite') {
                $this->verifySqlite($restorePath);
            } else {
                $this->verifyPgsql($restorePath, $pgsqlTarget);
            }
        } catch (Throwable $e) {
            report($e);
            $message = Str::limit($e->getMessage(), 2000);
            $this->recordVerification($backup, ['verified_at' => null, 'verify_error' => $message]);

            throw new BackupException("Backup verification failed: {$message}", 'BACKUP_VERIFY_FAILED', ['backup_id' => $backup->id]);
        } finally {
            if ($workDirectory !== null) {
                $this->removeDirectory($workDirectory);
            }
        }

        return $this->recordVerification($backup, ['verified_at' => Carbon::now(), 'verify_error' => null]);
    }

    /**
     * Apply the retention policy: keep the newest completed backup of each of
     * the last `keep_daily` days that have one and of each of the last
     * `keep_weekly` ISO weeks that have one (company-local dates); delete the
     * other completed backups. Failed rows (which hold no file) older than the
     * daily window are removed too.
     *
     * @return int how many backups were deleted
     *
     * @throws BackupException BACKUP_VERIFY_IN_PROGRESS while a restore test runs
     */
    public function prune(): int
    {
        $lock = Cache::lock(self::VERIFY_LOCK, $this->timeout() * 2);

        if (! $lock->get()) {
            throw new BackupException('A restore test is running; prune once it finishes.', 'BACKUP_VERIFY_IN_PROGRESS');
        }

        try {
            $this->sweepStaleWorkDirectories();

            return $this->pruneLocked();
        } finally {
            $lock->release();
        }
    }

    private function pruneLocked(): int
    {
        $timezone = CompanyClock::timezone();
        $keepDaily = max(1, (int) config('backups.keep_daily', 14));
        $keepWeekly = max(0, (int) config('backups.keep_weekly', 8));

        $completed = Backup::query()
            ->where('status', BackupStatus::Completed)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $keep = [];
        $days = [];
        $weeks = [];

        foreach ($completed as $backup) {
            $local = $backup->created_at->copy()->setTimezone($timezone);
            $day = $local->format('Y-m-d');
            $week = $local->format('o-\WW');

            if (! isset($days[$day]) && count($days) < $keepDaily) {
                $days[$day] = true;
                $keep[$backup->id] = true;
            }

            if (! isset($weeks[$week]) && count($weeks) < $keepWeekly) {
                $weeks[$week] = true;
                $keep[$backup->id] = true;
            }
        }

        $deleted = 0;

        foreach ($completed as $backup) {
            if (! isset($keep[$backup->id])) {
                $this->delete($backup);
                $deleted++;
            }
        }

        $staleFailures = Backup::query()
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '<', Carbon::now()->subDays($keepDaily))
            ->get();

        foreach ($staleFailures as $backup) {
            $this->delete($backup);
            $deleted++;
        }

        return $deleted + $this->pruneOrphanFiles($keepDaily, $keepWeekly);
    }

    /**
     * A file whose row is soft-deleted was meant to be gone: delete() removes
     * the row first and the file after, and a failed file delete is only
     * logged. It is retried here, whatever its age.
     *
     * Files no row knows about: after a database is restored from an older
     * backup, the files taken since then have no rows, and a process that died
     * between writing a file and saving its row leaves one too. They are kept
     * for the whole retention window (the restore file among them) and removed
     * once older than it.
     */
    private function pruneOrphanFiles(int $keepDaily, int $keepWeekly): int
    {
        $live = Backup::query()->pluck('file')->flip();
        $trashed = Backup::onlyTrashed()->pluck('file')->flip();
        $cutoff = Carbon::now()->subDays(max($keepDaily, $keepWeekly * 7))->getTimestamp();
        $disk = $this->disk();
        $deleted = 0;

        foreach ($disk->files() as $file) {
            if (! str_starts_with($file, 'fine-backup-')
                || ! str_ends_with($file, '.gz')
                || preg_match(self::FILE_PATTERN, $file) !== 1
                || $live->has($file)) {
                continue;
            }

            if ($trashed->has($file)) {
                $this->deleteFileQuietly($file);
                $deleted++;

                continue;
            }

            try {
                if ($disk->lastModified($file) >= $cutoff) {
                    continue;
                }
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $this->deleteFileQuietly($file);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Delete a backup: the row is soft-deleted (audit trail) and its file
     * removed from the backups disk. A file that cannot be removed now is
     * retried by prune() (see pruneOrphanFiles()).
     */
    public function delete(Backup $backup): void
    {
        $file = DB::transaction(function () use ($backup): string {
            $locked = Backup::query()->lockForUpdate()->findOrFail($backup->id);
            $locked->delete();

            return $locked->file;
        });

        $this->deleteFileQuietly($file);
    }

    /**
     * The disk the backup files live on (private; outside the web root).
     */
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('backups.disk', 'backups'));
    }

    /**
     * @throws BackupException BACKUP_FILE_INVALID for a file name this service did not generate
     */
    public function guardFileName(string $file): void
    {
        if (preg_match(self::FILE_PATTERN, $file) !== 1 || str_contains($file, '..')) {
            throw new BackupException('The backup file name is not valid.', 'BACKUP_FILE_INVALID');
        }
    }

    private function sourceConnection(): Connection
    {
        /** @var Connection */
        return DB::connection(config('backups.connection') ?: null);
    }

    private function timeout(): int
    {
        return max(60, (int) config('backups.timeout', 900));
    }

    private function fileName(string $driver, Carbon $startedAt): string
    {
        $extension = $driver === 'sqlite' ? 'sqlite' : 'dump';

        return sprintf('fine-backup-%s-%s.%s.gz', $startedAt->copy()->utc()->format('Ymd-His'), Str::lower(Str::random(8)), $extension);
    }

    /**
     * The row is written once, after the outcome is known. Writing it before
     * the dump would put the backup's own unfinished row inside the dump: a
     * database restored from that file would show a backup that never ends.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $file, string $driver, BackupTrigger $trigger, ?User $user, Carbon $startedAt, BackupStatus $status, array $attributes): Backup
    {
        return DB::transaction(fn (): Backup => Backup::create([
            'file' => $file,
            'driver' => $driver,
            'status' => $status,
            'trigger' => $trigger,
            'user_id' => $user?->id,
            'started_at' => $startedAt,
            'completed_at' => Carbon::now(),
            ...$attributes,
        ]));
    }

    /**
     * @param  array{verified_at: ?Carbon, verify_error: ?string}  $attributes
     */
    private function recordVerification(Backup $backup, array $attributes): Backup
    {
        return DB::transaction(function () use ($backup, $attributes): Backup {
            // withTrashed: the owner may delete the backup while it is being verified;
            // the outcome is still recorded on its row instead of crashing the command.
            $locked = Backup::withTrashed()->lockForUpdate()->findOrFail($backup->id);
            $locked->update($attributes);

            return $locked;
        });
    }

    /**
     * SQLite: `VACUUM INTO` writes a consistent, compacted copy of the live
     * database (safe while others write). It cannot run inside a transaction.
     */
    private function dumpSqlite(Connection $connection, string $path): void
    {
        if ($connection->transactionLevel() > 0) {
            throw new RuntimeException('A SQLite backup cannot run inside an open transaction.');
        }

        $connection->statement('VACUUM INTO ?', [$path]);

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('VACUUM INTO produced no file.');
        }
    }

    /**
     * PostgreSQL: `pg_dump -Fc` (custom format, restorable with pg_restore).
     */
    private function dumpPgsql(Connection $connection, string $path): void
    {
        $config = $connection->getConfig();

        $command = [
            (string) config('backups.pgsql.pg_dump', 'pg_dump'),
            '--format=custom',
            '--no-password',
            ...$this->pgsqlServerArguments($config),
            '--dbname='.$config['database'],
            '--file='.$path,
        ];

        $this->runPgsqlTool($command, $config);

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('pg_dump produced no file.');
        }
    }

    /**
     * Restore into the scratch database (wiping what it held), then check the
     * restored schema answers queries and carries the migration history.
     *
     * @param  array{config: array<string, mixed>, database: string}  $target
     */
    private function verifyPgsql(string $path, array $target): void
    {
        $config = $target['config'];

        $this->runPgsqlTool([
            (string) config('backups.pgsql.pg_restore', 'pg_restore'),
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--no-password',
            ...$this->pgsqlServerArguments($config),
            '--dbname='.$target['database'],
            $path,
        ], $config);

        $name = 'backup_verify_scratch';
        $scratchConfig = array_merge($config, ['database' => $target['database'], 'name' => $name]);
        unset($scratchConfig['url']);

        config(["database.connections.{$name}" => $scratchConfig]);
        DB::purge($name);

        try {
            $migrations = (int) DB::connection($name)->table('migrations')->count();
        } finally {
            DB::purge($name);
        }

        if ($migrations === 0) {
            throw new RuntimeException('The restored database has no migration history.');
        }
    }

    /**
     * The scratch database a PostgreSQL backup is restored into. Refuses an
     * unset name and, above all, the database being backed up.
     *
     * @return array{config: array<string, mixed>, database: string}
     */
    private function pgsqlVerifyTarget(): array
    {
        $connection = $this->sourceConnection();
        $database = (string) config('backups.pgsql.verify_database', '');

        if ($connection->getDriverName() !== 'pgsql' || $database === '') {
            throw new BackupException(
                'Verifying a PostgreSQL backup needs a scratch database (BACKUP_VERIFY_DATABASE) on the PostgreSQL connection.',
                'BACKUP_VERIFY_NOT_CONFIGURED',
            );
        }

        $config = $connection->getConfig();

        if ($database === (string) $config['database']) {
            throw new BackupException(
                'The verification scratch database cannot be the live database: restoring into it would wipe it.',
                'BACKUP_VERIFY_SCRATCH_IS_SOURCE',
            );
        }

        return ['config' => $config, 'database' => $database];
    }

    /**
     * SQLite: the decompressed file is the scratch database. It must pass
     * PRAGMA integrity_check and carry the migration history.
     */
    private function verifySqlite(string $path): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $result = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);

            if ($result !== ['ok']) {
                throw new RuntimeException('SQLite integrity check failed: '.Str::limit(implode('; ', $result), 500));
            }

            $hasMigrations = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")->fetchColumn();

            if ($hasMigrations === 0 || (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 0) {
                throw new RuntimeException('The restored database has no migration history.');
            }
        } finally {
            $pdo = null;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function pgsqlServerArguments(array $config): array
    {
        $host = $config['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        $arguments = [];

        if (filled($host)) {
            $arguments[] = '--host='.$host;
        }

        if (filled($config['port'] ?? null)) {
            $arguments[] = '--port='.$config['port'];
        }

        if (filled($config['username'] ?? null)) {
            $arguments[] = '--username='.$config['username'];
        }

        return $arguments;
    }

    /**
     * Run pg_dump / pg_restore without a shell. The password travels in the
     * child's PGPASSWORD environment variable, never in its arguments.
     *
     * @param  list<string>  $command
     * @param  array<string, mixed>  $config
     */
    private function runPgsqlTool(array $command, array $config): void
    {
        $environment = [];

        if (filled($config['password'] ?? null)) {
            $environment['PGPASSWORD'] = (string) $config['password'];
        }

        if (filled($config['sslmode'] ?? null)) {
            $environment['PGSSLMODE'] = (string) $config['sslmode'];
        }

        $result = Process::timeout($this->timeout())->env($environment)->run($command);

        if ($result->failed()) {
            $output = trim($result->errorOutput()) ?: trim($result->output());

            throw new RuntimeException(sprintf(
                '%s exited with code %s: %s',
                basename($command[0]),
                (string) $result->exitCode(),
                Str::limit($output, 1500),
            ));
        }
    }

    /**
     * Gzip the dump, then read the archive back once in full (see
     * inspectArchive()) so a truncated or corrupt archive is never stored as
     * a completed backup.
     *
     * @return array{checksum: string, size: int}
     */
    private function compress(string $source, string $destination): array
    {
        $in = fopen($source, 'rb');
        $out = gzopen($destination, 'wb6');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not open the dump for compression.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                    throw new RuntimeException('Compressing the dump failed.');
                }
            }

            // zlib writes the last deflate block and the CRC/size trailer here.
            $closed = gzclose($out);
            $out = null;

            if ($closed === false) {
                throw new RuntimeException('Finishing the compressed dump failed.');
            }
        } finally {
            fclose($in);

            if ($out !== null) {
                gzclose($out);
            }
        }

        $dumpSize = filesize($source);

        if ($dumpSize === false) {
            throw new RuntimeException('Could not read the dump.');
        }

        return $this->inspectArchive($destination, $dumpSize);
    }

    /**
     * Stream a gzip archive to its end: it must inflate cleanly, reach the
     * end of the gzip stream (the trailer's CRC and length are checked by
     * zlib) and inflate to exactly the dump's size. Returns its sha256 and
     * compressed size.
     *
     * @return array{checksum: string, size: int}
     */
    private function inspectArchive(string $path, int $expectedSize): array
    {
        $handle = fopen($path, 'rb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        if ($handle === false || $inflate === false) {
            throw new RuntimeException('Could not read the compressed dump.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $inflated = 0;

        try {
            while (! feof($handle)) {
                // Small reads bound the inflated chunk (deflate tops out near 1:1000).
                $chunk = fread($handle, 64 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the compressed dump.');
                }

                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                hash_update($hash, $chunk);

                $data = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);

                if ($data === false) {
                    throw new RuntimeException('The compressed dump is corrupt.');
                }

                $inflated += strlen($data);
            }
        } finally {
            fclose($handle);
        }

        if (inflate_get_status($inflate) !== ZLIB_STREAM_END || $inflated !== $expectedSize) {
            throw new RuntimeException('The compressed dump is incomplete.');
        }

        return ['checksum' => hash_final($hash), 'size' => $size];
    }

    private function gunzip(string $source, string $destination): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not open the backup for decompression.');
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('The backup file is not a valid gzip archive.');
                }

                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Decompressing the backup failed.');
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    private function storeFile(string $file, string $localPath): void
    {
        $this->guardFileName($file);
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not read the compressed dump.');
        }

        try {
            if ($this->disk()->writeStream($file, $stream) === false) {
                throw new RuntimeException('Could not write the backup file to the backups disk.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Copy a stored backup to a local file, returning its sha256.
     */
    private function copyFromDisk(string $file, string $localPath): string
    {
        $in = $this->disk()->readStream($file);
        $out = fopen($localPath, 'wb');

        if (! is_resource($in) || $out === false) {
            throw new RuntimeException('Could not read the backup file.');
        }

        $hash = hash_init('sha256');

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('Reading the backup file failed.');
                }

                hash_update($hash, $chunk);

                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Copying the backup file failed.');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return hash_final($hash);
    }

    private function deleteFileQuietly(string $file): void
    {
        try {
            $this->guardFileName($file);

            if ($this->disk()->exists($file)) {
                $this->disk()->delete($file);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A private (0700) working directory under the system temp dir; dumps
     * hold every record, so nothing else on the machine may read them.
     */
    private function makeWorkDirectory(): string
    {
        $path = $this->workDirectoryBase().DIRECTORY_SEPARATOR.self::WORK_DIRECTORY_PREFIX.Str::random(16);

        if (! mkdir($path, 0700) && ! is_dir($path)) {
            throw new RuntimeException('Could not create a working directory for the backup.');
        }

        return $path;
    }

    private function workDirectoryBase(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    }

    /**
     * A process killed mid dump (request timeout, OOM, SIGKILL) never reaches
     * its finally block, so its working directory, a plain copy of the whole
     * database, stays behind. Ones untouched for twice the timeout (no dump
     * or restore runs that long) are removed.
     */
    private function sweepStaleWorkDirectories(): void
    {
        $cutoff = time() - $this->timeout() * 2;
        $pattern = $this->workDirectoryBase().DIRECTORY_SEPARATOR.self::WORK_DIRECTORY_PREFIX.'*';

        foreach (glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $path) {
            if (preg_match(self::WORK_DIRECTORY_PATTERN, basename($path)) !== 1 || is_link($path)) {
                continue;
            }

            if ($this->lastModifiedWithin($path) < $cutoff) {
                $this->removeDirectory($path);
            }
        }
    }

    /**
     * The newest mtime of the directory and the files in it (a file being
     * written does not touch its directory's mtime).
     */
    private function lastModifiedWithin(string $path): int
    {
        clearstatcache();
        $newest = (int) @filemtime($path);

        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $newest = max($newest, (int) @filemtime($path.DIRECTORY_SEPARATOR.$entry));
            }
        }

        return $newest;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }
}
