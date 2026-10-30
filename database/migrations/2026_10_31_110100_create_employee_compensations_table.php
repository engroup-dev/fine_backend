<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HR-11: pay terms are versioned by effective date, never edited in place.
        // A new row supersedes the previous one from its effective_from onwards.
        Schema::create('employee_compensations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('pay_type', 50);
            $table->decimal('monthly_salary', 15, 4)->nullable();
            $table->decimal('hourly_rate', 15, 4)->nullable();
            $table->date('effective_from');
            $table->string('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensations');
    }
};
