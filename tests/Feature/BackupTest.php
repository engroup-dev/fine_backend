<?php

declare(strict_types=1);

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\BackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

/**
 * W6-03: database backups. The source database is a throwaway SQLite file in
 * the system temp dir (its own connection, outside the test transaction) and
 * the backups disk is faked, so nothing here reads or writes
 * database/database.sqlite or storage/app/backups. PostgreSQL runs through a
 * faked Process: no pg_dump is ever executed.
 */
beforeEach(function () {
    Storage::fake('backups');

    $this->sourcePath = tempnam(sys_get_temp_dir(), 'fine-backup-test-');
    config([
        'database.connections.backup_source' => [
            'driver' => 'sqlite',
            'database' => $this->sourcePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'backups.connection' => 'backup_source',
    ]);
    DB::purge('backup_source');

    $source = DB::connection('backup_source');
    $source->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
    $source->table('migrations')->insert(['migration' => '0001_01_01_000000_create_users_table', 'batch' => 1]);
    $source->statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $source->table('widgets')->insert([['name' => 'سرير'], ['name' => 'Sofa']]);

    $company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner', null);
    $this->admin = $makeUser('admin', null);
    $this->unitOwner = $makeUser('owner', $this->unit->id);
    $this->cashier = $makeUser('cashier', $this->unit->id);

    /** A completed backup row with a real gzip file on the fake disk. */
    $this->storedBackup = function (string $contents = 'payload', string $driver = 'sqlite', array $attributes = []): Backup {
        $file = 'fine-backup-'.bin2hex(random_bytes(6)).($driver === 'sqlite' ? '.sqlite.gz' : '.dump.gz');
        $gzip = gzencode($contents);
        Storage::disk('backups')->put($file, $gzip);

        return Backup::create([
            'file' => $file,
            'size_bytes' => strlen($gzip),
            'checksum' => hash('sha256', $gzip),
            'driver' => $driver,
            'status' => BackupStatus::Completed,
            'trigger' => BackupTrigger::Scheduled,
            'started_at' => now(),
            'completed_at' => now(),
            ...$attributes,
        ]);
    };
});

afterEach(function () {
    DB::purge('backup_source');
    DB::purge('backup_pg');

    if (is_file($this->sourcePath)) {
        unlink($this->sourcePath);
    }
});

test('the owner takes a SQLite backup: gzip + sha256 on the private disk, recorded and audited', function () {
    AuditLog::query()->delete();

    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/backups')
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.driver', 'sqlite')
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.user.id', $this->owner->id)
        ->assertJsonPath('data.error', null);

    $backup = Backup::query()->sole();
    expect($backup->file)->toMatch('/^fine-backup-\d{8}-\d{6}-[a-z0-9]{8}\.sqlite\.gz$/');
    Storage::disk('backups')->assertExists($backup->file);

    $stored = Storage::disk('backups')->get($backup->file);
    expect(hash('sha256', $stored))->toBe($backup->checksum)
        ->and(strlen($stored))->toBe($backup->size_bytes)
        ->and($response->json('data.checksum'))->toBe($backup->checksum);

    // The dump is a real SQLite database holding the source rows.
    $restored = tempnam(sys_get_temp_dir(), 'fine-backup-restored-');
    file_put_contents($restored, gzdecode($stored));
    $pdo = new PDO('sqlite:'.$restored);
    expect($pdo->query('SELECT name FROM widgets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe(['سرير', 'Sofa']);
    $pdo = null;
    unlink($restored);

    $audit = AuditLog::query()->where('table_name', 'backups')->where('action', 'created')->sole();
    expect($audit->operating_unit_id)->toBeNull()
        ->and($audit->user_id)->toBe($this->owner->id);
});

test('only the company-wide owner reaches the backup endpoints', function () {
    $backup = ($this->storedBackup)();

    foreach ([$this->admin, $this->unitOwner, $this->cashier] as $user) {
        $headers = $user->is($this->admin) ? [] : ['X-Operating-Unit-ID' => $this->unit->id];

        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/backups')->assertForbidden();
        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/backups')->assertForbidden();
        $this->actingAs($user)->withHeaders($headers)->get("/api/v1/backups/{$backup->id}/download")->assertForbidden();
        $this->actingAs($user)->withHeaders($headers)->deleteJson("/api/v1/backups/{$backup->id}")->assertForbidden();
    }

    $this->actingAs($this->admin)->getJson('/api/v1/backups')->assertJsonPath('code', 'BACKUP_FORBIDDEN');

    expect(Backup::query()->count())->toBe(1);
    Storage::disk('backups')->assertExists($backup->file);
});

test('guests are refused', function () {
    $this->getJson('/api/v1/backups')->assertUnauthorized();
    $this->postJson('/api/v1/backups')->assertUnauthorized();
});

test('the owner lists backups newest first', function () {
    $older = ($this->storedBackup)();
    Backup::query()->whereKey($older->id)->update(['created_at' => now()->subDay()]);
    $newer = ($this->storedBackup)();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/backups')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.status_label', 'مكتملة')
        ->assertJsonPath('data.0.trigger', 'scheduled');
});

test('the owner downloads a backup as a stream, found by id', function () {
    $backup = ($this->storedBackup)('the dump bytes');

    $response = $this->actingAs($this->owner)->get("/api/v1/backups/{$backup->id}/download");

    $response->assertOk()
        ->assertHeader('X-Backup-Checksum-Sha256', $backup->checksum)
        ->assertDownload($backup->file);
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and(gzdecode($response->streamedContent()))->toBe('the dump bytes');
});

test('a cross-origin desktop can read the download file name and checksum headers', function () {
    $backup = ($this->storedBackup)();

    $response = $this->actingAs($this->owner)
        ->withHeaders(['Origin' => 'null'])
        ->get("/api/v1/backups/{$backup->id}/download")
        ->assertOk();

    $exposed = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers')));
    expect(array_map('strtolower', $exposed))->toContain('content-disposition', 'x-backup-checksum-sha256');
});

test('download refuses paths, unfinished, deleted and missing backups', function () {
    $this->actingAs($this->owner)->get('/api/v1/backups/..%2F..%2F.env/download')->assertNotFound();
    $this->actingAs($this->owner)->get('/api/v1/backups/not-a-uuid/download')->assertNotFound();

    $failed = Backup::create([
        'file' => 'fine-backup-failed.sqlite.gz', 'driver' => 'sqlite', 'status' => BackupStatus::Failed,
        'trigger' => BackupTrigger::Manual, 'error' => 'boom',
    ]);
    $this->actingAs($this->owner)->getJson("/api/v1/backups/{$failed->id}/download")
        ->assertStatus(422)->assertJsonPath('code', 'BACKUP_NOT_COMPLETED');

    $missing = ($this->storedBackup)();
    Storage::disk('backups')->delete($missing->file);
    $this->actingAs($this->owner)->getJson("/api/v1/backups/{$missing->id}/download")
        ->assertStatus(422)->assertJsonPath('code', 'BACKUP_FILE_MISSING');

    $deleted = ($this->storedBackup)();
    $deleted->delete();
    $this->actingAs($this->owner)->getJson("/api/v1/backups/{$deleted->id}/download")->assertNotFound();
});

test('the owner deletes a backup: the row is soft-deleted and the file removed', function () {
    $backup = ($this->storedBackup)();

    $this->actingAs($this->owner)->deleteJson("/api/v1/backups/{$backup->id}")->assertNoContent();

    expect(Backup::query()->find($backup->id))->toBeNull()
        ->and(Backup::withTrashed()->find($backup->id))->not->toBeNull();
    Storage::disk('backups')->assertMissing($backup->file);

    $this->actingAs($this->owner)->getJson('/api/v1/backups')->assertJsonCount(0, 'data');
});

test('a second backup is refused while one is running', function () {
    $lock = Cache::lock('backups:run', 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->owner)->postJson('/api/v1/backups')
        ->assertStatus(422)->assertJsonPath('code', 'BACKUP_IN_PROGRESS');

    expect(Backup::query()->count())->toBe(0);
    $lock->release();
});

test('a failed dump is recorded as failed, leaves no file and answers 422 BACKUP_FAILED', function () {
    config(['database.connections.backup_source.database' => sys_get_temp_dir().'/fine-backup-no-such-dir/'.uniqid().'.sqlite']);
    DB::purge('backup_source');

    $this->actingAs($this->owner)->postJson('/api/v1/backups')
        ->assertStatus(422)
        ->assertJsonPath('code', 'BACKUP_FAILED')
        ->assertJsonPath('backup.status', 'failed');

    $backup = Backup::query()->sole();
    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->not->toBeEmpty()
        ->and($backup->checksum)->toBeNull();
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

test('the backup row is written after the dump, so no dump holds its own backup as running', function () {
    config([
        'database.connections.backup_pg' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5432', 'database' => 'fine_prod',
            'username' => 'fine', 'password' => 'S3cret!pw', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ],
        'backups.connection' => 'backup_pg',
    ]);

    $rowsDuringDump = null;
    Process::fake(function (PendingProcess $process) use (&$rowsDuringDump) {
        $rowsDuringDump = Backup::query()->count();
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        file_put_contents(substr($file, strlen('--file=')), 'PGDMP');

        return Process::result();
    });

    $this->artisan('backup:run')->assertSuccessful();

    expect($rowsDuringDump)->toBe(0)
        ->and(Backup::query()->sole()->status)->toBe(BackupStatus::Completed);
});

test('a truncated or corrupt archive is refused before it is stored', function () {
    $service = app(BackupService::class);
    $inspect = fn (string $path, int $size): array => (fn () => $this->inspectArchive($path, $size))->call($service);

    $dump = str_repeat('fine backup row data ', 20000);
    $path = tempnam(sys_get_temp_dir(), 'fine-backup-archive-');

    try {
        $gzip = gzencode($dump);
        file_put_contents($path, $gzip);
        expect($inspect($path, strlen($dump)))->toBe(['checksum' => hash('sha256', $gzip), 'size' => strlen($gzip)]);

        // The trailer (last deflate block + CRC/size) never reached the disk.
        file_put_contents($path, substr($gzip, 0, -12));
        expect(fn () => $inspect($path, strlen($dump)))->toThrow(RuntimeException::class, 'incomplete');

        // A flipped CRC byte.
        $corrupt = $gzip;
        $corrupt[strlen($corrupt) - 6] = chr(ord($corrupt[strlen($corrupt) - 6]) ^ 0xFF);
        file_put_contents($path, $corrupt);
        expect(fn () => $inspect($path, strlen($dump)))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

test('backup:run --scheduled records a scheduled backup with no user', function () {
    $this->artisan('backup:run --scheduled')->assertSuccessful();

    $backup = Backup::query()->sole();
    expect($backup->trigger)->toBe(BackupTrigger::Scheduled)
        ->and($backup->user_id)->toBeNull()
        ->and($backup->status)->toBe(BackupStatus::Completed);
});

test('the backup is scheduled daily at 02:00 without overlapping, with prune and a monthly verify', function () {
    $events = collect(app(Schedule::class)->events());

    $run = $events->first(fn ($event) => str_contains($event->command, 'backup:run'));
    expect($run)->not->toBeNull()
        ->and($run->expression)->toBe('0 2 * * *')
        ->and($run->withoutOverlapping)->toBeTrue()
        ->and($run->command)->toContain('--scheduled');

    expect($events->first(fn ($event) => str_contains($event->command, 'backup:prune')))->not->toBeNull()
        ->and($events->first(fn ($event) => str_contains($event->command, 'backup:verify')))->not->toBeNull();
});

test('PostgreSQL backups run pg_dump -Fc without a shell and keep the password out of the arguments', function () {
    config([
        'database.connections.backup_pg' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5433', 'database' => 'fine_prod',
            'username' => 'fine', 'password' => 'S3cret!pw', 'charset' => 'utf8', 'prefix' => '',
            'search_path' => 'public', 'sslmode' => 'require',
        ],
        'backups.connection' => 'backup_pg',
    ]);

    Process::fake(function (PendingProcess $process) {
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        file_put_contents(substr($file, strlen('--file=')), "PGDMP\x01custom-format-dump");

        return Process::result();
    });

    $this->actingAs($this->owner)->postJson('/api/v1/backups')
        ->assertCreated()
        ->assertJsonPath('data.driver', 'pgsql')
        ->assertJsonPath('data.status', 'completed');

    Process::assertRanTimes(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command)
            && $command[0] === 'pg_dump'
            && in_array('--format=custom', $command, true)
            && in_array('--host=db.internal', $command, true)
            && in_array('--port=5433', $command, true)
            && in_array('--username=fine', $command, true)
            && in_array('--dbname=fine_prod', $command, true)
            && collect($command)->every(fn (string $arg) => ! str_contains($arg, 'S3cret'))
            && ($process->environment['PGPASSWORD'] ?? null) === 'S3cret!pw'
            && ($process->environment['PGSSLMODE'] ?? null) === 'require';
    }, 1);

    $backup = Backup::query()->sole();
    expect($backup->file)->toEndWith('.dump.gz')
        ->and(gzdecode(Storage::disk('backups')->get($backup->file)))->toBe("PGDMP\x01custom-format-dump");
});

test('a failing pg_dump is recorded with its error output and no file', function () {
    config([
        'database.connections.backup_pg' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5432', 'database' => 'fine_prod',
            'username' => 'fine', 'password' => 'S3cret!pw', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ],
        'backups.connection' => 'backup_pg',
    ]);

    Process::fake(['*' => Process::result(errorOutput: 'pg_dump: error: connection refused', exitCode: 1)]);

    $this->artisan('backup:run')->assertFailed();

    $backup = Backup::query()->sole();
    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toContain('connection refused')
        ->and($backup->error)->not->toContain('S3cret');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

test('backup:verify restores the newest SQLite backup into a scratch file and passes the integrity check', function () {
    $this->artisan('backup:run')->assertSuccessful();
    $backup = Backup::query()->sole();

    $this->artisan('backup:verify')->assertSuccessful();

    expect($backup->fresh()->verified_at)->not->toBeNull()
        ->and($backup->fresh()->verify_error)->toBeNull();
});

test('backup:verify fails a file that no longer matches its checksum', function () {
    $this->artisan('backup:run')->assertSuccessful();
    $backup = Backup::query()->sole();
    Storage::disk('backups')->put($backup->file, gzencode('tampered'));

    $this->artisan('backup:verify', ['backup' => $backup->id])->assertFailed();

    expect($backup->fresh()->verified_at)->toBeNull()
        ->and($backup->fresh()->verify_error)->toContain('checksum');
});

test('backup:verify fails a backup that is not a valid SQLite database', function () {
    $backup = ($this->storedBackup)('this is not a database at all, just text that is long enough to read');

    $this->artisan('backup:verify', ['backup' => $backup->id])->assertFailed();

    expect($backup->fresh()->verify_error)->not->toBeEmpty();
});

test('backup:verify refuses a PostgreSQL backup without a scratch database, or with the live one', function () {
    config([
        'database.connections.backup_pg' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5432', 'database' => 'fine_prod',
            'username' => 'fine', 'password' => 'S3cret!pw', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ],
        'backups.connection' => 'backup_pg',
        'backups.pgsql.verify_database' => null,
    ]);
    Process::fake();
    $backup = ($this->storedBackup)('PGDMP', 'pgsql');

    $this->artisan('backup:verify', ['backup' => $backup->id])
        ->expectsOutputToContain('BACKUP_VERIFY_NOT_CONFIGURED')
        ->assertFailed();

    // The skipped restore test shows on the row, not only in the exit code.
    expect($backup->fresh()->verify_error)->toStartWith('BACKUP_VERIFY_NOT_CONFIGURED')
        ->and($backup->fresh()->verified_at)->toBeNull();
    $this->actingAs($this->owner)->getJson('/api/v1/backups')
        ->assertJsonPath('data.0.verify_error', $backup->fresh()->verify_error);

    config(['backups.pgsql.verify_database' => 'fine_prod']);
    $this->artisan('backup:verify', ['backup' => $backup->id])
        ->expectsOutputToContain('BACKUP_VERIFY_SCRATCH_IS_SOURCE')
        ->assertFailed();

    Process::assertNothingRan();
});

/**
 * The PostgreSQL restore test against a faked pg_restore. The scratch
 * connection BackupService opens after the restore is resolved through a
 * per-name DB::extend, so no PostgreSQL server is needed: it records the
 * config it was given and answers with an in-memory SQLite database.
 */
function fakePgsqlVerify(?array &$scratchConfig, int $migrationRows = 1): void
{
    config([
        'database.connections.backup_pg' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5432', 'database' => 'fine_prod',
            'username' => 'fine', 'password' => 'S3cret!pw', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ],
        'backups.connection' => 'backup_pg',
        'backups.pgsql.verify_database' => 'fine_verify',
    ]);

    DB::extend('backup_verify_scratch', function (array $config, string $name) use (&$scratchConfig, $migrationRows) {
        $scratchConfig = $config;
        $connection = app('db.factory')->make(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], $name);
        $connection->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');

        for ($i = 0; $i < $migrationRows; $i++) {
            $connection->table('migrations')->insert(['migration' => "m{$i}", 'batch' => 1]);
        }

        return $connection;
    });
}

test('backup:verify restores a PostgreSQL backup into the scratch database only, password in PGPASSWORD', function () {
    $scratchConfig = null;
    fakePgsqlVerify($scratchConfig);

    $restoredBytes = null;
    Process::fake(function (PendingProcess $process) use (&$restoredBytes) {
        $restoredBytes = file_get_contents(end($process->command));

        return Process::result();
    });

    $backup = ($this->storedBackup)("PGDMP\x01custom-format-dump", 'pgsql');

    $this->artisan('backup:verify', ['backup' => $backup->id])->assertSuccessful();

    Process::assertRanTimes(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command)
            && $command[0] === 'pg_restore'
            && in_array('--dbname=fine_verify', $command, true)
            && ! in_array('--dbname=fine_prod', $command, true)
            && collect($command)->every(fn (string $arg) => ! str_contains($arg, 'fine_prod'))
            && in_array('--clean', $command, true)
            && in_array('--if-exists', $command, true)
            && in_array('--no-owner', $command, true)
            && in_array('--host=db.internal', $command, true)
            && collect($command)->every(fn (string $arg) => ! str_contains($arg, 'S3cret'))
            && ($process->environment['PGPASSWORD'] ?? null) === 'S3cret!pw';
    }, 1);
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'pg_dump');

    // pg_restore got the decompressed dump; the integrity check ran on the scratch database.
    expect($restoredBytes)->toBe("PGDMP\x01custom-format-dump")
        ->and($scratchConfig['database'])->toBe('fine_verify')
        ->and($scratchConfig['host'])->toBe('db.internal')
        ->and($backup->fresh()->verified_at)->not->toBeNull()
        ->and($backup->fresh()->verify_error)->toBeNull();
});

