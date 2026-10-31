<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client's delivery addresses. At most one is the default; a sale
        // keeps a text snapshot of the one it was delivered to.
        Schema::create('client_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('city', 100)->nullable();
            $table->string('address', 500);
            $table->string('phone', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
