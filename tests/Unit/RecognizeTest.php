<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use PHPUnit\Framework\TestCase;

final class RecognizeTest extends TestCase
{
    public function testRecognizeUrlReturnsTypedResult(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                'artist' => 'Tears For Fears',
                'title' => 'Everybody Wants To Rule The World',
                'album' => 'Songs From The Big Chair',
                'release_date' => '2014-11-10',
                'label' => 'UMC (Universal Music Catalogue)',
                'timecode' => '00:56',
                'song_link' => 'https://lis.tn/NbkVb',
            ],
        ]));

        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $result = $audd->recognize('https://audd.tech/example.mp3');

        self::assertNotNull($result);
        self::assertSame('Tears For Fears', $result->artist);
        self::assertSame('Everybody Wants To Rule The World', $result->title);
        self::assertSame('00:56', $result->timecode);
        self::assertTrue($result->isPublicMatch());
        self::assertFalse($result->isCustomMatch());
        self::assertSame('https://lis.tn/NbkVb?thumb', $result->thumbnailUrl());
    }

    public function testRecognizeReturnsNullOnNoMatch(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => null,
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        self::assertNull($audd->recognize('https://x.mp3'));
    }

    public function testRecognizeCustomMatch(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => ['timecode' => '01:45', 'audio_id' => 146],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $r = $audd->recognize('https://x.mp3');
        self::assertNotNull($r);
        self::assertTrue($r->isCustomMatch());
        self::assertFalse($r->isPublicMatch());
        self::assertSame(146, $r->audio_id);
        self::assertNull($r->thumbnailUrl());
    }

    public function testReturnListJoinsToCommaString(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => ['timecode' => '00:01'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $audd->recognize('https://x.mp3', return_metadata: ['apple_music', 'spotify']);

        $req = $mock->history[0]['request'];
        $body = (string) $req->getBody();
        self::assertStringContainsString('return=apple_music%2Cspotify', $body);
    }

    public function testForwardCompatExtrasViaMagicGet(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                'timecode' => '00:01',
                'artist' => 'X',
                'title' => 'Y',
                'tidal' => ['url' => 'https://tidal.com/track/123'],
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $r = $audd->recognize('https://x.mp3');
        self::assertNotNull($r);
        // Unknown server field accessible via __get even though not in typed surface.
        self::assertSame(['url' => 'https://tidal.com/track/123'], $r->tidal);
        self::assertArrayHasKey('tidal', $r->extras);
    }

    public function testThumbnailUrlSkipsYouTube(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => [
                'timecode' => '00:01', 'artist' => 'X', 'title' => 'Y',
                'song_link' => 'https://www.youtube.com/watch?v=abc',
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $r = $audd->recognize('https://x.mp3');
        self::assertNotNull($r);
        self::assertNull($r->thumbnailUrl());
    }
}
