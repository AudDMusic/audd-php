<?php

declare(strict_types=1);

namespace AudD;

use AudD\Internal\HttpClient;
use AudD\Internal\Retry;
use AudD\Internal\RetryClass;
use AudD\Internal\Timeouts;
use Psr\Http\Client\ClientInterface;

/**
 * Tokenless longpoll consumer for browser/widget/extension use cases.
 *
 * Carries no api_token — the category alone authorizes the subscription.
 * The user/server who derived the category is responsible for ensuring a
 * callback URL is set on their account (we can't preflight that without a
 * token).
 *
 * ```php
 * $consumer = new LongpollConsumer(category: 'abc123def');
 * $poll = $consumer->iterate();
 * $poll->onMatch(fn ($m) => print_r($m));
 * $poll->onNotification(fn ($n) => print_r($n));
 * $poll->onError(fn ($e) => fwrite(STDERR, $e->getMessage()));
 * $poll->run();
 * ```
 *
 * Responses, retries and stopping work as described on `LongpollPoll`.
 * `maxRetries` (attempts per poll request, including the first) and
 * `backoffFactor` (seconds; also scales the waits between polls) apply the
 * same way as on the authenticated client.
 */
final class LongpollConsumer
{
    private const LONGPOLL_URL = 'https://api.audd.io/longpoll/';

    private readonly HttpClient $http;
    private readonly Retry $policy;

    public function __construct(
        private readonly string $category,
        int $maxRetries = 3,
        float $backoffFactor = 0.5,
        ?ClientInterface $httpClient = null,
    ) {
        // Tokenless: pass empty token; the server doesn't require it on /longpoll/.
        $this->http = new HttpClient(
            apiToken: '',
            connectTimeout: Timeouts::LONGPOLL_CONNECT,
            readTimeout: Timeouts::LONGPOLL_READ,
            client: $httpClient,
        );
        $this->policy = new Retry(RetryClass::READ, $maxRetries, $backoffFactor);
    }

    /**
     * Build a longpoll handle. Register `onMatch`/`onNotification`/`onError`
     * on the returned `LongpollPoll`, then call `run()` to drive the loop
     * until `close()` is called or an error ends the subscription.
     *
     * @param int|null $sinceTime Server timestamp (ms) to resume after; only
     *                            newer events are delivered.
     * @param int      $timeout   Seconds the server holds each poll open
     *                            waiting for events, 1 to 120.
     */
    public function iterate(?int $sinceTime = null, int $timeout = 50): LongpollPoll
    {
        $params = ['category' => $this->category, 'timeout' => (string) $timeout];
        if ($sinceTime !== null) {
            $params['since_time'] = (string) $sinceTime;
        }
        return new LongpollPoll(
            url: self::LONGPOLL_URL,
            params: $params,
            http: $this->http,
            policy: $this->policy,
        );
    }

    public function close(): void
    {
        $this->http->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
