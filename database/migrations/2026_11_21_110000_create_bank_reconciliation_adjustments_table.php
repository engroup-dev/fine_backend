<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRS-04: statement items (bank charges, interest, direct deposits/debits)
 * added while reconciling. Each row links the reconciliation to the voucher
 * that posted the item; the voucher survives if the draft is discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignUuid('treasury_voucher_id')->unique()->constrained('treasury_vouchers')->restrictOnDelete();
            $table->string('type', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_adjustments');
    }
};