test('a failing pg_restore, or a restored database with no migration history, fails the restore test', function () {
    $scratchConfig = null;
    fakePgsqlVerify($scratchConfig, migrationRows: 0);
    Process::fake(['*' => Process::result(errorOutput: 'pg_restore: error: could not connect to database "fine_verify"', exitCode: 1)]);
    $backup = ($this->storedBackup)('PGDMP', 'pgsql');

    $this->artisan('backup:verify', ['backup' => $backup->id])
        ->expectsOutputToContain('BACKUP_VERIFY_FAILED')
        ->assertFailed();

    expect($backup->fresh()->verified_at)->toBeNull()
        ->and($backup->fresh()->verify_error)->toContain('pg_restore exited with code 1')
        ->and($backup->fresh()->verify_error)->toContain('could not connect to database "fine_verify"')
        ->and($backup->fresh()->verify_error)->not->toContain('S3cret')
        ->and($scratchConfig)->toBeNull();

    // pg_restore succeeds, but the scratch database holds no migration history.
    Process::fake();

    $this->artisan('backup:verify', ['backup' => $backup->id])->assertFailed();

    expect($backup->fresh()->verified_at)->toBeNull()
        ->and($backup->fresh()->verify_error)->toContain('no migration history')
        ->and($scratchConfig['database'])->toBe('fine_verify');
});

