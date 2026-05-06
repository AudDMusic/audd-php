<?php

declare(strict_types=1);

namespace AudD\Models;

final class StreamCallbackResultEntry extends ForwardCompatModel
{
    private const KNOWN = [
        'artist', 'title', 'score', 'album', 'release_date', 'label',
        'song_link', 'apple_music', 'spotify', 'deezer', 'napster', 'musicbrainz',
    ];

    public readonly string $artist;
    public readonly string $title;
    public readonly int $score;
    public readonly ?string $album;
    public readonly ?string $release_date;
    public readonly ?string $label;
    public readonly ?string $song_link;
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
        $this->artist = (string) ($payload['artist'] ?? '');
        $this->title = (string) ($payload['title'] ?? '');
        $this->score = (int) ($payload['score'] ?? 0);
        $this->album = isset($payload['album']) ? (string) $payload['album'] : null;
        $this->release_date = isset($payload['release_date']) ? (string) $payload['release_date'] : null;
        $this->label = isset($payload['label']) ? (string) $payload['label'] : null;
        $this->song_link = isset($payload['song_link']) ? (string) $payload['song_link'] : null;
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
