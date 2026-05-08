# stream-to-csv

Subscribe to (or attach to) an AudD stream slot, longpoll its recognitions,
and append each match as a row to a CSV file.

```sh
cd examples/stream-to-csv
composer install
export AUDD_API_TOKEN=your-token        # from https://dashboard.audd.io

# Mode 1: provision-and-listen — adds the slot, deletes it on exit.
php stream-to-csv.php --url https://stream.example/live.m3u8
php stream-to-csv.php --url https://stream.example/live.m3u8 --radio-id 12345

# Mode 2: listen-only — attaches to an existing slot. Account state is
# left unchanged; nothing is added or deleted on exit.
php stream-to-csv.php --radio-id 12345
```

CSV columns: `received_at, radio_id, timestamp, score, artist, title, album, song_link`.
Default output path is `audd_stream_tracks.csv` in the current directory; pass
`--output FILE` to change it. Append mode — re-runs add rows; the header is
written only when the file is fresh. Each row is flushed immediately, so a
`kill -9` still leaves a valid CSV.

## Mode 1 vs Mode 2

The two modes differ in what they do to your AudD account.

**Mode 1 (provision-and-listen)** — `--url` (with or without `--radio-id`):
on startup it calls `streams()->add(...)`; on exit it calls `streams()->delete(...)`.
If `--radio-id` is omitted, slot `99999` is used.

**Mode 2 (listen-only)** — `--radio-id` only: attaches to a slot that already
exists in your account. No `add`, no `delete`, account state untouched.

## Callback-URL handling

AudD's longpoll endpoint requires a callback URL configured on your account
even though events are delivered via longpoll, not the URL itself — the URL
just has to return 200. The example handles this differently per mode:

- **Mode 1:** if no callback URL is set (server returns error #19), the
  example installs `https://audd.tech/empty/` so longpoll starts working,
  and prints a notice. On exit it leaves that placeholder in place (the AudD
  API has no "unset" verb) and reminds you it's there. If you already have a
  real URL configured, the example doesn't touch it.
- **Mode 2:** if no callback URL is set, the example refuses to start with a
  pointer to `streams()->setCallbackUrl(...)`. Mode 2 never mutates account
  state, so it can't quietly install a placeholder.

The example discriminates on `$e->errorCode === 19`, not the exception class
— the SDK maps code 19 to `AudDBlockedException` (a subclass of
`AudDApiException`), but other related codes can land on different
subclasses too. Catching `AudDApiException` and switching on `errorCode` is
the durable pattern.

Notification envelopes (stream stopped, can't connect, etc.) go to stderr;
result envelopes are what get written to CSV.

## Signal handling

`pcntl_signal(SIGINT|SIGTERM, ...)` flips a stop flag; the longpoll loop
checks it via `pcntl_signal_dispatch()` after each event. Mode 1 deletes the
stream slot in the `finally` block before exit. Requires the `pcntl`
extension (built into the standard PHP CLI on Linux/macOS).
