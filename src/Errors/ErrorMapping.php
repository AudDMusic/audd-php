<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * AudD numeric error code → exception class lookup, plus the helper that
 * raises the right typed exception from a `status: error` response body.
 *
 * @internal
 */
final class ErrorMapping
{
    /**
     * @return class-string<AudDApiException>
     */
    public static function classForCode(int $code): string
    {
        return match ($code) {
            900, 901, 903 => AudDAuthenticationException::class,
            902 => AudDQuotaException::class,
            904, 905 => AudDSubscriptionException::class,
            50, 51, 600, 601, 602, 700, 701, 702, 906 => AudDInvalidRequestException::class,
            300, 400, 500 => AudDInvalidAudioException::class,
            610 => AudDStreamLimitException::class,
            611 => AudDRateLimitException::class,
            907 => AudDNotReleasedException::class,
            19, 31337 => AudDBlockedException::class,
            20 => AudDNeedsUpdateException::class,
            100, 1000 => AudDServerException::class,
            default => AudDServerException::class,
        };
    }

    /**
     * Extract branded artist/title text from an error response's `result`,
     * if present. Used to surface server-side denial branding (e.g., IP ban
     * notices) on the exception's `brandedMessage` field, never on a result.
     *
     * @param mixed $result
     */
    public static function brandedMessage(mixed $result): ?string
    {
        if (!is_array($result)) {
            return null;
        }
        $artist = $result['artist'] ?? null;
        $title = $result['title'] ?? null;
        if (!$artist && !$title) {
            return null;
        }
        $parts = array_filter([$artist, $title], static fn ($p): bool => $p !== null && $p !== '');
        return implode(' — ', array_map(strval(...), $parts));
    }

    /**
     * Inspect a server `status: error` body and throw the appropriate exception.
     *
     * @param array<string, mixed> $body
     *
     * @throws AudDApiException always
     */
    public static function raiseFromErrorResponse(
        array $body,
        int $httpStatus,
        ?string $requestId,
        bool $customCatalogContext = false,
    ): never {
        $err = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = (int) ($err['error_code'] ?? 0);
        $message = (string) ($err['error_message'] ?? '');
        $rawRequested = $body['request_params'] ?? $body['requested_params'] ?? [];
        $requestedParams = is_array($rawRequested) ? $rawRequested : [];
        $requestMethod = isset($body['request_api_method']) && is_string($body['request_api_method'])
            ? $body['request_api_method'] : null;
        $branded = self::brandedMessage($body['result'] ?? null);

        $cls = self::classForCode($code);
        if ($customCatalogContext && $cls === AudDSubscriptionException::class) {
            throw new AudDCustomCatalogAccessException(
                errorCode: $code,
                serverMessage: $message,
                httpStatus: $httpStatus,
                requestId: $requestId,
                requestedParams: $requestedParams,
                requestMethod: $requestMethod,
                brandedMessage: $branded,
                rawResponse: $body,
            );
        }
        throw new $cls(
            errorCode: $code,
            apiMessage: $message,
            httpStatus: $httpStatus,
            requestId: $requestId,
            requestedParams: $requestedParams,
            requestMethod: $requestMethod,
            brandedMessage: $branded,
            rawResponse: $body,
        );
    }
}
