<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * 100 / 1000 / unknown codes / generic upstream failures.
 *
 * Also raised on HTTP non-2xx with a non-JSON body — preserving the HTTP status
 * so users get an actionable code rather than a confusing "unparseable response".
 */
final class AudDServerException extends AudDApiException
{
}
