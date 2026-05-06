<?php

declare(strict_types=1);

namespace AudD;

/**
 * Inspection event emitted by the SDK request lifecycle.
 *
 * Spec §7.7a. Hooks receive these via the `onEvent` callback passed to
 * `\AudD\AudD::__construct`. Plain readonly data class — never includes the
 * api_token or request body bytes.
 */
final class AudDEvent
{
    /**
     * @param array<string, mixed> $extras Free-form metadata (e.g. error_type
     *                                     for exception events). Never carries
     *                                     api_token or body bytes.
     */
    public function __construct(
        public readonly AudDEventKind $kind,
        /** AudD method name, e.g. "recognize", "addStream". */
        public readonly string $method,
        public readonly string $url,
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
        public readonly ?float $elapsedMs = null,
        public readonly ?int $errorCode = null,
        public readonly array $extras = [],
    ) {
    }
}
