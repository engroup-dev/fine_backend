<?php

declare(strict_types=1);

use App\Console\Commands\SnapshotDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * db:snapshot copies a throwaway SQLite file on its own connection, so it runs
 * outside any test transaction and never touches database/database.sqlite.
 */
beforeEach(function () {
    $this->sourcePath = tempnam(sys_get_temp_dir(), 'fine-snapshot-test-');
    config([
        'database.connections.snapshot_source' => [
            'driver' => 'sqlite',
            'database' => $this->sourcePath,
            'prefix' => '',
        ],
        'backups.connection' => 'snapshot_source',
    ]);
    DB::purge('snapshot_source');

    $source = DB::connection('snapshot_source');
    $source->statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $source->table('widgets')->insert([['name' => 'سرير'], ['name' => 'Sofa']]);

    $this->storageDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fine-snapshot-storage-'.uniqid();
    $this->app->useStoragePath($this->storageDir);
});

afterEach(function () {
    DB::purge('snapshot_source');
    File::delete($this->sourcePath);
    File::deleteDirectory($this->storageDir);
    Carbon::setTestNow();
});

test('it writes a readable copy of the sqlite database', function () {
    Carbon::setTestNow('2026-11-21 10:15:00');

    $this->artisan('db:snapshot')->assertSuccessful();

    $path = SnapshotDatabase::directory().DIRECTORY_SEPARATOR.'database-20261121-101500.sqlite';
    expect(is_file($path))->toBeTrue();

    $copy = new PDO('sqlite:'.$path);
    expect((int) $copy->query('SELECT COUNT(*) FROM widgets')->fetchColumn())->toBe(2);
});

test('it keeps only the newest ten snapshots', function () {
    File::ensureDirectoryExists(SnapshotDatabase::directory());
    foreach (range(1, 12) as $day) {
        File::put(SnapshotDatabase::directory().DIRECTORY_SEPARATOR.sprintf('database-202611%02d-000000.sqlite', $day), 'old');
    }

    Carbon::setTestNow('2026-11-21 10:15:00');
    $this->artisan('db:snapshot')->assertSuccessful();

    $left = collect(glob(SnapshotDatabase::directory().DIRECTORY_SEPARATOR.'database-*.sqlite'))->map(fn ($f) => basename($f))->sort()->values();
    expect($left)->toHaveCount(10)
        ->and($left->first())->toBe('database-20261104-000000.sqlite')
        ->and($left->last())->toBe('database-20261121-101500.sqlite');
});

test('it refuses non-sqlite connections', function () {
    config(['database.connections.snapshot_source.driver' => 'pgsql']);
    DB::purge('snapshot_source');

    $this->artisan('db:snapshot')->assertFailed();
});
