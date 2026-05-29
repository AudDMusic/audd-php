<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDServerException;
use AudD\Internal\Source;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * customCatalog.add is metered — auto-retry on transport failure could
 * double-charge for the same fingerprinting work. The SDK must therefore
 * make exactly one upload attempt, regardless of maxRetries.
 */
final class CustomCatalogRetryTest extends TestCase
{
    public function testCustomCatalogAddDoesNotRetryOn5xx(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(503, ['err' => 'service unavailable']));
        // Defensively queue a second response — if the SDK wrongly retries, it
        // will consume this and the count assertion below will catch it.
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        // maxRetries: 5 makes the test assertion meaningful — even with
        // generous retries configured, customCatalog.add must stay single-shot.
        $audd = new AudD(
            apiToken: 'test',
            maxRetries: 5,
            backoffFactor: 0.001,
            httpClient: $mock->buildClient(),
        );

        try {
            $audd->customCatalog()->add(123, Source::bytes('fake audio bytes'));
            self::fail('expected AudDServerException on 503');
        } catch (AudDServerException) {
            self::assertCount(1, $mock->history, 'customCatalog.add must make exactly one attempt on 5xx');
        }
    }

    public function testCustomCatalogAddDoesNotRetryOnPreUploadConnectError(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(new ConnectException(
            'connection refused',
            new Request('POST', 'https://api.audd.io/upload/'),
        ));
        // Same defensive second entry — should remain unconsumed.
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        $audd = new AudD(
            apiToken: 'test',
            maxRetries: 5,
            backoffFactor: 0.001,
            httpClient: $mock->buildClient(),
        );

        try {
            $audd->customCatalog()->add(456, Source::bytes('fake audio bytes'));
            self::fail('expected AudDConnectionException on pre-upload connect failure');
        } catch (AudDConnectionException) {
            self::assertCount(
                1,
                $mock->history,
                'customCatalog.add must make exactly one attempt on connect failure',
            );
        }
    }
}
