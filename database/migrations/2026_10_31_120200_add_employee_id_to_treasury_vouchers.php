<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-02: a voucher whose counter account is in the employee advances
     * subtree (1215) records the employee it was paid to or received from.
     */
    public function up(): void
    {
        Schema::table('treasury_vouchers', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->nullable()->after('client_id')->constrained('employees')->restrictOnDelete();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_vouchers', function (Blueprint $table): void {
            $table->dropIndex(['employee_id']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
