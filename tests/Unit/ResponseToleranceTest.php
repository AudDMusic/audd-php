<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Models\EnterpriseMatch;
use AudD\Models\RecognitionResult;
use AudD\Streams;
use PHPUnit\Framework\TestCase;

/**
 * A successful API response must never throw (or warn) because a field is
 * absent or arrives as an unexpected type. The enterprise endpoint legitimately
 * returns songs with no `score` (and no `isrc`/`upc`/`label`); recognize/stream
 * responses can carry wrong-typed fields. None of that may break parsing.
 */
final class ResponseToleranceTest extends TestCase
{
    public function testEnterpriseSongWithoutScoreParsesWithNullScore(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                [
                    'songs' => [
                        [
                            // No score, no isrc, no upc, no label — all legitimately absent.
                            'artist' => 'Tears For Fears',
                            'title' => 'Everybody Wants To Rule The World',
                            'timecode' => '00:56',
                            'song_link' => 'https://lis.tn/NbkVb',
                        ],
                    ],
                ],
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $matches = $audd->recognizeEnterprise('https://audd.tech/example.mp3', limit: 1);

        self::assertCount(1, $matches);
        $match = $matches[0];
        self::assertInstanceOf(EnterpriseMatch::class, $match);
        self::assertNull($match->score);
        self::assertNull($match->isrc);
        self::assertNull($match->upc);
        self::assertNull($match->label);
        self::assertSame('Tears For Fears', $match->artist);
        self::assertSame('00:56', $match->timecode);
    }

    public function testEnterpriseSongWithScoreParsesScore(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                ['songs' => [['title' => 'X', 'score' => 97]]],
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $matches = $audd->recognizeEnterprise('https://x.mp3', limit: 1);

        self::assertCount(1, $matches);
        self::assertSame(97, $matches[0]->score);
    }

    public function testRecognizeToleratesWrongTypedFields(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                // artist as an array, album as a nested object, audio_id as a
                // non-numeric string: none of these may throw or warn.
                'artist' => ['unexpected' => 'shape'],
                'album' => ['nested' => ['deep' => 1]],
                'audio_id' => 'not-a-number',
                'title' => 'Real Title',
                'timecode' => '00:10',
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $result = $audd->recognize('https://x.mp3');

        self::assertInstanceOf(RecognitionResult::class, $result);
        self::assertNull($result->artist);
        self::assertNull($result->album);
        self::assertNull($result->audio_id);
        self::assertSame('Real Title', $result->title);
    }

    public function testStreamCallbackSongWithoutScoreParses(): void
    {
        $event = Streams::parseCallback([
            'result' => [
                'radio_id' => 7,
                'results' => [
                    ['artist' => 'A', 'title' => 'T'],
                ],
            ],
        ]);

        self::assertTrue($event->isMatch());
        self::assertNotNull($event->match);
        self::assertNull($event->match->song->score);
        self::assertSame('A', $event->match->song->artist);
    }
}
