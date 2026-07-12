<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * Server returned malformed JSON in a 2xx response. (Non-JSON in a non-2xx
 * response is mapped to AudDServerException instead.)
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
