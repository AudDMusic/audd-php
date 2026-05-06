<?php

/*
 * Walk a folder of audio files, recognize each via the AudD API, write the
 * recognized artist/title (and album/year when present) into the file's tags,
 * then rename the file to "Artist - Title.ext".
 *
 * Default is dry-run; pass --apply to actually write tags and rename.
 *
 *   php scan-and-rename.php /path/to/folder
 *   php scan-and-rename.php /path/to/folder --apply
 *   php scan-and-rename.php /path/to/folder --apply --concurrency 4
 *
 * Reads the API token from AUDD_API_TOKEN.
 *
 * Tag writing uses getID3's WriteTags module — most reliable on MP3 (ID3v2);
 * other formats are recognized and renamed but not retagged. See README.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use AudD\AudD;
use AudD\Models\RecognitionResult;
use JamesHeinrich\GetID3\WriteTags;

const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'opus', 'm4a', 'mp4', 'wav', 'aac'];
const RETAGGABLE_EXTS = ['mp3'];   // getID3 writers are most reliable for ID3v2/MP3.
const MAX_BASE_LEN = 200;

/**
 * @return array{root: string, apply: bool, concurrency: int}|null
 */
function parseArgs(array $argv): ?array
{
    $root = null;
    $apply = false;
    $concurrency = 1;
    $argc = count($argv);
    for ($i = 1; $i < $argc; $i++) {
        $a = $argv[$i];
        if ($a === '--apply') {
            $apply = true;
        } elseif ($a === '--concurrency') {
            if (!isset($argv[$i + 1])) {
                return null;
            }
            $n = (int) $argv[++$i];
            if ($n < 1) {
                return null;
            }
            $concurrency = $n;
        } elseif (str_starts_with($a, '--')) {
            return null;
        } elseif ($root === null) {
            $root = $a;
        } else {
            return null;
        }
    }
    if ($root === null || !is_dir($root)) {
        return null;
    }
    return ['root' => $root, 'apply' => $apply, 'concurrency' => $concurrency];
}

/**
 * @return list<string>
 */
function collectAudioFiles(string $root): array
{
    $out = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $entry) {
        /** @var SplFileInfo $entry */
        if (!$entry->isFile()) {
            continue;
        }
        $ext = strtolower($entry->getExtension());
        if (in_array($ext, AUDIO_EXTS, true)) {
            $out[] = $entry->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * Replace filesystem-unsafe characters and trim to MAX_BASE_LEN.
 */
function sanitize(string $s): string
{
    $s = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]#', '_', $s) ?? $s;
    $s = trim((string) preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) > MAX_BASE_LEN) {
        $s = mb_substr($s, 0, MAX_BASE_LEN);
    }
    return $s;
}

function targetBasename(RecognitionResult $r, string $ext): ?string
{
    $artist = $r->artist;
    $title = $r->title;
    if ($artist === null || $title === null || $artist === '' || $title === '') {
        return null;
    }
    $base = sanitize($artist . ' - ' . $title);
    if ($base === '' || $base === '-') {
        return null;
    }
    return $base . '.' . $ext;
}

function yearFrom(?string $releaseDate): ?string
{
    if ($releaseDate === null || $releaseDate === '') {
        return null;
    }
    if (preg_match('/^(\d{4})/', $releaseDate, $m) === 1) {
        return $m[1];
    }
    return null;
}

/**
 * Write artist/title/album/year using getID3's WriteTags module. Returns null
 * on success or a human-readable error string. We only call this for formats
 * in RETAGGABLE_EXTS — see README for why.
 */
function writeTags(string $path, RecognitionResult $r): ?string
{
    $writer = new WriteTags();
    $writer->filename = $path;
    $writer->tagformats = ['id3v2.4', 'id3v1'];
    $writer->overwrite_tags = true;
    $writer->remove_other_tags = false;
    $writer->tag_encoding = 'UTF-8';

    $data = [];
    if ($r->artist !== null && $r->artist !== '') {
        $data['ARTIST'] = [$r->artist];
    }
    if ($r->title !== null && $r->title !== '') {
        $data['TITLE'] = [$r->title];
    }
    if ($r->album !== null && $r->album !== '') {
        $data['ALBUM'] = [$r->album];
    }
    $year = yearFrom($r->release_date);
    if ($year !== null) {
        $data['YEAR'] = [$year];
    }
    $writer->tag_data = $data;

    if ($writer->WriteTags() !== true) {
        $errs = $writer->errors;
        return is_array($errs) && $errs !== [] ? implode('; ', array_map('strval', $errs)) : 'unknown getID3 error';
    }
    return null;
}

