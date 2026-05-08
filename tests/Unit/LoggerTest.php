<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\AudDEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * v0.3.0: PSR-3 logger wiring on the AudD client.
 *
 * Defaults to `Psr\Log\NullLogger` (silent — matches v0.2.0 behavior modulo
 * the previous `error_log` write). When a logger is supplied:
 *  - onEvent hook failures are emitted at `debug` level with `exception` ctx
 *  - server code-51 deprecations are emitted at `warning` level
 *  - the api_token is never written to message or context
 */
final class LoggerTest extends TestCase
{
    public function testDefaultLoggerEmitsNoRecords(): void
    {
        // The "default" logger (NullLogger) is a black hole. We can't
        // observe it directly, but we can prove that omitting the logger
        // arg compiles + runs end-to-end without leaking into PHP's
        // error_log either (the v0.2.0 behavior we replaced).
        $tmp = tempnam(sys_get_temp_dir(), 'audd-logger-');
        self::assertIsString($tmp);
        $prev = ini_set('error_log', $tmp);

        try {
            $mock = new MockHttp();
            $mock->handler->append(MockHttp::jsonResponse(
                200,
                ['status' => 'success', 'result' => null],
            ));

            // No `logger:` arg → NullLogger; throwing hook still swallowed.
            $audd = new AudD(
                apiToken: 'super-secret-token',
                httpClient: $mock->buildClient(),
                onEvent: static function (AudDEvent $e): void {
                    throw new \RuntimeException('boom');
                },
            );
            self::assertNull($audd->recognize('https://x.mp3'));

            $logged = (string) file_get_contents($tmp);
            self::assertSame('', $logged, 'NullLogger must not write to error_log');
        } finally {
            if ($prev !== false) {
                ini_set('error_log', $prev);
            }
            @unlink($tmp);
        }
    }

    public function testHookExceptionRoutedToLoggerAtDebugLevel(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(
            200,
            ['status' => 'success', 'result' => null],
        ));

        $logger = new SpyLogger();
        $audd = new AudD(
            apiToken: 'test',
            httpClient: $mock->buildClient(),
            onEvent: static function (AudDEvent $e): void {
                throw new \RuntimeException('boom');
            },
            logger: $logger,
        );
        self::assertNull($audd->recognize('https://x.mp3'));

        // The hook is invoked once per event (Request + Response) — both
        // throw, so we expect a debug record per emit.
        $debug = $logger->byLevel(LogLevel::DEBUG);
        self::assertNotEmpty($debug, 'expected debug record(s) from hook failure');
        foreach ($debug as $rec) {
            self::assertSame('audd: onEvent hook threw', $rec['message']);
            self::assertArrayHasKey('exception', $rec['context']);
            self::assertInstanceOf(\RuntimeException::class, $rec['context']['exception']);
            self::assertSame('boom', $rec['context']['exception']->getMessage());
        }
    }

    public function testApiTokenNeverAppearsInLogRecords(): void
    {
        $mock = new MockHttp();
        $mock->handler->append(MockHttp::jsonResponse(
            200,
            ['status' => 'success', 'result' => null],
        ));

        $logger = new SpyLogger();
        $audd = new AudD(
            apiToken: 'super-secret-token-DO-NOT-LEAK',
            httpClient: $mock->buildClient(),
            onEvent: static function (AudDEvent $e): void {
                throw new \RuntimeException('boom carrying super-secret-token-DO-NOT-LEAK in its message? no');
            },
            logger: $logger,
        );
        $audd->recognize('https://x.mp3');

        foreach ($logger->records as $rec) {
            self::assertStringNotContainsString(
                'super-secret-token-DO-NOT-LEAK',
                $rec['message'],
                'api_token must not appear in log message',
            );
            $serializedCtx = serialize(self::stripExceptionMessages($rec['context']));
            self::assertStringNotContainsString(
                'super-secret-token-DO-NOT-LEAK',
                $serializedCtx,
                'api_token must not appear in log context',
            );
        }
    }

    /**
     * The `exception` context entry holds a Throwable whose message we don't
     * control (callers can put anything in it). Strip those before scanning
     * the rest of the context for the api_token.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private static function stripExceptionMessages(array $ctx): array
    {
        $out = $ctx;
        if (isset($out['exception']) && $out['exception'] instanceof \Throwable) {
            $out['exception'] = $out['exception']::class;
        }
        return $out;
    }
}

/**
 * Minimal PSR-3 spy. psr/log v3 ships no TestLogger; this fake captures the
 * level/message/context of every record for assertion.
 *
 * @internal
 */
final class SpyLogger extends AbstractLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function byLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => $r['level'] === $level,
        ));
    }
}
