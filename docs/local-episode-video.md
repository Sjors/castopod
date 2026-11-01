# Local episode Video tab

This patch adds a Video tab to published episodes with an entry in
`writable/episode-videos.json`. It opens the full-page static player on the same
origin at `/video/`; it does not embed it in Castopod or add a catalog.
Unmapped episodes and unpublished previews keep their current tabs.

The mapping is a JSON object keyed by podcast handle and episode slug:

```json
{
  "nado": {
    "an-episode-slug": "naddr1..."
  }
}
```

Use a complete `naddr1…` (preferred for editable videos), `nevent1…` or `note1…`
identifier. The static player's compiled channel allowlist decides whose videos
can play; Castopod does no relay requests. A missing or invalid mapping file
leaves the Video tab hidden. Updating the mapping may require clearing the
episode page cache using the normal Castopod cache workflow.

Deploy the static player separately and route `/video/` to it in nginx before
Castopod's PHP fallback. The player and the tab patch do not publish Nostr
events or upload media. Keep demonstration content and its mapping off the
production site until replaced with the podcast's actual videos.
