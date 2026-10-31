<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-04 (HR-14): overtime hours worked on the day, on top of hours_worked
     * (the two together at most 24).
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->decimal('overtime_hours', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('overtime_hours');
        });
    }
};
