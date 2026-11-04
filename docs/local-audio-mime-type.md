# Consistent M4A MIME declarations

Castopod v1.15.6 can store getID3's `audio/mp4` detection for M4A audio while
common HTTP servers deliver the `.m4a` file as `audio/x-m4a`. Apple's RSS guide
lists `audio/x-m4a` for this format.

The audio entity exposes `audio/x-m4a` when an M4A file has a known audio MIME
alias (`audio/mp4` or `audio/m4a`). This covers RSS enclosures and trailers as
well as other entity consumers, including previously imported media. Raw stored
metadata and the media files remain unchanged. Other file extensions and unknown
or video MIME types retain their original values.

Run `php vendor/bin/phpunit --no-coverage tests/modules/Media/AudioMimetypeTest.php`.
The tests cover old aliases, case-insensitive extensions, canonical M4A, MP3,
MP4 and unknown/video types, including unchanged raw metadata.

This topic is based on v1.15.6 and has no required topic dependency. The canonical
[patch catalog](https://github.com/Sjors/sovereign-podcaster/blob/main/docs/patches/README.md)
records maintained topics and source/publication state. Deployment must preserve
other local audio behavior and invalidate existing rendered-feed caches.

Disabling gzip compression for already compressed audio is a separate web-server
configuration repair, not part of this application patch.
