<?php

declare(strict_types=1);

namespace AudD;

/**
 * Streaming providers reachable via the lis.tn `?<provider>` redirect helper
 * and (for some) via direct URLs in the per-provider metadata blocks.
 *
 * The backing values are the keys `streamingUrls()` returns. The lis.tn
 * redirect key for a provider comes from `lisTnKey()`.
 */
enum StreamingProvider: string
{
    case SPOTIFY = 'spotify';
    case APPLE_MUSIC = 'apple_music';
    case DEEZER = 'deezer';
    /**
     * @deprecated Napster links don't resolve: `streamingUrl(NAPSTER)` returns
     *             null and `streamingUrls()` leaves Napster out.
     */
    case NAPSTER = 'napster';
    case YOUTUBE = 'youtube';

    /**
     * The `lis.tn/<id>?<key>` redirect key for this provider, or null when
     * lis.tn has no redirect for it.
     *
     * @internal Used by the result models' streaming-URL helpers.
     */
    public function lisTnKey(): ?string
    {
        return match ($this) {
            self::APPLE_MUSIC => 'apple',
            self::NAPSTER => null,
            default => $this->value,
        };
    }
}
