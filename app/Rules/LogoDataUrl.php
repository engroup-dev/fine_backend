<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * W6-02: the company logo is a base64 image data URL of at most 200 KB.
 * Only raster types are accepted (PNG, JPEG, WebP — never SVG, which can
 * carry script), and the decoded bytes must really be an image of the
 * declared type. Failure messages never echo the submitted value.
 */
final class LogoDataUrl implements ValidationRule
{
    /** The largest accepted data URL, in bytes (200 KB). */
    public const MAX_BYTES = 200 * 1024;

    /** @var array<string, int> declared MIME type => getimagesize() image type */
    private const ALLOWED_TYPES = [
        'image/png' => IMAGETYPE_PNG,
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('The logo must be an image data URL.');

            return;
        }

        if (strlen($value) > self::MAX_BYTES) {
            $fail('The logo may not be larger than 200 KB.');

            return;
        }

        if (preg_match('#\Adata:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/]+={0,2})\z#', $value, $matches) !== 1) {
            $fail('The logo must be a base64 PNG, JPEG or WebP data URL.');

            return;
        }

        $bytes = base64_decode($matches[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== self::ALLOWED_TYPES[$matches[1]]) {
            $fail('The logo is not a valid image of the declared type.');
        }
    }
}
