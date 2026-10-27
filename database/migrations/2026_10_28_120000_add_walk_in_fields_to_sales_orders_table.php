<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-INV-003 (owner decision #7): Optional walk-in customer name and phone on POS sales.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('walk_in_name', 100)->nullable()->after('buyer_unit_id');
            $table->string('walk_in_phone', 30)->nullable()->after('walk_in_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['walk_in_phone', 'walk_in_name']);
        });
    }
};
