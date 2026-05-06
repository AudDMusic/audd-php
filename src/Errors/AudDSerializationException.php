<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * Server returned malformed JSON in a 2xx response. (Non-JSON in non-2xx
 * is mapped to AudDServerException — see design spec §6.6.)
 */
final class AudDSerializationException extends AudDException
{
    public function __construct(
        string $message,
        public readonly string $rawText = '',
    ) {
        parent::__construct($message);
    }
}
