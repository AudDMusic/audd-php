<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConnectionException;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Errors\AudDSerializationException;
use AudD\LongpollConsumer;
use AudD\LongpollPoll;
use AudD\Models\StreamCallbackMatch;
use AudD\Models\StreamCallbackNotification;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The longpoll loop against the three response shapes the endpoint sends
 * (events, timeout, error), the top-level callback shape, unusable bodies
 * and transport failures.
 */
final class LongpollResponsesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/';

    public function testEventsFixtureDeliversBothSongsInOrderAndAdvancesSinceTime(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(self::fixtureResponse('longpoll_events.json'));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $audd = new AudD(apiToken: 'super-secret-token', backoffFactor: 0.001, httpClient: $mock->buildClient());
        $poll = $audd->streams()->longpoll('92b1cc7f0', skipCallbackCheck: true);
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->errors);
        self::assertSame([], $rec->notifications);
        self::assertCount(2, $rec->matches);
        self::assertSame('Alan Walker, A$AP Rocky', $rec->matches[0]->song->artist);
        self::assertSame('Live Fast (PUBGM)', $rec->matches[0]->song->title);
        self::assertSame(7, $rec->matches[0]->radio_id);
        self::assertSame('Tears For Fears', $rec->matches[1]->song->artist);
        self::assertSame('Everybody Wants To Rule The World', $rec->matches[1]->song->title);
        self::assertSame(7, $rec->matches[1]->radio_id);

        self::assertCount(2, $mock->history);
        self::assertStringNotContainsString('since_time', self::query($mock, 0));
        self::assertStringContainsString('since_time=1795547548112', self::query($mock, 1));
        foreach ($mock->history as $entry) {
            $uri = (string) $entry['request']->getUri();
            self::assertStringNotContainsString('api_token', $uri);
            self::assertStringNotContainsString('super-secret-token', $uri);
            self::assertStringNotContainsString('super-secret-token', (string) $entry['request']->getBody());
            foreach ($entry['request']->getHeaders() as $values) {
                foreach ($values as $value) {
                    self::assertStringNotContainsString('super-secret-token', $value);
                }
            }
        }
    }

    public function testTimeoutFixtureDeliversNothingAndSendsItsTimestamp(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(self::fixtureResponse('longpoll_no_events.json'));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate(sinceTime: 1777901000000);
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->matches);
        self::assertSame([], $rec->notifications);
        self::assertSame([], $rec->errors);
        self::assertCount(2, $mock->history);
        self::assertStringContainsString('since_time=1777901000000', self::query($mock, 0));
        self::assertStringContainsString('since_time=1777901270049', self::query($mock, 1));
    }

    public function testErrorFixtureReachesOnErrorOnceAndStops(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(self::fixtureResponse('longpoll_error.json'));
        $mock->handler->append(self::fixtureResponse('longpoll_error.json'));
        $mock->handler->append(self::fixtureResponse('longpoll_no_events.json'));

        $poll = self::consumer($mock)->iterate(timeout: 500);
        $rec = self::record($poll);
        $poll->run();

        self::assertCount(1, $mock->history, 'an error body must not be requested again');
        self::assertSame([], $rec->matches);
        self::assertCount(1, $rec->errors);
        self::assertInstanceOf(AudDInvalidRequestException::class, $rec->errors[0]);
        self::assertSame('Invalid timeout arg.  Must be 1-120.', $rec->errors[0]->apiMessage);
    }

    public function testErrorBodyIsThrownFromRunWithoutOnError(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(self::fixtureResponse('longpoll_error.json'));

        $poll = self::consumer($mock)->iterate();
        try {
            $poll->run();
            self::fail('expected AudDInvalidRequestException');
        } catch (AudDInvalidRequestException $e) {
            self::assertStringContainsString('Must be 1-120', $e->getMessage());
        }
        self::assertCount(1, $mock->history);
    }

    public function testTopLevelCallbackShapeStillDelivers(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => '-',
            'notification' => [
                'radio_id' => 3, 'stream_running' => false,
                'notification_code' => 650, 'notification_message' => "can't connect",
            ],
            'time' => 1587939136,
            'timestamp' => 1587939136000,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => ['radio_id' => 4, 'results' => [['artist' => 'A', 'title' => 'B', 'score' => 90]]],
            'timestamp' => 1587939137000,
        ]));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate();
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->errors);
        self::assertCount(1, $rec->notifications);
        self::assertSame(650, $rec->notifications[0]->notification_code);
        self::assertCount(1, $rec->matches);
        self::assertSame('A', $rec->matches[0]->song->artist);
        self::assertStringContainsString('since_time=1587939136000', self::query($mock, 1));
        self::assertStringContainsString('since_time=1587939137000', self::query($mock, 2));
    }

    public function testEventDataAsJsonStringIsDecoded(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $events = self::fixture('longpoll_events.json');
        $events['events'][0]['data'] = json_encode($events['events'][0]['data'], JSON_THROW_ON_ERROR);
        $mock->handler->append(MockHttp::jsonResponse(200, $events));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate();
        $rec = self::record($poll);
        $poll->run();

        self::assertCount(2, $rec->matches);
        self::assertSame('Live Fast (PUBGM)', $rec->matches[0]->song->title);
    }

    public function testUnparseableAndEmptyResultEventsAreSkipped(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(MockHttp::jsonResponse(200, ['events' => [
            ['timestamp' => 10, 'data' => 'not json'],
            ['timestamp' => 20, 'data' => ['status' => 'success', 'result' => ['radio_id' => 1, 'results' => []]]],
            ['timestamp' => 30, 'data' => ['something' => 'else']],
            'not-an-object',
            ['timestamp' => 40, 'data' => [
                'status' => 'success',
                'result' => ['radio_id' => 2, 'results' => [['artist' => 'Kept', 'title' => 'T', 'score' => 99]]],
            ]],
        ]]));
        // Every event skipped, but the timestamps still move since_time on.
        $mock->handler->append(MockHttp::jsonResponse(200, ['events' => [
            ['timestamp' => 50, 'data' => ['status' => 'success', 'result' => ['radio_id' => 1, 'results' => []]]],
        ]]));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate();
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->errors);
        self::assertSame([], $rec->notifications);
        self::assertCount(1, $rec->matches);
        self::assertSame('Kept', $rec->matches[0]->song->artist);
        self::assertCount(3, $mock->history);
        self::assertStringContainsString('since_time=40', self::query($mock, 1));
        self::assertStringContainsString('since_time=50', self::query($mock, 2));
    }

    public function testEmptyEventsListKeepsSinceTimeAndPollsAgain(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(MockHttp::jsonResponse(200, ['events' => []]));
        $mock->handler->append(MockHttp::jsonResponse(200, ['events' => []]));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate(sinceTime: 123);
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->errors);
        self::assertCount(3, $mock->history);
        for ($i = 0; $i < 3; $i++) {
            self::assertStringContainsString('since_time=123', self::query($mock, $i));
        }
    }

    public function testJunkBodiesBackOffAndEndTheSubscriptionAfterTheBudget(): void
    {
        $mock = new MockHttp();
        $times = [];
        $bodies = ['<html>oops</html>', '', '{}', '[1,2]', '{"unknown":"shape"}'];
        for ($i = 0; $i < 30; $i++) {
            $body = $bodies[$i % count($bodies)];
            $mock->handler->append(function () use (&$times, $body): ResponseInterface {
                $times[] = hrtime(true);
                return MockHttp::rawResponse(200, $body);
            });
        }

        $poll = self::consumer($mock)->iterate();
        $rec = self::record($poll);
        $started = microtime(true);
        $poll->run();

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertSame([], $rec->matches);
        self::assertCount(8, $mock->history, 'junk must stop after 8 polls in a row, not spin');
        self::assertCount(1, $rec->errors);
        self::assertInstanceOf(AudDSerializationException::class, $rec->errors[0]);
        // Each wait is longer than the one before (backoff, not a tight loop).
        $gaps = [];
        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = $times[$i] - $times[$i - 1];
        }
        self::assertGreaterThan($gaps[0], $gaps[count($gaps) - 1]);
    }

    public function testJunkBodyWaitsAboutASecondWithDefaultBackoff(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $times = [];
        $mock->handler->append(function () use (&$times): ResponseInterface {
            $times[] = microtime(true);
            return MockHttp::rawResponse(200, '<not-json>');
        });
        $mock->handler->append(function () use (&$times, &$poll): ResponseInterface {
            $times[] = microtime(true);
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $consumer = new LongpollConsumer(category: 'abc', httpClient: $mock->buildClient());
        $poll = $consumer->iterate();
        $poll->run();

        self::assertCount(2, $times);
        $gap = $times[1] - $times[0];
        self::assertGreaterThanOrEqual(0.45, $gap);
        self::assertLessThan(2.0, $gap);
    }

    public function testTransientFailuresDoNotEndTheSubscription(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $mock->handler->append(new ConnectException('connection refused', new Request('GET', 'https://api.audd.io/longpoll/')));
        $mock->handler->append(MockHttp::jsonResponse(503, ['err' => 'unavailable']));
        $mock->handler->append(MockHttp::rawResponse(502, '<html>bad gateway</html>'));
        $mock->handler->append(self::fixtureResponse('longpoll_events.json'));
        $mock->handler->append(function () use (&$poll): ResponseInterface {
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock, maxRetries: 1)->iterate();
        $rec = self::record($poll);
        $poll->run();

        self::assertSame([], $rec->errors);
        self::assertCount(2, $rec->matches);
        self::assertCount(5, $mock->history);
    }

    public function testPersistentNetworkFailureReachesOnErrorAfterTheBudget(): void
    {
        $mock = new MockHttp();
        for ($i = 0; $i < 20; $i++) {
            $mock->handler->append(new ConnectException('connection refused', new Request('GET', 'https://api.audd.io/longpoll/')));
        }

        $poll = self::consumer($mock, maxRetries: 1)->iterate();
        $rec = self::record($poll);
        $poll->run();

        self::assertCount(8, $mock->history);
        self::assertCount(1, $rec->errors);
        self::assertInstanceOf(AudDConnectionException::class, $rec->errors[0]);
        self::assertStringContainsString('connection refused', $rec->errors[0]->getMessage());
    }

    public function testReadTimeoutCoversTheServerSideWait(): void
    {
        $mock = new MockHttp();
        $poll = null;
        $seen = null;
        $mock->handler->append(function (RequestInterface $r, array $options) use (&$poll, &$seen): ResponseInterface {
            $seen = $options;
            $poll->close();
            return self::fixtureResponse('longpoll_no_events.json');
        });

        $poll = self::consumer($mock)->iterate(timeout: 120);
        $poll->run();

        self::assertIsArray($seen);
        self::assertSame(150.0, $seen['read_timeout']);
        if (\extension_loaded('curl')) {
            self::assertSame(150, $seen['curl'][CURLOPT_LOW_SPEED_TIME]);
        }
    }

    public function testCloseInsideAHandlerDropsTheRestOfTheResponse(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(self::fixtureResponse('longpoll_events.json'));

        $poll = self::consumer($mock)->iterate();
        $matches = [];
        $poll->onMatch(function (StreamCallbackMatch $m) use (&$matches, $poll): void {
            $matches[] = $m;
            $poll->close();
        });
        $poll->run();

        self::assertCount(1, $matches);
        self::assertCount(1, $mock->history);
    }

    private static function consumer(MockHttp $mock, int $maxRetries = 3): LongpollConsumer
    {
        return new LongpollConsumer(
            category: 'abc123def',
            maxRetries: $maxRetries,
            backoffFactor: 0.001,
            httpClient: $mock->buildClient(),
        );
    }

    private static function record(LongpollPoll $poll): LongpollRecorder
    {
        $rec = new LongpollRecorder();
        $poll->onMatch(function (StreamCallbackMatch $m) use ($rec): void {
            $rec->matches[] = $m;
        });
        $poll->onNotification(function (StreamCallbackNotification $n) use ($rec): void {
            $rec->notifications[] = $n;
        });
        $poll->onError(function (\Throwable $e) use ($rec): void {
            $rec->errors[] = $e;
        });
        return $rec;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $raw = file_get_contents(self::FIXTURES . $name);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private static function fixtureResponse(string $name): ResponseInterface
    {
        $raw = file_get_contents(self::FIXTURES . $name);
        self::assertIsString($raw);
        return MockHttp::rawResponse(200, $raw, ['Content-Type' => 'application/json']);
    }

    private static function query(MockHttp $mock, int $index): string
    {
        return $mock->history[$index]['request']->getUri()->getQuery();
    }
}
