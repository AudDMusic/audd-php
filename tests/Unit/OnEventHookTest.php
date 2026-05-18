<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\AudDEvent;
use AudD\AudDEventKind;
use AudD\Errors\AudDConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * v0.2.0 Item D: onEvent inspection hook. Spec §7.7a.
 *
 * The hook receives Request/Response/Exception events around each
 * recognize / recognizeEnterprise call. Hook exceptions are swallowed and
 * logged. The event payload never includes the api_token or request body bytes.
 */
final class OnEventHookTest extends TestCase
{
    public function testEmitsRequestAndResponseEventsAroundRecognize(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(
            200,
            ['status' => 'success', 'result' => ['timecode' => '00:01']],
            ['x-request-id' => 'req-abc'],
        ));

        /** @var list<AudDEvent> $events */
        $events = [];
        $audd = new AudD(
            apiToken: 'test',
            httpClient: $mock->buildClient(),
            onEvent: function (AudDEvent $e) use (&$events): void {
                $events[] = $e;
            },
        );
        $audd->recognize('https://x.mp3');

        self::assertCount(2, $events);
        self::assertSame(AudDEventKind::Request, $events[0]->kind);
        self::assertSame('recognize', $events[0]->method);
        self::assertSame('https://api.audd.io/', $events[0]->url);
        self::assertNull($events[0]->httpStatus);

        self::assertSame(AudDEventKind::Response, $events[1]->kind);
        self::assertSame(200, $events[1]->httpStatus);
        self::assertSame('req-abc', $events[1]->requestId);
        self::assertNotNull($events[1]->elapsedMs);
        self::assertGreaterThanOrEqual(0.0, $events[1]->elapsedMs);
    }

    public function testEmitsExceptionEventOnConnectionError(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(new \GuzzleHttp\Exception\ConnectException(
            'connection refused',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.audd.io/'),
        ));

        /** @var list<AudDEvent> $events */
        $events = [];
        $audd = new AudD(
            apiToken: 'test',
            httpClient: $mock->buildClient(),
            maxRetries: 1,
            onEvent: function (AudDEvent $e) use (&$events): void {
                $events[] = $e;
            },
        );
        try {
            $audd->recognize('https://x.mp3');
            self::fail('Expected AudDConnectionException');
        } catch (AudDConnectionException) {
        }

        self::assertNotEmpty($events);
        $last = $events[count($events) - 1];
        self::assertSame(AudDEventKind::Exception, $last->kind);
        self::assertSame('recognize', $last->method);
        self::assertArrayHasKey('error_type', $last->extras);
    }

    public function testHookExceptionsAreSwallowed(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        $audd = new AudD(
            apiToken: 'test',
            httpClient: $mock->buildClient(),
            onEvent: function (AudDEvent $e): void {
                throw new \RuntimeException('boom');
            },
        );
        // Hook exception MUST NOT propagate. With the default NullLogger no
        // diagnostic surface is emitted — see LoggerTest for the PSR-3 path.
        self::assertNull($audd->recognize('https://x.mp3'));
    }

    public function testEventPayloadOmitsApiToken(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        /** @var list<AudDEvent> $events */
        $events = [];
        $audd = new AudD(
            apiToken: 'super-secret-token',
            httpClient: $mock->buildClient(),
            onEvent: function (AudDEvent $e) use (&$events): void {
                $events[] = $e;
            },
        );
        $audd->recognize('https://x.mp3');

        // The api_token must not appear anywhere in the event surface.
        foreach ($events as $e) {
            $serialized = json_encode([
                'kind' => $e->kind->name,
                'method' => $e->method,
                'url' => $e->url,
                'request_id' => $e->requestId,
                'http_status' => $e->httpStatus,
                'elapsed_ms' => $e->elapsedMs,
                'error_code' => $e->errorCode,
                'extras' => $e->extras,
            ]);
            self::assertIsString($serialized);
            self::assertStringNotContainsString('super-secret-token', $serialized);
        }
    }

    public function testEnterpriseRecognizeAlsoEmitsEvents(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => [],
        ]));

        /** @var list<AudDEvent> $events */
        $events = [];
        $audd = new AudD(
            apiToken: 'test',
            httpClient: $mock->buildClient(),
            onEvent: function (AudDEvent $e) use (&$events): void {
                $events[] = $e;
            },
        );
        $audd->recognizeEnterprise('https://x.mp3', limit: 1);

        self::assertCount(2, $events);
        self::assertSame('https://enterprise.audd.io/', $events[0]->url);
        self::assertSame('recognize', $events[0]->method);
    }

    public function testNoEventsEmittedWhenHookIsNull(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));

        // Sanity: not passing onEvent must not blow up.
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        self::assertNull($audd->recognize('https://x.mp3'));
    }

    public function testAudDEventKindEnumCases(): void
    {
        self::assertNotSame(AudDEventKind::Request, AudDEventKind::Response);
        self::assertNotSame(AudDEventKind::Response, AudDEventKind::Exception);
    }
}
