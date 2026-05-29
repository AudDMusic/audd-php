<?php

declare(strict_types=1);

namespace AudD\Models;

final class NapsterMetadata extends ForwardCompatModel
{
    private const KNOWN = ['id', 'name', 'isrc', 'artistName', 'albumName'];

    public readonly ?string $id;
    public readonly ?string $name;
    public readonly ?string $isrc;
    public readonly ?string $artistName;
    public readonly ?string $albumName;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->id = self::asString($payload['id'] ?? null);
        $this->name = self::asString($payload['name'] ?? null);
        $this->isrc = self::asString($payload['isrc'] ?? null);
        $this->artistName = self::asString($payload['artistName'] ?? null);
        $this->albumName = self::asString($payload['albumName'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
