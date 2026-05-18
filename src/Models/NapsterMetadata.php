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
        $this->id = isset($payload['id']) ? (string) $payload['id'] : null;
        $this->name = isset($payload['name']) ? (string) $payload['name'] : null;
        $this->isrc = isset($payload['isrc']) ? (string) $payload['isrc'] : null;
        $this->artistName = isset($payload['artistName']) ? (string) $payload['artistName'] : null;
        $this->albumName = isset($payload['albumName']) ? (string) $payload['albumName'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
