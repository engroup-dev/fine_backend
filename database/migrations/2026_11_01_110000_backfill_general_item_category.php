<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    public const GENERAL_NAME = 'عام';

    public const GENERAL_CODE = 'GEN';

    /**
     * W6-06: an item's category becomes required, so the system always has
     * a general "عام" category to pick, and every item that has none, or
     * whose category was soft-deleted, moves into it (soft-deleted items
     * too).
     *
     * Categories are one company-wide catalog (their unit column was dropped
     * on 2026-09-29), so there is a single general category. It is ensured
     * on every database, fresh installs included, so the first item can be
     * saved without creating a category by hand.
     *
     * An existing live "عام" is reused only when it is a top-level leaf with
     * no item type (the shape this migration would create): a sub-category
     * that happens to be called "عام" (say "Foam > عام"), a parent with
     * children, or a typed category is left alone, and a new top-level "عام"
     * is created with the code GEN (GEN-2, GEN-3… if GEN is taken).
     * Query-builder writes on purpose: a backfill is not an audited user
     * action.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $categoryId = $this->reusableGeneralCategoryId() ?? $this->createGeneralCategory();

            $uncategorized = DB::table('inventory_items')
                ->whereNull('category_id')
                ->update(['category_id' => $categoryId]);

            // An item still pointing at a soft-deleted category is just as
            // uncategorized: the item form would refuse to re-send that
            // category, so every later edit of the item would fail.
            $inDeletedCategory = DB::table('inventory_items')
                ->whereIn('category_id', DB::table('item_categories')->whereNotNull('deleted_at')->select('id'))
                ->update(['category_id' => $categoryId]);

            if ($uncategorized > 0) {
                Log::info("W6-06: moved {$uncategorized} uncategorized inventory item(s) into the general category.", [
                    'category_id' => $categoryId,
                ]);
            }

            if ($inDeletedCategory > 0) {
                Log::info("W6-06: moved {$inDeletedCategory} inventory item(s) whose category was deleted into the general category.", [
                    'category_id' => $categoryId,
                ]);
            }
        });
    }

    /**
     * Not reversible: after the backfill an item placed in "عام" by it cannot
     * be told apart from one a user put there, and the category may already
     * hold such items.
     */
    public function down(): void {}

    private function reusableGeneralCategoryId(): ?string
    {
        return DB::table('item_categories as category')
            ->where('category.name', self::GENERAL_NAME)
            ->whereNull('category.deleted_at')
            ->whereNull('category.parent_id')
            ->whereNull('category.item_type')
            ->whereNotExists(fn ($children) => $children
                ->selectRaw('1')
                ->from('item_categories as child')
                ->whereColumn('child.parent_id', 'category.id')
                ->whereNull('child.deleted_at'))
            ->orderBy('category.created_at')
            ->orderBy('category.id')
            ->value('category.id');
    }

    private function createGeneralCategory(): string
    {
        $categoryId = (string) Str::uuid();
        $now = now();

        DB::table('item_categories')->insert([
            'id' => $categoryId,
            'parent_id' => null,
            'name' => self::GENERAL_NAME,
            'code_segment' => null,
            'code' => $this->freeCode(),
            'item_type' => null,
            'child_code_length' => 2,
            'description' => 'Default category for items saved without one (W6-06).',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $categoryId;
    }

    private function freeCode(): string
    {
        $code = self::GENERAL_CODE;
        $suffix = 1;

        while (DB::table('item_categories')->where('code', $code)->exists()) {
            $suffix++;
            $code = self::GENERAL_CODE.'-'.$suffix;
        }

        return $code;
    }
};
