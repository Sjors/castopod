# Episode search appearance on Castopod v1.15.6

Branch `2026/11/episode-seo` builds on the latest published
`2026/11/transcript-indexing` head, `5e794419097fef3df53930a9333824e028413bed`.
That prerequisite has the same tree as its original local preparation commit.
The homepage appearance topic is independent; it is not included in this branch.
All changes remain local, unsigned, unpushed and undeployed.

## Public-page review (2026-11-03)

The review sampled the episode and transcript pages for:

- Episode 101: The COLDCARD Hack.
- Episode 100: Bitcoin Core 31.
- Episode 94: Silent Payments part 2.

All six HTML pages returned 200 with valid, distinct episode/transcript
canonicals. The COLDCARD episode also returned the same metadata to a Googlebot
user agent. The transcript pages were present and linked from their episodes.
The patch preserves these behaviors.

The episode titles did not identify the podcast. Open Graph URL/type were absent,
and the COLDCARD embedded player had neither an HTTP nor HTML indexing control.
Source inspection also identified repeated output escaping in the episode helper
and templates that declared the visitor's interface language as the podcast
document language. The latter did not manifest in the sampled English responses;
it is covered with a supported French UI request and English podcast content.
Dutch Google settings do not imply that the website uses a Dutch UI locale.

Descriptions were already plain text and began with useful topic information.
Their lengths were 769 characters for COLDCARD, 270 for Core 31 and 1285 for
Silent Payments. Long descriptions are not automatically invalid and are not
truncated by this patch. Editorial summaries, structure, sources and recurring
boilerplate placement belong in a separate shownote review.

## Upstream fixes and adaptations

These open upstream MRs were fetched and inspected on 2026-11-03. Each adapted
commit retains Progi1984's authorship and subject with a `Backport of` reference.
The new commits use current UTC author and committer timestamps because the
ports substantially adapt develop's code to the stable release.

| MR | Source head | Stable behavior |
| --- | --- | --- |
| [!377](https://code.castopod.org/adaures/castopod/-/merge_requests/377) | `1d3bd07dba85a2b326fdf84baa594b16b7a4ca32` | Episode/transcript descriptions, site name and audio artist metadata are escaped once by MetaTags. Stored HTML entities in plain descriptions are decoded before output; entity/feed behavior is unchanged. |
| [!382](https://code.castopod.org/adaures/castopod/-/merge_requests/382) | `774bdf6094c998ce2450d3bfecb2cd7e770d98c3` | Append the podcast title to episode page titles, retaining the transcript prefix and schema association. |
| [!380](https://code.castopod.org/adaures/castopod/-/merge_requests/380) | `d8f3dd443bd8dc756664618a551fd2a7869b119d` | Add `og:type=article` and use each episode/transcript page's canonical as `og:url`. |
| [!381](https://code.castopod.org/adaures/castopod/-/merge_requests/381) | `85d8aeba80efaf367e2c9a0ff76f64a20e3bc62a` | Use the podcast language for public podcast, episode, preview and embed document declarations; preserve UI/player control localization. |
| [!385](https://code.castopod.org/adaures/castopod/-/merge_requests/385) | `28a390ca8dd7a133254ac0f4a16bd39d69439813` | Send `noindex, indexifembedded` for embedded players in HTTP and HTML, including cached responses. |

The ports keep the v1.15.6 MetaTags library instead of importing develop's
HtmlHead service. Escaping and Open Graph changes are restricted to episode
metadata so unrelated helper functions and the separate homepage patch remain
independent. The explicit description entity decoding also covers imported
HTML entities after the entity getter strips markup. Schema descriptions use
that same plain text, while stored shownotes and feeds remain unchanged.

## Validation and application

Regression tests check metadata escaping, branded titles, unchanged episode and
transcript canonicals/schema, Open Graph identity, document language independent
of UI locale and embedded-player indexing controls. Rendering fixtures use
synthetic data, an isolated test database and a mocked Vite asset service.

The maintained patch catalog records the final test counts, source pins and
combined discovery/video/homepage test result. No production browser playback
test, frontend rebuild, search reindexing or deployment is part of preparation.

Do not deploy while the owner's transcript hold is in place. A later authorized
installation must inspect current source and active tasks, preserve independent
video/card changes, snapshot affected source and settings, and reconcile source
changes rather than replace the live tree. Invalidate affected page caches after
installation and verify real crawler responses, player routes, unchanged feeds,
and transcript associations against fresh baselines. Do not restore an old
database over completed transcripts or regenerate production media as part of
these metadata changes.
