<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * W6-03: a backup operation was refused or failed (a backup already running,
 * a dump or verification that failed, a file that is missing or does not
 * match its checksum). Rendered as 422 with its machine code plus any context
 * keys (bootstrap/app.php).
 */
final class BackupException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, public readonly string $errorCode, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
