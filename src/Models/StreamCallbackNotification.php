<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Lifecycle-event variant of a stream callback (e.g. "stream stopped",
 * "can't connect"). The notification block lives under `notification.*`;
 * the outer body carries an additional `time` field which the constructor
 * accepts via the inner-array `__time` slot or — preferred — via the
 * `$outerTime` constructor argument.
 */
final class StreamCallbackNotification extends ForwardCompatModel
{
    private const KNOWN = ['radio_id', 'stream_running', 'notification_code', 'notification_message'];

    public readonly int $radio_id;
    public readonly ?bool $stream_running;
    public readonly int $notification_code;
    public readonly string $notification_message;
    /** Outer `time` field on the callback body (unix seconds), if present. */
    public readonly ?int $time;

    /**
     * @param array<string, mixed> $payload   The inner `notification` object.
     * @param int|null             $outerTime The outer `time` field, when known.
     */
    public function __construct(array $payload, ?int $outerTime = null)
    {
        $this->radio_id = (int) ($payload['radio_id'] ?? 0);
        $this->stream_running = isset($payload['stream_running']) ? (bool) $payload['stream_running'] : null;
        $this->notification_code = (int) ($payload['notification_code'] ?? 0);
        $this->notification_message = (string) ($payload['notification_message'] ?? '');
        $this->time = $outerTime;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
