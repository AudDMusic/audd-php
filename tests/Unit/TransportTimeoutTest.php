<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\AudD;
use AudD\Errors\AudDConnectionException;
use AudD\Internal\HttpClient;
use AudD\Internal\Retry;
use AudD\Internal\RetryClass;
use AudD\LongpollPoll;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Timeouts and cancellation against a real socket that accepts connections
 * (through the kernel backlog) and never answers.
 */
final class TransportTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $address = '';

    protected function setUp(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "could not listen: {$errstr}");
        $this->server = $server;
        $this->address = (string) stream_socket_get_name($server, false);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    public function testBuiltInClientEnforcesTheReadTimeout(): void
    {
        $http = new HttpClient('token', connectTimeout: 2.0, readTimeout: 1.0);
        $started = microtime(true);
        try {
            $http->get("http://{$this->address}/", []);
            self::fail('expected a timeout');
        } catch (TransferException $e) {
            self::assertStringContainsString('cURL error 28', $e->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    public function testPerCallTimeoutAppliesThroughAnInjectedGuzzleClient(): void
    {
        $http = new HttpClient('token', connectTimeout: 2.0, readTimeout: 60.0, client: new Client());
        $started = microtime(true);
        try {
            $http->get("http://{$this->address}/", [], perCallTimeout: 1.0);
            self::fail('expected a timeout');
        } catch (TransferException $e) {
            self::assertStringContainsString('cURL error 28', $e->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    public function testInjectedClientCurlOptionsAreKept(): void
    {
        $seen = [];
        $handler = static function (RequestInterface $r, array $options) use (&$seen) {
            $seen[] = $options;
            return Create::promiseFor(new Response(200, [], '{"status":"success","result":null}'));
        };
        $client = new Client([
            'handler' => HandlerStack::create($handler),
            'curl' => [CURLOPT_SSLCERT => '/etc/ssl/client.pem', CURLOPT_LOW_SPEED_TIME => 7],
        ]);
        $audd = new AudD(apiToken: 'test', httpClient: $client);

        $audd->recognize('https://audd.tech/example.mp3', timeout: 2);
        $audd->recognizeEnterprise('https://audd.tech/example.mp3', limit: 1);

        self::assertCount(2, $seen);
        self::assertSame(2.0, $seen[0]['read_timeout']);
        self::assertSame('/etc/ssl/client.pem', $seen[0]['curl'][CURLOPT_SSLCERT]);
        self::assertSame(7, $seen[0]['curl'][CURLOPT_LOW_SPEED_TIME], 'the client\'s own setting wins');
        self::assertSame(1, $seen[0]['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(3600.0, $seen[1]['read_timeout']);
        self::assertSame(1, $seen[1]['curl'][CURLOPT_TCP_KEEPALIVE]);
        self::assertSame('/etc/ssl/client.pem', $seen[1]['curl'][CURLOPT_SSLCERT]);
    }

    public function testDefaultTimeoutsReachAnInjectedGuzzleClient(): void
    {
        $seen = [];
        $handler = static function (RequestInterface $r, array $options) use (&$seen) {
            $seen[] = $options;
            return Create::promiseFor(new Response(200, [], '{"status":"success","result":null}'));
        };
        $audd = new AudD(apiToken: 'test', httpClient: new Client(['handler' => HandlerStack::create($handler)]));

        $audd->recognize('https://audd.tech/example.mp3');

        self::assertSame(30.0, $seen[0]['connect_timeout']);
        self::assertSame(60.0, $seen[0]['read_timeout']);
        self::assertSame(60, $seen[0]['curl'][CURLOPT_LOW_SPEED_TIME]);
    }

    public function testCancelStopsAWaitingRequest(): void
    {
        $http = new HttpClient('', connectTimeout: 2.0, readTimeout: 30.0);
        $started = microtime(true);
        try {
            $http->get(
                "http://{$this->address}/longpoll/",
                ['category' => 'abc'],
                sendToken: false,
                cancel: static fn (): bool => microtime(true) - $started > 0.5,
            );
            self::fail('expected the request to be cancelled');
        } catch (AudDConnectionException $e) {
            self::assertSame('Request cancelled', $e->getMessage());
        }
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testCloseFromASignalHandlerEndsAWaitingLongpoll(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_alarm')) {
            self::markTestSkipped('ext-pcntl is required');
        }
        $poll = new LongpollPoll(
            url: "http://{$this->address}/longpoll/",
            params: ['category' => 'abc', 'timeout' => '50'],
            http: new HttpClient('', connectTimeout: 2.0, readTimeout: 120.0),
            policy: new Retry(RetryClass::READ, 3, 0.001),
        );
        $errors = [];
        $poll->onError(function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $previous = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static fn () => $poll->close());
        pcntl_alarm(1);
        $started = microtime(true);
        try {
            $poll->run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($previous);
        }

        self::assertLessThan(4.0, microtime(true) - $started);
        self::assertSame([], $errors, 'close() is not an error');
    }
}
