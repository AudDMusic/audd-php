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
        $this->id = self::asString($payload['id'] ?? null) ?? '';
        $score = $payload['score'] ?? null;
        // MusicBrainz scores arrive as int or numeric string; preserve both,
        // drop anything else (array/object) to null.
        $this->score = (is_int($score) || is_string($score)) ? $score : null;
        $this->title = self::asString($payload['title'] ?? null);
        $this->length = self::asInt($payload['length'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
