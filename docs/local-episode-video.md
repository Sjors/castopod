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

For a published episode with a video, the first tabs are **Video, Audio,
Comments**. The normal episode URL renders a responsive, same-origin iframe
using the static `/video/` player. The `/audio` tab immediately shows Castopod's
normal bottom player with metadata loaded and playback paused. Its bootstrap
button is hidden and does not attach the canplay autoplay hook. Comments uses
the explicit `/discussion` route. The header audio control is omitted on all
tabs of video episodes. Each view has its own cache key. ActivityPub/JSON,
RSS and the other episode routes retain their existing behavior.

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
