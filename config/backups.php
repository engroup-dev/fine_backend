<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database backups (W6-03)
|--------------------------------------------------------------------------
|
| backup:run dumps the database (SQLite `VACUUM INTO`, PostgreSQL
| `pg_dump -Fc`), gzips the dump and writes it with its sha256 to the private
| `backups` disk (config/filesystems.php, storage/app/backups — outside the
| public web root). backup:prune applies the retention below and
| backup:verify restores a backup into a scratch database and checks it.
|
*/

return [

    /*
     * The connection to back up. Null backs up the default connection.
     */
    'connection' => env('BACKUP_DB_CONNECTION'),

    /*
     * The filesystem disk the backup files are written to.
     */
    'disk' => env('BACKUP_DISK', 'backups'),

    /*
     * Seconds a dump or a verification restore may take before it is stopped.
     */
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

    /*
     * Retention: the newest backup of each of the last N days that have one,
     * plus the newest backup of each of the last N ISO weeks that have one.
     */
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 14),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 8),

    'pgsql' => [
        'pg_dump' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),

        /*
         * backup:verify restores a PostgreSQL backup into this database, which
         * it wipes first. It must already exist, the same server credentials
         * must be able to write it, and it can never be the database being
         * backed up (verification refuses that).
         */
        'verify_database' => env('BACKUP_VERIFY_DATABASE'),
    ],

];
