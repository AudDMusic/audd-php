<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDConfigurationException;
use AudD\Errors\AudDConnectionException;
use AudD\Internal\HttpClient;
use AudD\Internal\ResponseDecoder;
use AudD\Internal\Retry;
use AudD\Internal\RetryClass;
use AudD\Internal\Source;
use AudD\Internal\SourceBytes;
use AudD\Internal\Timeouts;
use AudD\Models\EnterpriseMatch;
use AudD\Models\RecognitionResult;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main client for the AudD music recognition API.
 *
 * Quickstart:
 * ```php
 * $audd = new \AudD\AudD(apiToken: 'test');
 * $r = $audd->recognize('https://audd.tech/example.mp3');
 * if ($r !== null) {
 *     echo $r->artist . ' — ' . $r->title;
 * }
 * ```
 *
 * Resource cleanup: `__destruct` closes the underlying Guzzle client when
 * owned by this instance. Call `$audd->close()` explicitly for early disposal.
 */
final class AudD
{
    private const API_BASE = 'https://api.audd.io';
    private const ENTERPRISE_BASE = 'https://enterprise.audd.io';

    /**
     * Environment variable consulted when apiToken is null/empty. Spec §7.11.
     */
    public const TOKEN_ENV_VAR = 'AUDD_API_TOKEN';

    private readonly HttpClient $http;
    private readonly HttpClient $enterpriseHttp;

    /** Mutable: rotated by setApiToken(). Read via getApiToken(). */
    private string $apiTokenValue;

    public readonly int $maxRetries;
    public readonly float $backoffFactor;

    /** @var \Closure(AudDEvent): void|null */
    private readonly ?\Closure $onEvent;

    private readonly LoggerInterface $logger;

    private ?Streams $streamsNamespace = null;
    private ?CustomCatalog $customCatalogNamespace = null;
    private ?Advanced $advancedNamespace = null;

    /**
     * Construct an AudD client.
     *
     * The api_token may be omitted (`null` or empty string); in that case the
     * SDK reads the `AUDD_API_TOKEN` environment variable. If neither is set,
     * an `AudDConfigurationException` is thrown — pointing the user to
     * https://dashboard.audd.io for a token. Spec §7.11.
     *
     * @param \Closure(AudDEvent): void|null $onEvent Optional inspection hook
     *  invoked with `Request`/`Response`/`Exception` events around every
     *  recognize / recognizeEnterprise call. Hook exceptions are swallowed and
     *  routed through `$logger->debug(...)`. The event payload never includes
     *  the api_token or request body bytes. Spec §7.7a.
     * @param LoggerInterface|null $logger Optional PSR-3 logger. When supplied
     *  the SDK emits diagnostic records — onEvent hook failures at `debug`
     *  level, deprecated-parameter (server code 51) notices at `warning`
     *  level. Defaults to `Psr\Log\NullLogger` (silent). The api_token is
     *  never written to the message or context.
     */
    public function __construct(
        ?string $apiToken = null,
        int $maxRetries = 3,
        float $backoffFactor = 0.5,
        ?ClientInterface $httpClient = null,
        ?\Closure $onEvent = null,
        ?LoggerInterface $logger = null,
    ) {
        $resolved = self::resolveToken($apiToken);
        $this->apiTokenValue = $resolved;
        $this->maxRetries = $maxRetries;
        $this->backoffFactor = $backoffFactor;
        $this->onEvent = $onEvent;
        $this->logger = $logger ?? new NullLogger();
        $this->http = new HttpClient(
            apiToken: $resolved,
            connectTimeout: Timeouts::DEFAULT_CONNECT,
            readTimeout: Timeouts::DEFAULT_READ,
            client: $httpClient,
        );
        $this->enterpriseHttp = new HttpClient(
            apiToken: $resolved,
            connectTimeout: Timeouts::ENTERPRISE_CONNECT,
            readTimeout: Timeouts::ENTERPRISE_READ,
            client: $httpClient,
        );
    }

