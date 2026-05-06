<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDAuthenticationException;
use AudD\Errors\AudDBlockedException;
use AudD\Errors\AudDCustomCatalogAccessException;
use AudD\Errors\AudDInvalidRequestException;
use AudD\Errors\AudDSerializationException;
use AudD\Errors\AudDServerException;
use AudD\Errors\AudDSubscriptionException;
use AudD\Errors\ErrorMapping;
use AudD\Internal\Source;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testAuthErrorMapsTo900(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 900, 'error_message' => 'bad token'],
        ]));
        $audd = new AudD(apiToken: 'bad', httpClient: $mock->buildClient());

        $this->expectException(AudDAuthenticationException::class);
        try {
            $audd->recognize('https://x.mp3');
        } catch (AudDAuthenticationException $e) {
            self::assertSame(900, $e->errorCode);
            self::assertSame('bad token', $e->apiMessage);
            self::assertSame(200, $e->httpStatus);
            throw $e;
        }
    }

    public function testHttp502NonJsonRaisesServerException(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::rawResponse(502, '<html>bad gateway</html>'));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), maxRetries: 1);

        $this->expectException(AudDServerException::class);
        try {
            $audd->recognize('https://x.mp3');
        } catch (AudDServerException $e) {
            self::assertSame(502, $e->httpStatus);
            throw $e;
        }
    }

    public function testHttp200NonJsonRaisesSerializationException(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::rawResponse(200, 'not json'));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());

        $this->expectException(AudDSerializationException::class);
        $audd->recognize('https://x.mp3');
    }

    public function testCode51WithUsableResultEmitsDeprecationAndReturns(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 51, 'error_message' => 'param X is deprecated'],
            'result' => ['timecode' => '00:01', 'artist' => 'A', 'title' => 'B'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());

        $errors = [];
        set_error_handler(static function (int $level, string $msg) use (&$errors): bool {
            $errors[] = [$level, $msg];
            return true;
        }, E_USER_DEPRECATED);
        try {
            $r = $audd->recognize('https://x.mp3');
        } finally {
            restore_error_handler();
        }
        self::assertNotNull($r);
        self::assertSame('A', $r->artist);
        self::assertNotEmpty($errors);
        self::assertSame(E_USER_DEPRECATED, $errors[0][0]);
        self::assertStringContainsString('deprecated', $errors[0][1]);
    }

    public function testCode51WithoutResultRaises(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 51, 'error_message' => 'param X is deprecated'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        $this->expectException(AudDInvalidRequestException::class);
        $audd->recognize('https://x.mp3');
    }

    public function testBrandedMessageCapturedOnException(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 19, 'error_message' => 'banned'],
            'result' => [
                'artist' => 'Sorry, your IP was banned',
                'title' => 'ApiRequest failed',
            ],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        try {
            $audd->recognize('https://x.mp3');
            self::fail('expected exception');
        } catch (AudDBlockedException $e) {
            self::assertNotNull($e->brandedMessage);
            self::assertStringContainsString('banned', $e->brandedMessage);
        }
    }

    public function testCustomCatalogContextWraps904(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 904, 'error_message' => 'enterprise required'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient(), maxRetries: 1);

        $this->expectException(AudDCustomCatalogAccessException::class);
        try {
            $audd->customCatalog()->add(42, Source::bytes('hello'));
        } catch (AudDCustomCatalogAccessException $e) {
            // The override message contains the "NOT for music recognition" framing.
            self::assertStringContainsString('private fingerprint database', $e->apiMessage);
            self::assertStringContainsString('enterprise required', $e->apiMessage);
            self::assertSame('enterprise required', $e->serverMessage);
            self::assertInstanceOf(AudDSubscriptionException::class, $e);
            throw $e;
        }
    }

    public function testRequestParamsAndRequestedParamsEchoBothHandled(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(200, [
            'status' => 'error',
            'error' => ['error_code' => 904, 'error_message' => 'no'],
            'requested_params' => ['url' => 'https://x.mp3', 'limit' => '1'],
        ]));
        $audd = new AudD(apiToken: 'test', httpClient: $mock->buildClient());
        try {
            $audd->recognizeEnterprise('https://x.mp3', limit: 1);
            self::fail('expected exception');
        } catch (AudDSubscriptionException $e) {
            self::assertArrayHasKey('limit', $e->requestedParams);
        }
    }

    public function testErrorMapClassForCodeUnknownDefaultsToServer(): void
    {
        self::assertSame(AudDServerException::class, ErrorMapping::classForCode(99999));
        self::assertSame(AudDAuthenticationException::class, ErrorMapping::classForCode(901));
        self::assertSame(AudDBlockedException::class, ErrorMapping::classForCode(31337));
    }
}
