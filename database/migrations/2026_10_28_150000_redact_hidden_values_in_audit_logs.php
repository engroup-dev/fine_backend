<?php

declare(strict_types=1);

use App\Observers\AuditObserver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FX-01: the audit observer used to log every attribute, so user password hashes
 * and remember tokens were written to audit_logs and returned by the audit API.
 * Replace them in existing rows; the observer redacts new ones.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => hidden attributes */
    private const HIDDEN = [
        'users' => ['password', 'remember_token'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        foreach (self::HIDDEN as $table => $keys) {
            DB::table('audit_logs')
                ->where('table_name', $table)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($keys): void {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach (['old_values', 'new_values'] as $column) {
                            $values = $row->{$column} !== null ? json_decode((string) $row->{$column}, true) : null;

                            if (! is_array($values)) {
                                continue;
                            }

                            $touched = false;
                            foreach ($keys as $key) {
                                if (array_key_exists($key, $values) && $values[$key] !== AuditObserver::REDACTED) {
                                    $values[$key] = AuditObserver::REDACTED;
                                    $touched = true;
                                }
                            }

                            if ($touched) {
                                $changes[$column] = json_encode($values, JSON_UNESCAPED_UNICODE);
                            }
                        }

                        if ($changes !== []) {
                            DB::table('audit_logs')->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Redacted hashes can't be restored, and must not be.
    }
};
