<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-issued document numbers — sales (S), quotations (Q), cutter work
 * orders (CWO), journal entries (JE), receipt vouchers (RV), payment vouchers (PV),
 * treasury transfers (TRF), cheques (CHQ), sales returns (RET) and POS shifts (SH). One counter per type and year serves
 * every unit, and it is locked while it moves, so two tills can never hand out the same number.
 */
final class DocumentNumberService
{
    public const SALE = 'S';

    public const QUOTATION = 'Q';

    public const CUTTER_WORK_ORDER = 'CWO';

    public const JOURNAL_ENTRY = 'JE';

    public const RECEIPT_VOUCHER = 'RV';

    public const PAYMENT_VOUCHER = 'PV';

    public const TREASURY_TRANSFER = 'TRF';

    public const CHEQUE = 'CHQ';

    public const SALES_RETURN = 'RET';

    public const POS_SHIFT = 'SH';

    /**
     * The next number for a document type, e.g. S-2026-00042.
     *
     * The year defaults to today. Journal entries pass their entry date's year:
     * a backdated correction counts against its own year's sequence.
     */
    public function next(string $documentType, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($documentType, $year): string {
            // Seed the year's row without racing a concurrent first request.
            DocumentSequence::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'document_type' => $documentType,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->last_number++;
            $sequence->save();

            return sprintf('%s-%d-%05d', $documentType, $year, $sequence->last_number);
        });
    }
}
