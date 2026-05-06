<?php

declare(strict_types=1);

namespace AudD\Models;

final class LyricsResult extends ForwardCompatModel
{
    private const KNOWN = [
        'artist', 'title', 'lyrics', 'song_id', 'media',
        'full_title', 'artist_id', 'song_link',
    ];

    public readonly string $artist;
    public readonly string $title;
    public readonly ?string $lyrics;
    public readonly ?int $song_id;
    public readonly ?string $media;
    public readonly ?string $full_title;
    public readonly ?int $artist_id;
    public readonly ?string $song_link;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->artist = (string) ($payload['artist'] ?? '');
        $this->title = (string) ($payload['title'] ?? '');
        $this->lyrics = isset($payload['lyrics']) ? (string) $payload['lyrics'] : null;
        $this->song_id = isset($payload['song_id']) ? (int) $payload['song_id'] : null;
        $this->media = isset($payload['media']) ? (string) $payload['media'] : null;
        $this->full_title = isset($payload['full_title']) ? (string) $payload['full_title'] : null;
        $this->artist_id = isset($payload['artist_id']) ? (int) $payload['artist_id'] : null;
        $this->song_link = isset($payload['song_link']) ? (string) $payload['song_link'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
