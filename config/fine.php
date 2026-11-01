<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | FX Tolerance
    |--------------------------------------------------------------------------
    |
    | The execute-payment validation gate requires a justification note when
    | the actual settled LYD deviates from the booked FX snapshot by more
    | than `tolerance_lyd`. Defaults to 1 piastre; finance can tune via the
    | `FX_TOLERANCE_LYD` env var without a code change.
    |
    | The hard cap, expressed as a percent of settled, surfaces a red
    | variance row that requires an explicit acknowledge chip in the UI
    | before submit. Defaults to 5%.
    |
    | These are also exposed on `PurchaseOrderStateService::FX_TOLERANCE_LYD`
    | and `::FX_HARD_CAP_PERCENT` as defensive defaults — the service
    | constants win if the config is missing.
    */

    'fx' => [
        'tolerance_lyd' => env('FX_TOLERANCE_LYD', 0.01),
        'hard_cap_percent' => env('FX_HARD_CAP_PERCENT', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export-all cap (W6-04)
    |--------------------------------------------------------------------------
    |
    | The most rows a list returns for `all=1` (the desktop's CSV export of a
    | server-paged list). More than this is refused with 422
    | EXPORT_TOO_LARGE instead of being cut short.
    */

    'export_all_limit' => 5000,

];
