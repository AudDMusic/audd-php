<?php

/*
 * Subscribe to (or attach to) an AudD stream slot, longpoll its recognitions,
 * and append each match as a row in a CSV file.
 *
 * Two modes:
 *
 *   Provision-and-listen (Mode 1):
 *     php stream-to-csv.php --url https://stream.example/live.m3u8
 *     php stream-to-csv.php --url https://stream.example/live.m3u8 --radio-id 12345
 *   Adds the stream slot on startup and DELETES it on exit.
 *
 *   Listen-only (Mode 2):
 *     php stream-to-csv.php --radio-id 12345
 *   Attaches to an existing slot; never adds and never deletes.
 *
 * Reads the API token from AUDD_API_TOKEN.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use AudD\AudD;
use AudD\Errors\AudDApiException;

const DEFAULT_OUTPUT = 'audd_stream_tracks.csv';
const EMPTY_CALLBACK_URL = 'https://audd.tech/empty/';
const DEFAULT_RADIO_ID = 99999;
// Server signals "no callback URL configured" with code 19 (mapped to
// AudDBlockedException in the SDK; we discriminate by code, not class).
const NO_CALLBACK_ERROR_CODE = 19;
const CSV_HEADER = ['received_at', 'radio_id', 'timestamp', 'score', 'artist', 'title', 'album', 'song_link'];

/**
 * @return array{mode: string, url: ?string, radioId: ?int, output: string}|null
 */
function parseArgs(array $argv): ?array
{
    $url = null;
    $radioId = null;
    $output = DEFAULT_OUTPUT;
    $argc = count($argv);
    for ($i = 1; $i < $argc; $i++) {
        $a = $argv[$i];
        switch ($a) {
            case '--url':
                if (!isset($argv[$i + 1])) {
                    return null;
                }
                $url = $argv[++$i];
                break;
            case '--radio-id':
                if (!isset($argv[$i + 1]) || !ctype_digit(ltrim($argv[$i + 1], '-'))) {
                    return null;
                }
                $radioId = (int) $argv[++$i];
                break;
            case '--output':
                if (!isset($argv[$i + 1])) {
                    return null;
                }
                $output = $argv[++$i];
                break;
            default:
                return null;
        }
    }
    if ($url === null && $radioId === null) {
        return null;
    }
    // url present (with or without explicit radio-id) → PROVISION.
    // radio-id only → LISTEN_ONLY.
    $mode = $url !== null ? 'provision' : 'listen-only';
    return ['mode' => $mode, 'url' => $url, 'radioId' => $radioId, 'output' => $output];
}

/**
 * Mode 1: install audd.tech/empty/ when no callback is set; leave a real URL alone.
 * Mode 2: refuse if no callback is set; otherwise just listen.
 *
 * Returns true iff this run installed the placeholder (so the exit notice can mention it).
 */
function preflightCallback(AudD $audd, string $mode): bool
{
    $existing = null;
    $noneSet = false;
    try {
        $existing = $audd->streams()->getCallbackUrl();
        if ($existing === '') {
            $noneSet = true;
        }
    } catch (AudDApiException $e) {
        if ($e->errorCode === NO_CALLBACK_ERROR_CODE) {
            $noneSet = true;
        } else {
            throw $e;
        }
    }

    if ($mode === 'listen-only') {
        if ($noneSet) {
            fwrite(
                STDERR,
                "stream slot exists but no callback URL is configured for this account; "
                . "longpoll won't deliver. Set one first via "
                . '$audd->streams()->setCallbackUrl(...).' . "\n",
            );
            exit(1);
        }
        return false;
    }

    // provision mode
    if ($noneSet) {
        $audd->streams()->setCallbackUrl(EMPTY_CALLBACK_URL);
        fwrite(
            STDERR,
            "longpoll requires any 200-OK URL server-side; using audd.tech/empty/ as a default.\n",
        );
        return true;
    }
    fwrite(STDERR, "keeping existing callback URL: $existing\n");
    return false;
}

/**
 * @param resource $fh
 */
function writeRow($fh, array $row): void
{
    fputcsv($fh, $row);
    fflush($fh);
}

/**
 * @param resource $fh
 */