test('backup:prune keeps 14 daily and 8 weekly backups and drops old failures', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', 'UTC'));

    // One backup per night (02:00 Tripoli = 00:00 UTC) for the last 70 days.
    $byDate = [];
    for ($i = 0; $i < 70; $i++) {
        $date = Carbon::parse('2026-11-01 00:00:00', 'UTC')->subDays($i);
        $backup = ($this->storedBackup)();
        Backup::query()->whereKey($backup->id)->update(['created_at' => $date]);
        $byDate[$date->toDateString()] = $backup->fresh();
    }

    $oldFailure = Backup::create(['file' => 'fine-backup-old-failure.sqlite.gz', 'driver' => 'sqlite', 'status' => BackupStatus::Failed, 'trigger' => BackupTrigger::Scheduled]);
    Backup::query()->whereKey($oldFailure->id)->update(['created_at' => now()->subDays(20)]);
    $recentFailure = Backup::create(['file' => 'fine-backup-new-failure.sqlite.gz', 'driver' => 'sqlite', 'status' => BackupStatus::Failed, 'trigger' => BackupTrigger::Scheduled]);
    Backup::query()->whereKey($recentFailure->id)->update(['created_at' => now()->subDays(2)]);

    $this->artisan('backup:prune')->expectsOutputToContain('Pruned 51 backup(s).')->assertSuccessful();

    // 14 newest days (Nov 1 .. Oct 19) + the newest of each of the 8 newest ISO
    // weeks: the Sundays Oct 18, 11, 4, Sep 27, 20, 13 (Nov 1 and Oct 25 are in the daily set).
    $expected = collect(range(0, 13))->map(fn (int $i) => Carbon::parse('2026-11-01')->subDays($i)->toDateString())
        ->merge(['2026-10-18', '2026-10-11', '2026-10-04', '2026-09-27', '2026-09-20', '2026-09-13'])
        ->sort()->values()->all();

    $kept = Backup::query()->where('status', BackupStatus::Completed)->get()
        ->map(fn (Backup $backup) => $backup->created_at->toDateString())->sort()->values()->all();

    expect($kept)->toBe($expected);

    foreach ($byDate as $date => $backup) {
        if (in_array($date, $expected, true)) {
            Storage::disk('backups')->assertExists($backup->file);
        } else {
            Storage::disk('backups')->assertMissing($backup->file);
            expect(Backup::withTrashed()->find($backup->id)->trashed())->toBeTrue();
        }
    }

    expect(Backup::query()->find($oldFailure->id))->toBeNull()
        ->and(Backup::query()->find($recentFailure->id))->not->toBeNull();

    Carbon::setTestNow();
});

