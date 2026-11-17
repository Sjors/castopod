# Local episode Video metadata

The video identifier is stored using Castopod's existing database-backed
settings service: `EpisodeVideo.identifier`, scoped to `episode:<database ID>`.
There is no new column, table, migration or relay-detection job. The record is
independent of the episode slug. Do not modify an upstream table or migration.

After publishing the video, open the existing episode's edit page, paste its
`naddr1…` (preferred), `nevent1…` or `note1…` identifier under **Published Nostr
video**, and select **Save video link**. Clear it and save to restore audio only.
This separate form uses the existing episode-edit permission and CSRF protection.

For the publishing workflow, the same operation is available locally:

```sh
php spark episodes:video --podcast nado --slug an-episode-slug --identifier naddr1...
php spark episodes:video --podcast nado --slug an-episode-slug --clear
```

These commands write immediately; use them only for an authorized episode.
They select by both podcast and slug, save through the settings service, and
clear the episode/podcast caches. They do not change the audio, GUID, publication
date or description, and perform no upload, relay lookup or Nostr publication.

## HLS and MP4 alternate enclosures in RSS

The publishing workflow can also advertise the video in the RSS feed as
Podcasting 2.0
[`podcast:alternateEnclosure`](https://podcasting2.org/docs/podcast-namespace/tags/alternate-enclosure)
elements: an HLS stream (the Podcast Standards Project's recommended video
format, read by apps such as Pocket Casts, Fountain, Podcast Addict, Podcast
Guru and TrueFans), a standalone MP4, or both. Pass the HLS master playlist
and the single highest-resolution MP4 from the video event's `imeta` receipt
together with its identifier:

```sh
php spark episodes:video --podcast nado --slug an-episode-slug --identifier naddr1... \
  --hls-url https://blossom.example/<playlist sha256>.m3u8 \
  --hls-sha256 <playlist sha256> --hls-size 639 --hls-type application/x-mpegURL \
  --hls-height 720 --hls-codecs av01.0.05M.08,mp4a.40.2 \
  --mp4-url https://blossom.example/<sha256>.mp4,https://mirror.example/<sha256>.mp4 \
  --mp4-sha256 <sha256> --mp4-size 123456789 --mp4-type video/mp4 \
  --mp4-height 720 --mp4-width 1280 --mp4-bitrate 1500000 --mp4-codecs avc1.64001f,mp4a.40.2
php spark episodes:video --podcast nado --slug an-episode-slug --show
```

For HLS, the sha256 and `--hls-size` describe the master playlist file itself
(its exact bytes and byte count), not the segments; `length` is required by the
namespace. `--hls-height` is the height of the highest rendition.
`--hls-type` accepts `application/x-mpegURL` (the default) or
`application/vnd.apple.mpegurl` and always stores and emits
`application/x-mpegURL`.

`--hls-url` and `--mp4-url` take a comma-separated list: the primary Blossom URL
first, then up to four mirrors, emitted in that order. When any `--hls-*`
option is given, `--hls-url`, `--hls-sha256` and `--hls-size` are required;
likewise for `--mp4-*`. The MP4 type defaults to `video/mp4`; the other fields
are optional. `--hls-*` and `--mp4-*` options require `--identifier` and may be
combined in one call. `--show` prints the stored identifier and the bound HLS
and MP4 records as JSON and writes nothing. Unknown options are rejected.

Every field is validated before anything is written, and an invalid value
writes nothing: each URL must be `https://`, at most 2048 characters, without
credentials, query or fragment, unique, and its path must end with the sha256
(optionally with an extension such as `.m3u8` or `.mp4`), as in Blossom
addressing. Each sha256 must be 64 lowercase hex characters, and the HLS and
MP4 hashes must differ. The MP4 type must be exactly `video/mp4`; size,
height, width and bitrate must be positive integers (height/width at most 8192)
and codecs are limited to letters, digits, spaces and `.,+-`.

The record is stored as JSON in `EpisodeVideo.enclosure`, scoped to
`episode:<database ID>`, as `{"identifier": …, "hls": {…}|null, "mp4": {…}|null}`
with at least one of `hls` and `mp4`. A record in the original MP4-only shape
(the MP4 fields next to the identifier) is still read as MP4-only. The feed
emits the record only while its identifier equals the episode's current valid
`EpisodeVideo.identifier`, so a stale video is never advertised for another
video:

- `--identifier X` with `--hls-*` and/or `--mp4-*` options **replaces** the
  whole stored set: giving only `--mp4-*` removes a previously stored HLS
  stream, and giving only `--hls-*` removes a previously stored MP4. Pass both
  to keep both;
- `--identifier X` alone, or saving the admin form, keeps the HLS/MP4 record
  only if it was saved for the same `X`, and otherwise deletes it;
- `--clear`, or clearing the admin form, deletes both.

For an episode with both, the RSS item contains, directly after the unchanged
audio `<enclosure>`, the HLS element first and then the MP4:

```xml
<podcast:alternateEnclosure type="application/x-mpegURL" length="639" height="720" codecs="av01.0.05M.08,mp4a.40.2" title="Video (HLS)">
  <podcast:source uri="https://blossom.example/<playlist sha256>.m3u8"/>
  <podcast:integrity type="sri" value="sha256-<base64 of the raw playlist digest>"/>
</podcast:alternateEnclosure>
<podcast:alternateEnclosure type="video/mp4" length="123456789" height="720" bitrate="1500000" codecs="avc1.64001f,mp4a.40.2" title="Video">
  <podcast:source uri="https://blossom.example/<sha256>.mp4"/>
  <podcast:source uri="https://mirror.example/<sha256>.mp4"/>
  <podcast:integrity type="sri" value="sha256-<base64 of the raw digest>"/>
</podcast:alternateEnclosure>
```

`height`, `bitrate` and `codecs` appear only when supplied; `default` is never
set and the width is stored but not emitted, because the tag has no width
attribute. Castopod performs no relay or Blossom lookup when it renders the
feed, and it does not check that the playlist, its segments or the MP4 remain
available.

Codec caveat: an AV1 stream (`av01…`), whether in HLS or MP4, plays only on
devices that can decode AV1; the codecs attribute lets apps skip an unsupported
alternate. Apps that ignore `podcast:alternateEnclosure`, such as Apple
Podcasts and Spotify, keep using the audio enclosure; Apple video support is
out of scope.

For a published episode with a video, the first tabs are **Video, Audio,
Comments**. The normal episode URL renders a responsive, same-origin iframe
using the static `/video/` player. The `/audio` tab immediately shows Castopod's
normal bottom player with metadata loaded and playback paused. Its bootstrap
button is hidden and does not attach the canplay autoplay hook. Comments uses
the explicit `/discussion` route. The header audio control is omitted on all
tabs of video episodes. Each view has its own cache key. ActivityPub/JSON
and the other episode routes retain their existing behavior; RSS keeps its
audio enclosure and only adds the bound HLS/MP4 alternate enclosures described
above.

Episodes without a valid video identifier have **no Video or Audio tab** and
retain their ordinary header audio button and comments page. Unpublished
previews retain the ordinary layout.
The channel-restricted player remains responsible for signature verification,
video availability, author restrictions and media playback. A stale stored
identifier is not an availability probe; clear it when withdrawing the video.

The settings data is covered by normal database backups. The PHP config class,
admin/public templates, command and routes remain local patches to preserve
during upgrades. Upstream migration changes need review as usual, but there is
no local database schema change to conflict with them. If the patch is removed,
its namespaced settings records can remain inert or be removed separately.

Deploy the static player separately and route `/video/` to it in nginx before
Castopod's PHP fallback. Configure the build's allowed channel and relays; the
PHP patch contains no channel, episode or hostname. Do not deploy an unrelated
demo channel. The iframe hides duplicate player branding/title and adds
`(powered by Castopod and Nostube)` beneath it.

This patch changes `resources/js/_modules/play-episode-button.ts`: rebuild and
deploy frontend assets together with their matching Vite manifest. Keeping only
the PHP files leaves the Audio tab's paused initialization unavailable. Preserve
the existing fonts, icons and other required static assets. No database schema
migration or Composer dependency update is required by this patch.

The instance's deployed state, backup and upgrade-preservation instructions are
recorded in the `castopod-maintenance` skill's patch register and the deployment
record under `/home/dev/Nado/maintenance/video-20261101/` on dev.
