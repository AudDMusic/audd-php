<?php

declare(strict_types=1);

namespace AudD;

/**
 * Lifecycle stage of an AudDEvent.
 */
enum AudDEventKind
{
    case Request;
    case Response;
    case Exception;
}
