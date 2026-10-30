<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-06: the warehouse a cutter order's outputs (cut pieces, restocked
 * remnants, weighed-in fill) land in. Nullable: existing orders keep landing
 * in their first block's warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutter_work_orders', function (Blueprint $table) {
            $table->foreignUuid('output_warehouse_id')
                ->nullable()
                ->after('notes')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cutter_work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_warehouse_id');
        });
    }
};
