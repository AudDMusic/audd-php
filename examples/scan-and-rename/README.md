# scan-and-rename

Walk a folder of audio files, recognize each via the AudD API, write the
recognized artist/title (plus album/year when present) into the file's tags,
and rename it to `Artist - Title.ext`.

```sh
cd examples/scan-and-rename
composer install
export AUDD_API_TOKEN=aud_xxx        # from https://dashboard.audd.io
php scan-and-rename.php /path/to/folder                       # dry-run (default)
php scan-and-rename.php /path/to/folder --apply               # actually tag + rename
php scan-and-rename.php /path/to/folder --apply --concurrency 4
```

What it does:

- Walks the folder recursively via `RecursiveDirectoryIterator`. Picks up
  `.mp3 .flac .ogg .opus .m4a .mp4 .wav .aac`.
- Calls `$audd->recognize($path)` once per file.
- On a match, sanitizes `Artist - Title` (replaces `/ \ : * ? " < > |` and
  control chars with `_`, trims to 200 chars) and renames in place. Skips on
  collision or when artist/title are missing.
- Prints `[3/27] foo.mp3  renamed: foo.mp3 -> Artist - Title` per file plus a
  summary at the end.

`--apply` is destructive — it rewrites tag bytes and renames files. Run the
default dry-run first and read the output before committing.

## Tag-writing scope

Tag writing uses [`james-heinrich/getid3`](https://github.com/JamesHeinrich/getID3)'s
`WriteTags` module. The writer is most reliable for **MP3** (ID3v2.4 + ID3v1),
which is what this example actually retags. Files in other formats are still
recognized and renamed; their tag bytes are left alone. To extend coverage,
add more entries to `RETAGGABLE_EXTS` and pick the matching `tagformats`
(`vorbiscomment` for OGG/Opus, `metaflac` for FLAC, `ape` for APE, etc.).

## Concurrency

`--concurrency` is accepted for API parity with the other-language examples
but PHP processes files sequentially here. Spinning up `pcntl_fork` workers
purely for I/O concurrency on a recognition loop is overkill; if you need
parallelism, run multiple `php scan-and-rename.php` processes against
disjoint sub-folders.

## License note

`james-heinrich/getid3` is dual-licensed **GPL-1.0-or-later / LGPL-3.0-only / MPL-2.0**.
Pick the license that fits your distribution before shipping anything derived
from this code. The AudD SDK itself (`audd/audd`) is MIT and stays MIT.
