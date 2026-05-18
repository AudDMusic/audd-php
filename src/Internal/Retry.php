<?php

declare(strict_types=1);

namespace AudD\Internal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;

/**
 * Cost-aware retry executor. Handles backoff + jitter and decides whether
 * a given exception or response status is retryable for a given cost class.
 *
 * @internal
 */
final class Retry
{
    private const HTTP_REQUEST_TIMEOUT = 408;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR_FLOOR = 500;
    private const BACKOFF_MAX_SECONDS = 30.0;

    public function __construct(
        public readonly RetryClass $retryClass,
        public readonly int $maxAttempts = 3,
        public readonly float $backoffFactor = 0.5,
    ) {
    }

    /**
     * Run `$fn` with retry. Returns the final HttpResponse, or rethrows the
     * last exception when retries are exhausted on a connection-side failure.
     *
     * @param \Closure(): HttpResponse $fn
     *
     * @throws \Throwable
     */
    public function run(\Closure $fn): HttpResponse
    {
        $lastExc = null;
        $lastResp = null;
        for ($attempt = 0; $attempt < $this->maxAttempts; $attempt++) {
            try {
                $resp = $fn();
            } catch (\Throwable $exc) {
                $lastExc = $exc;
                $lastResp = null;
                if (!$this->shouldRetryException($exc)) {
                    throw $exc;
                }
                if ($attempt + 1 >= $this->maxAttempts) {
                    throw $exc;
                }
                $this->sleep($this->backoffDelay($attempt));
                continue;
            }

            if (!$this->shouldRetryResponse($resp)) {
                return $resp;
            }
            $lastResp = $resp;
            $lastExc = null;
            if ($attempt + 1 >= $this->maxAttempts) {
                return $resp;
            }
            $this->sleep($this->backoffDelay($attempt));
        }

        if ($lastResp !== null) {
            return $lastResp;
        }
        // Exhausted on exceptions — the inner throw already covered this; this
        // branch is unreachable in normal flow but keeps the type checker happy.
        if ($lastExc !== null) {
            throw $lastExc;
        }
        throw new \LogicException('Retry loop exited without a result or exception');
    }

    private function shouldRetryResponse(HttpResponse $resp): bool
    {
        $s = $resp->httpStatus;
        return match ($this->retryClass) {
            RetryClass::READ => in_array($s, [self::HTTP_REQUEST_TIMEOUT, self::HTTP_TOO_MANY_REQUESTS], true)
                || $s >= self::HTTP_SERVER_ERROR_FLOOR,
            RetryClass::RECOGNITION => $s >= self::HTTP_SERVER_ERROR_FLOOR,
            RetryClass::MUTATING, RetryClass::NONE => false,
        };
    }

    private function shouldRetryException(\Throwable $exc): bool
    {
        return match ($this->retryClass) {
            RetryClass::READ => $exc instanceof TransferException,
            RetryClass::RECOGNITION, RetryClass::MUTATING => $exc instanceof ConnectException,
            RetryClass::NONE => false,
        };
    }

    private function backoffDelay(int $attempt): float
    {
        $base = min($this->backoffFactor * (2 ** $attempt), self::BACKOFF_MAX_SECONDS);
        $jitter = 0.5 + (mt_rand() / mt_getrandmax());
        return $base * $jitter;
    }

    private function sleep(float $seconds): void
    {
        $microSeconds = (int) round($seconds * 1_000_000);
        if ($microSeconds > 0) {
            usleep($microSeconds);
        }
    }
}
