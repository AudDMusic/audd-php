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
        $this->id = isset($payload['id']) ? (string) $payload['id'] : null;
        $this->name = isset($payload['name']) ? (string) $payload['name'] : null;
        $this->duration_ms = isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null;
        $this->explicit = isset($payload['explicit']) ? (bool) $payload['explicit'] : null;
        $this->popularity = isset($payload['popularity']) ? (int) $payload['popularity'] : null;
        $this->track_number = isset($payload['track_number']) ? (int) $payload['track_number'] : null;
        $this->type = isset($payload['type']) ? (string) $payload['type'] : null;
        $this->uri = isset($payload['uri']) ? (string) $payload['uri'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
