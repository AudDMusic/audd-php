<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * 611 — per-stream daily rate limit (and HTTP 429).
 */
final class AudDRateLimitException extends AudDApiException
{
}
