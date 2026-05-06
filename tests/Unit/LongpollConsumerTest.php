<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\LongpollConsumer;
use PHPUnit\Framework\TestCase;

final class LongpollConsumerTest extends TestCase
{
    public function testIterateYieldsResponses(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout', 'timestamp' => 100,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 200,
            'result' => ['radio_id' => 1, 'results' => []],
        ]));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            backoffFactor: 0.001,
        );
        $iter = $consumer->iterate(timeout: 1);
        $first = $iter->current();
        self::assertSame('no events before timeout', $first['timeout']);
        $iter->next();
        $second = $iter->current();
        self::assertSame(200, $second['time']);
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
        $this->expectException(AudDServerException::class);
        $iter = $consumer->iterate();
        $iter->current();
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
        $this->expectException(AudDSerializationException::class);
        $iter = $consumer->iterate();
        $iter->current();
    }

    public function testNoTokenAddedToQuery(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, ['timeout' => 'x']));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
        );
        $iter = $consumer->iterate();
        $iter->current();

        $req = $mock->history[0]['request'];
        $query = $req->getUri()->getQuery();
        self::assertStringNotContainsString('api_token', $query);
        self::assertStringContainsString('category=abc', $query);
    }

    public function testRetriesOn5xx(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(503, ['err' => 'boom']));
        $mock->handler->append(MockHttp::jsonResponse(200, ['timeout' => 'ok']));

        $consumer = new LongpollConsumer(
            category: 'abc',
            httpClient: $mock->buildClient(),
            maxRetries: 3,
            backoffFactor: 0.001,
        );
        $iter = $consumer->iterate();
        $first = $iter->current();
        self::assertSame('ok', $first['timeout']);
        self::assertCount(2, $mock->history);
    }
}
