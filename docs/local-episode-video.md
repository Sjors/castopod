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

## MP4 alternate enclosure in RSS

The publishing workflow can also advertise the video's standalone MP4 in the
RSS feed as a Podcasting 2.0
[`podcast:alternateEnclosure`](https://podcasting2.org/docs/podcast-namespace/tags/alternate-enclosure).
Pass the single highest-resolution MP4 from the video event's `imeta` receipt
together with its identifier:

```sh
php spark episodes:video --podcast nado --slug an-episode-slug --identifier naddr1... \
  --mp4-url https://blossom.example/<sha256>.mp4,https://mirror.example/<sha256>.mp4 \
  --mp4-sha256 <sha256> --mp4-size 123456789 --mp4-type video/mp4 \
  --mp4-height 720 --mp4-width 1280 --mp4-bitrate 1500000 --mp4-codecs avc1.64001f,mp4a.40.2
php spark episodes:video --podcast nado --slug an-episode-slug --show
```

`--mp4-url` takes a comma-separated list: the primary Blossom URL first, then
up to four mirrors, emitted in that order. `--mp4-url`, `--mp4-sha256` and
`--mp4-size` are required; type defaults to `video/mp4`, the rest is optional.
`--show` prints the stored identifier and the bound MP4 record as JSON and
writes nothing. Unknown options are rejected.

Every field is validated before anything is written, and an invalid value
writes nothing: each URL must be `https://`, at most 2048 characters, without
credentials, query or fragment, unique, and its path must end with the sha256
(optionally with an extension), as in Blossom addressing. The sha256 must be 64
lowercase hex characters, the type exactly `video/mp4`, size, height, width and
bitrate positive integers (height/width at most 8192) and codecs limited to
letters, digits, spaces and `.,+-`.

The record is stored as JSON in `EpisodeVideo.enclosure`, scoped to
`episode:<database ID>`, and records the identifier it was saved for. The feed
emits it only while that identifier equals the episode's current valid
`EpisodeVideo.identifier`, so a stale MP4 is never advertised for another video:

- `--identifier X` with `--mp4-*` options saves both;
- `--identifier X` alone, or saving the admin form, keeps the MP4 only if it
  was saved for the same `X`, and otherwise deletes it;
- `--clear`, or clearing the admin form, deletes both.

For an episode with a bound MP4, the RSS item contains, directly after the
unchanged audio `<enclosure>`:

```xml
<podcast:alternateEnclosure type="video/mp4" length="123456789" height="720" bitrate="1500000" codecs="avc1.64001f,mp4a.40.2" title="Video">
  <podcast:source uri="https://blossom.example/<sha256>.mp4"/>
  <podcast:source uri="https://mirror.example/<sha256>.mp4"/>
  <podcast:integrity type="sri" value="sha256-<base64 of the raw digest>"/>
</podcast:alternateEnclosure>
```

`height`, `bitrate` and `codecs` appear only when supplied; `default` is never
set and the width is stored but not emitted, because the tag has no width
attribute. Castopod performs no relay or Blossom lookup when it renders the
feed, and it does not check that the URLs remain available. Apple Podcasts
ignores this tag; Apple video support is out of scope.

For a published episode with a video, the first tabs are **Video, Audio,
Comments**. The normal episode URL renders a responsive, same-origin iframe
using the static `/video/` player. The `/audio` tab immediately shows Castopod's
normal bottom player with metadata loaded and playback paused. Its bootstrap
button is hidden and does not attach the canplay autoplay hook. Comments uses
the explicit `/discussion` route. The header audio control is omitted on all
tabs of video episodes. Each view has its own cache key. ActivityPub/JSON
and the other episode routes retain their existing behavior; RSS keeps its
audio enclosure and only adds the bound MP4 alternate enclosure described above.

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
