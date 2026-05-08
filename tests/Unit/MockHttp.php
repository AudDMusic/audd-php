<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Test helper: build a Guzzle client backed by a MockHandler with a queue of
 * canned responses.
 */
final class MockHttp
{
    public readonly MockHandler $handler;

    /**
     * @var list<\Psr\Http\Message\RequestInterface>
     */
    public array $history = [];

    public function __construct()
    {
        $this->handler = new MockHandler();
    }

    public function buildClient(): Client
    {
        $stack = HandlerStack::create($this->handler);
        $history = \GuzzleHttp\Middleware::history($this->history);
        $stack->push($history);
        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public static function jsonResponse(int $status, mixed $body, array $headers = []): Response
    {
        $defaultHeaders = ['Content-Type' => 'application/json'];
        return new Response(
            $status,
            array_merge($defaultHeaders, $headers),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public static function rawResponse(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers, $body);
    }
}
