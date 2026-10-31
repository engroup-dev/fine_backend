<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W5-06: company-wide client groups (wholesale, retail, contractors...).
     * A new client takes its group's default credit limit and payment terms;
     * the default price tier tells the POS which tier to start a sale on.
     * Codes are unique among live groups, so a removed group's code can be
     * reused: a partial unique index enforces it where the driver supports
     * one (SQLite, PostgreSQL); the write validation gives the friendly 422.
     */
    public function up(): void
    {
        Schema::create('client_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->index();
            $table->string('name', 100);
            $table->decimal('default_credit_limit', 18, 4)->default(0);
            $table->unsignedInteger('default_payment_terms_days')->default(30);
            $table->string('default_price_tier', 10)->default('retail');
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX client_groups_code_live_unique ON client_groups (code) WHERE deleted_at IS NULL');
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignUuid('client_group_id')->nullable()->after('operating_unit_id')
                ->constrained('client_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_group_id');
        });

        Schema::dropIfExists('client_groups');
    }
};
