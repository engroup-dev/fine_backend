<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-03: barcodes, scanning and labels.
 *
 * An item may carry one barcode (a manufacturer EAN or an internal code the
 * desktop generates from the item code). It is optional and unique, so a scan
 * resolves to at most one item (GET /inventory/lookup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
