<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->string('number')->unique();
            $table->date('return_date');
            $table->string('refund_method', 10); // cash | bank | credit
            $table->foreignUuid('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('total_cost', 18, 4);
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['operating_unit_id', 'return_date']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignUuid('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->string('condition', 10); // restock | damaged
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->decimal('returned_amount', 18, 4)->default(0)->after('amount_paid');
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->foreignUuid('sales_return_id')->nullable()->after('sales_order_id')->constrained('sales_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_return_id');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('returned_amount');
        });

        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};
