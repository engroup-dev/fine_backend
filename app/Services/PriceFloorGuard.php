<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SalesRuleException;
use App\Models\InventoryItem;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * BR-PRC-003 / BR-PRC-004: Checks each item line's net unit price (after line
 * discount and pro-rata share of invoice discount) against its price floor
 * (max of min_price and cost_price on the item's pricing basis). Selling below
 * the floor requires either a seller holding approve-price-override or a
 * manager credential override.
 */
final class PriceFloorGuard
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Evaluate normalized sale lines against each item's price floor.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{login?: string|null, password?: string|null, reason?: string|null}|null  $priceOverride
     * @return array{by_user_id: string|null, reason: string|null}
     */
    public function verify(array $lines, float $invoiceDiscount, User $seller, ?array $priceOverride = null): array
    {
        $subtotal = array_sum(array_map(
            fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'] - (float) $l['discount_amount'],
            $lines,
        ));

        $itemIds = array_values(array_unique(array_filter(array_map(
            fn (array $l): ?string => ($l['line_type'] ?? SalesOrderLine::TYPE_ITEM) === SalesOrderLine::TYPE_ITEM
                ? ($l['inventory_item_id'] ?? null)
                : null,
            $lines,
        ))));

        if ($itemIds === []) {
            return ['by_user_id' => null, 'reason' => null];
        }

        $items = InventoryItem::whereIn('id', $itemIds)->get()->keyBy('id');
        $violations = [];

        foreach ($lines as $line) {
            if (($line['line_type'] ?? SalesOrderLine::TYPE_ITEM) === SalesOrderLine::TYPE_BUNDLE) {
                continue;
            }

            /** @var InventoryItem|null $item */
            $item = $items->get($line['inventory_item_id'] ?? '');
            if ($item === null) {
                continue;
            }

            $lengthM = isset($line['length_m']) ? (float) $line['length_m'] : null;
            $widthM = isset($line['width_m']) ? (float) $line['width_m'] : null;
            $heightM = isset($line['height_m']) ? (float) $line['height_m'] : null;

            $minPrice = $item->minPriceFor(1.0, $lengthM, $widthM, $heightM) ?? 0.0;
            $cost = $item->costFor(1.0, $lengthM, $widthM, $heightM) ?? 0.0;
            $floor = max($minPrice, $cost);

            if ($floor <= 0) {
                continue;
            }

            $quantity = (float) $line['quantity'];
            $lineNet = $quantity * (float) $line['unit_price'] - (float) $line['discount_amount'];
            $invoiceShare = ($invoiceDiscount > 0 && $subtotal > 0 && $lineNet > 0)
                ? $invoiceDiscount * ($lineNet / $subtotal)
                : 0.0;
            $netUnitPrice = ($lineNet - $invoiceShare) / $quantity;

            if ($netUnitPrice < $floor - 0.0001) {
                $violations[] = [
                    'position' => (int) $line['position'],
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'net_price' => round($netUnitPrice, 4),
                    'floor' => round($floor, 4),
                    'reason' => $cost > $minPrice ? 'cost' : 'min_price',
                ];
            }
        }

        if ($violations === []) {
            return ['by_user_id' => null, 'reason' => null];
        }

        if ($priceOverride === null || $priceOverride === []) {
            $seller->load('roles.permissions');

            if ($seller->hasPermission('approve-price-override')) {
                return [
                    'by_user_id' => $seller->id,
                    'reason' => 'approved by seller',
                ];
            }

            throw new SalesRuleException(
                'One or more items are priced below the minimum selling floor.',
                'PRICE_BELOW_FLOOR',
                ['lines' => $violations],
            );
        }

        return $this->verifyOverride($priceOverride, $seller);
    }

    /**
     * @param  array{login?: string|null, password?: string|null, reason?: string|null}  $priceOverride
     * @return array{by_user_id: string, reason: string}
     */
    private function verifyOverride(array $priceOverride, User $seller): array
    {
        $throttleKey = 'price-override:'.$seller->id;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw new SalesRuleException(
                'Too many failed price override attempts. Please wait before trying again.',
                'PRICE_OVERRIDE_THROTTLED',
                ['retry_after' => RateLimiter::availableIn($throttleKey)],
            );
        }

        $identifier = trim((string) ($priceOverride['login'] ?? ''));
        $password = (string) ($priceOverride['password'] ?? '');
        $reason = trim((string) ($priceOverride['reason'] ?? ''));

        $approver = $identifier !== ''
            ? User::where(function ($query) use ($identifier): void {
                $query->where('email', $identifier)
                    ->orWhere('phone', $identifier);
            })->first()
            : null;

        if ($approver === null || ! Hash::check($password, $approver->password) || ! $approver->is_active) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new SalesRuleException(
                'The provided manager credentials are invalid or inactive.',
                'PRICE_OVERRIDE_INVALID',
            );
        }

        if ($approver->id === $seller->id) {
            throw new SalesRuleException(
                'A seller cannot approve their own price override.',
                'PRICE_OVERRIDE_NOT_ALLOWED',
            );
        }

        $approver->load('roles.permissions');

        if (! $approver->hasPermission('approve-price-override')) {
            throw new SalesRuleException(
                'The selected user is not authorized to approve price overrides.',
                'PRICE_OVERRIDE_NOT_ALLOWED',
            );
        }

        RateLimiter::clear($throttleKey);

        return [
            'by_user_id' => $approver->id,
            'reason' => $reason,
        ];
    }
}
