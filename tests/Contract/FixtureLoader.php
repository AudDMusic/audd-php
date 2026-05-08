<?php

declare(strict_types=1);

namespace AudD\Tests\Contract;

/**
 * Locates the canonical OpenAPI fixture set. Honors the AUDD_OPENAPI_FIXTURES
 * env var (set by CI when checking out audd-openapi alongside the SDK), and
 * falls back to ../../audd-openapi/fixtures relative to this repo for local dev.
 */
final class FixtureLoader
{
    public static function dir(): string
    {
        $env = getenv('AUDD_OPENAPI_FIXTURES');
        if ($env !== false && $env !== '' && is_dir($env)) {
            return $env;
        }
        $local = __DIR__ . '/../../../audd-openapi/fixtures';
        if (is_dir($local)) {
            return realpath($local) ?: $local;
        }
        throw new \RuntimeException(
            'Could not locate audd-openapi/fixtures. Set AUDD_OPENAPI_FIXTURES env var '
            . 'or check out audd-openapi alongside this repo.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = self::dir() . '/' . $name;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Fixture {$name} did not parse to an associative array");
        }
        return $decoded;
    }
}
