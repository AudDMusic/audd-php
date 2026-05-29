<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * One candidate song in a stream-callback recognition match. Almost every
 * match has exactly one Song; multiple candidates only appear when the same
 * fingerprint resolves to several near-identical catalog records.
 */
final class StreamCallbackSong extends ForwardCompatModel
{
    private const KNOWN = [
        'artist', 'title', 'score', 'album', 'release_date', 'label',
        'song_link', 'isrc', 'upc', 'apple_music', 'spotify', 'deezer', 'napster', 'musicbrainz',
    ];

    public readonly string $artist;
    public readonly string $title;
    /** Match confidence, 0–100. Null when the server omits it. */
    public readonly ?int $score;
    public readonly ?string $album;
    public readonly ?string $release_date;
    public readonly ?string $label;
    public readonly ?string $song_link;
    public readonly ?string $isrc;
    public readonly ?string $upc;
    public readonly ?AppleMusicMetadata $apple_music;
    public readonly ?SpotifyMetadata $spotify;
    public readonly ?DeezerMetadata $deezer;
    public readonly ?NapsterMetadata $napster;
    /** @var list<MusicBrainzEntry>|null */
    public readonly ?array $musicbrainz;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->artist = self::asString($payload['artist'] ?? null) ?? '';
        $this->title = self::asString($payload['title'] ?? null) ?? '';
        $this->score = self::asInt($payload['score'] ?? null);
        $this->album = self::asString($payload['album'] ?? null);
        $this->release_date = self::asString($payload['release_date'] ?? null);
        $this->label = self::asString($payload['label'] ?? null);
        $this->song_link = self::asString($payload['song_link'] ?? null);
        $this->isrc = self::asString($payload['isrc'] ?? null);
        $this->upc = self::asString($payload['upc'] ?? null);
        $this->apple_music = isset($payload['apple_music']) && is_array($payload['apple_music'])
            ? new AppleMusicMetadata($payload['apple_music']) : null;
        $this->spotify = isset($payload['spotify']) && is_array($payload['spotify'])
            ? new SpotifyMetadata($payload['spotify']) : null;
        $this->deezer = isset($payload['deezer']) && is_array($payload['deezer'])
            ? new DeezerMetadata($payload['deezer']) : null;
        $this->napster = isset($payload['napster']) && is_array($payload['napster'])
            ? new NapsterMetadata($payload['napster']) : null;
        if (isset($payload['musicbrainz']) && is_array($payload['musicbrainz'])) {
            $entries = [];
            foreach ($payload['musicbrainz'] as $entry) {
                if (is_array($entry)) {
                    $entries[] = new MusicBrainzEntry($entry);
                }
            }
            $this->musicbrainz = $entries;
        } else {
            $this->musicbrainz = null;
        }
        parent::__construct(self::extractExtras($payload, self::KNOWN), $payload);
    }
}
