# Homepage search appearance

The instance homepage can have a descriptive title while retaining a short site
name. The existing single-podcast redirect remains unchanged; these additions
apply to the instance homepage listing. General settings now exposes optional **Homepage title** (`App.homeTitle`)
and **Alternative site name** (`App.siteAlternateName`) fields. An empty homepage
title falls back to the existing site name. The alternative should be a commonly
used form of that name, not a list of search keywords.

The homepage emits one `WebSite` JSON-LD node with its configured site name and
canonical root URL. A distinct, nonempty alternative name is included when set.
It also displays the existing site description above the podcast list, escaped
as plain text. Titles and metadata use the metadata library's escaping once.
Episode, podcast, feed and transcript metadata are unchanged.

Use the existing site icon upload in General settings for recognizable artwork:
a square PNG at least 512 pixels wide and tall. This native control generates
the favicon and app icon variants; the patch does not replace the default
Castopod icons or choose artwork for other instances. Preserve the existing
site media before replacing an icon. The homepage social image follows that icon.

This patch applies independently to Castopod v1.15.6. It can be combined with
the sitemap, transcript indexing and episode video topics. It requires no schema
migration or dependency change. Rebuild the normal frontend assets when changing
templates so the CSS utility scan includes the rendered introduction.

Preparation is not deployment approval. Save existing settings and site media,
inspect current source changes, and apply only after the maintainer authorizes
deployment. After applying source and settings, invalidate affected page caches
and check root/sort URLs, site icons, social metadata, schema and visible text.
Google decides the final title, snippet and site name; these are preferences,
not a ranking promise or a guarantee of a particular search result appearance.

Upstream checked 2026-11-03: `main` remains v1.15.6; `develop` has moved to an
HtmlHead service. Open SEO MRs !377, !380 and !383 address escaping, Open Graph
tags and podcast/episode schema, respectively; they do not implement this
homepage identity and settings behavior. Port by behavior when moving beyond
v1.15.6 rather than blindly applying a helper diff to the new service.

## Validation (2026-11-03)

On the independent v1.15.6 topic, `tests/unit` passed with 8 tests and 27
assertions. Applied to discovery integration `7f8d8f912256048f47221c9c20a2b11c11422688`,
`tests/unit` plus `tests/modules/Media` passed with 41 tests and 218 assertions.
The tests use PHP 8.3.6, PHPUnit 10.5.63 and a disposable MariaDB database.
Homepage rendering uses synthetic settings and a mocked Vite asset service.

PHPStan, ECS and Rector dry-run passed for changed application/test classes and
the SEO helper. PHP syntax checks passed for every changed PHP file. The English
language file retains upstream translation formatting rather than accepting a
whole-file alignment rewrite from ECS. The PHP templates were syntax-checked
and rendered by the homepage tests; no frontend rebuild or browser playback test
was performed. Production settings, site icons and services were not changed.

## Optional domain identity

The homepage remains independent. If the podcast-domains topic is also installed,
its optional identity provider supplies the hub's name, description, descriptive
title and alternate name. Without that provider, existing settings are used.
