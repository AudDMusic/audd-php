<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Errors\AudDSerializationException;
use AudD\Helpers;
use AudD\Models\StreamCallbackMatch;
use AudD\Streams;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class StreamsTest extends TestCase
{
    public function testListReturnsTypedStreams(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                ['radio_id' => 1, 'url' => 'https://radio.example/live.mp3', 'stream_running' => true,
                    'longpoll_category' => 'cat123abc'],
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $streams = $audd->streams()->list();
        self::assertCount(1, $streams);
        self::assertSame(1, $streams[0]->radio_id);
        self::assertSame('cat123abc', $streams[0]->longpoll_category);
    }

    public function testGetCallbackUrlEmpty(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => 'https://my.app/cb',
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        self::assertSame('https://my.app/cb', $audd->streams()->getCallbackUrl());
    }

    public function testSetCallbackUrlMergesReturnMetadata(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $audd->streams()->setCallbackUrl('https://my.app/cb', returnMetadata: ['apple_music', 'spotify']);

        $body = (string) $mock->history[0]['request']->getBody();
        self::assertStringContainsString('return%3Dapple_music%252Cspotify', $body);
    }

    public function testSetCallbackUrlRefusesDuplicateReturnParam(): void
    {
        $mock = new MockHttp();
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());

        $this->expectException(AudDInvalidRequestException::class);
        $this->expectExceptionMessageMatches('/refusing to silently overwrite/');
        $audd->streams()->setCallbackUrl('https://x.test/?return=spotify', returnMetadata: 'apple_music');
    }

    public function testLongpollPreflightFailsWith19RaisesHint(): void
    {
        $mock = new MockHttp();
        // First call: getCallbackUrl preflight returns code 19.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 19, 'error_message' => 'no callback url'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());

        $this->expectException(AudDInvalidRequestException::class);
        $this->expectExceptionMessageMatches("/Longpoll won't deliver events/");
        // Preflight runs at construction time of the poll handle.
        $audd->streams()->longpoll('cat');
    }

    public function testLongpollByRadioIdDerivesCategoryAndDispatches(): void
    {
        $mock = new MockHttp();
        // Preflight (getCallbackUrl) succeeds, then a keepalive then a match.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => 'https://my.app/cb',
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout',
            'timestamp' => 1234567,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 1234600,
            'timestamp' => 1234600,
            'result' => [
                'radio_id' => 1, 'timestamp' => '2020-04-13 10:31:43', 'play_length' => 10,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 100]],
            ],
        ]));
        $audd = new AudD(apiToken: 'test-token', httpClient: $mock->buildClient());
        $expectedCategory = Helpers::deriveLongpollCategory('test-token', 1);

        $poll = $audd->streams()->longpoll(radioId: 1);
        $matches = [];
        $poll->onMatch(function (StreamCallbackMatch $m) use ($poll, &$matches): void {
            $matches[] = $m;
            $poll->close();
        });
        $poll->run();
        self::assertCount(1, $matches);

        // The longpoll request (history index 1; index 0 is the preflight)
        // must carry the derived 9-char category in its query string.
        $longpollUri = (string) $mock->history[1]['request']->getUri();
        self::assertStringContainsString('category=' . $expectedCategory, $longpollUri);
    }

    public function testLongpollRequestNeverSendsApiToken(): void
    {
        $mock = new MockHttp();
        // Preflight (getCallbackUrl) succeeds, then a keepalive, then a match.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => 'https://my.app/cb',
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout',
            'timestamp' => 1234567,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 1234600,
            'timestamp' => 1234600,
            'result' => [
                'radio_id' => 1, 'timestamp' => '2020-04-13 10:31:43', 'play_length' => 10,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 100]],
            ],
        ]));
        $audd = new AudD(apiToken: 'super-secret-token', httpClient: $mock->buildClient());

        $poll = $audd->streams()->longpoll(radioId: 1);
        $poll->onMatch(function (StreamCallbackMatch $m) use ($poll): void {
            $poll->close();
        });
        $poll->run();

        // Every longpoll GET (indices 1 and 2; index 0 is the POST preflight)
        // must omit the api_token entirely — the derived category authorizes it.
        for ($i = 1; $i < count($mock->history); $i++) {
            $uri = (string) $mock->history[$i]['request']->getUri();
            self::assertStringContainsString('/longpoll/', $uri);
            self::assertStringNotContainsString('api_token', $uri);
            self::assertStringNotContainsString('super-secret-token', $uri);
        }
    }

    public function testLongpollKeywordCategoryFormWorks(): void
    {
        $mock = new MockHttp();
        // Preflight succeeds; construction completes without driving the loop.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => 'https://my.app/cb',
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $poll = $audd->streams()->longpoll(category: 'abc123def');
        self::assertNotNull($poll);
        // Only the preflight request has fired so far — construction is lazy.
        self::assertSame(1, count($mock->history));
    }

    public function testLongpollPositionalCategoryStillWorks(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => 'https://my.app/cb',
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        // Positional category (first arg) — must not throw at construction.
        $poll = $audd->streams()->longpoll('abc123def');
        self::assertNotNull($poll);
    }

    public function testLongpollRejectsBothCategoryAndRadioId(): void
    {
        $mock = new MockHttp();
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $this->expectException(AudDInvalidRequestException::class);
        $this->expectExceptionMessageMatches('/exactly one of/');
        $audd->streams()->longpoll(category: 'abc', radioId: 1);
    }

    public function testLongpollRejectsNeitherCategoryNorRadioId(): void
    {
        $mock = new MockHttp();
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $this->expectException(AudDInvalidRequestException::class);
        $this->expectExceptionMessageMatches('/one of .* is required/');
        $audd->streams()->longpoll();
    }

    public function testLongpollSkipCallbackCheckBypassesPreflight(): void
    {
        $mock = new MockHttp();
        // Two responses: one keepalive (silently absorbed), one match.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout',
            'timestamp' => 1234567,
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 1234600,
            'timestamp' => 1234600,
            'result' => [
                'radio_id' => 1, 'timestamp' => '2020-04-13 10:31:43', 'play_length' => 10,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 100]],
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $poll = $audd->streams()->longpoll('cat', skipCallbackCheck: true);
        $matches = [];
        $poll->onMatch(function (StreamCallbackMatch $m) use ($poll, &$matches): void {
            $matches[] = $m;
            $poll->close(); // stop after first match
        });
        $poll->run();
        self::assertCount(1, $matches);
        self::assertSame('A', $matches[0]->song->artist);
    }

    public function testLongpollTreatsUnknownBodyAsKeepalive(): void
    {
        $mock = new MockHttp();
        // A body with neither `result` nor `notification` and no `timeout` key
        // is still a benign keepalive — the loop must continue polling, not
        // terminate with a serialization error.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'something' => 'else',
            'timestamp' => 1234567,
        ]));
        // An empty object is likewise a keepalive.
        $mock->handler->append(MockHttp::jsonResponse(200, []));
        // Then a real match arrives.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'time' => 1234600,
            'timestamp' => 1234600,
            'result' => [
                'radio_id' => 1, 'timestamp' => '2020-04-13 10:31:43', 'play_length' => 10,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 100]],
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());

        $poll = $audd->streams()->longpoll('cat', skipCallbackCheck: true);
        $matches = [];
        $errors = [];
        $poll->onMatch(function (StreamCallbackMatch $m) use ($poll, &$matches): void {
            $matches[] = $m;
            $poll->close();
        });
        $poll->onError(function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $poll->run();

        self::assertSame([], $errors, 'keepalive bodies must not surface as errors');
        self::assertCount(1, $matches);
        self::assertSame('A', $matches[0]->song->artist);
    }

    public function testParseCallbackResultDiscriminator(): void
    {
        $payload = [
            'status' => 'success',
            'result' => [
                'radio_id' => 7,
                'timestamp' => '2020-04-13 10:31:43',
                'play_length' => 111,
                'results' => [['artist' => 'A', 'title' => 'B', 'score' => 100]],
            ],
        ];
        $parsed = Streams::parseCallback($payload);
        self::assertTrue($parsed->isMatch());
        self::assertFalse($parsed->isNotification());
        self::assertNotNull($parsed->match);
        self::assertSame(7, $parsed->match->radio_id);
        self::assertSame('A', $parsed->match->song->artist);
        self::assertSame([], $parsed->match->alternatives);
    }

    public function testParseCallbackSplitsAlternatives(): void
    {
        $payload = [
            'status' => 'success',
            'result' => [
                'radio_id' => 7,
                'results' => [
                    ['artist' => 'A', 'title' => 'B', 'score' => 100],
                    ['artist' => 'AA', 'title' => 'BB', 'score' => 90],
                    ['artist' => 'AAA', 'title' => 'BBB', 'score' => 80],
                ],
            ],
        ];
        $parsed = Streams::parseCallback($payload);
        self::assertNotNull($parsed->match);
        self::assertSame('A', $parsed->match->song->artist);
        self::assertCount(2, $parsed->match->alternatives);
        self::assertSame('AA', $parsed->match->alternatives[0]->artist);
        self::assertSame('AAA', $parsed->match->alternatives[1]->artist);
    }

    public function testParseCallbackNotificationDiscriminator(): void
    {
        $payload = [
            'status' => '-',
            'notification' => [
                'radio_id' => 3,
                'stream_running' => false,
                'notification_code' => 650,
                'notification_message' => 'Recognition failed',
            ],
            'time' => 1587939136,
        ];
        $parsed = Streams::parseCallback($payload);
        self::assertTrue($parsed->isNotification());
        self::assertFalse($parsed->isMatch());
        self::assertNotNull($parsed->notification);
        self::assertSame(650, $parsed->notification->notification_code);
        self::assertSame(1587939136, $parsed->notification->time);
    }

    public function testParseCallbackThrowsOnEmptyResults(): void
    {
        $payload = ['status' => 'success', 'result' => ['radio_id' => 1, 'results' => []]];
        $this->expectException(AudDSerializationException::class);
        $this->expectExceptionMessageMatches('/results is empty/');
        Streams::parseCallback($payload);
    }

    public function testParseCallbackThrowsOnNeitherKey(): void
    {
        $payload = ['status' => 'success', 'something_else' => 1];
        $this->expectException(AudDSerializationException::class);
        $this->expectExceptionMessageMatches('/neither result nor notification/');
        Streams::parseCallback($payload);
    }

    public function testHandleCallbackAcceptsString(): void
    {
        $body = json_encode([
            'status' => 'success',
            'result' => [
                'radio_id' => 4,
                'results' => [['artist' => 'X', 'title' => 'Y', 'score' => 95]],
            ],
        ], JSON_THROW_ON_ERROR);
        $parsed = Streams::handleCallback($body);
        self::assertTrue($parsed->isMatch());
        self::assertSame('X', $parsed->match->song->artist);
    }

    public function testHandleCallbackAcceptsArray(): void
    {
        $body = [
            'status' => 'success',
            'result' => [
                'radio_id' => 4,
                'results' => [['artist' => 'X', 'title' => 'Y', 'score' => 95]],
            ],
        ];
        $parsed = Streams::handleCallback($body);
        self::assertTrue($parsed->isMatch());
        self::assertSame(4, $parsed->match->radio_id);
    }

    public function testHandleCallbackAcceptsPsr7ServerRequest(): void
    {
        $bodyJson = json_encode([
            'status' => '-',
            'notification' => [
                'radio_id' => 9,
                'stream_running' => true,
                'notification_code' => 100,
                'notification_message' => 'hello',
            ],
            'time' => 1700000000,
        ], JSON_THROW_ON_ERROR);
        $request = new ServerRequest('POST', '/audd-callback', [], $bodyJson);
        $parsed = Streams::handleCallback($request);
        self::assertTrue($parsed->isNotification());
        self::assertSame(9, $parsed->notification->radio_id);
        self::assertSame(1700000000, $parsed->notification->time);
    }

    public function testHandleCallbackThrowsOnInvalidJson(): void
    {
        $this->expectException(AudDSerializationException::class);
        Streams::handleCallback('<not-json>');
    }

    public function testDeriveLongpollCategoryFormula(): void
    {
        // hex-MD5(hex-MD5(token) + str(radio_id))[:9]
        $token = 'my-secret-token';
        $radioId = 42;
        $inner = md5($token);
        $expected = substr(md5($inner . '42'), 0, 9);

        self::assertSame($expected, Helpers::deriveLongpollCategory($token, $radioId));

        $audd = new AudD(apiToken: $token);
        self::assertSame($expected, $audd->streams()->deriveLongpollCategory($radioId));
        $audd->close();
    }
}
