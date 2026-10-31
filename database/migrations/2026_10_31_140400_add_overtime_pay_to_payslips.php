<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-04 (HR-14): overtime pay, its own figure inside gross:
     * gross = base + attendance + overtime + additions.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->decimal('overtime_pay', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn('overtime_pay');
        });
    }
};
