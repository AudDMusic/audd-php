<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * One configured real-time stream as returned by `getStreams`.
 */
final class Stream extends ForwardCompatModel
{
    private const KNOWN = ['radio_id', 'url', 'stream_running', 'longpoll_category'];

    public readonly int $radio_id;
    public readonly string $url;
    public readonly bool $stream_running;
    public readonly ?string $longpoll_category;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->radio_id = self::asInt($payload['radio_id'] ?? null) ?? 0;
        $this->url = self::asString($payload['url'] ?? null) ?? '';
        $this->stream_running = self::asBool($payload['stream_running'] ?? null) ?? false;
        $this->longpoll_category = self::asString($payload['longpoll_category'] ?? null);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
