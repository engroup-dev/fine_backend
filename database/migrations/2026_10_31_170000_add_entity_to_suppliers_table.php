<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-07: a supplier is a party on the shared entities table (vendor role),
     * so a company that is both a client and a supplier is one entity. The
     * supplier row keeps its own name and contact columns for the existing
     * API. record_version enables optimistic locking.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignUuid('entity_id')->nullable()->after('operating_unit_id')
                ->constrained('entities')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entity_id');
            $table->dropColumn('record_version');
        });
    }
};
