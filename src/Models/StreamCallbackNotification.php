<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Lifecycle-event variant of a stream callback (e.g. "stream stopped",
 * "can't connect"). The notification fields live under `notification.*`; the
 * outer callback body carries an additional `time` field (unix seconds), which
 * the constructor receives via the `$outerTime` argument and exposes as the
 * public `$time` property.
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
        $this->radio_id = self::asInt($payload['radio_id'] ?? null) ?? 0;
        $this->stream_running = self::asBool($payload['stream_running'] ?? null);
        $this->notification_code = self::asInt($payload['notification_code'] ?? null) ?? 0;
        $this->notification_message = self::asString($payload['notification_message'] ?? null) ?? '';
        $this->time = $outerTime;
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
