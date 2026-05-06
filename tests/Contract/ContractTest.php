<?php

declare(strict_types=1);

namespace AudD\Tests\Contract;

use AudD\Errors\AudDAuthenticationException;
use AudD\Errors\AudDBlockedException;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Errors\AudDQuotaException;
use AudD\Errors\AudDSubscriptionException;
use AudD\Errors\ErrorMapping;
use AudD\Models\EnterpriseMatch;
use AudD\Models\RecognitionResult;
use AudD\Streams;
use PHPUnit\Framework\TestCase;

/**
 * Validates the SDK's parsers against the canonical fixture set in
 * github.com/AudDMusic/audd-openapi (or its sibling checkout for local dev).
 *
 * Every fixture exercises at least one typed model. Additions to the fixture
 * directory should be paired with new assertions here.
 */
final class ContractTest extends TestCase
{
    public function testRecognizeBasic(): void
    {
        $body = FixtureLoader::load('recognize_basic.json');
        self::assertSame('success', $body['status']);
        $result = new RecognitionResult($body['result']);
        self::assertSame('Tears For Fears', $result->artist);
        self::assertSame('Everybody Wants To Rule The World', $result->title);
        self::assertSame('00:56', $result->timecode);
        self::assertSame('https://lis.tn/NbkVb?thumb', $result->thumbnailUrl());
        self::assertTrue($result->isPublicMatch());
    }

    public function testRecognizeWithMetadataParsesAppleMusicAndSpotifyAndMusicBrainz(): void
    {
        $body = FixtureLoader::load('recognize_with_metadata.json');
        $result = new RecognitionResult($body['result']);
        self::assertNotNull($result->apple_music);
        self::assertSame('Tears for Fears', $result->apple_music->artistName);
        self::assertNotNull($result->spotify);
        self::assertNotNull($result->musicbrainz);
        self::assertNotEmpty($result->musicbrainz);
        self::assertSame('14379ff4-5128-4e0f-8d08-1a4e50f53187', $result->musicbrainz[0]->id);
    }

    public function testRecognizeCustomMatch(): void
    {
        $body = FixtureLoader::load('recognize_custom_match.json');
        $result = new RecognitionResult($body['result']);
        self::assertTrue($result->isCustomMatch());
        self::assertFalse($result->isPublicMatch());
        self::assertSame(146, $result->audio_id);
    }

    public function testEnterpriseWithIsrcUpc(): void
    {
        $body = FixtureLoader::load('enterprise_with_isrc_upc.json');
        $matches = [];
        foreach ($body['result'] as $chunk) {
            foreach ($chunk['songs'] as $song) {
                $matches[] = new EnterpriseMatch($song);
            }
        }
        self::assertCount(1, $matches);
        self::assertSame('GBUM71403885', $matches[0]->isrc);
        self::assertSame('00602547037169', $matches[0]->upc);
    }

    public function testStreamsCallbackResultDiscriminator(): void
    {
        $body = FixtureLoader::load('streams_callback_with_result.json');
        $payload = Streams::parseCallback($body);
        self::assertTrue($payload->isMatch());
        self::assertNotNull($payload->match);
        self::assertSame(7, $payload->match->radio_id);
        self::assertSame('Alan Walker, A$AP Rocky', $payload->match->song->artist);
        self::assertSame([], $payload->match->alternatives);
    }

    public function testStreamsCallbackNotificationDiscriminator(): void
    {
        $body = FixtureLoader::load('streams_callback_with_notification.json');
        $payload = Streams::parseCallback($body);
        self::assertTrue($payload->isNotification());
        self::assertNotNull($payload->notification);
        self::assertSame(650, $payload->notification->notification_code);
        self::assertSame(1587939136, $payload->notification->time);
    }

    public function testGetStreamsEmpty(): void
    {
        $body = FixtureLoader::load('getStreams_empty.json');
        self::assertSame('success', $body['status']);
        self::assertSame([], $body['result']);
    }

    public function testLongpollNoEventsShape(): void
    {
        $body = FixtureLoader::load('longpoll_no_events.json');
        self::assertArrayHasKey('timeout', $body);
        self::assertSame('no events before timeout', $body['timeout']);
        self::assertIsInt($body['timestamp']);
    }

    public function testError900MapsToAuthenticationException(): void
    {
        $body = FixtureLoader::load('error_900_invalid_token.json');
        self::assertSame(
            AudDAuthenticationException::class,
            ErrorMapping::classForCode((int) $body['error']['error_code']),
        );
        $caught = null;
        try {
            ErrorMapping::raiseFromErrorResponse($body, 200, null);
        } catch (AudDAuthenticationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertSame(900, $caught->errorCode);
    }

    public function testError904EnterpriseMapsToSubscription(): void
    {
        $body = FixtureLoader::load('error_904_enterprise_unauthorized.json');
        self::assertSame(
            AudDSubscriptionException::class,
            ErrorMapping::classForCode((int) $body['error']['error_code']),
        );
        $caught = null;
        try {
            ErrorMapping::raiseFromErrorResponse($body, 200, null);
        } catch (AudDSubscriptionException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertArrayHasKey('limit', $caught->requestedParams);
    }

    public function testError902MapsToQuota(): void
    {
        $body = FixtureLoader::load('error_902_stream_limit.json');
        self::assertSame(
            AudDQuotaException::class,
            ErrorMapping::classForCode((int) $body['error']['error_code']),
        );
    }

    public function testError700MapsToInvalidRequest(): void
    {
        $body = FixtureLoader::load('error_700_no_file.json');
        self::assertSame(
            AudDInvalidRequestException::class,
            ErrorMapping::classForCode((int) $body['error']['error_code']),
        );
    }

    public function testError19MapsToBlocked(): void
    {
        $body = FixtureLoader::load('error_19_no_callback_url.json');
        self::assertSame(
            AudDBlockedException::class,
            ErrorMapping::classForCode((int) $body['error']['error_code']),
        );
    }
}
