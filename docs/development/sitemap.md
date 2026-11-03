# Sitemap and optional domain selection

This topic works on v1.15.6 without other local features. Sitemap caches are keyed
by origin. All published shows, episodes and available transcripts are listed by
default, together with instance/custom pages; scheduled episodes are excluded.

If the optional podcast-domains configuration is present, only same-origin shows
are listed. Instance-wide pages stay on the hub; dedicated roots that redirect
are omitted. Neither the cache topic, domain configuration, homepage preview,
video nor transcript indexing is required. The optional policy is covered by an
integration test that skips only when that provider is absent.

Advertise each origin's own sitemap from robots.txt or submit it to search tools.
No instance hostname or deployment-specific robots directive is included here.
