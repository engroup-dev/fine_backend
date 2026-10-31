<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-08 (decision #6): an optional schedule of equal monthly installments
     * on a receivable sale, no interest or fees. The schedule moves no money
     * and posts nothing; collections keep going through the sale's payments
     * and the line statuses are derived from them (InstallmentPlanService).
     */
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->restrictOnDelete();
            $table->foreignUuid('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            // What the installments add up to: the sale's outstanding once the down payment is in.
            $table->decimal('financed_amount', 15, 4);
            // Money collected as a down payment when the plan was opened (0 when none).
            $table->decimal('down_payment', 15, 4)->default(0);
            // The sale's amount_paid / returned_amount right after the plan opened:
            // only money and returns after that point touch the installments.
            $table->decimal('opening_paid', 15, 4)->default(0);
            $table->decimal('opening_returned', 15, 4)->default(0);
            $table->unsignedSmallInteger('installment_count');
            $table->string('frequency', 20)->default('monthly');
            $table->date('first_due_date');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['operating_unit_id', 'client_id']);
        });

        Schema::create('installment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('installment_plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->unique(['installment_plan_id', 'number']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_lines');
        Schema::dropIfExists('installment_plans');
    }
};
