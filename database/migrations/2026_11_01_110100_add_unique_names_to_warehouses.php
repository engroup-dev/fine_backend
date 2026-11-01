<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * W6-06: a warehouse name is unique within its operating unit and parent
     * (top-level warehouses per unit, sub-warehouses per parent).
     *
     * Existing duplicates are renamed, not refused: a warehouse name is only
     * a label (stock, movements and journals reference the id), so renaming
     * cannot corrupt anything, while refusing would block the deploy until
     * someone edits data by hand. The rename is deterministic: within each
     * duplicate group the oldest warehouse (created_at, then id) keeps the
     * name and the others become "<name> (2)", "<name> (3)"… skipping any
     * suffix already in use in the same unit and parent. Every rename is
     * written to the log with the warehouse id, old name and new name.
     *
     * Two partial unique indexes enforce the rule because NULL parent_id
     * values never collide in a plain composite index (SQLite and
     * PostgreSQL alike): one for top-level warehouses, one for
     * sub-warehouses.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // Undated rows sort first on SQLite and PostgreSQL alike.
            $groups = DB::table('warehouses')
                ->orderByRaw('CASE WHEN created_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'operating_unit_id', 'parent_id', 'name'])
                ->groupBy(fn (object $warehouse): string => $warehouse->operating_unit_id.'|'.($warehouse->parent_id ?? ''));

            foreach ($groups as $siblings) {
                $taken = $siblings->pluck('name')->flip()->map(fn (): bool => true)->all();

                foreach ($siblings->groupBy('name') as $name => $sameName) {
                    foreach ($sameName->slice(1) as $duplicate) {
                        $newName = $this->freeName((string) $name, $taken);
                        $taken[$newName] = true;

                        DB::table('warehouses')->where('id', $duplicate->id)->update([
                            'name' => $newName,
                            'updated_at' => now(),
                        ]);

                        Log::warning('W6-06: renamed a duplicate warehouse so names are unique per unit and parent.', [
                            'warehouse_id' => $duplicate->id,
                            'operating_unit_id' => $duplicate->operating_unit_id,
                            'parent_id' => $duplicate->parent_id,
                            'old_name' => $name,
                            'new_name' => $newName,
                        ]);
                    }
                }
            }
        });

        DB::statement('CREATE UNIQUE INDEX warehouses_unit_top_level_name_unique ON warehouses (operating_unit_id, name) WHERE parent_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX warehouses_unit_parent_name_unique ON warehouses (operating_unit_id, parent_id, name) WHERE parent_id IS NOT NULL');
    }

    /** Renamed warehouses keep their new names. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouses_unit_top_level_name_unique');
        DB::statement('DROP INDEX IF EXISTS warehouses_unit_parent_name_unique');
    }

    /**
     * The first "<name> (n)" (n ≥ 2) not used by a sibling, cut to fit the
     * 255-character column.
     *
     * @param  array<string, bool>  $taken
     */
    private function freeName(string $name, array $taken): string
    {
        $n = 2;

        do {
            $suffix = " ({$n})";
            $candidate = mb_substr($name, 0, 255 - mb_strlen($suffix)).$suffix;
            $n++;
        } while (isset($taken[$candidate]));

        return $candidate;
    }
};
