<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-03 (HR-13): rewards and deductions. A standing pay item recurs every
     * period it is in force; a one-off adjustment applies to one period once
     * approved. Payroll copies both onto the payslip as calculated.
     */
    public function up(): void
    {
        Schema::create('employee_pay_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            // An allowance-types code (addition) or a deduction-types code (deduction).
            $table->string('code', 64);
            $table->string('kind', 20);
            $table->string('calc', 20);
            $table->decimal('value', 15, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'active']);
        });

        Schema::create('employee_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('period', 7);
            $table->string('kind', 20);
            $table->string('code', 64);
            $table->decimal('amount', 15, 4);
            $table->string('reason', 500);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['period', 'status']);
            $table->index(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_adjustments');
        Schema::dropIfExists('employee_pay_items');
    }
};
