<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\StockLot;

/**
 * Piece sizes (L × W × H, metres) and whether a lot is that piece. Two sizes
 * are the same piece when their sorted dimensions agree within half a
 * centimetre, so a 2.0 × 0.7 × 0.1 cushion matches a lot stocked as
 * 0.7 × 2.0 × 0.1. Shared by bundle fulfilment and assembly orders.
 */
final class LotSize
{
    /** Two sizes within half a centimetre are the same piece. */
    public const TOLERANCE_M = 0.005;

    /**
     * A size from three dimensions, or null unless all three are positive.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function of(mixed $length, mixed $width, mixed $height): ?array
    {
        foreach ([$length, $width, $height] as $dimension) {
            if (! is_numeric($dimension) || (float) $dimension <= 0) {
                return null;
            }
        }

        return [(float) $length, (float) $width, (float) $height];
    }

    /**
     * Whether the lot is the wanted size. No wanted size matches any lot; a
     * wanted size never matches a lot without all three dimensions.
     *
     * @param  array{0: float, 1: float, 2: float}|null  $want
     */
    public static function matches(?array $want, StockLot $lot): bool
    {
        if ($want === null) {
            return true;
        }

        if ($lot->length_m === null || $lot->width_m === null || $lot->height_m === null) {
            return false;
        }

        $want = array_values($want);
        $have = [(float) $lot->length_m, (float) $lot->width_m, (float) $lot->height_m];
        sort($want);
        sort($have);

        foreach ($want as $i => $dimension) {
            if (abs((float) $dimension - $have[$i]) > self::TOLERANCE_M) {
                return false;
            }
        }

        return true;
    }
}
