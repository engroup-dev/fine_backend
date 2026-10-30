<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-01: the annual leave entitlement the employee card's leave balance is
     * measured against (used annual days / entitlement). Company-wide, 30 by
     * default.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annual_leave_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('annual_leave_days');
        });
    }
};
