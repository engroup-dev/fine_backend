<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Filters for the debtor statements report (كشف حساب المدينين).
 *
 * Dates are Y-m-d strings matched against journal_entries.entry_date.
 * The unit filter applies to journal lines; status, city, client group
 * and minimum balance apply to the client and its closing balance.
 */
final readonly class DebtorStatementFilters
{
    /**
     * @param  list<string>  $clientIds
     * @param  list<string>  $statuses
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?string $operatingUnitId = null,
        public array $clientIds = [],
        public array $statuses = [],
        public ?string $city = null,
        public ?float $minBalance = null,
        public bool $includeUnassigned = false,
        public ?string $clientGroupId = null,
    ) {}
}
