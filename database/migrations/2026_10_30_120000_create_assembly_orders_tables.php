<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-07 (decision #12): light furniture assembly orders (ASM-). An order
 * consumes component lots and produces one finished-goods lot (FG-) at the
 * summed actual cost of what was drawn, posting DR 1134 / CR each component
 * lot's account. Nothing is reserved while the order is a draft. Item, lot
 * and order references are restrictOnDelete: a completed order carries posted
 * value (its movements and journal entry), so a force delete must not make
 * its history vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('number')->unique();
            $table->foreignUuid('finished_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->foreignUuid('output_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft | completed | cancelled
            $table->foreignUuid('bundle_id')->nullable()->constrained('bundles')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('total_cost', 18, 4)->nullable();
            $table->foreignUuid('finished_stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
        });

        Schema::create('assembly_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assembly_order_id')->constrained('assembly_orders')->restrictOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            // The piece size from the bundle template (or entered by hand): a
            // sized line only draws lots of that size (LotSize).
            $table->decimal('length_m', 8, 3)->nullable();
            $table->decimal('width_m', 8, 3)->nullable();
            $table->decimal('height_m', 8, 3)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable(); // the drawn lot's actual cost, set on completion
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['assembly_order_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_order_lines');
        Schema::dropIfExists('assembly_orders');
    }
};
