<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * v0.2.0 Item C: setApiToken rotation. PHP is single-threaded per request so
 * no lock is needed; we just update the field on AudD + both transports + the
 * streams-namespace token resolver. Spec §7.10.
 */
final class SetApiTokenTest extends TestCase
{
    public function testSetApiTokenUpdatesSubsequentRequests(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => ['timecode' => '00:01'],
        ]));
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => ['timecode' => '00:02'],
        ]));

        $audd = new AudD(apiToken: 'old', httpClient: $mock->buildClient());
        $audd->recognize('https://x.mp3');
        $audd->setApiToken('new');
        self::assertSame('new', $audd->getApiToken());
        $audd->recognize('https://y.mp3');

        $body0 = (string) $mock->history[0]['request']->getBody();
        $body1 = (string) $mock->history[1]['request']->getBody();
        self::assertStringContainsString('api_token=old', $body0);
        self::assertStringContainsString('api_token=new', $body1);
    }

    public function testSetApiTokenPropagatesToEnterpriseTransport(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'success', 'result' => [],
        ]));

        $audd = new AudD(apiToken: 'old', httpClient: $mock->buildClient());
        $audd->setApiToken('rotated');
        $audd->recognizeEnterprise('https://x.mp3', limit: 1);

        $body = (string) $mock->history[0]['request']->getBody();
        self::assertStringContainsString('api_token=rotated', $body);
    }

    public function testSetApiTokenRejectsEmptyString(): void
    {
        $audd = new AudD(apiToken: 'old');
        $this->expectException(AudDConfigurationException::class);
        $audd->setApiToken('');
    }

    public function testSetApiTokenPropagatesToStreamsCategory(): void
    {
        // deriveLongpollCategory uses MD5 of the api_token; rotating should
        // change the category for the same radio_id.
        $audd = new AudD(apiToken: 'old');
        $cat0 = $audd->streams()->deriveLongpollCategory(42);
        $audd->setApiToken('new');
        $cat1 = $audd->streams()->deriveLongpollCategory(42);
        self::assertNotSame($cat0, $cat1);
    }

    public function testGetApiTokenReturnsConstructorValue(): void
    {
        $audd = new AudD(apiToken: 'abc123');
        self::assertSame('abc123', $audd->getApiToken());
    }
}
