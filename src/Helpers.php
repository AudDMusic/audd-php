<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDInvalidRequestException;
use AudD\Models\StreamCallbackPayload;

/**
 * Pure helpers used by streams.* and webhook handlers. No HTTP, no SDK state.
 */
final class Helpers
{
    /**
     * Compute the 9-character longpoll category locally from token + radio_id.
     *
     * Formula (per docs.audd.io/streams.md): hex-MD5 of (hex-MD5 of api_token,
     * concatenated with the radio_id rendered as a decimal string), truncated
     * to the first 9 hex chars.
     */
    public static function deriveLongpollCategory(string $apiToken, int $radioId): string
    {
        $inner = md5($apiToken);
        $full = md5($inner . (string) $radioId);
        return substr($full, 0, 9);
    }

    /**
     * Parse a callback POST body into a typed StreamCallbackPayload.
     *
     * @param array<string, mixed> $body
     */
    public static function parseCallback(array $body): StreamCallbackPayload
    {
        return StreamCallbackPayload::parse($body);
    }

    /**
     * Append `?return=<metadata>` (or merge as `&return=`) to a callback URL.
     *
     * If the URL already has a `return` query parameter, raises an
     * AudDInvalidRequestException — refusing to silently overwrite conflicting
     * intent.
     *
     * @param string|list<string>|null $returnMetadata
     */
    public static function addReturnToUrl(string $url, string|array|null $returnMetadata): string
    {
        if ($returnMetadata === null) {
            return $url;
        }
        $metadata = is_array($returnMetadata) ? implode(',', $returnMetadata) : $returnMetadata;

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new AudDInvalidRequestException(
                errorCode: 0,
                apiMessage: 'Cannot parse callback URL',
                httpStatus: 0,
                requestId: null,
            );
        }
        $query = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        if (isset($query['return'])) {
            throw new AudDInvalidRequestException(
                errorCode: 0,
                apiMessage: 'URL already contains a `return` query parameter; pass returnMetadata=null '
                    . 'or remove the parameter from the URL — refusing to silently overwrite.',
                httpStatus: 0,
                requestId: null,
            );
        }
        $query['return'] = $metadata;
        $parts['query'] = http_build_query($query);

        return self::unparseUrl($parts);
    }

    /**
     * @param array{
     *   scheme?: string, host?: string, port?: int, user?: string, pass?: string,
     *   path?: string, query?: string, fragment?: string
     * } $parts
     */
    private static function unparseUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = ($user || $pass !== '') ? $user . $pass . '@' : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
