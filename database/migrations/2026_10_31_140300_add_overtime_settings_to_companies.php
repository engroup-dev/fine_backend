<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-04 (HR-14): the monthly standard hours a salary is divided by for the
     * overtime hourly base (default 208), and the overtime multiplier of an
     * employee without a shift (default 1.5).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->decimal('monthly_standard_hours', 6, 2)->default(208);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.5);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['monthly_standard_hours', 'overtime_multiplier']);
        });
    }
};
