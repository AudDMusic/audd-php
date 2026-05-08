<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDApiException;
use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\Errors\ErrorMapping;
use AudD\Internal\HttpClient;
use AudD\Internal\Retry;
use AudD\Models\CallbackEvent;
use AudD\Models\Stream;
use AudD\Models\StreamCallbackMatch;
use AudD\Models\StreamCallbackNotification;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Streams namespace — real-time recognition stream management. Reached via
 * `$audd->streams()->...`.
 *
 * Longpoll preflight: by default `longpoll(...)` performs a one-time
 * `getCallbackUrl()` preflight. If the server returns code 19 (no callback
 * URL configured), this raises AudDInvalidRequestException with a
 * remediation hint. Pass `skipCallbackCheck: true` to skip.
 */
final class Streams
{
    private const API_BASE = 'https://api.audd.io';
    private const NO_CALLBACK_ERROR_CODE = 19;
    private const PREFLIGHT_HINT =
        "Longpoll won't deliver events because no callback URL is configured for this account. "
        . 'Set one first via $audd->streams()->setCallbackUrl(...) — `https://audd.tech/empty/` is fine '
        . "if you only want longpolling and don't need a real receiver. "
        . 'To skip this check, pass skipCallbackCheck=true.';

    /** @var \Closure(): string */
    private readonly \Closure $apiTokenGetter;

    /**
     * @param \Closure(): string $apiTokenGetter Resolves the current api_token
     *                                           on each call (so token rotation
     *                                           via AudD::setApiToken is picked
     *                                           up by deriveLongpollCategory).
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Retry $readPolicy,
        private readonly Retry $mutatingPolicy,
        \Closure $apiTokenGetter,
    ) {
        $this->apiTokenGetter = $apiTokenGetter;
    }

    public function deriveLongpollCategory(int $radioId): string
    {
        return Helpers::deriveLongpollCategory(($this->apiTokenGetter)(), $radioId);
    }

    /**
     * Parse a callback body into a typed `CallbackEvent`. Exactly one
     * of `$result->match`/`$result->notification` is non-null on success.
     *
     * Accepts a parsed associative array — for raw bytes or a PSR-7 request,
     * use `handleCallback(...)` instead.
     *
     * @param array<string, mixed> $body
     *
     * @throws AudDSerializationException When the body contains neither a
     *                                    `result` nor a `notification` block,
     *                                    or when those blocks are malformed.
     */
    public static function parseCallback(array $body): CallbackEvent
    {
        if (isset($body['notification']) && is_array($body['notification'])) {
            $outerTime = isset($body['time']) ? (int) $body['time'] : null;
            $notif = new StreamCallbackNotification($body['notification'], $outerTime);
            return new CallbackEvent(match: null, notification: $notif);
        }
        if (isset($body['result']) && is_array($body['result'])) {
            /** @var array<string, mixed> $result */
            $result = $body['result'];
            if (!isset($result['results']) || !is_array($result['results']) || $result['results'] === []) {
                throw new AudDSerializationException(
                    'callback result.results is empty',
                    self::asJsonText($body),
                );
            }
            $match = new StreamCallbackMatch($result);
            return new CallbackEvent(match: $match, notification: null);
        }
        throw new AudDSerializationException(
            'callback body has neither result nor notification',
            self::asJsonText($body),
        );
    }

