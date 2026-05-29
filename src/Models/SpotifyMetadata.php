<?php

declare(strict_types=1);

namespace AudD\Models;

final class SpotifyMetadata extends ForwardCompatModel
{
    private const KNOWN = [
        'id', 'name', 'duration_ms', 'explicit', 'popularity',
        'track_number', 'type', 'uri',
    ];

    public readonly ?string $id;
    public readonly ?string $name;
    public readonly ?int $duration_ms;
    public readonly ?bool $explicit;
    public readonly ?int $popularity;
    public readonly ?int $track_number;
    public readonly ?string $type;
    public readonly ?string $uri;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->id = self::asString($payload['id'] ?? null);
        $this->name = self::asString($payload['name'] ?? null);
        $this->duration_ms = self::asInt($payload['duration_ms'] ?? null);
        $this->explicit = self::asBool($payload['explicit'] ?? null);
        $this->popularity = self::asInt($payload['popularity'] ?? null);
        $this->track_number = self::asInt($payload['track_number'] ?? null);
        $this->type = self::asString($payload['type'] ?? null);
        $this->uri = self::asString($payload['uri'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
