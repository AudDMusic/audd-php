<?php

declare(strict_types=1);

namespace AudD\Internal;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * Build a PSR-7 request from Guzzle-style options for the case where a user
 * injected their own PSR-18 client.
 *
 * @internal
 */
final class PsrRequestBuilder
{
    /**
     * @param array<string, mixed> $options
     */
    public static function build(string $method, string $url, array $options): Request
    {
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }
        $body = null;

        if (isset($options[RequestOptions::QUERY]) && is_array($options[RequestOptions::QUERY])) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($options[RequestOptions::QUERY]);
        }

        if (isset($options[RequestOptions::FORM_PARAMS]) && is_array($options[RequestOptions::FORM_PARAMS])) {
            $body = http_build_query($options[RequestOptions::FORM_PARAMS]);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (isset($options[RequestOptions::MULTIPART]) && is_array($options[RequestOptions::MULTIPART])) {
            // For PSR-18 fallback, build a multipart stream via Guzzle's helper.
            $multipart = new \GuzzleHttp\Psr7\MultipartStream($options[RequestOptions::MULTIPART]);
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $multipart->getBoundary();
            $body = $multipart;
        }

        /** @var array<string, string|list<string>> $headers */
        return new Request($method, $url, $headers, $body);
    }
}
