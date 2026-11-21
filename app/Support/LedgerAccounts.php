<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Standard ledger account codes referenced across accounting operations.
 *
 * ACC-12, ACC-15, BR-CUR-008, TRS-01, TRS-05, TRS-06.
 */
final class LedgerAccounts
{
    public const RETAINED_EARNINGS = '33';

    public const FX_GAIN = '42';

    public const FX_LOSS = '53';

    public const NOTES_RECEIVABLE = '124';

    public const CHEQUES_UNDER_COLLECTION = '125';

    public const NOTES_PAYABLE = '227';

    public const CASH_OVERAGE = '44';

    public const CASH_SHORTAGE = '625';

    /** Equity counterpart of treasury opening balances. */
    public const OPENING_BALANCES = '34';

    /** Bank reconciliation adjustments: charges and commissions on the statement. */
    public const BANK_CHARGES = '610';

    /** Bank reconciliation adjustments: interest the bank credited. */
    public const BANK_INTEREST = '46';
}
