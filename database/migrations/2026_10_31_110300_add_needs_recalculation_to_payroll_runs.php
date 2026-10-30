<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-11: a pay-history change dated into a calculated period makes that
     * run's payslips stale. The flag is raised by the change and cleared by
     * the next calculation; a flagged run cannot be submitted for approval.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->boolean('needs_recalculation')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn('needs_recalculation');
        });
    }
};
