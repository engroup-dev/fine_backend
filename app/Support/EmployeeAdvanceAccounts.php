<?php

declare(strict_types=1);

namespace App\Support;

/**
 * W5-00: the employee advances subtree, account 1215 (سلف الموظفين) and every
 * descendant by parent_account_id. Lines here carry the employee party.
 *
 * Computed per call (no static cache) so it never goes stale across requests
 * that add sub-accounts, or across tests.
 */
final class EmployeeAdvanceAccounts
{
    public const ROOT_CODE = '1215';

    /**
     * @return array<int, string>
     */
    public static function subtreeIds(): array
    {
        return AccountTree::subtreeIds(self::ROOT_CODE);
    }

    public static function contains(string $accountId): bool
    {
        return in_array($accountId, self::subtreeIds(), true);
    }
}
