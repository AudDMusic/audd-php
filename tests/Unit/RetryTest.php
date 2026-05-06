<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDServerException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    public function testReadPolicyRetriesOn500(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(500, ['err' => 'boom']));
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        // recognize uses RECOGNITION which retries 5xx.
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), backoffFactor: 0.001);
        $r = $audd->recognize('https://x.mp3');
        self::assertNull($r);
        self::assertCount(2, $mock->history);
    }

    public function testRecognitionPolicyDoesNotRetryReadTimeoutAfterUpload(): void
    {
        // Simulate post-upload read timeout: a TransferException that's NOT a
        // ConnectException. Spec §7.1 says recognition shouldn't retry these.
        // We use a generic Guzzle TransferException via a stream error.
        $mock = new MockHttp();
        $err = new \GuzzleHttp\Exception\RequestException(
            'read timeout after upload',
            new Request('POST', 'https://x'),
        );
        $mock->handler->append($err);

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), backoffFactor: 0.001);
        // Either the underlying RequestException or our typed AudDConnectionException
        // is acceptable; the load-bearing assertion is that no retry happened.
        try {
            $audd->recognize('https://x.mp3');
            self::fail('expected exception');
        } catch (\GuzzleHttp\Exception\RequestException | \AudD\Errors\AudDConnectionException) {
            self::assertCount(1, $mock->history);
        }
    }

    public function testMutatingPolicyDoesNotRetry5xx(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(503, ['err' => 'boom']));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), backoffFactor: 0.001);
        try {
            $audd->streams()->delete(1);
            self::fail('expected error');
        } catch (AudDServerException) {
            self::assertCount(1, $mock->history);
        }
    }

    public function testRecognitionPolicyRetriesPreUploadConnectException(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(new ConnectException(
            'connection refused',
            new Request('POST', 'https://x'),
        ));
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), backoffFactor: 0.001);
        $r = $audd->recognize('https://x.mp3');
        self::assertNull($r);
        self::assertCount(2, $mock->history);
    }

    public function testReadPolicyExhaustsRetriesAndSurfacesConnectionException(): void
    {
        $mock = new MockHttp();
        $err = new ConnectException('refused', new Request('POST', 'https://x'));
        $mock->handler->append($err);
        $mock->handler->append($err);
        $mock->handler->append($err);

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), backoffFactor: 0.001);
        $this->expectException(AudDConnectionException::class);
        $audd->streams()->list();
    }
}
