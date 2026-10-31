<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-02: the company profile printed on document headers and receipts.
     * The logo is a size-limited image data URL (at most 200 KB) kept in the
     * row itself, so no file ever lands on a public disk path.
     *
     * Trade-off, kept on purpose: the plan puts the logo on the companies row,
     * so every Eloquent load of Company (CompanyClock via $unit->company, the
     * default_currency lookups, Company::query()->first() in HR settings) also
     * fetches it. That is one single-row read, cached per loaded relation and
     * deduplicated by eager loads, of at most 200 KB (a print logo is usually
     * far smaller). Company::$hidden keeps it out of implicit serialization,
     * not out of the SELECT. If that read ever shows up in profiling, move the
     * logo to its own one-row table read only by CompanyController.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('legal_name')->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('commercial_register', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->mediumText('logo')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->text('invoice_terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'legal_name', 'tax_number', 'commercial_register', 'address', 'phone',
                'email', 'logo', 'receipt_footer', 'invoice_terms',
            ]);
        });
    }
};
