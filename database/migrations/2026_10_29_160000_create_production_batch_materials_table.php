<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-05 (decision #11): chemicals issued to a foam batch. One row per lot
 * drawn: an issued line that spans two lots FIFO lands as two rows, each with
 * the lot's own actual cost and its `consumption` movement. The batch's
 * material_cost is the sum of its rows' total_cost. Batch, item and lot are
 * restrictOnDelete: a row carries posted value (its journal entry and
 * consumption movement), so a force delete must not make it vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batch_materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_batch_id')->constrained('production_batches')->restrictOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUuid('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['production_batch_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_materials');
    }
};
