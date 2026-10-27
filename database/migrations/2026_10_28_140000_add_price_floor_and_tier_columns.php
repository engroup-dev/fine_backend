<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3-06 / FR-PRC-001, FR-IVT-025, BR-PRC-003, BR-PRC-004 (decisions #9, #10):
     * Item minimum selling price, retail/wholesale price tier per sale and
     * quotation, per-line suggested list price, and manager price override
     * tracking on sales orders.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->decimal('min_price', 18, 4)->nullable()->after('wholesale_price');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('price_tier', 10)->default('retail')->after('channel');
            $table->foreignUuid('price_override_by_user_id')->nullable()->after('sold_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->string('price_override_reason', 255)->nullable()->after('price_override_by_user_id');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->decimal('list_price', 18, 4)->nullable()->after('height_m');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('price_tier', 10)->default('retail')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('price_tier');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn('list_price');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_override_by_user_id');
            $table->dropColumn(['price_tier', 'price_override_reason']);
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn('min_price');
        });
    }
};
