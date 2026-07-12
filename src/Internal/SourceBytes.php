<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Wrapper signaling "this string is raw audio bytes, not a URL or file path."
 * Construct it via the public `AudD::bytes($bytes)` entry point.
 *
 * @internal
 */
final class SourceBytes
{
    public function __construct(
        public readonly string $bytes,
    ) {
    }
}
