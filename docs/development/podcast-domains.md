# Web domains for a multi-podcast instance

This topic builds only on `2026/11/web-cache-origins` and supports a shared Castopod installation with a hub domain
and optional dedicated domains for selected podcasts. nginx owns the redirects;
Castopod still uses its native `/@handle` routes. This is not an ActivityPub actor
migration, and does not modify podcast or episode GUIDs, feed relocation settings,
media locations, authentication settings or stored actor identities.

## Request URL configuration

In both HTTPS virtual hosts, include this alongside `SCRIPT_FILENAME` and the
existing FastCGI parameters in every PHP entry point:

```nginx
fastcgi_param app_baseURL https://$server_name/;
```

Use explicitly configured server names. Replace the private `.env` short-name
`app.baseURL` entry with a full-class fallback, for example:

```dotenv
Config\App.baseURL = 'https://show.example/'
podcastweb.hubURL = 'https://hub.example/'
podcastweb.domains = '{"main":"https://show.example/"}'
podcastweb.hubName = 'Example Podcasts'
podcastweb.hubTitle = 'Example Podcasts — Conversations and readings'
podcastweb.hubDescription = 'Our main podcast and other recordings.'
podcastweb.hubAlternateName = ''
```

Remove short-name `app.baseURL` and `app_baseURL` entries from `.env`; otherwise
its values take precedence over nginx's FastCGI request parameter. The full-class
fallback supplies the existing origin to CLI tasks. Leave `media.baseURL` alone.
A root URL includes HTTP(S), a hostname and an optional port, without credentials,
query strings, fragments or a non-root path. Configuration is private and is not
stored in podcast records. Omitting `hubURL` retains single-domain behavior.

Hub branding selects the site name and description only on the hub. With the
optional `2026/11/homepage-preview` topic it also selects its descriptive title,
alternate name, WebSite schema and visible introduction. Neither that topic,
video nor transcript/episode SEO is a prerequisite. Branding overrides apply only
when `hubName` is set. Dedicated domains retain the existing settings. An empty
hub title falls back to the hub name; an empty alternate name remains omitted.
The hub still lists every published podcast, including those with dedicated
domains. Native hub links to a dedicated show may take one nginx redirect.
Unmapped future podcasts stay on the hub.

## nginx routing responsibilities

- Dedicated root `/` redirects permanently to `/@main` and other `/@handle`
  paths can return 404. Keep the dedicated show's existing RSS and episode URLs.
- The hub serves the app, uploaded media, assets and any separate player. Redirect
  the dedicated show's browser pages, feed aliases and audio routes to the
  dedicated host, preserving paths and query strings.
- Retain ActivityPub-negotiated GETs, original federation endpoints and POSTs on
  the federation domain. Do not blanket-redirect these to migrate web pages.
- Keep certificate and HTTP-to-HTTPS handling for both virtual hosts.
- Serve host-specific robots directives. The static file from the earlier
  sitemap deployment otherwise advertises one hostname on both domains:

```nginx
location = /robots.txt {
    default_type text/plain;
    return 200 "User-agent: *\nDisallow:\n\nSitemap: https://$server_name/sitemap.xml\n";
}
```

The exact site map and redirects belong in private deployment configuration.
The separate upstream custom-domain MR !376 was examined at
`83cb23089c469e9cc4710c42c88354162fc5a272`. Its behavior removes dedicated shows
from the hub and changes their federation domain, which does not fit this setup.

## Caches and discovery

Rendered pages, RSS and map markers append an origin hash to their existing
cache key. Model/query caches remain shared. The original key prefixes are
retained, so podcast/episode/page invalidation still reaches every origin. Map
marker invalidation now removes all origin variants. No cache-wide per-host
prefix is introduced.

The optional `2026/11/sitemap` topic detects this configuration without taking
a domain-topic dependency. Its sitemaps keep their one-hour lifetime and use the request origin plus the domain
selection configuration in their cache key. The hub lists its instance pages and
unmapped shows. Dedicated domains list only their mapped shows, including the
existing published-episode/transcript selection. The dedicated root is omitted
because it redirects. Another hostname's pages and redirects are omitted.

Deploy source, the private environment changes and nginx routing together. Clear
old rendered output once on deployment, without restoring an old database or
changing episode/media records. Check actual canonical tags, home branding,
JSON-LD, both sitemaps/robots files, feeds, GUIDs, enclosures, byte ranges and
federation responses. Keep original media and existing local application patches.
Preparing this branch does not deploy it or reload nginx.
