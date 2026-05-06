<?php

declare(strict_types=1);

namespace AudD\Models;

final class AppleMusicMetadata extends ForwardCompatModel
{
    private const KNOWN = [
        'artistName', 'url', 'durationInMillis', 'name', 'isrc',
        'albumName', 'trackNumber', 'composerName', 'discNumber', 'releaseDate',
    ];

    public readonly ?string $artistName;
    public readonly ?string $url;
    public readonly ?int $durationInMillis;
    public readonly ?string $name;
    public readonly ?string $isrc;
    public readonly ?string $albumName;
    public readonly ?int $trackNumber;
    public readonly ?string $composerName;
    public readonly ?int $discNumber;
    public readonly ?string $releaseDate;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->artistName = isset($payload['artistName']) ? (string) $payload['artistName'] : null;
        $this->url = isset($payload['url']) ? (string) $payload['url'] : null;
        $this->durationInMillis = isset($payload['durationInMillis']) ? (int) $payload['durationInMillis'] : null;
        $this->name = isset($payload['name']) ? (string) $payload['name'] : null;
        $this->isrc = isset($payload['isrc']) ? (string) $payload['isrc'] : null;
        $this->albumName = isset($payload['albumName']) ? (string) $payload['albumName'] : null;
        $this->trackNumber = isset($payload['trackNumber']) ? (int) $payload['trackNumber'] : null;
        $this->composerName = isset($payload['composerName']) ? (string) $payload['composerName'] : null;
        $this->discNumber = isset($payload['discNumber']) ? (int) $payload['discNumber'] : null;
        $this->releaseDate = isset($payload['releaseDate']) ? (string) $payload['releaseDate'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
