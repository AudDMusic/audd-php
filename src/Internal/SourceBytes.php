<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Wrapper signaling "this string is raw audio bytes, not a URL or file path."
 * Use Source::bytes($bytes) to construct.
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
