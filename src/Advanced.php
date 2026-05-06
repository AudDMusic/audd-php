<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDSerializationException;
use AudD\Errors\ErrorMapping;
use AudD\Internal\HttpClient;
use AudD\Internal\Retry;
use AudD\Models\LyricsResult;
use GuzzleHttp\Exception\TransferException;

/**
 * Advanced namespace — lyrics search and a generic raw-request escape hatch.
 * Reached only via `$audd->advanced->...`.
 *
 * Note: the locked pattern requires Advanced to use the RECOGNITION retry
 * policy (find_lyrics is metered; spec §7.1 puts it in the same cost class
 * as recognize).
 */
final class Advanced
{
    private const API_BASE = 'https://api.audd.io';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Retry $recognitionPolicy,
    ) {
    }

    /**
     * @return list<LyricsResult>
     */
    public function findLyrics(string $query): array
    {
        $body = $this->rawRequest('findLyrics', ['q' => $query]);
        if (($body['status'] ?? null) === 'error') {
            ErrorMapping::raiseFromErrorResponse($body, 200, null);
        }
        $rawResults = $body['result'] ?? [];
        if (!is_array($rawResults)) {
            return [];
        }
        $out = [];
        foreach ($rawResults as $row) {
            if (is_array($row)) {
                $out[] = new LyricsResult($row);
            }
        }
        return $out;
    }

    /**
     * Hit any AudD endpoint by method name. Useful for endpoints not yet
     * wrapped by typed methods on this SDK.
     *
     * @param array<string, scalar>|null $params
     *
     * @return array<string, mixed>
     */
    public function rawRequest(string $method, ?array $params = null): array
    {
        $data = $params ?? [];

        $do = function () use ($method, $data): \AudD\Internal\HttpResponse {
            return $this->http->postForm(self::API_BASE . '/' . $method . '/', $data);
        };

        try {
            $resp = $this->recognitionPolicy->run($do);
        } catch (TransferException $exc) {
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }
        $body = $resp->jsonBody;
        if (!is_array($body)) {
            throw new AudDSerializationException('Unparseable response', $resp->rawText);
        }
        /** @var array<string, mixed> $body */
        return $body;
    }
}
