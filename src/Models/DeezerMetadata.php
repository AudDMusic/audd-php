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
        $this->id = self::asInt($payload['id'] ?? null);
        $this->title = self::asString($payload['title'] ?? null);
        $this->duration = self::asInt($payload['duration'] ?? null);
        $this->link = self::asString($payload['link'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
