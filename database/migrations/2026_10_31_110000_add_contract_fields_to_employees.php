<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // W5-01 employee card: contract and bank details. All optional so existing
        // employees stay valid; employee_number is unique when given.
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_number', 50)->nullable()->unique()->after('entity_id');
            $table->date('contract_start')->nullable()->after('hire_date');
            $table->date('contract_end')->nullable()->after('contract_start');
            $table->date('probation_end')->nullable()->after('contract_end');
            $table->date('termination_date')->nullable()->after('probation_end');
            $table->string('bank_name', 150)->nullable()->after('termination_date');
            $table->string('bank_account', 100)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['employee_number']);
            $table->dropColumn([
                'employee_number',
                'contract_start',
                'contract_end',
                'probation_end',
                'termination_date',
                'bank_name',
                'bank_account',
            ]);
        });
    }
};