function handleEvent(array $body, int $radioId, $fh): void
{
    if (isset($body['timeout'])) {
        return; // no events this window — keep-alive, skip.
    }
    if (isset($body['notification']) && is_array($body['notification'])) {
        $n = $body['notification'];
        fwrite(
            STDERR,
            sprintf(
                "notification radio_id=%s code=%s %s\n",
                $n['radio_id'] ?? '',
                $n['notification_code'] ?? '',
                $n['notification_message'] ?? '',
            ),
        );
        return;
    }
    // Result envelope: either nested under 'result' or flat at the top level.
    $inner = isset($body['result']) && is_array($body['result']) ? $body['result'] : $body;
    $results = $inner['results'] ?? null;
    if (!is_array($results)) {
        return;
    }
    $receivedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    $timestamp = isset($inner['timestamp']) ? (string) $inner['timestamp'] : '';
    foreach ($results as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        writeRow($fh, [
            $receivedAt,
            (string) $radioId,
            $timestamp,
            (string) ($entry['score'] ?? ''),
            (string) ($entry['artist'] ?? ''),
            (string) ($entry['title'] ?? ''),
            (string) ($entry['album'] ?? ''),
            (string) ($entry['song_link'] ?? ''),
        ]);
        fwrite(
            STDERR,
            sprintf(
                "logged %s — %s (radio_id=%d)\n",
                (string) ($entry['artist'] ?? ''),
                (string) ($entry['title'] ?? ''),
                $radioId,
            ),
        );
    }
}

// ─── main ────────────────────────────────────────────────────────────────

$args = parseArgs($argv);
if ($args === null) {
    fwrite(STDERR, "usage:\n");
    fwrite(STDERR, "  php stream-to-csv.php --url <stream-url> [--radio-id N] [--output FILE]   (provision)\n");
    fwrite(STDERR, "  php stream-to-csv.php --radio-id N [--output FILE]                        (listen-only)\n");
    exit(2);
}

try {
    $audd = new AudD();
} catch (Throwable $e) {
    fwrite(STDERR, 'fatal: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $weSetPlaceholder = preflightCallback($audd, $args['mode']);

    $radioId = $args['radioId'] ?? DEFAULT_RADIO_ID;
    if ($args['mode'] === 'provision') {
        $audd->streams()->add($args['url'], $radioId);
        fwrite(STDERR, "subscribed: radio_id=$radioId url={$args['url']}\n");
    } else {
        fwrite(STDERR, "listening to existing slot: radio_id=$radioId\n");
    }

    // Open the CSV (append; header only when fresh).
    $fresh = !file_exists($args['output']) || filesize($args['output']) === 0;
    $fh = fopen($args['output'], 'a');
    if ($fh === false) {
        fwrite(STDERR, "cannot open {$args['output']} for append\n");
        exit(1);
    }
    if ($fresh) {
        writeRow($fh, CSV_HEADER);
    }

    // Signal handling — clean teardown on SIGINT/SIGTERM. We can't run cleanup
    // inside the handler (longpoll's HTTP request is mid-flight), so flip a
    // flag and let the loop check it via pcntl_signal_dispatch().
    $stopping = false;
    $signalHandler = function (int $signo) use (&$stopping): void {
        $stopping = true;
        fwrite(STDERR, "\nsignal $signo received; stopping after current poll cycle\n");
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);

    $category = $audd->streams()->deriveLongpollCategory($radioId);
    fwrite(STDERR, "longpolling category=$category — Ctrl-C to stop\n");

    // Pass skipCallbackCheck=true: we already preflighted above and remediated.
    foreach ($audd->streams()->longpoll($category, timeout: 50, skipCallbackCheck: true) as $body) {
        handleEvent($body, $radioId, $fh);
        pcntl_signal_dispatch();
        if ($stopping) {
            break;
        }
    }

    fclose($fh);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    // Mode 1: delete the stream slot. Mode 2: never mutates account state.
    if (isset($args) && $args['mode'] === 'provision' && isset($radioId)) {
        try {
            $audd->streams()->delete($radioId);
            fwrite(STDERR, "deleted stream slot radio_id=$radioId\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "teardown: failed to delete radio_id=$radioId: " . $e->getMessage() . "\n");
        }
        if (isset($weSetPlaceholder) && $weSetPlaceholder) {
            fwrite(
                STDERR,
                "left audd.tech/empty/ as your account callback — change it via "
                . '$audd->streams()->setCallbackUrl(...) if needed.' . "\n",
            );
        }
    } elseif (isset($args) && $args['mode'] === 'listen-only') {
        fwrite(STDERR, "listen-only: account state unchanged.\n");
    }
    if (isset($audd)) {
        $audd->close();
    }
}

exit($exitCode ?? 0);
