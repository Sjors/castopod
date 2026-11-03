# Rendered caches for multiple web origins

This independent v1.15.6 topic appends the request origin to cached pages, RSS and
map-marker responses. Shared model/query caches remain shared. Original cache-key
prefixes are retained so normal episode, podcast, comment and page invalidation
removes every origin variant. Map marker invalidation also removes all variants.

No hostnames, redirects, identity settings, sitemap, transcript indexing or video
feature are added. Configure native request URL generation separately when serving
one instance through multiple hosts. The podcast-domains topic builds on this fix;
other installations can use it on its own. No migration, dependency or asset build.
