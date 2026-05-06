<?php

declare(strict_types=1);

namespace AudD;

/**
 * SDK version constant.
 */
final class Version
{
    public const VERSION = '1.5.6';

    /**
     * @return string The User-Agent header value emitted on every request.
     */
    public static function userAgent(): string
    {
        $php = PHP_VERSION;
        $os = PHP_OS_FAMILY;
        return 'audd-php/' . self::VERSION . ' php/' . $php . ' (' . strtolower($os) . ')';
    }
}
