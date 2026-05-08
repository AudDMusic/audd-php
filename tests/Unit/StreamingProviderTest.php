<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Models\EnterpriseMatch;
use AudD\Models\RecognitionResult;
use AudD\StreamingProvider;
use PHPUnit\Framework\TestCase;

/**
 * v0.2.0 Item B: StreamingProvider enum + streamingUrl / streamingUrls /
 * previewUrl helpers on RecognitionResult and EnterpriseMatch. Spec §4.3.
 */
final class StreamingProviderTest extends TestCase
{
    public function testStreamingUrlReturnsRedirectForLisTn(): void
    {
        $r = self::buildResult(['song_link' => 'https://lis.tn/abc']);
        self::assertSame('https://lis.tn/abc?spotify', $r->streamingUrl(StreamingProvider::SPOTIFY));
        self::assertSame('https://lis.tn/abc?apple_music', $r->streamingUrl(StreamingProvider::APPLE_MUSIC));
        self::assertSame('https://lis.tn/abc?deezer', $r->streamingUrl(StreamingProvider::DEEZER));
        self::assertSame('https://lis.tn/abc?napster', $r->streamingUrl(StreamingProvider::NAPSTER));
        self::assertSame('https://lis.tn/abc?youtube', $r->streamingUrl(StreamingProvider::YOUTUBE));
    }

    public function testStreamingUrlPreservesExistingQueryString(): void
    {
        $r = self::buildResult(['song_link' => 'https://lis.tn/abc?a=1']);
        self::assertSame('https://lis.tn/abc?a=1&spotify', $r->streamingUrl(StreamingProvider::SPOTIFY));
    }

    public function testStreamingUrlReturnsNullForNonLisTnWithoutMetadata(): void
    {
        $r = self::buildResult(['song_link' => 'https://www.youtube.com/watch?v=abc']);
        self::assertNull($r->streamingUrl(StreamingProvider::SPOTIFY));
        self::assertNull($r->streamingUrl(StreamingProvider::YOUTUBE));
    }

    public function testStreamingUrlReturnsNullWhenNoSongLink(): void
    {
        self::assertNull(self::buildResult([])->streamingUrl(StreamingProvider::SPOTIFY));
    }

    public function testStreamingUrlPrefersDirectMetadataOverLisTn(): void
    {
        $r = self::buildResult([
            'song_link' => 'https://lis.tn/abc',
            'apple_music' => ['url' => 'https://music.apple.com/album/123'],
            'deezer' => ['link' => 'https://deezer.com/track/456'],
            'spotify' => ['external_urls' => ['spotify' => 'https://open.spotify.com/track/789']],
            'napster' => ['href' => 'https://napster.com/track/000'],
        ]);
        self::assertSame('https://music.apple.com/album/123', $r->streamingUrl(StreamingProvider::APPLE_MUSIC));
        self::assertSame('https://deezer.com/track/456', $r->streamingUrl(StreamingProvider::DEEZER));
        self::assertSame('https://open.spotify.com/track/789', $r->streamingUrl(StreamingProvider::SPOTIFY));
        self::assertSame('https://napster.com/track/000', $r->streamingUrl(StreamingProvider::NAPSTER));
        // YouTube has no direct-URL path, only lis.tn redirect.
        self::assertSame('https://lis.tn/abc?youtube', $r->streamingUrl(StreamingProvider::YOUTUBE));
    }

    public function testStreamingUrlsReturnsAllResolvableForLisTn(): void
    {
        $r = self::buildResult(['song_link' => 'https://lis.tn/x']);
        $urls = $r->streamingUrls();
        self::assertCount(5, $urls);
        self::assertSame('https://lis.tn/x?spotify', $urls['spotify']);
        self::assertSame('https://lis.tn/x?apple_music', $urls['apple_music']);
        self::assertSame('https://lis.tn/x?deezer', $urls['deezer']);
        self::assertSame('https://lis.tn/x?napster', $urls['napster']);
        self::assertSame('https://lis.tn/x?youtube', $urls['youtube']);
    }

    public function testStreamingUrlsEmptyWithoutSongLinkOrMetadata(): void
    {
        self::assertSame([], self::buildResult([])->streamingUrls());
    }

    public function testStreamingUrlsReturnsOnlyMetadataPathWhenNonLisTn(): void
    {
        $r = self::buildResult([
            'song_link' => 'https://www.youtube.com/watch?v=abc',
            'apple_music' => ['url' => 'https://music.apple.com/album/123'],
        ]);
        $urls = $r->streamingUrls();
        self::assertSame(['apple_music' => 'https://music.apple.com/album/123'], $urls);
    }

    public function testPreviewUrlPriorityApple(): void
    {
        $r = self::buildResult([
            'apple_music' => ['previews' => [['url' => 'https://apple.preview/1.m4a']]],
            'spotify' => ['preview_url' => 'https://spotify.preview/1.mp3'],
            'deezer' => ['preview' => 'https://deezer.preview/1.mp3'],
        ]);
        self::assertSame('https://apple.preview/1.m4a', $r->previewUrl());
    }

    public function testPreviewUrlPrioritySpotifyWhenNoApple(): void
    {
        $r = self::buildResult([
            'spotify' => ['preview_url' => 'https://spotify.preview/1.mp3'],
            'deezer' => ['preview' => 'https://deezer.preview/1.mp3'],
        ]);
        self::assertSame('https://spotify.preview/1.mp3', $r->previewUrl());
    }

    public function testPreviewUrlPriorityDeezerLast(): void
    {
        $r = self::buildResult(['deezer' => ['preview' => 'https://deezer.preview/1.mp3']]);
        self::assertSame('https://deezer.preview/1.mp3', $r->previewUrl());
    }

    public function testPreviewUrlNullWhenNoPreviewsCarried(): void
    {
        self::assertNull(self::buildResult([])->previewUrl());
        self::assertNull(self::buildResult(['apple_music' => ['url' => 'x']])->previewUrl());
    }

    public function testEnterpriseMatchStreamingUrlsLisTnOnly(): void
    {
        $m = new EnterpriseMatch([
            'score' => 100, 'timecode' => '00:01',
            'song_link' => 'https://lis.tn/X',
        ]);
        self::assertSame('https://lis.tn/X?spotify', $m->streamingUrl(StreamingProvider::SPOTIFY));
        self::assertCount(5, $m->streamingUrls());
    }

    public function testEnterpriseMatchNoMetadataFallback(): void
    {
        // Even with metadata-block-like data, EnterpriseMatch ignores it.
        $m = new EnterpriseMatch([
            'score' => 100, 'timecode' => '00:01',
            'song_link' => 'https://www.youtube.com/watch?v=abc',
            'apple_music' => ['url' => 'https://music.apple.com/album/123'],
        ]);
        self::assertNull($m->streamingUrl(StreamingProvider::APPLE_MUSIC));
        self::assertSame([], $m->streamingUrls());
    }

    public function testStreamingProviderEnumCases(): void
    {
        self::assertSame('spotify', StreamingProvider::SPOTIFY->value);
        self::assertSame('apple_music', StreamingProvider::APPLE_MUSIC->value);
        self::assertSame('deezer', StreamingProvider::DEEZER->value);
        self::assertSame('napster', StreamingProvider::NAPSTER->value);
        self::assertSame('youtube', StreamingProvider::YOUTUBE->value);
        self::assertCount(5, StreamingProvider::cases());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function buildResult(array $payload): RecognitionResult
    {
        $payload['timecode'] = $payload['timecode'] ?? '00:01';
        return new RecognitionResult($payload);
    }
}