    /**
     * Build an AudD client purely from the `AUDD_API_TOKEN` environment
     * variable. Equivalent to `new AudD()` (which also consults the env)
     * but communicates intent at the call site.
     */
    public static function fromEnvironment(
        int $maxRetries = 3,
        float $backoffFactor = 0.5,
        ?ClientInterface $httpClient = null,
        ?\Closure $onEvent = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            apiToken: null,
            maxRetries: $maxRetries,
            backoffFactor: $backoffFactor,
            httpClient: $httpClient,
            onEvent: $onEvent,
            logger: $logger,
        );
    }

    /**
     * Read-only view of the current api_token (rotated by setApiToken).
     */
    public function getApiToken(): string
    {
        return $this->apiTokenValue;
    }

    /**
     * Rotate the api_token used for subsequent requests. PHP is single-threaded
     * per request so no lock is needed — in-flight requests already hold the
     * value they were started with on the cURL handle. Spec §7.10.
     *
     * @throws AudDConfigurationException If `$newToken` is empty.
     */
    public function setApiToken(string $newToken): void
    {
        if ($newToken === '') {
            throw new AudDConfigurationException(
                'setApiToken: new token must be a non-empty string.',
            );
        }
        $this->apiTokenValue = $newToken;
        $this->http->setApiToken($newToken);
        $this->enterpriseHttp->setApiToken($newToken);
    }

    /**
     * Resolve api_token: explicit arg → AUDD_API_TOKEN env → throw.
     */
    private static function resolveToken(?string $apiToken): string
    {
        if ($apiToken !== null && $apiToken !== '') {
            return $apiToken;
        }
        $env = getenv(self::TOKEN_ENV_VAR);
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $env = $_ENV[self::TOKEN_ENV_VAR] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }
        throw new AudDConfigurationException(
            'AudD api_token not supplied and ' . self::TOKEN_ENV_VAR . ' env var is unset. '
            . 'Get a token at https://dashboard.audd.io and pass it as '
            . 'new \\AudD\\AudD(apiToken: ...) or set ' . self::TOKEN_ENV_VAR . '.',
        );
    }

    public function streams(): Streams
    {
        return $this->streamsNamespace ??= new Streams(
            $this->http,
            $this->readPolicy(),
            $this->mutatingPolicy(),
            fn (): string => $this->apiTokenValue,
        );
    }

    public function customCatalog(): CustomCatalog
    {
        return $this->customCatalogNamespace ??= new CustomCatalog(
            $this->http,
            $this->mutatingPolicy(),
        );
    }

    public function advanced(): Advanced
    {
        // Locked pattern C2: Advanced uses RECOGNITION retry policy.
        return $this->advancedNamespace ??= new Advanced(
            $this->http,
            $this->recognitionPolicy(),
        );
    }

    /**
     * Recognize a (≤25s) audio sample by URL, file path, PSR-7 stream,
     * resource handle, or wrapped raw bytes (Source::bytes($buf)).
     *
     * Returns null when the server returned status=success with result=null
     * (no match found) — distinct from raising an error.
     *
     * @phpstan-param string|StreamInterface|SourceBytes|resource $source
     *
     * @param string|list<string>|null $return_  Comma-string or list of metadata keys:
     *                                           apple_music, spotify, deezer, napster, musicbrainz.
     */
    public function recognize(
        mixed $source,
        string|array|null $return_ = null,
        ?string $market = null,
        ?float $timeout = null,
    ): ?RecognitionResult {
        $reopen = Source::prepare($source);
        $ret = self::formatReturn($return_);
        $url = self::API_BASE . '/';

        $do = function () use ($reopen, $ret, $market, $timeout): \AudD\Internal\HttpResponse {
            [$data, $files] = $reopen();
            if ($ret !== null) {
                $data['return'] = $ret;
            }
            if ($market !== null) {
                $data['market'] = $market;
            }
            if ($files !== null) {
                return $this->http->postMultipart(self::API_BASE . '/', $data, $files, $timeout);
            }
            return $this->http->postForm(self::API_BASE . '/', $data, $timeout);
        };

        $this->safeEmit(new AudDEvent(
            kind: AudDEventKind::Request,
            method: 'recognize',
            url: $url,
        ));
        $started = hrtime(true);

        try {
            $resp = $this->recognitionPolicy()->run($do);
        } catch (TransferException $exc) {
            $this->safeEmit(new AudDEvent(
                kind: AudDEventKind::Exception,
                method: 'recognize',
                url: $url,
                elapsedMs: self::elapsedMs($started),
                extras: ['error_type' => $exc::class],
            ));
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }

        $this->safeEmit(new AudDEvent(
            kind: AudDEventKind::Response,
            method: 'recognize',
            url: $url,
            requestId: $resp->requestId,
            httpStatus: $resp->httpStatus,
            elapsedMs: self::elapsedMs($started),
        ));

        $body = ResponseDecoder::decodeOrThrow($resp, logger: $this->logger);
        $result = $body['result'] ?? null;
        if ($result === null || !is_array($result)) {
            return null;
        }
        return new RecognitionResult($result);
    }

    /**
     * Recognize a long file (hours, days) on the enterprise endpoint.
     *
     * Returns an empty list when no matches were found. Uses 1-hour read
     * timeout by default for multi-GB uploads. Always pass `limit=1` (or
     * higher) when invoking this against the live API in dev/testing.
     *
     * @phpstan-param string|StreamInterface|SourceBytes|resource $source
     *
     * @param string|list<string>|null $return_
     *
     * @return list<EnterpriseMatch>
     */
    public function recognizeEnterprise(
        mixed $source,
        string|array|null $return_ = null,
        ?int $skip = null,
        ?int $every = null,
        ?int $limit = null,
        ?int $skipFirstSeconds = null,
        ?bool $useTimecode = null,
        ?bool $accurateOffsets = null,
        ?float $timeout = null,
    ): array {
        $reopen = Source::prepare($source);
        $ret = self::formatReturn($return_);
        $extra = self::buildEnterpriseFields(
            $ret,
            $skip,
            $every,
            $limit,
            $skipFirstSeconds,
            $useTimecode,
            $accurateOffsets,
        );
        $url = self::ENTERPRISE_BASE . '/';

        $do = function () use ($reopen, $extra, $timeout): \AudD\Internal\HttpResponse {
            [$data, $files] = $reopen();
            foreach ($extra as $k => $v) {
                $data[$k] = $v;
            }
            if ($files !== null) {
                return $this->enterpriseHttp->postMultipart(
                    self::ENTERPRISE_BASE . '/',
                    $data,
                    $files,
                    $timeout,
                );
            }
            return $this->enterpriseHttp->postForm(self::ENTERPRISE_BASE . '/', $data, $timeout);
        };

        $this->safeEmit(new AudDEvent(
            kind: AudDEventKind::Request,
            method: 'recognize',
            url: $url,
        ));
        $started = hrtime(true);

        try {
            $resp = $this->recognitionPolicy()->run($do);
        } catch (TransferException $exc) {
            $this->safeEmit(new AudDEvent(
                kind: AudDEventKind::Exception,
                method: 'recognize',
                url: $url,
                elapsedMs: self::elapsedMs($started),
                extras: ['error_type' => $exc::class],
            ));
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }

        $this->safeEmit(new AudDEvent(
            kind: AudDEventKind::Response,
            method: 'recognize',
            url: $url,
            requestId: $resp->requestId,
            httpStatus: $resp->httpStatus,
            elapsedMs: self::elapsedMs($started),
        ));

        $body = ResponseDecoder::decodeOrThrow($resp, logger: $this->logger);
        $chunks = $body['result'] ?? [];
        if (!is_array($chunks)) {
            return [];
        }
        $out = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $songs = $chunk['songs'] ?? [];
            if (!is_array($songs)) {
                continue;
            }
            foreach ($songs as $song) {
                if (is_array($song)) {
                    $out[] = new EnterpriseMatch($song);
                }
            }
        }
        return $out;
    }

    public function close(): void
    {
        $this->http->close();
        $this->enterpriseHttp->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Invoke `onEvent` swallowing any exception so observability never breaks
     * the request path. Hook errors are routed through the PSR-3 logger at
     * `debug` level (defaults to `NullLogger` — silent). Spec §7.7a.
     */
    private function safeEmit(AudDEvent $event): void
    {
        $hook = $this->onEvent;
        if ($hook === null) {
            return;
        }
        try {
            $hook($event);
        } catch (\Throwable $exc) {
            $this->logger->debug(
                'audd: onEvent hook threw',
                ['exception' => $exc],
            );
        }
    }

    /**
     * Convert a hrtime(true) start point (nanoseconds) into elapsed
     * milliseconds.
     */
    private static function elapsedMs(int $startedNs): float
    {
        return (hrtime(true) - $startedNs) / 1_000_000.0;
    }

    private function readPolicy(): Retry
    {
        return new Retry(RetryClass::READ, $this->maxRetries, $this->backoffFactor);
    }

    private function recognitionPolicy(): Retry
    {
        return new Retry(RetryClass::RECOGNITION, $this->maxRetries, $this->backoffFactor);
    }

    private function mutatingPolicy(): Retry
    {
        return new Retry(RetryClass::MUTATING, $this->maxRetries, $this->backoffFactor);
    }

    /**
     * @param string|list<string>|null $return_
     */
    private static function formatReturn(string|array|null $return_): ?string
    {
        if ($return_ === null) {
            return null;
        }
        if (is_string($return_)) {
            return $return_;
        }
        return implode(',', $return_);
    }

    /**
     * @return array<string, string>
     */
    private static function buildEnterpriseFields(
        ?string $returnStr,
        ?int $skip,
        ?int $every,
        ?int $limit,
        ?int $skipFirstSeconds,
        ?bool $useTimecode,
        ?bool $accurateOffsets,
    ): array {
        $fields = [];
        if ($returnStr !== null) {
            $fields['return'] = $returnStr;
        }
        foreach (
            [
                'skip' => $skip,
                'every' => $every,
                'limit' => $limit,
                'skip_first_seconds' => $skipFirstSeconds,
            ] as $k => $v
        ) {
            if ($v !== null) {
                $fields[$k] = (string) $v;
            }
        }
        if ($useTimecode !== null) {
            $fields['use_timecode'] = $useTimecode ? 'true' : 'false';
        }
        if ($accurateOffsets !== null) {
            $fields['accurate_offsets'] = $accurateOffsets ? 'true' : 'false';
        }
        return $fields;
    }
}
