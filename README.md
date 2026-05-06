# audd-php

[![Packagist](https://img.shields.io/packagist/v/audd/audd.svg)](https://packagist.org/packages/audd/audd)
[![PHP versions](https://img.shields.io/packagist/php-v/audd/audd.svg)](https://packagist.org/packages/audd/audd)

Official PHP SDK for the [AudD](https://audd.io) music recognition API.
PHP 8.1+, PSR-7/PSR-17/PSR-18 (HTTP) and PSR-3 (logging) friendly.

## Quickstart

```bash
composer require audd/audd:^1.4.0
```

```php
use AudD\AudD;

$audd = new AudD(apiToken: 'test'); // get yours at https://dashboard.audd.io
$result = $audd->recognize('https://audd.tech/example.mp3');
if ($result !== null) {
    echo $result->artist . ' — ' . $result->title;
}
```

The `apiToken` argument may be omitted; the SDK falls back to the
`AUDD_API_TOKEN` environment variable. Use `AudD::fromEnvironment()` to
make that intent explicit at the call site.

## Capabilities

| What | How |
|---|---|
| Recognize a short clip (≤25s) | `$audd->recognize($source)` |
| Recognize a long file (hours, days) | `$audd->recognizeEnterprise($source, limit: 1)` |
| Manage real-time stream recognition | `$audd->streams()->add($url, $radioId)` etc. |
| Long-poll for stream events | `$audd->streams()->longpoll($category)` |

`$source` accepts a URL, a file path, a PSR-7 stream, a `resource`, or
raw bytes wrapped via `AudD\Internal\Source::bytes($buf)` — auto-detected.

## Errors

Every server error becomes a typed exception:

```php
use AudD\AudD;
use AudD\Errors\AudDAuthenticationException;
use AudD\Errors\AudDSubscriptionException;

try {
    (new AudD(apiToken: 'bad'))->recognize('https://x.mp3');
} catch (AudDAuthenticationException $e) {
    echo "check your token: {$e->errorCode} {$e->apiMessage}";
} catch (AudDSubscriptionException) {
    echo 'this endpoint is not enabled on your token';
}
```

Every `AudDApiException` carries `errorCode`, `apiMessage`, `httpStatus`,
`requestId`, `requestedParams`, `requestMethod`, `brandedMessage`, and
`rawResponse`.

## Logging (PSR-3)

The client accepts an optional `Psr\Log\LoggerInterface`. When supplied,
the SDK emits diagnostic records you can route to whatever logging stack
you already use — Monolog, Symfony, Laravel, Slim — they all implement
PSR-3.

```php
use AudD\AudD;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$logger = new Logger('audd');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$audd = new AudD(apiToken: 'test', logger: $logger);
```

Records emitted today:

| Level | Message | Context |
|---|---|---|
| `debug` | `audd: onEvent hook threw` | `exception` |
| `warning` | `audd: deprecated parameter` | `message` (server code 51) |

The default is `Psr\Log\NullLogger` — silent. The api_token is never
written to a log message or context. The `onEvent` inspection callback
remains a separate mechanism (one fires synchronous events around every
request; the other is for backend-style log routing) — both can be
configured at the same time.

## Inspection hook (`onEvent`)

```php
use AudD\AudD;
use AudD\AudDEvent;
use AudD\AudDEventKind;

$audd = new AudD(
    apiToken: 'test',
    onEvent: function (AudDEvent $e): void {
        // Request / Response / Exception, with method, url, requestId,
        // httpStatus, elapsedMs, errorCode, extras. No api_token, no body.
        if ($e->kind === AudDEventKind::Response) {
            error_log("audd {$e->method} -> {$e->httpStatus} ({$e->elapsedMs}ms)");
        }
    },
);
```

Hook exceptions never propagate — they are routed to the PSR-3 logger at
`debug` level (silent by default).

## Forward compatibility

Models accept and round-trip unknown server fields via `extras`:

```php
$result = $audd->recognize('https://example.mp3', return_: ['apple_music']);
echo $result->appleMusic->url;     // typed
print_r($result->extras);          // any other unknown fields
```

If AudD adds a new metadata block tomorrow (e.g. `tidal`), you can read
it as `$result->extras['tidal']` today — no SDK release needed.

## License

MIT — see [LICENSE](LICENSE).
