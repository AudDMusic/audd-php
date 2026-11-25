<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDServerException;
use AudD\Internal\Source;
use AudD\Internal\SourceBytes;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testPublicBytesEntryPointWrapsRawBytes(): void
    {
        $wrapped = AudD::bytes("\x00\x01raw-audio");
        self::assertInstanceOf(SourceBytes::class, $wrapped);

        // The wrapper routes through the multipart 'file' part unchanged.
        $reopen = Source::prepare($wrapped);
        [$data, $files] = $reopen();
        self::assertSame([], $data);
        self::assertNotNull($files);
        self::assertSame("\x00\x01raw-audio", $files[0]['contents']);
    }

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
        $this->expectExceptionMessageMatches('/AudD::bytes/');
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
            self::assertSame('content', $files1[0]['contents']->getContents());

            // Retry: must reseek to 4 (where we were).
            [, $files2] = $reopen();
            self::assertNotNull($files2);
            self::assertSame('content', $files2[0]['contents']->getContents());
        } finally {
            if (isset($fh) && is_resource($fh)) {
                fclose($fh);
            }
            @unlink($tmp);
        }
    }

    public function testResourceSourceStaysOpenAfterGuzzleClosesTheBody(): void
    {
        $fh = fopen('php://temp', 'w+b');
        self::assertNotFalse($fh);
        fwrite($fh, 'audio-bytes');
        rewind($fh);
        try {
            $reopen = Source::prepare($fh);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                [, $files] = $reopen();
                self::assertNotNull($files);
                $body = new MultipartStream($files);
                self::assertStringContainsString('audio-bytes', (string) $body);
                // What Guzzle does once a request is done with its body.
                $body->close();
                unset($body, $files);
                gc_collect_cycles();
                self::assertTrue(is_resource($fh), 'the caller\'s handle must stay open');
            }
        } finally {
            fclose($fh);
        }
    }

    public function testResourceSourceRetriesAfter5xxAndLeavesHandleOpen(): void
    {
        $fh = fopen('php://temp', 'w+b');
        self::assertNotFalse($fh);
        fwrite($fh, 'audio-bytes');
        rewind($fh);

        $mock = new MockHttp();
        $mock->handler->append(MockHttp::rawResponse(502, '<html>bad gateway</html>'));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success',
            'result' => ['timecode' => '00:01', 'artist' => 'A', 'title' => 'B'],
        ]));
        $audd = new AudD(apiToken: 'test', backoffFactor: 0.001, httpClient: $mock->buildClient());
        try {
            $result = $audd->recognize($fh);
            self::assertNotNull($result);
            self::assertSame('A', $result->artist);
            self::assertCount(2, $mock->history);
            self::assertStringContainsString('audio-bytes', (string) $mock->history[1]['request']->getBody());
            $mock->history = [];
            gc_collect_cycles();
            self::assertTrue(is_resource($fh));
        } finally {
            fclose($fh);
        }
    }

    public function testUnseekableStreamKeepsTheServerErrorInsteadOfRetrying(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('audio-bytes'));
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::rawResponse(502, '<html>bad gateway</html>'));
        $mock->handler->append(MockHttp::jsonResponse(200, ['status' => 'success', 'result' => null]));
        $audd = new AudD(apiToken: 'test', backoffFactor: 0.001, httpClient: $mock->buildClient());

        try {
            $audd->recognize($stream);
            self::fail('expected AudDServerException');
        } catch (AudDServerException $e) {
            self::assertSame(502, $e->httpStatus);
        }
        self::assertCount(1, $mock->history);
    }
}
