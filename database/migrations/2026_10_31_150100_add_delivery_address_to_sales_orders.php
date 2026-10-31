<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The address chosen at checkout, plus a text snapshot so later edits
        // to the client's address book never rewrite a delivery note.
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignUuid('delivery_address_id')->nullable()->after('walk_in_phone')
                ->constrained('client_addresses')->nullOnDelete();
            $table->string('delivery_address', 800)->nullable()->after('delivery_address_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_address_id');
            $table->dropColumn('delivery_address');
        });
    }
};
