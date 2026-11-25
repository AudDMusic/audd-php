<?php

declare(strict_types=1);

namespace AudD;

use AudD\Errors\AudDConnectionException;
use AudD\Internal\HttpClient;
use AudD\Internal\ResponseDecoder;
use AudD\Internal\Retry;
use AudD\Internal\Source;
use AudD\Internal\SourceBytes;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\StreamInterface;

/**
 * Custom-catalog endpoint. Reached via `$audd->customCatalog()->...`.
 *
 * NOT for music recognition — see `add()` method docstring.
 */
final class CustomCatalog
{
    private const UPLOAD_URL = 'https://api.audd.io/upload/';

    /**
     * @param Retry $retryPolicy The SDK passes a no-retry policy: `add()` is
     *  sent once, and the caller decides whether to send it again.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Retry $retryPolicy,
    ) {
    }

    /**
     * **This is NOT how you submit audio for music recognition.** For
     * recognition, use `$audd->recognize(...)` (or `recognizeEnterprise(...)`
     * to scan beyond the first 12 seconds of a file). This method adds a
     * song to your **private fingerprint catalog** so AudD's recognition can
     * later identify *your own* tracks for *your account only*. Requires special
     * access — contact api@audd.io if you need it enabled.
     *
     * Calling this again with the same `$audioId` re-fingerprints that slot.
     * There is no public list/delete endpoint; track audio_id ↔ song mappings
     * on your side.
     *
     * Retry behavior: `add()` is sent once and never retried automatically —
     * any failure, including a transport error, surfaces as an exception.
     * Check the error before sending it again (an upload to an `audio_id`
     * that already holds a song replaces it).
     *
     * @phpstan-param string|StreamInterface|SourceBytes|resource $source
     */
    public function add(int $audioId, mixed $source): void
    {
        $reopen = Source::prepare($source);

        $do = function () use ($reopen, $audioId): \AudD\Internal\HttpResponse {
            [$data, $files] = $reopen();
            $data['audio_id'] = (string) $audioId;
            return $this->http->postMultipart(self::UPLOAD_URL, $data, $files ?? []);
        };

        try {
            $resp = $this->retryPolicy->run($do);
        } catch (TransferException $exc) {
            throw new AudDConnectionException($exc->getMessage(), $exc);
        }
        // Throws on error; success is ignored since the body just confirms.
        ResponseDecoder::decodeOrThrow($resp, customCatalogContext: true);
    }
}
