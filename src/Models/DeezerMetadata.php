<?php

declare(strict_types=1);

namespace AudD\Models;

final class DeezerMetadata extends ForwardCompatModel
{
    private const KNOWN = ['id', 'title', 'duration', 'link'];

    public readonly ?int $id;
    public readonly ?string $title;
    public readonly ?int $duration;
    public readonly ?string $link;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->id = isset($payload['id']) ? (int) $payload['id'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->duration = isset($payload['duration']) ? (int) $payload['duration'] : null;
        $this->link = isset($payload['link']) ? (string) $payload['link'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
