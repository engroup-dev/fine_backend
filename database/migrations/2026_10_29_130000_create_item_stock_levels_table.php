<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-02: reorder levels and shelf locations.
 *
 * FR-STK-007 / BR-STK-004: one min/max/reorder row per item per warehouse
 * (max ≥ min, enforced in StockLevelService). FR-STK-004: the row also holds
 * the item's bin in that warehouse, and every lot carries its own
 * `bin_location`, defaulted from the row at intake and transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stock_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('min_qty', 18, 4)->default(0);
            $table->decimal('max_qty', 18, 4)->default(0);
            $table->decimal('reorder_qty', 18, 4)->nullable();
            $table->string('bin_location', 50)->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->unique(['inventory_item_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->string('bin_location', 50)->nullable()->after('warehouse_id');
            $table->index('bin_location');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropIndex(['bin_location']);
            $table->dropColumn('bin_location');
        });

        Schema::dropIfExists('item_stock_levels');
    }
};
