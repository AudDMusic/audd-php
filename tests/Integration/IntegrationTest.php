<?php

declare(strict_types=1);

namespace AudD\Tests\Integration;

use AudD\AudD;
use AudD\Errors\AudDAuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the live AudD API. Skipped unless AUDD_API_TOKEN
 * is set in the environment.
 */
final class IntegrationTest extends TestCase
{
    private function token(): string
    {
        $t = getenv('AUDD_API_TOKEN');
        if ($t === false || $t === '') {
            self::markTestSkipped('AUDD_API_TOKEN env var not set; skipping live integration tests.');
        }
        return $t;
    }

    public function testRecognizeKnownUrl(): void
    {
        $token = $this->token();
        $audd = new AudD(apiToken: $token);
        try {
            $r = $audd->recognize('https://audd.tech/example.mp3');
            self::assertNotNull($r);
            self::assertSame('Tears For Fears', $r->artist);
        } finally {
            $audd->close();
        }
    }

    public function testInvalidTokenRaisesAuthError(): void
    {
        $audd = new AudD(apiToken: 'definitely-not-a-real-token-9999');
        try {
            $this->expectException(AudDAuthenticationException::class);
            $audd->recognize('https://audd.tech/example.mp3');
        } finally {
            $audd->close();
        }
    }
}
