<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3-05 / FR-INV-014, FR-INV-015, BR-INV-017 (decision #9):
     * Line and invoice discounts on sales orders and quotations.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->decimal('gross_amount', 18, 4)->default(0)->after('cash_account_id');
            $table->decimal('invoice_discount', 18, 4)->default(0)->after('gross_amount');
        });

        DB::table('sales_orders')->update(['gross_amount' => DB::raw('total_amount')]);

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 4)->default(0)->after('unit_price');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->decimal('gross_amount', 18, 4)->default(0)->after('status');
            $table->decimal('invoice_discount', 18, 4)->default(0)->after('gross_amount');
        });

        DB::table('quotations')->update(['gross_amount' => DB::raw('total_amount')]);

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 4)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->dropColumn('discount_amount');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['invoice_discount', 'gross_amount']);
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn('discount_amount');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['invoice_discount', 'gross_amount']);
        });
    }
};
