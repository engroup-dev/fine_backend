<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-03 (HR-13): the allowances and bonuses a payslip pays on top of base
     * and attendance pay; gross = base + attendance + additions.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->json('additions')->nullable();
            $table->decimal('additions_pay', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn(['additions', 'additions_pay']);
        });
    }
};
