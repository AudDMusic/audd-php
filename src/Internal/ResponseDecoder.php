<?php

declare(strict_types=1);

namespace AudD\Internal;

use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\Errors\ErrorMapping;
use Psr\Log\LoggerInterface;

/**
 * Shared response decoder. Distinguishes:
 *   - HTTP non-2xx + non-JSON body → AudDServerException (preserves status)
 *   - HTTP 2xx + non-JSON body     → AudDSerializationException
 *   - status=error + code 51 + result → emit E_USER_DEPRECATED, strip error, fall through
 *   - status=error otherwise        → typed AudDApiException via ErrorMapping
 *   - status=success                → returns the decoded body dict
 *
 * @internal
 */
final class ResponseDecoder
{
    private const HTTP_CLIENT_ERROR_FLOOR = 400;
    public const DEPRECATED_PARAMS_CODE = 51;

    /**
     * @return array<string, mixed>
     */
    public static function decodeOrThrow(
        HttpResponse $resp,
        bool $customCatalogContext = false,
        ?LoggerInterface $logger = null,
    ): array {
        $body = $resp->jsonBody;

        if (!is_array($body) || !self::isAssoc($body)) {
            if ($resp->httpStatus >= self::HTTP_CLIENT_ERROR_FLOOR) {
                throw new AudDServerException(
                    errorCode: 0,
                    apiMessage: sprintf('HTTP %d with non-JSON response body', $resp->httpStatus),
                    httpStatus: $resp->httpStatus,
                    requestId: $resp->requestId,
                    rawResponse: $resp->rawText,
                );
            }
            throw new AudDSerializationException('Unparseable response', $resp->rawText);
        }

        self::maybeWarnAndStripDeprecation($body, $logger);

        $status = $body['status'] ?? null;
        if ($status === 'error') {
            ErrorMapping::raiseFromErrorResponse(
                $body,
                $resp->httpStatus,
                $resp->requestId,
                $customCatalogContext,
            );
        }
        if ($status === 'success') {
            return $body;
        }
        throw new AudDServerException(
            errorCode: 0,
            apiMessage: sprintf('Unexpected response status: %s', var_export($status, true)),
            httpStatus: $resp->httpStatus,
            requestId: $resp->requestId,
            rawResponse: $body,
        );
    }

    /**
     * If body carries a code-51 deprecation warning + a usable result,
     * emit a PHP-native E_USER_DEPRECATED notice, mirror it on the optional
     * PSR-3 logger at `warning` level, and rewrite the body to look like a
     * normal success response.
     *
     * @param array<string, mixed> $body
     */
    private static function maybeWarnAndStripDeprecation(array &$body, ?LoggerInterface $logger = null): void
    {
        $err = $body['error'] ?? null;
        if (!is_array($err)) {
            return;
        }
        $code = (int) ($err['error_code'] ?? 0);
        if ($code !== self::DEPRECATED_PARAMS_CODE) {
            return;
        }
        if (!array_key_exists('result', $body) || $body['result'] === null) {
            return;
        }
        $msg = (string) ($err['error_message'] ?? 'Deprecated parameter used');
        @trigger_error($msg, E_USER_DEPRECATED);
        $logger?->warning('audd: deprecated parameter', ['message' => $msg]);
        unset($body['error']);
        $body['status'] = 'success';
    }

    /**
     * @param array<int|string, mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        foreach (array_keys($arr) as $k) {
            if (is_string($k)) {
                return true;
            }
        }
        return false;
    }
}
