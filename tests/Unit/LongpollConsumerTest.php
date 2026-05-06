<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\LongpollConsumer;
use AudD\Models\StreamCallbackMatch;
use PHPUnit\Framework\TestCase;

final class LongpollConsumerTest extends TestCase
{
    public function testIterateInvokesMatchHandler(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout', 'timestamp' => 100,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 200,
            'timestamp' => 200,
            'result' => [
                'radio_id' => 1,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 90]],
            ],
        ]));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            backoffFactor: 0.001,
        );
        $poll = $consumer->iterate(timeout: 1);
        $matches = [];
        $poll->onMatch(function (StreamCallbackMatch $m) use ($poll, &$matches): void {
            $matches[] = $m;
            $poll->close();
        });
        $poll->run();
        self::assertCount(1, $matches);
        self::assertSame('A', $matches[0]->song->artist);
        // Sanity: the keepalive consumed one HTTP call, the match the next.
        self::assertCount(2, $mock->history);
    }

    public function testNon2xxRaisesServerException(): void
    {
        $mock = new MockHttp();
        // S5: even with no token, a 401 must abort, not loop.
        $mock->handler->append(MockHttp::rawResponse(401, '<html>nope</html>'));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            maxRetries: 1,
            backoffFactor: 0.001,
        );
        $poll = $consumer->iterate();
        $captured = null;
        $poll->onError(function (\Throwable $e) use (&$captured): void {
            $captured = $e;
        });
        $poll->run();
        self::assertInstanceOf(AudDServerException::class, $captured);
    }

    public function test2xxBadJsonRaisesSerializationException(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::rawResponse(200, '<not-json>'));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            backoffFactor: 0.001,
        );
        $poll = $consumer->iterate();
        $captured = null;
        $poll->onError(function (\Throwable $e) use (&$captured): void {
            $captured = $e;
        });
        $poll->run();
        self::assertInstanceOf(AudDSerializationException::class, $captured);
    }

    public function testNoTokenAddedToQuery(): void
    {
        $mock = new MockHttp();
        // First a keepalive, then enough to terminate via close()
        $mock->handler->append(MockHttp::jsonResponse(200, ['timeout' => 'x', 'timestamp' => 1]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 2, 'timestamp' => 2,
            'result' => ['radio_id' => 1, 'results' => [['artist' => 'A', 'title' => 'B', 'score' => 90]]],
        ]));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
        );
        $poll = $consumer->iterate();
        $poll->onMatch(fn () => $poll->close());
        $poll->run();

        $req = $mock->history[0]['request'];
        $query = $req->getUri()->getQuery();
        self::assertStringNotContainsString('api_token', $query);
        self::assertStringContainsString('category=abc', $query);
    }

    public function testRetriesOn5xx(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(503, ['err' => 'boom']));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'ok', 'timestamp' => 1,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 2, 'timestamp' => 2,
            'result' => ['radio_id' => 1, 'results' => [['artist' => 'A', 'title' => 'B', 'score' => 90]]],
        ]));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            maxRetries: 3,
            backoffFactor: 0.001,
        );
        $poll = $consumer->iterate();
        $poll->onMatch(fn () => $poll->close());
        $poll->run();
        // 503 → retry; keepalive (consumed silently); match.
        self::assertCount(3, $mock->history);
    }

    public function testKeepaliveIsSilentlyAbsorbed(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout', 'timestamp' => 100,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 200, 'timestamp' => 200,
            'result' => ['radio_id' => 1, 'results' => [['artist' => 'A', 'title' => 'B', 'score' => 90]]],
        ]));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            backoffFactor: 0.001,
        );
        $poll = $consumer->iterate();
        $matches = [];
        $notifications = [];
        $poll->onMatch(function ($m) use (&$matches, $poll): void {
            $matches[] = $m;
            $poll->close();
        });
        $poll->onNotification(function ($n) use (&$notifications): void {
            $notifications[] = $n;
        });
        $poll->run();
        // Keepalive must NOT surface as a notification; only the match should
        // reach the consumer.
        self::assertCount(1, $matches);
        self::assertCount(0, $notifications);
        // since_time must have advanced — second request must include it.
        $secondQuery = $mock->history[1]['request']->getUri()->getQuery();
        self::assertStringContainsString('since_time=100', $secondQuery);
    }
}
