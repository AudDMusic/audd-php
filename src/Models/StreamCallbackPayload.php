<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Wrapper over a callback payload posted to the user's webhook endpoint.
 * Discriminates between a recognition result and a stream notification.
 */
final class StreamCallbackPayload
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public readonly ?StreamCallbackResult $result,
        public readonly ?StreamCallbackNotification $notification,
        public readonly ?int $time,
        public readonly array $rawPayload,
    ) {
    }

    public function isResult(): bool
    {
        return $this->result !== null;
    }

    public function isNotification(): bool
    {
        return $this->notification !== null;
    }

    /**
     * Parse a callback POST body into the typed payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function parse(array $payload): self
    {
        if (isset($payload['notification']) && is_array($payload['notification'])) {
            return new self(
                result: null,
                notification: new StreamCallbackNotification($payload['notification']),
                time: isset($payload['time']) ? (int) $payload['time'] : null,
                rawPayload: $payload,
            );
        }
        $inner = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];
        return new self(
            result: new StreamCallbackResult($inner),
            notification: null,
            time: null,
            rawPayload: $payload,
        );
    }
}
