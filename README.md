# audd-php

[![CI](https://github.com/AudDMusic/audd-php/actions/workflows/ci.yml/badge.svg)](https://github.com/AudDMusic/audd-php/actions/workflows/ci.yml)
[![Contract](https://github.com/AudDMusic/audd-php/actions/workflows/contract.yml/badge.svg)](https://github.com/AudDMusic/audd-php/actions/workflows/contract.yml)
[![Packagist](https://img.shields.io/packagist/v/audd/audd.svg)](https://packagist.org/packages/audd/audd)
[![PHP versions](https://img.shields.io/packagist/php-v/audd/audd.svg)](https://packagist.org/packages/audd/audd)

Official PHP SDK for [music recognition API](https://audd.io): identify music from a short audio clip, a long audio file, or a live stream.

The API itself is so simple that it can easily be used even without an SDK: [docs.audd.io](https://docs.audd.io).

## Quickstart

```bash
composer require audd/audd:^1.5.7
```

Get your API token at [dashboard.audd.io](https://dashboard.audd.io).

Recognize from a URL:

```php
use AudD\AudD;

$audd = new AudD('your-api-token');
$result = $audd->recognize('https://audd.tech/example.mp3');
if ($result !== null) {
    echo $result->artist . ' — ' . $result->title;
}
```

Recognize from a local file:

```php
use AudD\AudD;

$audd = new AudD('your-api-token');
$result = $audd->recognize('/path/to/clip.mp3');
if ($result !== null) {
    echo $result->artist . ' — ' . $result->title;
}
```

`recognize()` accepts a URL, a filesystem path, a PSR-7 `StreamInterface`, a `resource` handle, or raw bytes wrapped via `AudD\Internal\Source::bytes($buf)` — auto-detected. It returns a `RecognitionResult` on a match, or `null` when the clip isn't recognized.

For longer audio files (full-length songs, short-form videos, podcasts, broadcasts, DJ sets), use `recognizeEnterprise($source, limit: ...)` — it returns `list<EnterpriseMatch>`, one per song detected across the file.

## Authentication

Pass the token as a named argument:

```php
$audd = new AudD('your-token');
```

Or omit it and set `AUDD_API_TOKEN` in the environment — the SDK reads it on construction:

```php
putenv('AUDD_API_TOKEN=your-token');
$audd = AudD::fromEnvironment();
```

`AudD::fromEnvironment()` is the explicit factory; plain `new AudD()` does the same env-var lookup but reads less obviously at the call site.

For long-running services that rotate tokens (from a secrets manager, Vault, AWS Parameter Store), call `$audd->setApiToken($newToken)`. Subsequent requests use the new value.

## What you get back

By default `recognize()` returns the core tags plus AudD's universal song link — no metadata-block opt-in needed:

```php
use AudD\AudD;
use AudD\StreamingProvider;

$audd = new AudD();
$result = $audd->recognize('https://audd.tech/example.mp3');
if ($result === null) {
    exit("no match\n");
}

// Core tags
echo $result->artist, ' — ', $result->title, "\n";
echo $result->album, ' / ', $result->release_date, ' / ', $result->label, "\n";

// AudD's universal song page (works in any browser, links into all providers)
echo $result->song_link, "\n";

// Helpers — driven off song_link, work without any return_metadata opt-in
echo $result->thumbnailUrl(), "\n";                                   // cover-art URL, or null
echo $result->streamingUrl(StreamingProvider::SPOTIFY), "\n";         // direct or lis.tn redirect
print_r($result->streamingUrls());                                    // ["spotify" => "...", ...]
```

If you need provider-specific metadata blocks, opt in per call. Request only what you need — each provider you ask for adds latency:

```php
$result = $audd->recognize(
    'https://audd.tech/example.mp3',
    return_metadata: ['apple_music', 'spotify'],
);
echo $result->apple_music->url, "\n";  // direct Apple Music link
echo $result->spotify->uri, "\n";      // spotify:track:...
echo $result->previewUrl(), "\n";      // first preview across requested providers, or null
```

Valid `return_metadata` values: `apple_music`, `spotify`, `deezer`, `napster`, `musicbrainz`. The corresponding properties (`$result->apple_music`, `$result->spotify`, …) are `null` when not requested.

`EnterpriseMatch` (returned by `recognizeEnterprise`) carries the same core tags plus `score`, `start_offset`, `end_offset`, `isrc`, `upc`. Access to `isrc`, `upc`, and `score` requires a Startup plan or higher — [contact us](mailto:api@audd.io) for enterprise features.

## Reading additional metadata

Every typed model exposes `extras` carrying any fields the SDK doesn't surface as a typed property. This is the supported way to read additional fields the SDK doesn't surface as typed properties:

```php
$result = $audd->recognize('https://example.mp3', return_metadata: ['apple_music']);

// Top-level extras
$genre = $result->extras['genre'] ?? null;

// Nested extras inside a typed metadata block
$artwork = $result->apple_music->extras['artwork'] ?? null;
```

Magic property access falls through to `extras` too — `$result->genre` returns the same value as `$result->extras['genre']`. Per-account custom fields and beta API responses surface here.

For sending arbitrary form fields the typed parameters don't cover, pass `extra_parameters`:

```php
$result = $audd->recognize(
    '/tmp/snippet.wav',
    return_metadata: ['apple_music'],
    extra_parameters: ['my_custom_flag' => '1'],
);
```

Typed parameters win on collision.

## Errors

Every server-side error becomes a typed exception. The hierarchy lets you handle whole families with one `catch`:

```
AudDException                          (base)
├── AudDConnectionException             network / TLS / timeout
├── AudDSerializationException          malformed JSON
├── AudDConfigurationException          missing or empty token
└── AudDApiException                    status=error from server
    ├── AudDAuthenticationException     900 / 901 / 903
    ├── AudDQuotaException              902
    ├── AudDSubscriptionException       904 / 905
    │   └── AudDCustomCatalogAccessException  904 from customCatalog
    ├── AudDInvalidRequestException     50 / 51 / 600 / 601 / 602 / 700–702 / 906
    ├── AudDInvalidAudioException       300 / 400 / 500
    ├── AudDStreamLimitException        610
    ├── AudDRateLimitException          611
    ├── AudDNotReleasedException        907
    ├── AudDBlockedException            19 / 31337
    ├── AudDNeedsUpdateException        20
    └── AudDServerException              100 / 1000 / unknown
```

Idiomatic catch:

```php
use AudD\AudD;
use AudD\Errors\AudDApiException;
use AudD\Errors\AudDAuthenticationException;
use AudD\Errors\AudDInvalidAudioException;

try {
    $result = (new AudD())->recognize('https://example.mp3');
} catch (AudDAuthenticationException $e) {
    exit("check your token: [#{$e->errorCode}] {$e->apiMessage}\n");
} catch (AudDInvalidAudioException $e) {
    echo "audio rejected: {$e->apiMessage}\n";
} catch (AudDApiException $e) {
    // catch-all for anything the server reported
    echo "AudD #{$e->errorCode}: {$e->apiMessage} (request_id={$e->requestId})\n";
}
```

`match` works equally well for typed dispatch on the exception class:

```php
catch (AudDApiException $e) {
    $action = match (true) {
        $e instanceof AudDAuthenticationException => 'reload-token',
        $e instanceof AudDRateLimitException      => 'back-off',
        $e instanceof AudDInvalidAudioException   => 'skip',
        default                                   => 'log-and-rethrow',
    };
}
```

Every `AudDApiException` carries `errorCode`, `apiMessage`, `httpStatus`, `requestId`, `requestedParams`, `requestMethod`, `brandedMessage`, and `rawResponse` — enough to log a full incident or open a support ticket.

## Logging (PSR-3)

The client accepts an optional `Psr\Log\LoggerInterface`. Pass any PSR-3 implementation — Monolog, Symfony's logger, Laravel's `Log` channel, or a custom one — and the SDK routes diagnostics through it. The default is `NullLogger` (silent), and the api_token is never written to a record.

```php
use AudD\AudD;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$logger = new Logger('audd');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$audd = new AudD(apiToken: 'your-token', logger: $logger);
```

Records emitted today: `debug` for `onEvent` hook failures (with the exception in context), `warning` for server-side deprecated-parameter notices (server code 51).

## Configuration

```php
use AudD\AudD;
use GuzzleHttp\Client;

$audd = new AudD(
    apiToken: 'your-token',
    maxRetries: 3,                                        // per-call retry budget
    backoffFactor: 0.5,                                   // initial backoff seconds (jittered)
    httpClient: new Client(['proxy' => 'http://corp:8080']),
    onEvent: fn ($e) => error_log((string) $e->method),
    logger: $logger,
);
```

**Custom HTTP client.** `httpClient` accepts any `Psr\Http\Client\ClientInterface`. Inject a configured Guzzle client, a Symfony PSR-18 adapter, or your own transport to add proxies, mTLS, custom CA bundles, or shared connection pools.

**Retries.** Calls are classified by cost and retried accordingly:

| Class         | Endpoints                                                                              | Retried on                                                  |
|---------------|----------------------------------------------------------------------------------------|-------------------------------------------------------------|
| `RECOGNITION` | `recognize`, `recognizeEnterprise`, `advanced->*`                                      | network errors and 5xx **before** the upload reaches server |
| `READ`        | `streams->list`, `streams->getCallbackUrl`, longpoll                                   | network errors and 5xx                                      |
| `MUTATING`    | `streams->setCallbackUrl`, `streams->add`, `streams->delete`, `customCatalog->add`     | network errors and 5xx (idempotent on the server)           |

`RECOGNITION` will not double-bill your account: once the server has accepted bytes, a 5xx after that is surfaced rather than retried.

**Inspection.** Pass an `onEvent` closure to receive an `AudDEvent` for every request / response / exception — useful for metrics, distributed tracing, or attaching `requestId` to your application logs. Events never carry the api_token or request bytes; exceptions raised from the hook are swallowed and routed through the PSR-3 logger at `debug` level so observability can't break the request path.

```php
use AudD\AudDEvent;
use AudD\AudDEventKind;

$audd = new AudD(
    apiToken: 'your-token',
    onEvent: function (AudDEvent $e): void {
        if ($e->kind === AudDEventKind::Response) {
            error_log("audd {$e->method} -> {$e->httpStatus} ({$e->elapsedMs}ms)");
        }
    },
);
```

**Timeouts.** Defaults are 30s connect / 60s read for standard endpoints, and 30s connect / 1 hour read for the enterprise endpoint (which can legitimately process multi-hour files). Override per call with `timeout:` (seconds).

## Streams

Real-time recognition off radio streams, broadcast feeds, and any other long-running URL. Configure once, then either receive callbacks on your server or poll for events.

```php
$audd->streams()->setCallbackUrl('https://your.server/audd-callback');
$audd->streams()->add('https://your.stream.url/listen.m3u8', radioId: 42);

foreach ($audd->streams()->list() as $stream) {
    echo $stream->radio_id, ' ', $stream->url, ' ', ($stream->stream_running ? 'on' : 'off'), "\n";
}
```

Inside your webhook handler, parse the POST body into a typed result. `handleCallback()` accepts a PSR-7 `ServerRequestInterface`, raw JSON bytes, or an already-decoded array — pick whichever your framework gives you:

```php
use AudD\Streams;

// PSR-7 request from your framework:
$result = Streams::handleCallback($request);

// or raw bytes:
$result = Streams::handleCallback(file_get_contents('php://input'));

if ($result->isMatch()) {
    $m = $result->match;
    echo $m->song->artist, ' — ', $m->song->title, "\n";
    foreach ($m->alternatives as $alt) {
        echo "  alt: ", $alt->artist, ' — ', $alt->title, "\n";
    }
} elseif ($result->isNotification()) {
    echo 'notification: ', $result->notification->notification_message, "\n";
}
```

Use `Streams::parseCallback($array)` if you already have the decoded JSON. Both methods are static.

`add()` accepts direct stream URLs (DASH, Icecast, HLS, m3u/m3u8) and the shortcuts `twitch:<channel>`, `youtube:<video_id>`, `youtube-ch:<channel_id>`.

### Receiving events without a callback URL (longpoll)

If you can't expose a public callback URL, longpoll instead. AudD still requires a callback URL to be configured for the account (`https://audd.tech/empty/` works as a no-op receiver), and the SDK preflights this for you — pass `skipCallbackCheck: true` to skip if you've already verified.

```php
use AudD\Models\StreamCallbackMatch;
use AudD\Models\StreamCallbackNotification;

$radioId = 1; // any integer you choose — your handle for this stream

$poll = $audd->streams()->longpoll(radioId: $radioId, timeout: 30);
$poll->onMatch(function (StreamCallbackMatch $m): void {
    echo $m->song->artist, ' — ', $m->song->title, "\n";
});
$poll->onNotification(function (StreamCallbackNotification $n): void {
    echo 'notification: ', $n->notification_message, "\n";
});
$poll->onError(function (\Throwable $e) use ($poll): void {
    fwrite(STDERR, $e->getMessage() . "\n");
    $poll->close();
});
$poll->run();  // blocks until close() or a terminal error
```

Keepalive responses (`{"timeout":"no events before timeout"}`) are silently absorbed — your `onMatch`/`onNotification` only fire on real events.

`deriveLongpollCategory` is a local computation: `MD5(MD5(api_token) + radio_id)` truncated to 9 hex chars. The category alone is sufficient to subscribe — the api_token is never sent over the wire for longpolls.

#### Tokenless consumers

For browser widgets, embedded extensions, or any context where shipping the api_token would leak it: derive the category server-side, ship only the category to the consumer, and have the consumer use `LongpollConsumer` — same callback API, no api_token required:

```php
use AudD\LongpollConsumer;

// $category was derived on your server and shared with this process.
$consumer = new LongpollConsumer(category: 'abc123def');
$poll = $consumer->iterate(timeout: 30);
$poll->onMatch(fn ($m) => print_r($m));
$poll->onError(fn ($e) => fwrite(STDERR, $e->getMessage()));
$poll->run();
```

## Custom catalog (advanced)

> **The custom-catalog endpoint is NOT how you submit audio for music recognition.**
> For recognition, use `recognize()` (or `recognizeEnterprise()` for longer audio files). The custom-catalog endpoint adds songs to your *private* fingerprint database so future `recognize()` calls on your account can identify *your own* tracks.
> Requires special access — contact api@audd.io.

```php
$audd->customCatalog()->add(audioId: 42, source: 'https://my.song.mp3');
```

## License

MIT — see [LICENSE](./LICENSE).

## Support

- Documentation: <https://docs.audd.io>
- Tokens: <https://dashboard.audd.io>
- Issues: <https://github.com/AudDMusic/audd-php/issues>
- Email: api@audd.io
