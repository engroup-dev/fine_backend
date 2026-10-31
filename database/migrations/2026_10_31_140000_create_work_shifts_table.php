<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-04 (HR-14): company-wide work shifts. The shift gives a daily-paid
     * employee's standard hours (the overtime hourly base) and every assigned
     * employee's overtime multiplier.
     */
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->decimal('standard_hours', 5, 2);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.5);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignUuid('work_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_shift_id');
        });

        Schema::dropIfExists('work_shifts');
    }
};
