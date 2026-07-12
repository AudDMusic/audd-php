<?php

declare(strict_types=1);

namespace AudD;

/**
 * Streaming providers reachable via the lis.tn `?<provider>` redirect helper
 * and (for some) via direct URLs in the per-provider metadata blocks.
 *
 * Backed by string values matching AudD's lis.tn redirect query keys so the
 * enum value can be appended directly: `"$songLink?{$provider->value}"`.
 */
enum StreamingProvider: string
{
    case SPOTIFY = 'spotify';
    case APPLE_MUSIC = 'apple_music';
    case DEEZER = 'deezer';
    case NAPSTER = 'napster';
    case YOUTUBE = 'youtube';
}
