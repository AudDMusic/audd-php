<?php

declare(strict_types=1);

namespace AudD\Models;

final class MusicBrainzEntry extends ForwardCompatModel
{
    private const KNOWN = ['id', 'score', 'title', 'length'];

    public readonly string $id;
    public readonly int|string|null $score;
    public readonly ?string $title;
    public readonly ?int $length;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->id = (string) ($payload['id'] ?? '');
        /** @var int|string|null $score */
        $score = $payload['score'] ?? null;
        $this->score = $score;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->length = isset($payload['length']) ? (int) $payload['length'] : null;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
