<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * Misconfiguration detected client-side before any HTTP call (e.g., missing
 * api_token, env-var unset, empty rotation token). Distinct from
 * AudDApiException, which carries a server-side error code.
 */
final class AudDConfigurationException extends AudDException
{
}
