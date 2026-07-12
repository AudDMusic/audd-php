<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\Internal\HttpClient;
use AudD\Internal\HttpResponse;
use AudD\Internal\Retry;
use AudD\Models\CallbackEvent;
use AudD\Models\StreamCallbackMatch;
use AudD\Models\StreamCallbackNotification;
use GuzzleHttp\Exception\TransferException;

/**
 * Active long-poll handle. Returned by `Streams::longpoll(...)` and by
 * `LongpollConsumer::iterate(...)` (tokenless).
 *
 * Register callbacks for matches, notifications, and errors, then call
 * `run()` to drive the loop. `run()` blocks until `close()` is called,
 * a terminal error fires, or the registered onError handler decides to
 * stop (by calling `close()`).
 *
 * ```php
 * $poll = $audd->streams()->longpoll($category);
 * $poll->onMatch(fn (StreamCallbackMatch $m)        => echo $m->song->artist);
 * $poll->onNotification(fn (StreamCallbackNotification $n) => echo $n->notification_message);
 * $poll->onError(fn (\Throwable $e)                  => fwrite(STDERR, $e->getMessage()));
 * $poll->run();
 * ```
 *
 * Keepalive responses — any body carrying neither a `result` nor a
 * `notification` block, such as `{"timeout":"no events before timeout", ...}` —
 * are silently absorbed: the loop advances `since_time` and continues.
 *
 * Failure handling:
 *   - HTTP non-2xx → AudDServerException → onError, then loop terminates.
 *   - JSON decode failure on 2xx → AudDSerializationException → onError, terminate.
 *   - 5xx and connection errors are retried via the READ-class retry policy.
 */
final class LongpollPoll
{
    private const HTTP_CLIENT_ERROR_FLOOR = 400;

    /** @var (\Closure(StreamCallbackMatch): void)|null */
    private ?\Closure $onMatch = null;

    /** @var (\Closure(StreamCallbackNotification): void)|null */
    private ?\Closure $onNotification = null;

    /** @var (\Closure(\Throwable): void)|null */
    private ?\Closure $onError = null;

    private bool $stopping = false;

    /**
     * @param array<string, scalar> $params Initial request params (the loop
     *                                      mutates `since_time` each iteration).
     */
    public function __construct(
        private string $url,
        private array $params,
        private readonly HttpClient $http,
        private readonly Retry $policy,
    ) {
    }

    /**
     * @param callable(StreamCallbackMatch): void $cb
     */
    public function onMatch(callable $cb): self
    {
        $this->onMatch = $cb instanceof \Closure ? $cb : \Closure::fromCallable($cb);
        return $this;
    }

    /**
     * @param callable(StreamCallbackNotification): void $cb
     */
    public function onNotification(callable $cb): self
    {
        $this->onNotification = $cb instanceof \Closure ? $cb : \Closure::fromCallable($cb);
        return $this;
    }

    /**
     * @param callable(\Throwable): void $cb
     */
    public function onError(callable $cb): self
    {
        $this->onError = $cb instanceof \Closure ? $cb : \Closure::fromCallable($cb);
        return $this;
    }

    /**
     * Request termination. Idempotent. The currently-blocked HTTP call cannot
     * be interrupted; the loop checks `$stopping` after each response.
     *
     * Safe to call from inside any handler — typical pattern is to call
     * `$poll->close()` from `onError` to end after a terminal error.
     */
    public function close(): void
    {
        $this->stopping = true;
    }

    /**
     * Block, driving the long-poll loop. Returns when `close()` is called or
     * a terminal error fires. Re-raises the terminal error if no `onError`
     * handler is registered.
     *
     * Keepalive responses are silently absorbed.
     */
    public function run(): void
    {
        while (!$this->stopping) {
            try {
                $resp = $this->fetchOnce();
            } catch (\Throwable $exc) {
                $this->dispatchError($exc);
                return;
            }

            if ($resp->httpStatus >= self::HTTP_CLIENT_ERROR_FLOOR) {
                $this->dispatchError(new AudDServerException(
                    errorCode: 0,
                    apiMessage: sprintf('Longpoll endpoint returned HTTP %d', $resp->httpStatus),
                    httpStatus: $resp->httpStatus,
                    requestId: $resp->requestId,
                    rawResponse: $resp->jsonBody ?? $resp->rawText,
                ));
                return;
            }

            $body = $resp->jsonBody;
            if (!is_array($body)) {
                $this->dispatchError(new AudDSerializationException(
                    'Longpoll response was not a JSON object',
                    $resp->rawText,
                ));
                return;
            }
            /** @var array<string, mixed> $body */

            // Advance since_time from the response timestamp regardless of
            // event type, so the next poll resumes after the last seen event.
            $ts = $body['timestamp'] ?? null;
            if (is_int($ts)) {
                $this->params['since_time'] = (string) $ts;
            } elseif (is_string($ts) && ctype_digit($ts)) {
                $this->params['since_time'] = $ts;
            }

            // Keepalive: server sends `{"timeout":"...", "timestamp":...}`
            // when no event happened in the longpoll window. Silently absorb.
            if (self::isLongpollKeepalive($body)) {
                continue;
            }

            try {
                $parsed = Streams::parseCallback($body);
            } catch (\Throwable $exc) {
                $this->dispatchError($exc);
                return;
            }

            $this->dispatch($parsed);
        }
    }

    private function fetchOnce(): HttpResponse
    {
        $do = function (): HttpResponse {
            // The longpoll endpoint authorizes via the derived category alone —
            // never send the api_token in the query string here.
            return $this->http->get($this->url, $this->params, sendToken: false);
        };
        try {
            return $this->policy->run($do);
        } catch (TransferException $exc) {
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }
    }

    private function dispatch(CallbackEvent $parsed): void
    {
        if ($parsed->match !== null && $this->onMatch !== null) {
            ($this->onMatch)($parsed->match);
        } elseif ($parsed->notification !== null && $this->onNotification !== null) {
            ($this->onNotification)($parsed->notification);
        }
    }

    private function dispatchError(\Throwable $exc): void
    {
        $this->stopping = true;
        if ($this->onError !== null) {
            ($this->onError)($exc);
            return;
        }
        throw $exc;
    }

    /**
     * A longpoll response carrying neither a `result` nor a `notification`
     * block is a benign keepalive (the server emits one when no event happened
     * within the poll window). Absorb it and keep polling rather than treating
     * it as a terminal serialization error.
     *
     * @param array<string, mixed> $body
     */
    private static function isLongpollKeepalive(array $body): bool
    {
        return !isset($body['result']) && !isset($body['notification']);
    }
}
