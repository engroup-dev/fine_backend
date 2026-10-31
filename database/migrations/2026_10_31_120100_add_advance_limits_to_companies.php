<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-02 (decision #14): advance limits, company-wide. One advance may not
     * exceed this multiple of the monthly base salary, and the monthly recovery
     * may not exceed this percentage of net pay.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->decimal('advance_max_salary_multiple', 8, 2)->default(1);
            $table->decimal('advance_max_deduction_pct', 5, 2)->default(50);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['advance_max_salary_multiple', 'advance_max_deduction_pct']);
        });
    }
};