test('backup:prune sweeps unknown backup files once older than the retention window, and a deleted row\'s file at once', function () {
    $disk = Storage::disk('backups');
    $known = ($this->storedBackup)();
    // delete() soft-deleted these rows but could not remove their files.
    $trashed = ($this->storedBackup)();
    Backup::query()->whereKey($trashed->id)->delete();
    $disk->put($trashed->file, gzencode('left behind'));
    $recentTrashed = ($this->storedBackup)();
    Backup::query()->whereKey($recentTrashed->id)->delete();

    // Files taken after the backup the database was restored from: no rows.
    $disk->put('fine-backup-20260801-020000-oldorphn.sqlite.gz', gzencode('old'));
    $disk->put('fine-backup-20261031-020000-neworphn.sqlite.gz', gzencode('recent'));
    $disk->put('unrelated.txt', 'not a backup');

    $old = now()->subDays(57)->getTimestamp();
    foreach (['fine-backup-20260801-020000-oldorphn.sqlite.gz', 'unrelated.txt', $known->file, $trashed->file] as $file) {
        touch($disk->path($file), $old);
    }

    $this->artisan('backup:prune')->expectsOutputToContain('Pruned 3 backup(s).')->assertSuccessful();

    $disk->assertMissing('fine-backup-20260801-020000-oldorphn.sqlite.gz');
    $disk->assertExists('fine-backup-20261031-020000-neworphn.sqlite.gz');
    $disk->assertExists('unrelated.txt');
    $disk->assertExists($known->file);
    $disk->assertMissing($trashed->file);
    $disk->assertMissing($recentTrashed->file);
});

