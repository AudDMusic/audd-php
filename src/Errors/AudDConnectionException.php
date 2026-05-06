<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * Network / TLS / timeout — no response received.
 */
final class AudDConnectionException extends AudDException
{
    public function __construct(
        string $message,
        public readonly ?\Throwable $original = null,
    ) {
        parent::__construct($message, 0, $original);
    }
}
