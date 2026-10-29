<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4-04: stagnant stock report and transfer lineage.
 *
 * - `stock_lots.received_at` is the lot's age anchor. For a lot that entered
 *   stock it is when it did. A lot split off by a warehouse transfer (`-XFER`)
 *   or a reservation (`-R`) takes its parent's idle-since at split time (the
 *   parent's last real movement, else the parent's received_at), so moving or
 *   setting goods aside neither refreshes nor ages them. Existing lots are
 *   backfilled from `created_at`.
 * - `inventory_movements(stock_lot_id, created_at)` serves the per-lot "last
 *   movement" lookup of GET /inventory/stagnant-stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->after('status')
                ->comment('Age anchor: when the lot entered stock; for a transfer/reservation split, the parent idle-since at split time');
        });

        DB::table('stock_lots')->whereNull('received_at')->update(['received_at' => DB::raw('created_at')]);

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index(['stock_lot_id', 'created_at'], 'inv_mov_lot_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inv_mov_lot_created_index');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });
    }
};
