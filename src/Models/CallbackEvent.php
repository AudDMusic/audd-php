<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Discriminated value object returned by Streams::parseCallback /
 * Streams::handleCallback.
 *
 * Exactly one of `$match` or `$notification` is non-null on success.
 */
final class CallbackEvent
{
    public function __construct(
        public readonly ?StreamCallbackMatch $match,
        public readonly ?StreamCallbackNotification $notification,
    ) {
    }

    public function isMatch(): bool
    {
        return $this->match !== null;
    }

    public function isNotification(): bool
    {
        return $this->notification !== null;
    }
}