/**
 * @return array{status: string, detail: string}
 */
function processFile(AudD $audd, string $path, bool $apply): array
{
    try {
        $result = $audd->recognize($path);
    } catch (Throwable $e) {
        return ['status' => 'error', 'detail' => 'recognize: ' . $e->getMessage()];
    }
    if ($result === null) {
        return ['status' => 'no-match', 'detail' => ''];
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $newName = targetBasename($result, $ext);
    if ($newName === null) {
        return ['status' => 'skipped', 'detail' => 'missing artist/title'];
    }

    $matchLabel = ($result->artist ?? '?') . ' - ' . ($result->title ?? '?');
    $newPath = dirname($path) . DIRECTORY_SEPARATOR . $newName;

    if (!$apply) {
        return ['status' => 'matched', 'detail' => 'would rename to "' . $newName . '" -> ' . $matchLabel];
    }

    if (in_array($ext, RETAGGABLE_EXTS, true)) {
        $err = writeTags($path, $result);
        if ($err !== null) {
            return ['status' => 'error', 'detail' => 'tag write: ' . $err];
        }
    }

    if ($newPath === $path) {
        return ['status' => 'renamed', 'detail' => 'tagged (already named correctly)'];
    }
    if (file_exists($newPath)) {
        return ['status' => 'skipped', 'detail' => 'target exists: ' . $newName];
    }
    if (!@rename($path, $newPath)) {
        return ['status' => 'error', 'detail' => 'rename failed: ' . $newName];
    }
    return ['status' => 'renamed', 'detail' => $newName . ' -> ' . $matchLabel];
}

// ─── main ────────────────────────────────────────────────────────────────

$args = parseArgs($argv);
if ($args === null) {
    fwrite(STDERR, "usage: php scan-and-rename.php <folder> [--apply] [--concurrency N]\n");
    fwrite(STDERR, "  default is dry-run; pass --apply to write tags and rename.\n");
    exit(2);
}

$files = collectAudioFiles($args['root']);
if ($files === []) {
    fwrite(STDERR, "no audio files found under {$args['root']}\n");
    exit(0);
}

try {
    $audd = new AudD();
} catch (Throwable $e) {
    fwrite(STDERR, 'fatal: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = count($files);
fwrite(
    STDOUT,
    sprintf(
        "%s: %d file(s) under %s%s\n",
        $args['apply'] ? 'applying' : 'DRY RUN',
        $total,
        $args['root'],
        $args['concurrency'] > 1 ? " (concurrency={$args['concurrency']} note: PHP runs sequentially in this example)" : '',
    ),
);
if (!$args['apply']) {
    fwrite(STDOUT, "(no files will be modified — pass --apply to commit)\n");
}

$counts = ['matched' => 0, 'renamed' => 0, 'no-match' => 0, 'skipped' => 0, 'error' => 0];
$exitCode = 0;
$done = 0;
foreach ($files as $file) {
    $done++;
    $outcome = processFile($audd, $file, $args['apply']);
    $status = $outcome['status'];
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    if ($status === 'error') {
        $exitCode = 1;
    }
    $rel = ltrim(substr($file, strlen($args['root'])), '/\\');
    $detail = $outcome['detail'] !== '' ? ': ' . $outcome['detail'] : '';
    fwrite(STDOUT, sprintf("[%d/%d] %s  %s%s\n", $done, $total, $rel, $status, $detail));
}

$audd->close();

fwrite(
    STDOUT,
    sprintf(
        "summary: matched=%d renamed=%d no-match=%d skipped=%d errors=%d\n",
        $counts['matched'],
        $counts['renamed'],
        $counts['no-match'],
        $counts['skipped'],
        $counts['error'],
    ),
);
if (!$args['apply'] && $counts['matched'] > 0) {
    fwrite(STDOUT, "re-run with --apply to write tags and rename.\n");
}

exit($exitCode);
