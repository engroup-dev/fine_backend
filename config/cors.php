<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * The desktop renderer is loaded from file:// and so is cross-origin: it
     * can read only the response headers listed here. W6-03 backup downloads
     * send the file name and the archive's sha256.
     */
    'exposed_headers' => ['Content-Disposition', 'X-Backup-Checksum-Sha256'],

    'max_age' => 0,

    'supports_credentials' => false,

];
