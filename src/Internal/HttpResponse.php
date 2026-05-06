<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Lightweight wrapper over a parsed HTTP response. Used internally by the
 * HttpClient + decoders to avoid carrying PSR-7 ResponseInterface across the
 * retry boundary.
 *
 * @internal
 */
final class HttpResponse
{
    /**
     * @param array<string, mixed>|list<mixed>|null $jsonBody Parsed JSON body, or null on parse failure.
     */
    public function __construct(
        public readonly array|null $jsonBody,
        public readonly int $httpStatus,
        public readonly ?string $requestId,
        public readonly string $rawText,
    ) {
    }
}
