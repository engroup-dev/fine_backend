<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-01: the audit log viewer reads a record's history by (table_name, record_id)
     * and lists newest first / by date range on created_at. The table was indexed
     * only on user_id and operating_unit_id.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['table_name', 'record_id'], 'audit_logs_table_name_record_id_index');
            $table->index('created_at', 'audit_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_table_name_record_id_index');
            $table->dropIndex('audit_logs_created_at_index');
        });
    }
};
