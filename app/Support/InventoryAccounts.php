<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\StockLot;

/**
 * THE rule for lot value (INV-11): a lot's value enters and leaves the ledger
 * through its item type's inventory account — intake, purchase receipt, sale
 * cost, bundle delivery, restock and internal transfers, sales-return restock,
 * foam close and cutter outputs all resolve the account here, so every flow
 * that posts lot value enters and relieves the same account. The unit's
 * `purchases` event mapping is no longer used for lot value: until 2026-10-27
 * most flows posted there (111 for every unit), so a foam block entered
 * through 1131 and left through 111.
 *
 * Known gaps, outside this rule: foam production consumption is not posted.
 * Raw-material lots are never drawn down by production, while foam close
 * credits 1121 with the batch's material cost, so 111 and its lots both keep
 * the consumed value and 1121 carries the credit. The fix is a consumption
 * flow that draws the lots down AND posts DR 1121 / CR 111 together; a
 * GL-only entry would leave 111 below its lots. A block consumed through a
 * cut-remnant decision with no remnant restocked leaves its value on its
 * account with no entry, and an item whose type changes while it holds
 * stock moves its lots to another account with no entry too.
 *
 * WIP accounts (1121 / 1122 / 1123) are not lot accounts: value parked in
 * production (a block reserved by a cutter work order sits in 1122) is posted
 * by its module on purpose. `inventory:reconcile-accounts` compares these
 * accounts with the lots and posts the correction.
 *
 * Resolve an item that may be soft-deleted with forItemId / forLot, never
 * through a BelongsTo relation: the relation hides a trashed item, and
 * forItem(null) falls back to 111 while forLot still reads the real type.
 */
final class InventoryAccounts
{
    /**
     * Every account a lot's value can sit in.
     *
     * @var list<string>
     */
    public const LOT_ACCOUNTS = ['111', '1131', '1132', '1133', '1134'];

    public static function forItemType(?string $itemType): string
    {
        return match ($itemType) {
            'foam_block' => '1131',
            'cut_template_piece', 'slice' => '1132',
            'byproduct_fill' => '1133',
            'furniture_finished_good', 'finished_good' => '1134',
            default => '111', // raw materials, chemicals, containers, fabric…
        };
    }

    /**
     * INV-11: the account an item's lots carry their value in.
     */
    public static function forItem(?InventoryItem $item): string
    {
        return self::forItemType($item?->item_type);
    }

    /**
     * INV-11: the account an item's lots carry their value in, by id — a
     * soft-deleted item still resolves to its own type, as in forLot.
     */
    public static function forItemId(?string $itemId): string
    {
        return self::forItem($itemId !== null ? InventoryItem::withTrashed()->find($itemId) : null);
    }

    /**
     * INV-11: the account this lot's value sits in, via its item.
     */
    public static function forLot(StockLot $lot): string
    {
        $item = $lot->relationLoaded('inventoryItem') ? $lot->inventoryItem : null;
        $item ??= InventoryItem::withTrashed()->find($lot->inventory_item_id);

        return self::forItem($item);
    }
}