    /**
     * Higher-level callback parser that accepts a PSR-7 ServerRequest, raw
     * bytes, or a pre-decoded array. Reads the request body when a PSR-7
     * request is supplied. Returns the same `CallbackEvent` as
     * `parseCallback()`.
     *
     * @param ServerRequestInterface|StreamInterface|string|array<string, mixed> $bodyOrRequest
     *
     * @throws AudDSerializationException When the body cannot be decoded or
     *                                    contains neither `result` nor
     *                                    `notification`.
     */
    public static function handleCallback(mixed $bodyOrRequest): CallbackEvent
    {
        if ($bodyOrRequest instanceof ServerRequestInterface) {
            $bodyOrRequest = (string) $bodyOrRequest->getBody();
        } elseif ($bodyOrRequest instanceof StreamInterface) {
            $bodyOrRequest = (string) $bodyOrRequest;
        }
        if (is_string($bodyOrRequest)) {
            try {
                $decoded = json_decode($bodyOrRequest, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exc) {
                throw new AudDSerializationException(
                    'callback body is not valid JSON: ' . $exc->getMessage(),
                    $bodyOrRequest,
                );
            }
            if (!is_array($decoded)) {
                throw new AudDSerializationException(
                    'callback body did not parse to an associative array',
                    $bodyOrRequest,
                );
            }
            $bodyOrRequest = $decoded;
        }
        if (!is_array($bodyOrRequest)) {
            throw new AudDSerializationException(
                'handleCallback: unsupported body type ' . get_debug_type($bodyOrRequest),
                '',
            );
        }
        /** @var array<string, mixed> $bodyOrRequest */
        return self::parseCallback($bodyOrRequest);
    }

    /**
     * @param string|list<string>|null $returnMetadata
     */
    public function setCallbackUrl(string $url, string|array|null $returnMetadata = null): void
    {
        $url = Helpers::addReturnToUrl($url, $returnMetadata);
        $this->postFor('setCallbackUrl', ['url' => $url], $this->mutatingPolicy);
    }

    public function getCallbackUrl(): string
    {
        $result = $this->postFor('getCallbackUrl', [], $this->readPolicy);
        return is_string($result) ? $result : (string) $result;
    }

    /**
     * Add a new real-time stream subscription.
     *
     * Accepts direct stream URLs (DASH, Icecast, HLS, m3u/m3u8) and shortcuts:
     * `twitch:<channel>`, `youtube:<video_id>`, `youtube-ch:<channel_id>`.
     *
     * @param string|null $callbacks Pass `"before"` to deliver callbacks at song start
     *                               instead of song end.
     */
    public function add(string $url, int $radioId, ?string $callbacks = null): void
    {
        $data = ['url' => $url, 'radio_id' => (string) $radioId];
        if ($callbacks !== null) {
            $data['callbacks'] = $callbacks;
        }
        $this->postFor('addStream', $data, $this->mutatingPolicy);
    }

    public function setUrl(int $radioId, string $url): void
    {
        $this->postFor(
            'setStreamUrl',
            ['radio_id' => (string) $radioId, 'url' => $url],
            $this->mutatingPolicy,
        );
    }

    public function delete(int $radioId): void
    {
        $this->postFor(
            'deleteStream',
            ['radio_id' => (string) $radioId],
            $this->mutatingPolicy,
        );
    }

    /**
     * @return list<Stream>
     */
    public function list(): array
    {
        $result = $this->postFor('getStreams', [], $this->readPolicy);
        if (!is_array($result)) {
            return [];
        }
        $out = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $out[] = new Stream($row);
            }
        }
        return $out;
    }

    /**
     * Open a long-poll subscription. Pass `radioId` (the common case — derives
     * the 9-char category locally from the api_token) or `category` (the
     * tokenless path — accepts a pre-derived category string e.g. shipped to
     * a browser/mobile/embedded client). Exactly one of the two is required.
     *
     * Returns a `LongpollPoll` handle: register `onMatch` / `onNotification` /
     * `onError` callbacks, then call `run()` to drive the loop until you call
     * `close()` or a terminal error fires.
     *
     * Keepalive responses (`{"timeout":"no events before timeout"}`) are
     * silently absorbed by the loop.
     *
     * On entry runs a one-time `getCallbackUrl()` preflight unless
     * `$skipCallbackCheck` is true — catches the silent-failure mode where
     * the account has no callback URL set.
     *
     * @throws AudDInvalidRequestException When neither or both of `$category`
     *                                     and `$radioId` are supplied, or when
     *                                     the preflight detects no callback
     *                                     URL is configured.
     */
    public function longpoll(
        ?string $category = null,
        ?int $radioId = null,
        ?int $sinceTime = null,
        int $timeout = 50,
        bool $skipCallbackCheck = false,
    ): LongpollPoll {
        if ($category !== null && $radioId !== null) {
            throw new AudDInvalidRequestException(
                errorCode: 0,
                apiMessage: 'longpoll(): pass exactly one of $category or $radioId, not both.',
                httpStatus: 0,
            );
        }
        if ($category === null && $radioId === null) {
            throw new AudDInvalidRequestException(
                errorCode: 0,
                apiMessage: 'longpoll(): one of $category or $radioId is required.',
                httpStatus: 0,
            );
        }
        if ($radioId !== null) {
            $category = $this->deriveLongpollCategory($radioId);
        }
        /** @var string $category */

        if (!$skipCallbackCheck) {
            try {
                $this->getCallbackUrl();
            } catch (AudDApiException $exc) {
                if ($exc->errorCode === self::NO_CALLBACK_ERROR_CODE) {
                    throw new AudDInvalidRequestException(
                        errorCode: 0,
                        apiMessage: self::PREFLIGHT_HINT,
                        httpStatus: $exc->httpStatus,
                        requestId: $exc->requestId,
                    );
                }
                throw $exc;
            }
        }

        $params = ['category' => $category, 'timeout' => (string) $timeout];
        if ($sinceTime !== null) {
            $params['since_time'] = (string) $sinceTime;
        }

        return new LongpollPoll(
            url: self::API_BASE . '/longpoll/',
            params: $params,
            http: $this->http,
            policy: $this->readPolicy,
        );
    }

    /**
     * @param array<string, scalar> $data
     *
     * @return mixed The body['result'] value (string for getCallbackUrl, list for getStreams, null otherwise).
     */
    private function postFor(string $path, array $data, Retry $policy): mixed
    {
        $do = function () use ($path, $data): \AudD\Internal\HttpResponse {
            return $this->http->postForm(self::API_BASE . '/' . $path . '/', $data);
        };
        try {
            $resp = $policy->run($do);
        } catch (TransferException $exc) {
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }
        return self::decodeStreamsResponse($resp);
    }

    /**
     * @return mixed body['result']
     */
    private static function decodeStreamsResponse(\AudD\Internal\HttpResponse $resp): mixed
    {
        $body = $resp->jsonBody;
        if (!is_array($body)) {
            throw new AudDSerializationException('Unparseable response', $resp->rawText);
        }
        if (($body['status'] ?? null) === 'error') {
            ErrorMapping::raiseFromErrorResponse($body, $resp->httpStatus, $resp->requestId);
        }
        if (($body['status'] ?? null) === 'success') {
            return $body['result'] ?? null;
        }
        throw new AudDServerException(
            errorCode: 0,
            apiMessage: 'Unexpected response status: ' . var_export($body['status'] ?? null, true),
            httpStatus: $resp->httpStatus,
            requestId: $resp->requestId,
            rawResponse: $body,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function asJsonText(array $body): string
    {
        try {
            return (string) json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
    }
}
