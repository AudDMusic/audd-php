<?php

declare(strict_types=1);

namespace AudD\Models;

use AudD\StreamingProvider;

/**
 * One match from the enterprise endpoint. Carries score + offset bounds.
 *
 * Streaming URLs are lis.tn-only: enterprise responses don't carry per-provider
 * metadata blocks, so there's no direct-URL fallback. Spec §4.3.
 */
final class EnterpriseMatch extends ForwardCompatModel
{
    private const KNOWN = [
        'score', 'timecode', 'artist', 'title', 'album', 'release_date',
        'label', 'isrc', 'upc', 'song_link', 'start_offset', 'end_offset',
    ];

    public readonly int $score;
    public readonly string $timecode;
    public readonly ?string $artist;
    public readonly ?string $title;
    public readonly ?string $album;
    public readonly ?string $release_date;
    public readonly ?string $label;
    public readonly ?string $isrc;
    public readonly ?string $upc;
    public readonly ?string $song_link;
    public readonly ?int $start_offset;
    public readonly ?int $end_offset;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->score = (int) ($payload['score'] ?? 0);
        $this->timecode = (string) ($payload['timecode'] ?? '');
        $this->artist = isset($payload['artist']) ? (string) $payload['artist'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->album = isset($payload['album']) ? (string) $payload['album'] : null;
        $this->release_date = isset($payload['release_date']) ? (string) $payload['release_date'] : null;
        $this->label = isset($payload['label']) ? (string) $payload['label'] : null;
        $this->isrc = isset($payload['isrc']) ? (string) $payload['isrc'] : null;
        $this->upc = isset($payload['upc']) ? (string) $payload['upc'] : null;
        $this->song_link = isset($payload['song_link']) ? (string) $payload['song_link'] : null;
        $this->start_offset = isset($payload['start_offset']) ? (int) $payload['start_offset'] : null;
        $this->end_offset = isset($payload['end_offset']) ? (int) $payload['end_offset'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }

    public function thumbnailUrl(): ?string
    {
        return self::lisTnRedirect($this->song_link, 'thumb');
    }

    /**
     * Lis.tn redirect URL for a streaming provider. Returns null when
     * `song_link` is not on `lis.tn` — enterprise responses don't carry the
     * per-provider metadata blocks that recognize() responses do, so the
     * direct-URL fallback used by RecognitionResult does not apply here.
     */
    public function streamingUrl(StreamingProvider $provider): ?string
    {
        return self::lisTnRedirect($this->song_link, $provider->value);
    }

    /**
     * All five providers' lis.tn redirect URLs. Empty array when `song_link`
     * is not on `lis.tn`. Spec §4.3.
     *
     * @return array<string, string>
     */
    public function streamingUrls(): array
    {
        $out = [];
        foreach (StreamingProvider::cases() as $p) {
            $url = $this->streamingUrl($p);
            if ($url !== null) {
                $out[$p->value] = $url;
            }
        }
        return $out;
    }

    private static function lisTnRedirect(?string $songLink, string $key): ?string
    {
        if ($songLink === null || $songLink === '') {
            return null;
        }
        $parsed = parse_url($songLink);
        if (!is_array($parsed) || ($parsed['host'] ?? null) !== 'lis.tn') {
            return null;
        }
        $sep = isset($parsed['query']) && $parsed['query'] !== '' ? '&' : '?';
        return $songLink . $sep . $key;
    }
}
