<?php

declare(strict_types=1);

namespace AudD\Models;

final class StreamCallbackNotification extends ForwardCompatModel
{
    private const KNOWN = ['radio_id', 'stream_running', 'notification_code', 'notification_message'];

    public readonly int $radio_id;
    public readonly ?bool $stream_running;
    public readonly int $notification_code;
    public readonly string $notification_message;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->radio_id = (int) ($payload['radio_id'] ?? 0);
        $this->stream_running = isset($payload['stream_running']) ? (bool) $payload['stream_running'] : null;
        $this->notification_code = (int) ($payload['notification_code'] ?? 0);
        $this->notification_message = (string) ($payload['notification_message'] ?? '');
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
