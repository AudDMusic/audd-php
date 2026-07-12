<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use PHPUnit\Framework\TestCase;

final class EnterpriseOffsetsTest extends TestCase
{
    public function testChunkOffsetAnchorsSecondsToFile(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                [
                    'offset' => '00:01:00',
                    'songs' => [
                        [
                            'artist' => 'A',
                            'title' => 'One',
                            'timecode' => '00:04',
                            'start_offset' => 4200,
                            'end_offset' => 11800,
                        ],
                    ],
                ],
                [
                    // No chunk offset -> seconds degrade to null.
                    'songs' => [
                        [
                            'artist' => 'B',
                            'title' => 'Two',
                            'timecode' => '00:12',
                            'start_offset' => 1000,
                            'end_offset' => 5000,
                        ],
                    ],
                ],
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $matches = $audd->recognizeEnterprise('https://audd.tech/long.mp3');

        self::assertCount(2, $matches);

        // 60s chunk base + 4200ms / 11800ms fragment-relative offsets.
        self::assertEqualsWithDelta(64.2, $matches[0]->start_seconds, 0.0001);
        self::assertEqualsWithDelta(71.8, $matches[0]->end_seconds, 0.0001);
        // Raw fragment-relative ms preserved behind them.
        self::assertSame(4200, $matches[0]->start_offset);
        self::assertSame(11800, $matches[0]->end_offset);

        // Absent chunk offset -> null seconds, never silently 0.
        self::assertNull($matches[1]->start_seconds);
        self::assertNull($matches[1]->end_seconds);

        // The computed seconds must not leak into extras.
        self::assertArrayNotHasKey('start_seconds', $matches[0]->extras);
        self::assertArrayNotHasKey('end_seconds', $matches[0]->extras);
    }

    public function testChunkOffsetOverOneHourAnchorsSeconds(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                [
                    // HH:MM:SS beyond one hour: 1h + 2m + 3s = 3723s.
                    'offset' => '01:02:03',
                    'songs' => [
                        [
                            'artist' => 'A',
                            'title' => 'One',
                            'timecode' => '00:04',
                            'start_offset' => 0,
                            'end_offset' => 2000,
                        ],
                    ],
                ],
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $matches = $audd->recognizeEnterprise('https://audd.tech/long.mp3');

        self::assertCount(1, $matches);
        // 3723s chunk base + 0ms/2000ms fragment-relative offsets.
        self::assertEqualsWithDelta(3723.0, $matches[0]->start_seconds, 0.0001);
        self::assertEqualsWithDelta(3725.0, $matches[0]->end_seconds, 0.0001);
    }

    public function testAccurateOffsetsDefaultsTrue(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $audd->recognizeEnterprise('https://audd.tech/long.mp3');

        $req = $mock->history[0]['request'];
        $body = (string) $req->getBody();
        self::assertStringContainsString('accurate_offsets=true', $body);
    }

    public function testAccurateOffsetsCanBeDisabled(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $audd->recognizeEnterprise('https://audd.tech/long.mp3', accurateOffsets: false);

        $req = $mock->history[0]['request'];
        $body = (string) $req->getBody();
        self::assertStringContainsString('accurate_offsets=false', $body);
    }
}
