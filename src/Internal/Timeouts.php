<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Default timeouts in seconds. Values mirror the design spec §7.2.
 *
 * @internal
 */
final class Timeouts
{
    public const DEFAULT_CONNECT = 30.0;
    public const DEFAULT_READ = 60.0;

    public const ENTERPRISE_CONNECT = 30.0;
    public const ENTERPRISE_READ = 3600.0;

    public const LONGPOLL_CONNECT = 10.0;
    public const LONGPOLL_READ = 120.0;
}
