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

For a published episode with a video, **Video is the first tab**. Opening the
normal episode URL redirects temporarily to the standalone `/video/` player.
The explicit `/discussion` route retains the audio/comments page, and the
player's **Audio / comments** link returns there. The redirect has `no-store`
so clearing the setting restores the ordinary landing page. ActivityPub/JSON,
RSS and the other episode routes are unaffected.

Episodes without a valid video identifier have **no Video tab** and open the
ordinary audio/comments page. Unpublished previews do not show the tab.
The channel-restricted player remains responsible for signature verification,
video availability, author restrictions and media playback. A stale stored
identifier is not an availability probe; clear it when withdrawing the video.

The settings data is covered by normal database backups. The PHP config class,
admin/public templates, command and routes remain local patches to preserve
during upgrades. Upstream migration changes need review as usual, but there is
no local database schema change to conflict with them. If the patch is removed,
its namespaced settings records can remain inert or be removed separately.

Deploy the static player separately and route `/video/` to it in nginx before
Castopod's PHP fallback. This work has only been prepared and tested locally;
the unrelated public demo video must not be associated with a production episode.
