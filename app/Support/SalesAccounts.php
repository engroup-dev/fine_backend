<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InventoryEventType;
use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\OperatingUnit;
use App\Services\CoaLinkService;

/**
 * The one place a sale's ledger accounts are chosen. Checkout, receivable
 * collection and delivery must agree, the same way InventoryAccounts keeps
 * intake and issue on one account.
 *
 * Every account returned is a leaf: revenue is the unit's own sales account,
 * a receivable is the client's own sub-account under 122. Section headers
 * (41, 122, 404, 407) are never posted to — AccountingService refuses them.
 * SAL rule: returns and discounts never post to the revenue account.
 */
final class SalesAccounts
{
    public const COGS = '51';

    /**
     * @throws SalesRuleException
     */
    public static function revenueFor(OperatingUnit $unit): string
    {
        $code = $unit->revenueAccount?->account_code;

        if ($code === null) {
            throw new SalesRuleException(
                "Operating unit '{$unit->name}' has no sales revenue account linked yet.",
                'UNIT_REVENUE_NOT_LINKED',
            );
        }

        return $code;
    }

    /**
     * SAL rule: returns and discounts never post to the revenue account.
     *
     * @throws SalesRuleException
     */
    public static function returnsFor(OperatingUnit $unit): string
    {
        return self::contraRevenueFor(
            $unit,
            InventoryEventType::SalesReturns,
            '4040',
            "Operating unit '{$unit->name}' has no sales returns account linked yet.",
            'UNIT_RETURNS_NOT_LINKED',
        );
    }

    /**
     * SAL rule: returns and discounts never post to the revenue account.
     *
     * @throws SalesRuleException
     */
    public static function discountFor(OperatingUnit $unit): string
    {
        return self::contraRevenueFor(
            $unit,
            InventoryEventType::GrantedDiscount,
            '4070',
            "Operating unit '{$unit->name}' has no granted discount account linked yet.",
            'UNIT_DISCOUNT_NOT_LINKED',
        );
    }

    /**
     * The client's own receivable sub-account. A client created before every
     * client got one is given its sub-account here, on first use, so a credit
     * sale never lands on the 122 header.
     *
     * @throws SalesRuleException
     */
    public static function receivableFor(?Client $client): string
    {
        if ($client === null) {
            throw new SalesRuleException('A receivable needs the client it is owed by.', 'CLIENT_REQUIRED');
        }

        $code = $client->account?->account_code;

        if ($code !== null) {
            return $code;
        }

        $client->account_id = app(CoaLinkService::class)->provisionNextSubAccount(
            ReceivableAccounts::ROOT_CODE,
            'عميل - '.($client->entity?->name ?? $client->id),
        );
        $client->save();

        return (string) $client->load('account')->account?->account_code;
    }

    /**
     * @throws SalesRuleException
     */
    public static function treasuryFor(CashAccount $cashAccount): string
    {
        return TreasuryAccounts::codeFor($cashAccount);
    }

    /**
     * @throws SalesRuleException
     */
    private static function contraRevenueFor(
        OperatingUnit $unit,
        InventoryEventType $event,
        string $derivedPrefix,
        string $message,
        string $errorCode,
    ): string {
        $revenueCode = $unit->revenueAccount?->account_code
            ?? ($unit->revenue_account_id !== null
                ? Account::whereKey($unit->revenue_account_id)->value('account_code')
                : null);

        $mapped = $unit->accountFor($event);

        if ($mapped !== null
            && $mapped->id !== $unit->revenue_account_id
            && ($revenueCode === null || $mapped->account_code !== $revenueCode)) {
            return $mapped->account_code;
        }

        if ($revenueCode !== null && preg_match('/^4020([1-4])$/', (string) $revenueCode, $matches) === 1) {
            $derivedCode = $derivedPrefix.$matches[1];
            if (Account::where('account_code', $derivedCode)->exists()) {
                return $derivedCode;
            }
        }

        throw new SalesRuleException($message, $errorCode);
    }
}
