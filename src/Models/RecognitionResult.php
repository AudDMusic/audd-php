<?php

declare(strict_types=1);

namespace AudD\Models;

use AudD\StreamingProvider;

/**
 * Single recognition result. Public-DB matches expose artist/title/etc.,
 * custom-DB matches expose audio_id only — both share `timecode`.
 *
 * Forward compat: any unknown server field is exposed via `$extras` and
 * via direct property access (`$result->newField`). See spec §5.
 */
final class RecognitionResult extends ForwardCompatModel
{
    private const KNOWN = [
        'timecode', 'audio_id', 'artist', 'title', 'album', 'release_date',
        'label', 'song_link', 'apple_music', 'spotify', 'deezer', 'napster', 'musicbrainz',
    ];

    public readonly string $timecode;
    public readonly ?int $audio_id;
    public readonly ?string $artist;
    public readonly ?string $title;
    public readonly ?string $album;
    public readonly ?string $release_date;
    public readonly ?string $label;
    public readonly ?string $song_link;
    public readonly ?AppleMusicMetadata $apple_music;
    public readonly ?SpotifyMetadata $spotify;
    public readonly ?DeezerMetadata $deezer;
    public readonly ?NapsterMetadata $napster;
    /** @var list<MusicBrainzEntry>|null */
    public readonly ?array $musicbrainz;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->timecode = (string) ($payload['timecode'] ?? '');
        $this->audio_id = isset($payload['audio_id']) ? (int) $payload['audio_id'] : null;
        $this->artist = isset($payload['artist']) ? (string) $payload['artist'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->album = isset($payload['album']) ? (string) $payload['album'] : null;
        $this->release_date = isset($payload['release_date']) ? (string) $payload['release_date'] : null;
        $this->label = isset($payload['label']) ? (string) $payload['label'] : null;
        $this->song_link = isset($payload['song_link']) ? (string) $payload['song_link'] : null;
        $this->apple_music = isset($payload['apple_music']) && is_array($payload['apple_music'])
            ? new AppleMusicMetadata($payload['apple_music']) : null;
        $this->spotify = isset($payload['spotify']) && is_array($payload['spotify'])
            ? new SpotifyMetadata($payload['spotify']) : null;
        $this->deezer = isset($payload['deezer']) && is_array($payload['deezer'])
            ? new DeezerMetadata($payload['deezer']) : null;
        $this->napster = isset($payload['napster']) && is_array($payload['napster'])
            ? new NapsterMetadata($payload['napster']) : null;
        if (isset($payload['musicbrainz']) && is_array($payload['musicbrainz'])) {
            $entries = [];
            foreach ($payload['musicbrainz'] as $entry) {
                if (is_array($entry)) {
                    $entries[] = new MusicBrainzEntry($entry);
                }
            }
            $this->musicbrainz = $entries;
        } else {
            $this->musicbrainz = null;
        }
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }

    /**
     * True for custom-catalog matches (audio_id is set).
     */
    public function isCustomMatch(): bool
    {
        return $this->audio_id !== null;
    }

    /**
     * True for AudD public-catalog matches (artist or title set, no audio_id).
     */
    public function isPublicMatch(): bool
    {
        return $this->audio_id === null && ($this->artist !== null || $this->title !== null);
    }

    /**
     * Cover-art image URL: returns `song_link?thumb` only when the song_link host is `lis.tn`.
     * Returns null for YouTube `song_link` values and for custom-DB matches (no song_link).
     */
    public function thumbnailUrl(): ?string
    {
        return self::lisTnRedirect($this->song_link, 'thumb');
    }

