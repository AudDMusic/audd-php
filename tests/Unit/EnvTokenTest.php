<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * v0.2.0 Item A: AUDD_API_TOKEN env-var auto-pickup + fromEnvironment factory
 * + AudDConfigurationException for the missing-token case. Spec §7.11.
 */
final class EnvTokenTest extends TestCase
{
    public function testEnvVarSuppliesTokenWhenArgOmitted(): void
    {
        $prev = getenv(AudD::TOKEN_ENV_VAR);
        putenv(AudD::TOKEN_ENV_VAR . '=from-env');
        try {
            $audd = new AudD();
            self::assertSame('from-env', $audd->getApiToken());
        } finally {
            self::restoreEnv($prev);
        }
    }

    public function testExplicitArgWinsOverEnv(): void
    {
        $prev = getenv(AudD::TOKEN_ENV_VAR);
        putenv(AudD::TOKEN_ENV_VAR . '=from-env');
        try {
            $audd = new AudD(apiToken: 'explicit');
            self::assertSame('explicit', $audd->getApiToken());
        } finally {
            self::restoreEnv($prev);
        }
    }

    public function testMissingTokenAndEnvThrows(): void
    {
        $prev = getenv(AudD::TOKEN_ENV_VAR);
        putenv(AudD::TOKEN_ENV_VAR);
        unset($_ENV[AudD::TOKEN_ENV_VAR]);
        try {
            $this->expectException(AudDConfigurationException::class);
            $this->expectExceptionMessageMatches('/dashboard\.audd\.io/');
            new AudD();
        } finally {
            self::restoreEnv($prev);
        }
    }

    public function testEmptyStringTokenFallsBackToEnv(): void
    {
        $prev = getenv(AudD::TOKEN_ENV_VAR);
        putenv(AudD::TOKEN_ENV_VAR . '=from-env');
        try {
            $audd = new AudD(apiToken: '');
            self::assertSame('from-env', $audd->getApiToken());
        } finally {
            self::restoreEnv($prev);
        }
    }

    public function testFromEnvironmentFactory(): void
    {
        $prev = getenv(AudD::TOKEN_ENV_VAR);
        putenv(AudD::TOKEN_ENV_VAR . '=from-env');
        try {
            $audd = AudD::fromEnvironment();
            self::assertSame('from-env', $audd->getApiToken());
        } finally {
            self::restoreEnv($prev);
        }
    }

    private static function restoreEnv(string|false $prev): void
    {
        if ($prev === false) {
            putenv(AudD::TOKEN_ENV_VAR);
            unset($_ENV[AudD::TOKEN_ENV_VAR]);
        } else {
            putenv(AudD::TOKEN_ENV_VAR . '=' . $prev);
        }
    }
}
