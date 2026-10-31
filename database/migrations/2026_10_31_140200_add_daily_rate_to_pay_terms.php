<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-04 (HR-14): the daily rate of daily pay terms, versioned in the pay
     * history (HR-11); the employee row keeps the copy in force today, like
     * the monthly salary and hourly rate.
     */
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $table): void {
            $table->decimal('daily_rate', 15, 4)->nullable();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->decimal('daily_rate', 15, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('daily_rate');
        });

        Schema::table('employee_compensations', function (Blueprint $table): void {
            $table->dropColumn('daily_rate');
        });
    }
};
