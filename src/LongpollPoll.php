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
 * `run()` to drive the loop. `run()` blocks until `close()` is called or an
 * error ends the subscription. That error goes to `onError`, or is thrown
 * from `run()` when no `onError` handler is registered.
 *
 * ```php
 * $poll = $audd->streams()->longpoll($category);
 * $poll->onMatch(fn (StreamCallbackMatch $m)        => print $m->song->artist);
 * $poll->onNotification(fn (StreamCallbackNotification $n) => print $n->notification_message);
 * $poll->onError(fn (\Throwable $e)                  => fwrite(STDERR, $e->getMessage()));
 * $poll->run();
 * ```
 *
 * The server answers each poll with one of:
 *   - `{"events": [...]}`: each event's `data` is the body a callback URL
 *     receives. Matches go to `onMatch` and notifications to `onNotification`,
 *     in order. An event that can't be parsed, or has an empty `results`
 *     list, is skipped.
 *   - `{"timeout": "no events before timeout", "timestamp": ...}`: nothing
 *     happened during the wait; the loop polls again.
 *   - `{"error": "..."}`: the request is invalid (for example a `timeout`
 *     outside 1-120). It reaches `onError` as AudDInvalidRequestException
 *     carrying the server's text, and the loop stops.
 * A callback body at the top level (`result` or `notification`) is delivered
 * the same way. Every poll sends `since_time`: the newest event timestamp, or
 * the timeout response's timestamp.
 *
 * Failure handling:
 *   - Each poll request is retried like other read calls: 408, 429, 5xx and
 *     network errors, up to the client's `maxRetries`.
 *   - When a poll still fails, or its body is unusable (not JSON, or no events
 *     and no timestamp), the subscription stays open: the loop waits about
 *     1 second, doubling up to 30 seconds, and polls again. After 8 such polls
 *     in a row the last error reaches `onError` and the loop stops.
 *   - A poll with nothing new (an empty `events` list) waits the same way.
 *   - Any other HTTP 4xx → AudDServerException → `onError`; the loop stops.
 *
 * Stopping: call `close()` from any handler, or from a pcntl signal handler
 * (`pcntl_async_signals(true)`). With the SDK's built-in HTTP client a poll
 * that is waiting on the server is abandoned within about a second. With an
 * injected HTTP client `run()` returns once the current poll ends, which takes
 * up to `timeout` seconds.
 */
final class LongpollPoll
{
    private const HTTP_CLIENT_ERROR_FLOOR = 400;
    private const HTTP_SERVER_ERROR_FLOOR = 500;
    private const RETRYABLE_STATUSES = [408, 429];

    /** Failed or unusable polls in a row before the error ends the subscription. */
    private const MAX_CONSECUTIVE_FAILURES = 8;
    private const MAX_WAIT_SECONDS = 30.0;
    /** Floor for the backoff factor so `backoffFactor: 0` can't poll in a tight loop. */
    private const MIN_WAIT_FACTOR = 0.001;
    private const SLEEP_SLICE_SECONDS = 0.2;
    /** How long a poll may stay silent beyond the server-side wait. */
    private const READ_TIMEOUT_MARGIN_SECONDS = 30.0;
    private const DEFAULT_SERVER_WAIT_SECONDS = 50.0;

    /** @var (\Closure(StreamCallbackMatch): void)|null */
    private ?\Closure $onMatch = null;

    /** @var (\Closure(StreamCallbackNotification): void)|null */
    private ?\Closure $onNotification = null;

    /** @var (\Closure(\Throwable): void)|null */
    private ?\Closure $onError = null;

    private bool $stopping = false;

    /**
     * @param array<string, scalar> $params Initial request params (the loop
     *                                      updates `since_time` after each poll).
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
     * Receives the error that ends the subscription. `run()` returns after
     * the handler does.
     *
     * @param callable(\Throwable): void $cb
     */
    public function onError(callable $cb): self
    {
        $this->onError = $cb instanceof \Closure ? $cb : \Closure::fromCallable($cb);
        return $this;
    }

