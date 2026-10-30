<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            // W5-00 employee party: lets employee advance balances be built from the ledger.
            // Restrict, not null-on-delete: employees are soft-deleted, and a hard delete
            // must never strip the party off advance history (required on 1215 lines).
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->restrictOnDelete();

            $table->index('employee_id');
            $table->index(['account_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'employee_id']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
