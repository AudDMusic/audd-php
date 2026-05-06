<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Helpers;
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
        $iter = $audd->streams()->longpoll('cat');
        // Trigger the generator.
        $iter->current();
    }

    public function testLongpollSkipCallbackCheckBypassesPreflight(): void
    {
        $mock = new MockHttp();
        // No preflight; just one longpoll response.
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'timeout' => 'no events before timeout',
            'timestamp' => 1234567,
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $iter = $audd->streams()->longpoll('cat', skipCallbackCheck: true);
        $first = $iter->current();
        self::assertSame('no events before timeout', $first['timeout']);
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
        $parsed = Helpers::parseCallback($payload);
        self::assertTrue($parsed->isResult());
        self::assertFalse($parsed->isNotification());
        self::assertNotNull($parsed->result);
        self::assertSame(7, $parsed->result->radio_id);
        self::assertSame('A', $parsed->result->results[0]->artist);
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
        $parsed = Helpers::parseCallback($payload);
        self::assertTrue($parsed->isNotification());
        self::assertFalse($parsed->isResult());
        self::assertSame(650, $parsed->notification->notification_code);
        self::assertSame(1587939136, $parsed->time);
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
