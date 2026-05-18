<?php

declare(strict_types=1);

namespace AudD\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * Source-type discriminator + per-attempt re-opener for retry safety.
 *
 * Auto-detects what kind of audio source the caller passed and converts to
 * the right multipart fields. Returns a *re-opener* — a 0-arg callable that
 * yields fresh form-data on each retry attempt.
 *
 * Why a re-opener? Guzzle's RequestBody is consumed once when the request is
 * sent. On retry, a Body that's already been read would yield zero bytes.
 * The re-opener ensures every retry attempt starts from a fresh body.
 *
 * Source types:
 *   - URL string ("http(s)://..."):    routed via `data['url']` field
 *   - file path string (existing file): routed via `multipart` 'file' part
 *   - StreamInterface (PSR-7 stream):  routed via `multipart` 'file' part
 *   - resource (PHP stream):           routed via `multipart` 'file' part
 *   - raw bytes (string starting with non-URL prefix is treated as path then bytes)
 *   - bytes wrapped via SourceBytes::raw($bytes) for unambiguous raw-bytes intent
 *
 * Unseekable streams: a retry will fail loudly rather than silently sending an
 * empty body.
 *
 * @internal
 */
final class Source
{
    /**
     * Wrap a string argument so the caller can declare "this is raw bytes,
     * not a URL or path" unambiguously.
     */
    public static function bytes(string $bytes): SourceBytes
    {
        return new SourceBytes($bytes);
    }

    /**
     * Build a re-opener for a given source. Each invocation of the returned
     * closure yields fresh `(data, multipart)` arrays suitable for handing
     * to Guzzle.
     *
     * @return \Closure():array{0: array<string, scalar>, 1: list<array<string, mixed>>|null}
     *
     * @phpstan-param string|StreamInterface|SourceBytes|resource $source
     */
    public static function prepare(mixed $source): \Closure
    {
        // URL string.
        if (is_string($source) && self::looksLikeUrl($source)) {
            $url = $source;
            return static function () use ($url): array {
                return [['url' => $url], null];
            };
        }

        // String arg: try filesystem path next.
        if (is_string($source)) {
            if (is_file($source)) {
                $path = $source;
                $name = basename($path);
                return static function () use ($path, $name): array {
                    $fh = fopen($path, 'rb');
                    if ($fh === false) {
                        throw new \RuntimeException("Could not open file for upload: {$path}");
                    }
                    return [[], [[
                        'name' => 'file',
                        'contents' => $fh,
                        'filename' => $name,
                        'headers' => ['Content-Type' => 'application/octet-stream'],
                    ]]];
                };
            }
            // Not a URL, not an existing file → most likely a typo. Don't
            // silently treat as raw bytes (which would yield a 700 from the API
            // and confuse the user); instead raise a descriptive error.
            throw new \InvalidArgumentException(
                sprintf(
                    '%s is neither an HTTP URL (must start with http:// or https://) nor an existing '
                    . 'file path. To pass raw bytes, wrap them with AudD\\Internal\\Source::bytes(\$bytes).',
                    self::truncate($source),
                ),
            );
        }

        // Explicit raw-bytes wrapper.
        if ($source instanceof SourceBytes) {
            $buf = $source->bytes;
            return static function () use ($buf): array {
                return [[], [[
                    'name' => 'file',
                    'contents' => $buf,
                    'filename' => 'upload.bin',
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                ]]];
            };
        }

        // PSR-7 stream.
        if ($source instanceof StreamInterface) {
            $start = null;
            $seekable = $source->isSeekable();
            if ($seekable) {
                try {
                    $start = $source->tell();
                } catch (\Throwable) {
                    $seekable = false;
                }
            }
            $firstCall = true;
            return static function () use ($source, &$firstCall, $seekable, $start): array {
                if (!$firstCall) {
                    if (!$seekable || $start === null) {
                        throw new \RuntimeException(
                            'Cannot retry an unseekable PSR-7 stream source. Buffer the content '
                            . 'as raw bytes via AudD\\Internal\\Source::bytes(...) instead.',
                        );
                    }
                    $source->seek($start);
                }
                $firstCall = false;
                return [[], [[
                    'name' => 'file',
                    'contents' => $source,
                    'filename' => 'upload.bin',
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                ]]];
            };
        }

        // PHP resource (e.g., fopen result).
        if (is_resource($source)) {
            $fl = $source;
            $meta = stream_get_meta_data($fl);
            $seekable = (bool) $meta['seekable'];
            $start = null;
            if ($seekable) {
                $tell = ftell($fl);
                if ($tell !== false) {
                    $start = $tell;
                }
            }
            $firstCall = true;
            return static function () use ($fl, &$firstCall, $seekable, $start): array {
                if (!$firstCall) {
                    if (!$seekable || $start === null) {
                        throw new \RuntimeException(
                            'Cannot retry an unseekable resource source. Buffer the content as '
                            . 'raw bytes via AudD\\Internal\\Source::bytes(...) instead.',
                        );
                    }
                    fseek($fl, $start);
                }
                $firstCall = false;
                return [[], [[
                    'name' => 'file',
                    'contents' => $fl,
                    'filename' => 'upload.bin',
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                ]]];
            };
        }

        throw new \InvalidArgumentException(
            'Unsupported source type: pass a URL string, file path, PSR-7 StreamInterface, '
            . 'PHP resource, or wrap raw bytes via AudD\\Internal\\Source::bytes(...).',
        );
    }

    private static function looksLikeUrl(string $s): bool
    {
        return str_starts_with($s, 'http://') || str_starts_with($s, 'https://');
    }

    private static function truncate(string $s, int $max = 80): string
    {
        if (strlen($s) <= $max) {
            return var_export($s, true);
        }
        return var_export(substr($s, 0, $max) . '…', true);
    }
}
