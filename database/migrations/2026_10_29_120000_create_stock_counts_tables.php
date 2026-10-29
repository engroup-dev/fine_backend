<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4-01 / INV-06 (decision #13): physical stock counts. A count snapshots a
 * warehouse's lots; on approval every difference moves its lot through an
 * `adjustment` movement and one journal entry posts the shortage and surplus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->string('number')->unique();
            $table->date('count_date');
            $table->string('status', 20)->default('draft'); // draft | counting | submitted | approved | cancelled
            $table->boolean('blind')->default(false);
            $table->foreignUuid('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('system_qty', 18, 4)->default(0);
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('variance_qty', 18, 4)->nullable();
            $table->decimal('variance_value', 18, 4)->nullable();
            $table->foreignUuid('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('new_stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['stock_count_id', 'inventory_item_id']);
        });

        // Append-only: every change of a counted quantity and who made it. A
        // line's counted_by_user_id only holds the latest counter, so the
        // self-approval rule reads this trail instead.
        Schema::create('stock_count_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('stock_count_line_id')->constrained('stock_count_lines')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_count_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_entries');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
