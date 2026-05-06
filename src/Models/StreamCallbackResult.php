<?php

declare(strict_types=1);

namespace AudD\Models;

final class StreamCallbackResult extends ForwardCompatModel
{
    private const KNOWN = ['radio_id', 'timestamp', 'play_length', 'results'];

    public readonly int $radio_id;
    public readonly ?string $timestamp;
    public readonly ?int $play_length;
    /** @var list<StreamCallbackResultEntry> */
    public readonly array $results;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->radio_id = (int) ($payload['radio_id'] ?? 0);
        $this->timestamp = isset($payload['timestamp']) ? (string) $payload['timestamp'] : null;
        $this->play_length = isset($payload['play_length']) ? (int) $payload['play_length'] : null;
        $entries = [];
        if (isset($payload['results']) && is_array($payload['results'])) {
            foreach ($payload['results'] as $entry) {
                if (is_array($entry)) {
                    $entries[] = new StreamCallbackResultEntry($entry);
                }
            }
        }
        $this->results = $entries;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