    /**
     * Direct or redirect URL for a streaming provider, with smart fallback.
     *
     * Resolution order:
     *  1. Direct URL from the corresponding metadata block (`apple_music.url`,
     *     `spotify.external_urls.spotify`, `deezer.link`, `napster.href`).
     *     Direct = no redirect, faster for clients. Available only when the
     *     user requested that provider via `return=`.
     *  2. lis.tn redirect `"{$songLink}?{$provider->value}"` when `song_link`
     *     is on `lis.tn`. Works regardless of whether `return=` was set.
     *  3. `null` otherwise (e.g., YouTube `song_link` and the user didn't
     *     request the provider's metadata). YouTube has only the lis.tn path.
     *
     * Spec §4.3.
     */
    public function streamingUrl(StreamingProvider $provider): ?string
    {
        $direct = $this->directStreamingUrl($provider);
        if ($direct !== null) {
            return $direct;
        }
        return self::lisTnRedirect($this->song_link, $provider->value);
    }

    /**
     * All providers with a resolvable URL — direct or via lis.tn redirect.
     *
     * Returns an associative array keyed by provider value (`"spotify"`,
     * `"apple_music"`, `"deezer"`, `"napster"`, `"youtube"`). Empty array
     * when neither path resolves for any provider. Spec §4.3.
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

    /**
     * First available 30-second audio preview URL, in priority order:
     *   apple_music.previews[0].url → spotify.preview_url → deezer.preview.
     *
     * Returns null if no metadata block carries a preview.
     *
     * **Note:** previews are governed by their respective providers' terms
     * of use (Apple Music, Spotify, Deezer). The SDK consumer is responsible
     * for honoring those terms — including caching restrictions, attribution
     * requirements, and any redistribution constraints.
     */
    public function previewUrl(): ?string
    {
        // Apple Music: previews is a list of {"url": "..."} entries. Lives in
        // the extras dict because we don't type the previews array directly.
        $am = $this->apple_music;
        if ($am !== null) {
            $previews = $am->extras['previews'] ?? null;
            if (is_array($previews) && $previews !== []) {
                $first = $previews[0] ?? null;
                if (is_array($first)) {
                    $url = $first['url'] ?? null;
                    if (is_string($url) && $url !== '') {
                        return $url;
                    }
                }
            }
        }
        // Spotify: preview_url field directly in extras (not in typed surface).
        $sp = $this->spotify;
        if ($sp !== null) {
            $spurl = $sp->extras['preview_url'] ?? null;
            if (is_string($spurl) && $spurl !== '') {
                return $spurl;
            }
        }
        // Deezer: preview field in extras (not in typed surface).
        $dz = $this->deezer;
        if ($dz !== null) {
            $dzurl = $dz->extras['preview'] ?? null;
            if (is_string($dzurl) && $dzurl !== '') {
                return $dzurl;
            }
        }
        return null;
    }

    /**
     * Pull a direct URL out of the corresponding metadata block, if present.
     */
    private function directStreamingUrl(StreamingProvider $provider): ?string
    {
        switch ($provider) {
            case StreamingProvider::APPLE_MUSIC:
                if ($this->apple_music !== null && is_string($this->apple_music->url) && $this->apple_music->url !== '') {
                    return $this->apple_music->url;
                }
                break;
            case StreamingProvider::SPOTIFY:
                if ($this->spotify !== null) {
                    $extUrls = $this->spotify->extras['external_urls'] ?? null;
                    if (is_array($extUrls)) {
                        $u = $extUrls['spotify'] ?? null;
                        if (is_string($u) && $u !== '') {
                            return $u;
                        }
                    }
                }
                break;
            case StreamingProvider::DEEZER:
                if ($this->deezer !== null && is_string($this->deezer->link) && $this->deezer->link !== '') {
                    return $this->deezer->link;
                }
                break;
            case StreamingProvider::NAPSTER:
                if ($this->napster !== null) {
                    $href = $this->napster->extras['href'] ?? null;
                    if (is_string($href) && $href !== '') {
                        return $href;
                    }
                }
                break;
            case StreamingProvider::YOUTUBE:
                // YouTube has no metadata block; only the lis.tn redirect path.
                break;
        }
        return null;
    }

    /**
     * Returns `"{$songLink}?{$key}"` only when `$songLink` is on `lis.tn`.
     * Used for thumbnails and provider redirects.
     */
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
