<?php

declare(strict_types=1);

namespace AudD\Internal;

use AudD\Errors\AudDConnectionException;
use AudD\Version;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP transport. Wraps either a Guzzle client (default) or any PSR-18 client
 * (for proxy/mTLS injection) and exposes uniform `postForm` / `postMultipart`
 * / `get` helpers that always inject the api_token and User-Agent.
 *
 * Forms are sent as application/x-www-form-urlencoded for token-only requests
 * (streams management, longpoll) and as multipart/form-data when audio data is
 * being uploaded.
 *
 * Owned-vs-injected: when the caller passes a PSR-18 client, the wrapper does
 * NOT close it on `close()`. When it manufactures a Guzzle client itself, it
 * does — preventing connection-pool leaks via __destruct. Design spec §7.4a.
 *
 * @internal
 */
final class HttpClient
{
    private readonly ClientInterface $client;

    /** True when the client was constructed by us (and we own its lifetime). */
    private readonly bool $owned;

    private bool $closed = false;

    /** Mutable: rotated by AudD::setApiToken() at runtime. Spec §7.10. */
    private string $apiToken;

    public function __construct(
        string $apiToken,
        private readonly float $connectTimeout = Timeouts::DEFAULT_CONNECT,
        private readonly float $readTimeout = Timeouts::DEFAULT_READ,
        ?ClientInterface $client = null,
    ) {
        $this->apiToken = $apiToken;
        if ($client === null) {
            $this->client = new GuzzleClient([
                RequestOptions::CONNECT_TIMEOUT => $connectTimeout,
                RequestOptions::READ_TIMEOUT => $readTimeout,
                RequestOptions::TIMEOUT => 0, // we honor read+connect explicitly
                RequestOptions::HTTP_ERRORS => false, // we surface non-2xx ourselves
                'headers' => ['User-Agent' => Version::userAgent()],
            ]);
            $this->owned = true;
        } else {
            $this->client = $client;
            $this->owned = false;
        }
    }

    /**
     * POST application/x-www-form-urlencoded with api_token always injected.
     *
     * @param array<string, scalar> $data
     */
    public function postForm(string $url, array $data, ?float $perCallTimeout = null): HttpResponse
    {
        $full = $data;
        $full['api_token'] = $this->apiToken;
        $options = $this->baseOptions($perCallTimeout);
        $options[RequestOptions::FORM_PARAMS] = $full;

        return $this->execute('POST', $url, $options);
    }

    /**
     * POST multipart/form-data with api_token always injected (as a regular field).
     *
     * @param array<string, scalar>      $data
     * @param list<array<string, mixed>> $files Multipart parts already shaped for Guzzle.
     */
    public function postMultipart(
        string $url,
        array $data,
        array $files,
        ?float $perCallTimeout = null,
    ): HttpResponse {
        $multipart = [];
        foreach ($data as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => (string) $value];
        }
        $multipart[] = ['name' => 'api_token', 'contents' => $this->apiToken];
        foreach ($files as $part) {
            $multipart[] = $part;
        }

        $options = $this->baseOptions($perCallTimeout);
        $options[RequestOptions::MULTIPART] = $multipart;

        return $this->execute('POST', $url, $options);
    }

    /**
     * GET with api_token + params merged into the query string.
     *
     * If apiToken is the empty string (tokenless mode used by LongpollConsumer),
     * no api_token is added to the query.
     *
     * @param array<string, scalar> $params
     */
    public function get(string $url, array $params, ?float $perCallTimeout = null): HttpResponse
    {
        $full = $params;
        if (!isset($full['api_token']) && $this->apiToken !== '') {
            $full['api_token'] = $this->apiToken;
        }
        $options = $this->baseOptions($perCallTimeout);
        $options[RequestOptions::QUERY] = $full;

        return $this->execute('GET', $url, $options);
    }

    /**
     * Rotate the api_token used for subsequent requests. Empty token is allowed
     * here (the tokenless LongpollConsumer uses it). The AudD::setApiToken
     * caller validates non-empty for the user-facing path.
     */
    public function setApiToken(string $newToken): void
    {
        $this->apiToken = $newToken;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        // Guzzle 7 has no explicit close; dropping the reference is enough.
        // PSR-18 has no Closeable concept either. We mark closed so __destruct
        // doesn't double-act.
    }

    public function __destruct()
    {
        if ($this->owned) {
            $this->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOptions(?float $perCallTimeout): array
    {
        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::READ_TIMEOUT => $perCallTimeout ?? $this->readTimeout,
            'headers' => ['User-Agent' => Version::userAgent()],
        ];
        // TCP keepalive defense for long-running enterprise uploads. Guzzle
        // doesn't expose a knob for this directly, so we set the cURL option.
        if ($this->readTimeout > 120) {
            $options['curl'] = [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 30,
                CURLOPT_TCP_KEEPINTVL => 30,
            ];
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(string $method, string $url, array $options): HttpResponse
    {
        try {
            // Guzzle's request() honors options; for a generic PSR-18 client we
            // fall back to constructing a request — but in practice users who
            // inject a PSR-18 client also wrap it with Guzzle's higher-level
            // shape (the simplest path for transport injection). To keep the
            // dispatch consistent, we call the Guzzle-specific request() if
            // available, else delegate to PSR-18.
            if ($this->client instanceof GuzzleClient) {
                $resp = $this->client->request($method, $url, $options);
            } else {
                $resp = $this->client->sendRequest(
                    PsrRequestBuilder::build($method, $url, $options),
                );
            }
        } catch (TransferException $exc) {
            throw $exc;
        } catch (\Throwable $exc) {
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }

        return self::wrap($resp);
    }

    public static function wrap(ResponseInterface $resp): HttpResponse
    {
        $body = (string) $resp->getBody();
        $parsed = self::tryDecodeJson($body);
        $requestId = null;
        $hdr = $resp->getHeader('x-request-id');
        if ($hdr !== []) {
            $requestId = $hdr[0];
        } else {
            $hdr = $resp->getHeader('X-Request-ID');
            if ($hdr !== []) {
                $requestId = $hdr[0];
            }
        }
        return new HttpResponse(
            jsonBody: $parsed,
            httpStatus: $resp->getStatusCode(),
            requestId: $requestId,
            rawText: $body,
        );
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public static function tryDecodeJson(string $body): array|null
    {
        if ($body === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    public static function ensureStreamUploadable(StreamInterface $stream): bool
    {
        return $stream->isReadable();
    }
}
