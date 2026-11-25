<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Thrown by a source re-opener when the audio can't be sent again (an
 * unseekable stream or resource). The retry loop answers it with the previous
 * attempt's outcome, so it never reaches the caller from a retry.
 *
 * @internal
 */
final class SourceNotReplayableException extends \RuntimeException
{
}
