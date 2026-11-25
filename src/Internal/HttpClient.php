<?php

declare(strict_types=1);

namespace AudD\Internal;

use AudD\Errors\AudDConnectionException;
use AudD\Version;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
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
 * does — preventing connection-pool leaks via __destruct.
 *
 * Timeouts: every request carries `connect_timeout` and `read_timeout`. The
 * read timeout is how long the transfer may go without receiving data. Guzzle's
 * cURL handler ignores `read_timeout`, so the same limit is also sent as cURL's
 * low-speed limit (abort when under 1 byte/s for that many seconds). Both reach
 * an injected Guzzle client as per-request options; `curl` options configured on
 * that client are kept and win on collision. A non-Guzzle PSR-18 client has no
 * per-request timeout channel and uses its own settings.
 *
 * @internal
 */
final class HttpClient
{
    private readonly ClientInterface $client;

    /** True when the client was constructed by us (and we own its lifetime). */
    private readonly bool $owned;

    private bool $closed = false;

    /** Owned clients only: event loop and client for cancellable GETs. */
    private ?CurlMultiHandler $multi = null;
    private ?GuzzleClient $cancellableClient = null;

    /** Mutable: rotated by AudD::setApiToken() at runtime. */
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
                RequestOptions::TIMEOUT => 0, // no total limit; connect + read timeouts are set per request
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
     * GET with params merged into the query string.
     *
     * The api_token is appended only when `$sendToken` is true and the token is
     * non-empty. Pass `sendToken: false` for endpoints that must not receive the
     * token in the query string (e.g. the longpoll endpoint, which authorizes
     * via the derived category alone and rejects a token parameter).
     *
     * `$cancel` is polled about once a second while the request waits; when it
     * returns true the request is abandoned and an AudDConnectionException is
     * thrown. This needs the SDK's own Guzzle client and ext-curl; with an
     * injected client the request runs to completion and `$cancel` is ignored.
     *
     * @param array<string, scalar>  $params
     * @param (\Closure(): bool)|null $cancel
     */
    public function get(
        string $url,
        array $params,
        ?float $perCallTimeout = null,
        bool $sendToken = true,
        ?\Closure $cancel = null,
    ): HttpResponse {
        $full = $params;
        if ($sendToken && !isset($full['api_token']) && $this->apiToken !== '') {
            $full['api_token'] = $this->apiToken;
        }
        $options = $this->baseOptions($perCallTimeout);
        $options[RequestOptions::QUERY] = $full;

        if ($cancel !== null) {
            $client = $this->cancellableClient();
            if ($client !== null && $this->multi !== null) {
                return $this->executeCancellable($client, $this->multi, $url, $options, $cancel);
            }
        }

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
        $readTimeout = $perCallTimeout ?? $this->readTimeout;
        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::READ_TIMEOUT => $readTimeout,
            'headers' => ['User-Agent' => Version::userAgent()],
        ];
        $curl = $this->curlOptions($readTimeout);
        if ($curl !== []) {
            $options['curl'] = $curl;
        }
        return $options;
    }

    /**
     * cURL-level options: the read timeout as a low-speed limit, TCP keepalive
     * for long enterprise uploads, then whatever `curl` options an injected
     * Guzzle client was configured with (a per-request `curl` array replaces
     * the client's, so they are merged back in here).
     *
     * @return array<int, mixed>
     */
    private function curlOptions(float $readTimeout): array
    {
        if (!\extension_loaded('curl')) {
            return [];
        }
        $curl = [];
        if ($readTimeout > 0) {
            $curl[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $curl[CURLOPT_LOW_SPEED_TIME] = max(1, (int) ceil($readTimeout));
        }
        // TCP keepalive defense for long-running enterprise uploads.
        if ($this->readTimeout > 120) {
            $curl[CURLOPT_TCP_KEEPALIVE] = 1;
            $curl[CURLOPT_TCP_KEEPIDLE] = 30;
            $curl[CURLOPT_TCP_KEEPINTVL] = 30;
        }
        if ($this->client instanceof GuzzleClient) {
            $configured = $this->client->getConfig('curl');
            if (is_array($configured)) {
                $curl = array_replace($curl, $configured);
            }
        }
        return $curl;
    }

    /**
     * Lazily build the Guzzle client used for cancellable GETs. Only for
     * clients this wrapper owns, and only when ext-curl is available.
     */
    private function cancellableClient(): ?GuzzleClient
    {
        if (!$this->owned || !\function_exists('curl_multi_init')) {
            return null;
        }
        if ($this->cancellableClient === null) {
            $this->multi = new CurlMultiHandler();
            $this->cancellableClient = new GuzzleClient([
                'handler' => HandlerStack::create($this->multi),
                RequestOptions::HTTP_ERRORS => false,
                'headers' => ['User-Agent' => Version::userAgent()],
            ]);
        }
        return $this->cancellableClient;
    }

    /**
     * Drive one request on the multi handler, checking `$cancel` between
     * event-loop ticks (each tick waits at most about a second). Runs PHP code
     * between ticks, so `pcntl` signal handlers get to run too.
     *
     * @param array<string, mixed> $options
     * @param \Closure(): bool     $cancel
     */
    private function executeCancellable(
        GuzzleClient $client,
        CurlMultiHandler $multi,
        string $url,
        array $options,
        \Closure $cancel,
    ): HttpResponse {
        $promise = $client->requestAsync('GET', $url, $options);
        while ($promise->getState() === PromiseInterface::PENDING) {
            if ($cancel()) {
                $promise->cancel();
                throw new AudDConnectionException('Request cancelled');
            }
            $multi->tick();
        }
        try {
            $resp = $promise->wait();
        } catch (\Throwable $exc) {
            if ($exc instanceof TransferException) {
                throw $exc;
            }
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }
        if (!$resp instanceof ResponseInterface) {
            throw new AudDConnectionException('HTTP client returned no response');
        }
        return self::wrap($resp);
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