    /**
     * Stop the loop. Idempotent, and safe to call from any handler or from a
     * pcntl signal handler. Events from the current response that haven't
     * been delivered yet are dropped.
     */
    public function close(): void
    {
        $this->stopping = true;
    }

    /**
     * Block, driving the long-poll loop. Returns when `close()` is called or
     * an error ends the subscription. Re-raises that error if no `onError`
     * handler is registered.
     */
    public function run(): void
    {
        $failures = 0;
        $quietPolls = 0;
        while (!$this->stopping) {
            $failure = null;
            $events = [];
            $progress = false;

            try {
                $resp = $this->fetchOnce();
            } catch (\Throwable $exc) {
                if ($this->isStopping()) {
                    // close() cancelled the request.
                    return;
                }
                if (!self::isTransient($exc)) {
                    $this->dispatchError($exc);
                    return;
                }
                $resp = null;
                $failure = $exc instanceof TransferException
                    ? new AudDConnectionException($exc->getMessage(), $exc)
                    : $exc;
            }

            if ($resp !== null) {
                if (self::isRetryableStatus($resp->httpStatus)) {
                    $failure = self::httpError($resp);
                } elseif ($resp->httpStatus >= self::HTTP_CLIENT_ERROR_FLOOR) {
                    $this->dispatchError(self::httpError($resp));
                    return;
                } else {
                    try {
                        [$events, $progress] = $this->consume($resp);
                    } catch (AudDSerializationException $exc) {
                        $failure = $exc;
                    } catch (AudDApiException $exc) {
                        $this->dispatchError($exc);
                        return;
                    }
                }
            }

            foreach ($events as $event) {
                if ($this->stopping) {
                    return;
                }
                $this->dispatch($event);
            }
            if ($this->stopping) {
                return;
            }

            if ($failure !== null) {
                $failures++;
                if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->dispatchError($failure);
                    return;
                }
            } else {
                $failures = 0;
            }

            if ($progress) {
                $quietPolls = 0;
                continue;
            }
            $this->wait($quietPolls++);
        }
    }

    private function fetchOnce(): HttpResponse
    {
        $do = function (): HttpResponse {
            // The longpoll endpoint authorizes via the category alone —
            // never send the api_token here.
            return $this->http->get(
                $this->url,
                $this->params,
                perCallTimeout: $this->readTimeout(),
                sendToken: false,
                cancel: fn (): bool => $this->stopping,
            );
        };
        return $this->policy->run($do);
    }

    /**
     * Parse one 2xx body, advance `since_time`, and return the events to
     * deliver plus whether the poll moved forward (an event, or a new
     * `since_time`).
     *
     * @return array{0: list<CallbackEvent>, 1: bool}
     *
     * @throws AudDSerializationException When the body is unusable.
     * @throws AudDApiException           When the body is an error.
     */
    private function consume(HttpResponse $resp): array
    {
        $body = $resp->jsonBody;
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new AudDSerializationException('Longpoll response was not a JSON object', $resp->rawText);
        }
        /** @var array<string, mixed> $body */
        if (isset($body['error'])) {
            throw self::errorFromBody($body, $resp);
        }

        $previous = $this->params['since_time'] ?? null;
        $events = [];
        if (array_key_exists('events', $body)) {
            [$events, $newest] = self::parseEvents($body['events']);
            $this->advance($newest);
        } elseif (isset($body['result']) || isset($body['notification'])) {
            $event = self::tryParse($body);
            if ($event !== null) {
                $events[] = $event;
            }
            $this->advance(self::asTimestamp($body['timestamp'] ?? null));
        } else {
            $timestamp = self::asTimestamp($body['timestamp'] ?? null);
            if ($timestamp === null) {
                throw new AudDSerializationException(
                    'Longpoll response carried no events and no timestamp',
                    $resp->rawText,
                );
            }
            $this->advance($timestamp);
        }

        $progress = $events !== [] || ($this->params['since_time'] ?? null) !== $previous;
        return [$events, $progress];
    }

    /**
     * @return array{0: list<CallbackEvent>, 1: int|null} Parsed events, in
     *                                                    order, and the newest
     *                                                    event timestamp.
     */
    private static function parseEvents(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [[], null];
        }
        $events = [];
        $newest = null;
        foreach ($raw as $event) {
            if (!is_array($event)) {
                continue;
            }
            $timestamp = self::asTimestamp($event['timestamp'] ?? null);
            if ($timestamp !== null && ($newest === null || $timestamp > $newest)) {
                $newest = $timestamp;
            }
            $data = $event['data'] ?? null;
            if (is_string($data)) {
                $data = HttpClient::tryDecodeJson($data);
            }
            if (!is_array($data)) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $parsed = self::tryParse($data);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }
        return [$events, $newest];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function tryParse(array $data): ?CallbackEvent
    {
        try {
            return Streams::parseCallback($data);
        } catch (AudDSerializationException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function errorFromBody(array $body, HttpResponse $resp): AudDApiException
    {
        $error = $body['error'];
        if (is_array($error)) {
            try {
                ErrorMapping::raiseFromErrorResponse($body, $resp->httpStatus, $resp->requestId);
            } catch (AudDApiException $exc) {
                return $exc;
            }
        }
        $message = is_scalar($error) ? (string) $error : '';
        return new AudDInvalidRequestException(
            errorCode: 0,
            apiMessage: $message !== '' ? $message : 'Longpoll request rejected',
            httpStatus: $resp->httpStatus,
            requestId: $resp->requestId,
            rawResponse: $body,
        );
    }

    private static function httpError(HttpResponse $resp): AudDServerException
    {
        return new AudDServerException(
            errorCode: 0,
            apiMessage: sprintf('Longpoll endpoint returned HTTP %d', $resp->httpStatus),
            httpStatus: $resp->httpStatus,
            requestId: $resp->requestId,
            rawResponse: $resp->jsonBody ?? $resp->rawText,
        );
    }

    private static function isRetryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true) || $status >= self::HTTP_SERVER_ERROR_FLOOR;
    }

    private static function isTransient(\Throwable $exc): bool
    {
        return $exc instanceof TransferException || $exc instanceof AudDConnectionException;
    }

    private static function asTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }
        return null;
    }

    private function advance(?int $timestamp): void
    {
        if ($timestamp !== null) {
            $this->params['since_time'] = (string) $timestamp;
        }
    }

    /**
     * Seconds a poll may go without data: the server-side wait plus a margin.
     */
    private function readTimeout(): float
    {
        $wait = $this->params['timeout'] ?? null;
        $seconds = is_numeric($wait) ? max(0.0, (float) $wait) : self::DEFAULT_SERVER_WAIT_SECONDS;
        return $seconds + self::READ_TIMEOUT_MARGIN_SECONDS;
    }

    /**
     * Back off before the next poll: about 2 × backoffFactor seconds (1 s by
     * default), doubling per quiet or failed poll, capped at 30 s. Sleeps in
     * short slices so `close()` from a signal handler takes effect quickly.
     */
    private function wait(int $quietPolls): void
    {
        $factor = max($this->policy->backoffFactor, self::MIN_WAIT_FACTOR);
        $base = $factor * (2 ** min($quietPolls + 1, 30));
        $jitter = 0.5 + (mt_rand() / mt_getrandmax());
        $seconds = min($base * $jitter, self::MAX_WAIT_SECONDS);
        $deadline = microtime(true) + $seconds;
        while (!$this->stopping) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return;
            }
            usleep((int) (min($left, self::SLEEP_SLICE_SECONDS) * 1_000_000));
        }
    }

    private function isStopping(): bool
    {
        return $this->stopping;
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
}
