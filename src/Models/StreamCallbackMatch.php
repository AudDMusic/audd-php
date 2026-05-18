<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * One recognition event from a stream callback or longpoll.
 *
 * Carries the top match in `$song` (always present); rare extra candidates
 * live in `$alternatives`. Alternatives may have different `artist`/`title`
 * from the top song — they typically represent variant catalog releases or
 * near-duplicate fingerprints that resolved to multiple records.
 */
final class StreamCallbackMatch extends ForwardCompatModel
{
    private const KNOWN = ['radio_id', 'timestamp', 'play_length', 'results'];

    public readonly int $radio_id;
    public readonly ?string $timestamp;
    public readonly ?int $play_length;
    public readonly StreamCallbackSong $song;
    /**
     * Additional candidate songs beyond the top match. Entries may have
     * different artist/title from `$song` — variant catalog releases.
     *
     * @var list<StreamCallbackSong>
     */
    public readonly array $alternatives;

    /**
     * Build a StreamCallbackMatch from a parsed `result` block. The block's
     * `results` array splits into `song` (index 0) + `alternatives` (rest).
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->radio_id = (int) ($payload['radio_id'] ?? 0);
        $this->timestamp = isset($payload['timestamp']) ? (string) $payload['timestamp'] : null;
        $this->play_length = isset($payload['play_length']) ? (int) $payload['play_length'] : null;
        $songs = [];
        if (isset($payload['results']) && is_array($payload['results'])) {
            foreach ($payload['results'] as $entry) {
                if (is_array($entry)) {
                    $songs[] = new StreamCallbackSong($entry);
                }
            }
        }
        if ($songs === []) {
            // Synthesize an empty StreamCallbackSong so $song is always non-null;
            // callers can distinguish via empty artist/title or via score==0.
            // (Spec: $song is always present, callbacks with empty results are
            // surfaced as parse errors via Streams::parseCallback.)
            $songs[] = new StreamCallbackSong([]);
        }
        $this->song = $songs[0];
        $this->alternatives = array_slice($songs, 1);
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
