<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Models\EnterpriseMatch;
use AudD\Models\RecognitionResult;
use AudD\Models\Stream;
use AudD\Models\StreamCallbackNotification;
use PHPUnit\Framework\TestCase;

/**
 * Wrong-typed scalar fields are COERCED when convertible and degrade to null
 * only when they aren't. Containers (arrays/objects) where a scalar belongs
 * always degrade to null.
 */
final class ScalarCoercionTest extends TestCase
{
    public function testConvertibleScalarsCoerce(): void
    {
        $match = new EnterpriseMatch([
            'score' => '85',          // numeric string → int
            'start_offset' => 1500.9, // float → truncated int
            'end_offset' => '1e3',    // scientific-notation string → int
            'artist' => 123,          // int → string
            'title' => 8.5,           // float → string
            'timecode' => 123,        // int → string (timecode is string-typed)
            'start_seconds' => ' 3.5 ', // numeric string (trimmed) → float
            'end_seconds' => 7,       // int → float
        ]);

        self::assertSame(85, $match->score);
        self::assertSame(1500, $match->start_offset);
        self::assertSame(1000, $match->end_offset);
        self::assertSame('123', $match->artist);
        self::assertSame('8.5', $match->title);
        self::assertSame('123', $match->timecode);
        self::assertSame(3.5, $match->start_seconds);
        self::assertSame(7.0, $match->end_seconds);
    }

    public function testBoolSourcesCoerce(): void
    {
        // bool → string uses PHP's canonical rendering ("1"/"").
        $result = new RecognitionResult(['artist' => true, 'title' => false]);
        self::assertSame('1', $result->artist);
        self::assertSame('', $result->title);

        // bool → int is 0/1.
        $match = new EnterpriseMatch(['score' => true, 'start_offset' => false]);
        self::assertSame(1, $match->score);
        self::assertSame(0, $match->start_offset);
    }

    public function testNonConvertibleScalarsDegradeToNull(): void
    {
        $match = new EnterpriseMatch([
            'score' => 'abc',            // non-numeric string
            'start_offset' => '85abc',   // partial-numeric string is not numeric
            'end_offset' => '1e999',     // overflows int range — no garbage 0
            'start_seconds' => 'NaN',
            'end_seconds' => 'Infinity',
            'artist' => ['unexpected'],  // array where string belongs
            'label' => ['k' => 'v'],     // object where string belongs
        ]);

        self::assertNull($match->score);
        self::assertNull($match->start_offset);
        self::assertNull($match->end_offset);
        self::assertNull($match->start_seconds);
        self::assertNull($match->end_seconds);
        self::assertNull($match->artist);
        self::assertNull($match->label);
    }

    public function testBoolStringWhitelistTrue(): void
    {
        foreach (['true', '1', 'yes', 'on', ' TRUE ', 'Yes', 'ON'] as $s) {
            $notif = new StreamCallbackNotification(['stream_running' => $s]);
            self::assertTrue($notif->stream_running, "expected true for '{$s}'");
        }
    }

    public function testBoolStringWhitelistFalse(): void
    {
        foreach (['false', '0', 'no', 'off', '', ' FALSE ', 'No', 'OFF'] as $s) {
            $notif = new StreamCallbackNotification(['stream_running' => $s]);
            self::assertFalse($notif->stream_running, "expected false for '{$s}'");
        }
    }

    public function testBoolUnrecognizedStringDegradesToNull(): void
    {
        foreach (['maybe', 'enabled', '2ish', 'null'] as $s) {
            $notif = new StreamCallbackNotification(['stream_running' => $s]);
            self::assertNull($notif->stream_running, "expected null for '{$s}'");
        }

        // Non-nullable surface: Stream.stream_running falls back to its
        // default (false) when the value can't be coerced.
        $stream = new Stream(['stream_running' => 'maybe']);
        self::assertFalse($stream->stream_running);
    }

    public function testBoolFromNumbers(): void
    {
        self::assertTrue((new StreamCallbackNotification(['stream_running' => 5]))->stream_running);
        self::assertTrue((new StreamCallbackNotification(['stream_running' => -1]))->stream_running);
        self::assertTrue((new StreamCallbackNotification(['stream_running' => 0.5]))->stream_running);
        self::assertFalse((new StreamCallbackNotification(['stream_running' => 0]))->stream_running);
        self::assertFalse((new StreamCallbackNotification(['stream_running' => 0.0]))->stream_running);
    }

    public function testLargeIntegerStringsStayExact(): void
    {
        // In-range big integers must not round-trip through float precision loss.
        $match = new EnterpriseMatch(['start_offset' => '123456789012345678']);
        self::assertSame(123456789012345678, $match->start_offset);

        // Out-of-range integers degrade to null, never saturate.
        $match = new EnterpriseMatch(['start_offset' => '99999999999999999999999']);
        self::assertNull($match->start_offset);
    }

    public function testHexStringIsNotNumeric(): void
    {
        $match = new EnterpriseMatch(['score' => '0x1A', 'start_seconds' => '0x1A']);
        self::assertNull($match->score);
        self::assertNull($match->start_seconds);
    }
}
