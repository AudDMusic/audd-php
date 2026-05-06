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
use AudD\Models\Stream;
use AudD\Models\StreamCallbackPayload;
use GuzzleHttp\Exception\TransferException;

/**
 * Streams namespace — real-time recognition stream management. Reached via
 * `$audd->streams->...`.
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
        . 'Set one first via $audd->streams->setCallbackUrl(...) — `https://audd.tech/empty/` is fine '
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
     * @param array<string, mixed> $body
     */
    public function parseCallback(array $body): StreamCallbackPayload
    {
        return Helpers::parseCallback($body);
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
     * Long-poll for stream events. Returns a Generator yielding raw response dicts.
     *
     * On first iteration, performs a preflight getCallbackUrl call unless
     * `$skipCallbackCheck` is true (mandatory DX safeguard — see spec §4.1).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function longpoll(
        string $category,
        ?int $sinceTime = null,
        int $timeout = 50,
        bool $skipCallbackCheck = false,
    ): \Generator {
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

        $curSince = $sinceTime;
        while (true) {
            $params = ['category' => $category, 'timeout' => (string) $timeout];
            if ($curSince !== null) {
                $params['since_time'] = (string) $curSince;
            }

            $do = function () use ($params): \AudD\Internal\HttpResponse {
                return $this->http->get(self::API_BASE . '/longpoll/', $params);
            };

            try {
                $resp = $this->readPolicy->run($do);
            } catch (TransferException $exc) {
                throw new AudDConnectionException($exc->getMessage(), $exc);
            }
            $body = $resp->jsonBody;
            if (!is_array($body)) {
                throw new AudDSerializationException('Unparseable longpoll response', $resp->rawText);
            }
            /** @var array<string, mixed> $body */
            yield $body;
            $ts = $body['timestamp'] ?? null;
            if (is_int($ts)) {
                $curSince = $ts;
            }
        }
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
}
