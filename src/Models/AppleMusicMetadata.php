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
        $this->artistName = self::asString($payload['artistName'] ?? null);
        $this->url = self::asString($payload['url'] ?? null);
        $this->durationInMillis = self::asInt($payload['durationInMillis'] ?? null);
        $this->name = self::asString($payload['name'] ?? null);
        $this->isrc = self::asString($payload['isrc'] ?? null);
        $this->albumName = self::asString($payload['albumName'] ?? null);
        $this->trackNumber = self::asInt($payload['trackNumber'] ?? null);
        $this->composerName = self::asString($payload['composerName'] ?? null);
        $this->discNumber = self::asInt($payload['discNumber'] ?? null);
        $this->releaseDate = self::asString($payload['releaseDate'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
