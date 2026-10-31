<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-02 (decision #14): salary advances and loans. An advance is paid out
     * of a treasury (DR 1215 / CR treasury) and recovered by monthly payroll
     * installments (HR-12).
     */
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 15, 4);
            $table->unsignedSmallInteger('installments');
            $table->decimal('monthly_amount', 15, 4);
            $table->string('start_period', 7);
            $table->foreignUuid('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->string('status', 20)->default('requested');
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('payout_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['operating_unit_id', 'status']);
        });

        Schema::create('advance_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_advance_id')->constrained('employee_advances')->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('amount', 15, 4);
            $table->decimal('recovered_amount', 15, 4)->default(0);
            // The payslip that last recovered part of it (payroll posting only).
            $table->foreignUuid('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['employee_advance_id', 'period']);
            $table->index(['status', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_installments');
        Schema::dropIfExists('employee_advances');
    }
};
