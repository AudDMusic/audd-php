<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Internal\Source;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testUrlSourceReturnsUrlField(): void
    {
        $reopen = Source::prepare('https://example.com/track.mp3');
        [$data, $files] = $reopen();
        self::assertSame(['url' => 'https://example.com/track.mp3'], $data);
        self::assertNull($files);
    }

    public function testFilePathSourceOpensFreshHandleEachAttempt(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'audd');
        try {
            file_put_contents($tmp, 'first-attempt-bytes');
            $reopen = Source::prepare($tmp);

            [, $files1] = $reopen();
            self::assertNotNull($files1);
            self::assertSame('first-attempt-bytes', stream_get_contents($files1[0]['contents']));
            fclose($files1[0]['contents']);

            // Second attempt — must yield fresh handle starting at byte 0.
            [, $files2] = $reopen();
            self::assertNotNull($files2);
            self::assertSame('first-attempt-bytes', stream_get_contents($files2[0]['contents']));
            fclose($files2[0]['contents']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testRawBytesViaSourceBytesWrapper(): void
    {
        $reopen = Source::prepare(Source::bytes("\x00\x01\x02 hello"));
        [$data, $files] = $reopen();
        self::assertSame([], $data);
        self::assertNotNull($files);
        self::assertSame("\x00\x01\x02 hello", $files[0]['contents']);
    }

    public function testSeekablePsr7StreamReseekedOnRetry(): void
    {
        $stream = Utils::streamFor('contents-here');
        $reopen = Source::prepare($stream);

        [, $files1] = $reopen();
        self::assertNotNull($files1);
        // Drain the stream — emulates Guzzle reading the body during the request.
        $files1[0]['contents']->getContents();
        self::assertSame(13, $files1[0]['contents']->tell());

        // Second attempt: closure should reseek before yielding the same stream.
        [, $files2] = $reopen();
        self::assertNotNull($files2);
        // Now position must be back at 0 (the captured start).
        self::assertSame(0, $files2[0]['contents']->tell());
        self::assertSame('contents-here', $files2[0]['contents']->getContents());
    }

    public function testStringNeitherUrlNorFileRaisesInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Source::bytes/');
        Source::prepare('definitely-not-a-real-path-or-url');
    }

    public function testResourceSourceSeeksBackOnRetry(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'audd');
        try {
            file_put_contents($tmp, 'res-content');
            $fh = fopen($tmp, 'rb');
            self::assertNotFalse($fh);
            fread($fh, 4); // advance past "res-"
            $reopen = Source::prepare($fh);

            [, $files1] = $reopen();
            self::assertNotNull($files1);
            self::assertSame('content', stream_get_contents($files1[0]['contents']));

            // Retry: must reseek to 4 (where we were).
            [, $files2] = $reopen();
            self::assertNotNull($files2);
            self::assertSame('content', stream_get_contents($files2[0]['contents']));
        } finally {
            if (isset($fh) && is_resource($fh)) {
                fclose($fh);
            }
            @unlink($tmp);
        }
    }
}
