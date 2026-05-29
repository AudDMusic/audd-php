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
        $this->artist = self::asString($payload['artist'] ?? null) ?? '';
        $this->title = self::asString($payload['title'] ?? null) ?? '';
        $this->lyrics = self::asString($payload['lyrics'] ?? null);
        $this->song_id = self::asInt($payload['song_id'] ?? null);
        $this->media = self::asString($payload['media'] ?? null);
        $this->full_title = self::asString($payload['full_title'] ?? null);
        $this->artist_id = self::asInt($payload['artist_id'] ?? null);
        $this->song_link = self::asString($payload['song_link'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
