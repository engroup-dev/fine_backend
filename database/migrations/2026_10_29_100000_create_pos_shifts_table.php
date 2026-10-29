<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-SLREP-008: cashier shifts — a drawer opened with a float by one cashier,
 * closed with a counted Z-report. Cash payments and refunds taken while a
 * shift is open are stamped with it; a unit opts in with pos_shifts_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('status', 10); // open | closed
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 18, 4);
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->nullable();
            $table->json('totals')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['cash_account_id', 'status']);
            $table->index(['operating_unit_id', 'status']);
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->foreignUuid('pos_shift_id')->nullable()->index()->after('sales_return_id')->constrained('pos_shifts')->nullOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignUuid('pos_shift_id')->nullable()->index()->after('cash_account_id')->constrained('pos_shifts')->nullOnDelete();
        });

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->boolean('pos_shifts_enabled')->default(false);
        });

        Schema::table('pos_daily_closes', function (Blueprint $table): void {
            $table->boolean('shift_based')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pos_daily_closes', function (Blueprint $table): void {
            $table->dropColumn('shift_based');
        });

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropColumn('pos_shifts_enabled');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['pos_shift_id']);
            $table->dropIndex(['pos_shift_id']);
            $table->dropColumn('pos_shift_id');
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropForeign(['pos_shift_id']);
            $table->dropIndex(['pos_shift_id']);
            $table->dropColumn('pos_shift_id');
        });

        Schema::dropIfExists('pos_shifts');
    }
};
