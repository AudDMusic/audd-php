<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\Internal\HttpClient;
use AudD\Internal\Retry;
use AudD\Internal\RetryClass;
use AudD\Internal\Timeouts;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Client\ClientInterface;

/**
 * Tokenless longpoll consumer for browser/widget/extension use cases.
 *
 * Carries no api_token — the category alone authorizes the subscription.
 * The user/server who derived the category is responsible for ensuring a
 * callback URL is set on their account (we can't preflight that without a
 * token).
 *
 * Hardening (locked patterns S5/S6):
 *   - HTTP non-2xx → AudDServerException (not silent loop forever)
 *   - JSON decode failure on 2xx → AudDSerializationException
 *   - READ-class retries on 5xx + connection errors
 *   - Configurable maxRetries / backoffFactor in constructor
 */
final class LongpollConsumer
{
    private const LONGPOLL_URL = 'https://api.audd.io/longpoll/';
    private const HTTP_CLIENT_ERROR_FLOOR = 400;

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
     * Iterate longpoll responses until the consumer is closed or the caller
     * stops consuming. Yields raw JSON dicts (recognition events, notifications,
     * or "no events before timeout" timeouts).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(?int $sinceTime = null, int $timeout = 50): \Generator
    {
        $curSince = $sinceTime;
        while (true) { // @phpstan-ignore-line: yield-loop runs until consumer stops or close()
            $params = ['category' => $this->category, 'timeout' => (string) $timeout];
            if ($curSince !== null) {
                $params['since_time'] = (string) $curSince;
            }

            $do = function () use ($params): \AudD\Internal\HttpResponse {
                // Tokenless: don't merge api_token. We pass through the params
                // directly; HttpClient::get respects empty apiToken.
                return $this->http->get(self::LONGPOLL_URL, $params);
            };

            try {
                $resp = $this->policy->run($do);
            } catch (TransferException $exc) {
                throw new AudDConnectionException($exc->getMessage(), $exc);
            }
            $body = self::decodeStrict($resp);
            yield $body;
            $ts = $body['timestamp'] ?? null;
            if (is_int($ts)) {
                $curSince = $ts;
            }
        }
    }

    public function close(): void
    {
        $this->http->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStrict(\AudD\Internal\HttpResponse $resp): array
    {
        if ($resp->httpStatus >= self::HTTP_CLIENT_ERROR_FLOOR) {
            throw new AudDServerException(
                errorCode: 0,
                apiMessage: sprintf('Longpoll endpoint returned HTTP %d', $resp->httpStatus),
                httpStatus: $resp->httpStatus,
                requestId: $resp->requestId,
                rawResponse: $resp->jsonBody ?? $resp->rawText,
            );
        }
        $body = $resp->jsonBody;
        if (!is_array($body)) {
            throw new AudDSerializationException(
                'Longpoll response was not a JSON object',
                $resp->rawText,
            );
        }
        /** @var array<string, mixed> $body */
        return $body;
    }
}