test('a restore test and a prune wait for a restore test already running', function () {
    $backup = ($this->storedBackup)();
    $lock = Cache::lock('backups:verify', 60);
    expect($lock->get())->toBeTrue();

    $this->artisan('backup:verify', ['backup' => $backup->id])
        ->expectsOutputToContain('BACKUP_VERIFY_IN_PROGRESS')
        ->assertFailed();
    $this->artisan('backup:prune')
        ->expectsOutputToContain('BACKUP_VERIFY_IN_PROGRESS')
        ->assertFailed();

    // The refused run is not a failed restore test.
    expect($backup->fresh()->verify_error)->toBeNull();
    $lock->release();

    $this->artisan('backup:prune')->assertSuccessful();
});

test('a working directory left by a killed run is swept by the next run and by prune', function () {
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $makeDirectory = function (int $age) use ($base): string {
        $path = $base.DIRECTORY_SEPARATOR.'fine-backup-'.Str::random(16);
        mkdir($path, 0700);
        file_put_contents($path.'/dump', 'a plain copy of the database');
        touch($path.'/dump', time() - $age);
        touch($path, time() - $age);

        return $path;
    };

    $stale = $makeDirectory(2 * 900 + 60);
    $fresh = $makeDirectory(60);

    try {
        $this->artisan('backup:run')->assertSuccessful();

        expect(is_dir($stale))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue();

        $staleAgain = $makeDirectory(2 * 900 + 60);
        $this->artisan('backup:prune')->assertSuccessful();

        expect(is_dir($staleAgain))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue();
    } finally {
        foreach ([$stale, $fresh, $staleAgain ?? null] as $path) {
            if ($path !== null && is_dir($path)) {
                @unlink($path.'/dump');
                @rmdir($path);
            }
        }
    }
});
