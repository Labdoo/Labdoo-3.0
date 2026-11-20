# Main-menu loading optimizations — 2026-11-19

Task: `6j3fVJ6WfV3849R3`. This report covers the performance change after the
separate filter-layout release `2320c2f5`.

## Implementation

- Dashboard maps query the actual accessible, exposed-filtered View, including
  its aggregation and node-access conditions. Tagged datasets expire after five
  minutes and vary by account, permissions, grants and language. Publication,
  coordinates and account changes invalidate the relevant caches.
- Next requests counted geographic clusters and then viewport detail as the map
  moves. At street zoom the endpoint returns individual points. The legacy array
  response remains available. Counts preserve coincident locations; the query
  removes translation duplicates and accepts valid points on the equator or
  Greenwich meridian. Consequently old and new raw counts need not be identical.
- Public footer scripts use ordered `defer`, allowing their downloads and HTML
  parsing to proceed together. Mobile header utilities start in their final
  hidden-menu state, avoiding the 58-pixel shift when footer behaviors attach.
- Responsive lossless WebP derivatives replace oversized logos, small domain
  icons, footer artwork and the Stories header. Existing content and attribution
  remain intact; image dimensions reserve layout space.
- Simple charts load a pinned Chart.js 4.4.0 bar subset. Mixed/advanced charts
  upgrade to the original full version before loading optional adapters/plugins,
  preserving existing charts and IDs. Gzip library size falls from 69,324 to
  58,448 bytes for a simple chart.
- Language-cacheable HTML embeds six safely encoded translated quote choices and
  the small observer. Selection remains fresh and nonrepeating across cached page
  loads, without an uncached request or an extra blocking quote script. Existing
  private/no-store quote API and old cached-HTML fallback remain supported.
- The maintained FPM deployment verifier now checks the actual Next default theme,
  clustered-map integrity and fresh-quote cache policy. Its isolated invocation
  tests the checker against this checkout before using it on v3.

An experimental `content-visibility` rule was removed: it did not establish a
repeatable LCP improvement and produced a mobile target-spacing audit regression.
Its trial reports are retained privately and are excluded from final results.

## Environment and method

The tested working tree is based on `d714a9dd`; unrelated existing edits were
preserved. Publication uses an isolated worktree based on the deployed
`2320c2f5`, copying only the reviewed task files. The private source manifest
records SHA-256 hashes of those tested files.

Drupal installed and locked: 11.4.13; installed Composer package versions match
the lockfile. PHP CLI/FPM: 8.3.36 locally; Node: 25.2.1; Playwright: 1.65.0;
Lighthouse: 12.8.2; Chrome: 157.0.8103.38. The inspected v3 PHP runtime is
8.3.6, so local results do not certify the identical PHP patch release.
PHP-FPM profiling enables OPcache and
verifies the effective account. No production load testing, schema updates,
configuration import, live queue processing or real email is involved.

All local browser/load checks use this checkout's isolated site on port 18411,
restricted clone database, mail collector and HTTP guards. Lighthouse runs
sequentially, with warmed server aggregates and a fresh browser cache, three
runs per mobile/desktop profile for each of nine menu routes. External assets
are not stubbed. Load tests and other browsers do not run concurrently with it.

Stories initially had 23 missing source images in the local clone. Their existing
public originals were copied read-only from v3 into the isolated site's media
directory; checksums and derivative HTTP 200 responses were verified. A separate
six-run reference restores the pre-optimization frontend temporarily, measures
with those same images, and restores the optimized files before final testing.
The initial broken-image Stories measurements are not a valid comparison.

Private artifacts: `tests/performance/.local/menu-opt-before`,
`menu-opt-after`, `menu-opt-iteration1`, `menu-opt-iteration2-activities`,
`menu-media`, and `tests/upgrade/.local/state/runs`. These raw reports, traces,
sessions and media are not committed.

## Origin image-cache inspection

Read-only v3 inspection confirmed `mod_headers` is enabled and `mod_expires` is
not loaded. The root `.htaccess` expiry block is conditional on `mod_expires`,
so that block is inactive. Public HTTPS requests, including the wordmark, return
the existing Basic Auth 401 through CloudFront; they cannot establish the cache
policy of a successful authenticated image response. No server-wide Apache or
CDN configuration was changed. Successful-response/CDN TTL verification remains
limited by that access boundary.

## Validation and results

All 54 final Lighthouse reports were inspected. Runtime, console and HTTP asset
errors: **0**. Six warnings are the existing school-dashboard canonical redirect
to `/es`, also present in the reference. Scores do not constitute a pass of every
audit: existing alt/link-content findings remain on Activities. Accessibility
medians are unchanged on every route/profile.

The table reports median mobile LCP in milliseconds; all individual before/after
metrics and scores are in [the sanitized results](menu-optimizations-20261119.json).

| Route | Before | After | Change |
| --- | ---: | ---: | ---: |
| actions | 2235 | 1579 | -29.3% |
| activities | 2977 | 2824 | -5.1% |
| dootrips | 2312 | 2102 | -9.1% |
| dootronics | 2156 | 2030 | -5.9% |
| hubs | 2346 | 2177 | -7.2% |
| schools | 2320 | 2229 | -3.9% |
| stories | 3002 | 2028 | -32.5% |
| teams | 2205 | 1908 | -13.5% |
| wiki | 2150 | 2057 | -4.3% |

Activities has a modest 5.2% observed median LCP reduction; the run ranges overlap,
so this is not a guaranteed field-speed improvement. Its mobile CLS falls from
0.057 to zero. Desktop LCP medians vary by +14 ms on Activities and +5 ms on Wiki,
with other routes stable or faster. The final chart lifecycle correction was checked with 33 desktop/mobile cases,
then the four affected dashboard routes were remeasured in 24 Lighthouse runs.
Some mobile blocking-time and speed-index audits vary between repeated runs;
all individual values and newly flagged audits remain in the JSON. Hubs/Stories
also show occasional short unattributed forced-reflow work. Accessibility is
unchanged. The school alias redirects to `/es`, so its result describes that
actual destination rather than a separately rendered school dashboard.

The pre-existing local legacy-theme/accessibility edits are preserved and excluded
from this release. The full local suite therefore validates the active dirty
checkout; the release contains identical task files on the newer deployed base,
but local results do not certify identical legacy-theme rendering on v3.


### Interrupted regression attempt

`menu-optimization-full-20261119` stopped before completion. The lab server
received SIGTERM (exit 143) after approximately two hours of process lifetime;
subsequent cases failed with connection refused. The runner was interrupted to
stop the cascade. Its progress snapshot records 165 passed, 93 failed and four
skipped of 529 planned; there is no complete JSON/HTML result for this attempt.
These counts are not a completed regression result. Two earlier failures were
obsolete logo/footer filename assertions; their expectations now explicitly
verify the responsive WebP source, source set and dimensions. The single
partially updated synthetic dootronic was identified and removed through the
isolated guard. A new full run uses a restarted server and writes logs to a
private file. No application source was changed during either run.


### Completed browser regression and focused corrections

The full recovered run `menu-optimization-full-recovered-20261119` executed all
529 planned cases: **500 expected passes, 3 unexpected failures, 26 intentional
skips, 0 flaky cases and 0 reporter/global errors**. Both its JSON and embedded
HTML ZIP statistics were inspected. One failure is Chrome's
`ERR_NETWORK_IO_SUSPENDED` after the host suspended. Two mobile home cases
incorrectly required Leaflet before the below-fold map entered the viewport.
The existing, previously untracked home-accessibility test is deliberately
included in this release: it preserves the accessibility, metadata and no-CDN
assertions, scrolls to the lazy map, and verifies its zoom control after loading.
This is a whole test-file addition on the destination, not a comment-only edit.

`menu-recovered-failures-20261120` repeats the affected cases in both relevant
profiles: **5/5 passed, 0 failures/skips/flaky/global errors**, with JSON and HTML
reports inspected. This covers the three failed cases and two desktop home
counterparts. Thus all 503 applicable unique cases have passing evidence across
the full run and focused correction; the original full-run failures remain in
its report and are not relabeled as passes.

The 26 intentional skips match the reference: four need the optional read-only V2
fixture, twenty are non-navigation/administrative actions, and two are external
destinations. None is a skip of the new map/render/quote cases. No budgets,
retries, access rules or performance thresholds were relaxed.

Additional focused checks: 71 quote/render/chart cases passed; the complete
ten-case map suite passed; header/script cases passed on desktop and mobile;
ten database-free FPM-verifier cases and standalone 2,000-point clustering
assertions passed. PHP and JavaScript syntax checks passed. Rebuilding the bar
bundle produces SHA-256 `08264e35ee626c0460cd5547327f0cc260dc8723bb9b21b56437865b6523c4b2`;
all 21 rebuilt image derivatives match the release assets byte for byte.

### Map load comparison

The comparable one-minute scenario uses ten virtual users and rotates the same
four map types. Its original baseline made 468 requests (467 completed
iterations), with p95 **762.94 ms**, no failed HTTP requests, all 1,868 checks
passing, and 694,304,314 received bytes.

After the change, the legacy representation made 546 requests (545 iterations):
p95 **59.04 ms**, no failed HTTP requests, 2,180/2,180 checks passing. Total
traffic is 769,672,893 bytes: more requests finish, but the full arrays remain
large. The maximum request was 3,796.92 ms; p95 is not a cold-request guarantee.

The clustered representation, run after the legacy pass with tagged datasets
warm, made 601 requests (600 iterations): p95 **11.74 ms**, no failed HTTP
requests, all 2,400 checks passing, and **2,230,500 received bytes**. Average
scenario traffic per request falls from about 1.48 MB to 3.71 kB (99.75%).
Traffic includes the initial isolation-check page and HTTP overhead; these k6
figures must not be confused with browser gzip or a first cold map measurement.
The existing 2,000-ms p95, 2% error and 100% functional-check budgets all pass.

A preflight attempt after the Codex daemon restarted found no running local
server. The isolation guard stopped it at setup with one refused request and no
load iterations. Its failed summary/log are retained privately as
`map-legacy-load-setup-refused-20261120`; it is not performance evidence. The lab
and separate FPM pool were restarted before the successful scenarios above.

### Sustained load and map readiness

The maintained 13-minute k6 regression completed 46,254 requests and 38,465
iterations, reaching 100 virtual users: p95 **15.40 ms**, zero failed HTTP
requests and **115,395/115,395 checks passed**. Maximum response was 2,536.35 ms.
All existing budgets pass without modification.

A separate three-run component comparison uses the old map JavaScript from
`2320c2f5` and the new script against the same current warm backend/data, with
external map-provider assets blocked. It measures marker loading, not complete
provider painting or the entire historical stack. For 56,558 locations, old
raw JSON is 3,640,725 decoded bytes / 501,818 encoded bytes; seven initial
clusters preserve that total in 443 decoded / 252 encoded bytes. Median marker
readiness falls from **1,390.8 to 48.1 ms** at 390px and **1,285.4 to 34.5 ms**
at 1440px. Full raw points remain available at detailed zoom.

### Backend profiling and limits

The separate PHP-FPM pool has OPcache enabled and verifies anonymous/basic
accounts. These measurements capture bootstrap and kernel handling, including
SQL before response sending; they do not separate every View/block or include
all late BigPipe rendering. Cold samples are single observations, not medians.

| Path/account | Before cold | After cold | Before warm samples | After warm samples |
| --- | ---: | ---: | --- | --- |
| Activities / anonymous | 6,480 ms | 8,442 ms | 10.87 / 6.92 ms | 13.72 / 7.96 ms |
| Devices / anonymous | 3,138 ms | 3,914 ms | 7.46 / 6.84 ms | 15.03 / 12.99 ms |
| Activities / basic | 2,995 ms | 3,834 ms | 560 / 488 ms | 800 / 642 ms |
| Devices / basic | 2,717 ms | 3,187 ms | 590 / 551 ms | 693 / 687 ms |
| Device map / anonymous | 574 ms | 2,138 ms | 497 / 509 ms | 6.59 / 8.55 ms |
| Device map / basic | 536 ms | 2,221 ms | 503 / 493 ms | 393 / 295 ms |

Map before uses raw points; after uses clusters at zoom 2. The real View-derived
access/filter query costs more on the first uncached request: its main joined
query increases from roughly 306 to 1,260 ms. Warm anonymous data then needs only
two queries. Cold HTML SQL also takes longer with similar query counts (for
example, Activities remains 2,069 queries), so these samples establish no general
backend speed improvement. The release trades extra cold dataset work for
correct access/filter semantics, reusable tagged data and much smaller browser
responses. Cold map latency remains a follow-up optimization opportunity; no
speculative indexes or production prewarming were introduced.

The actual isolated FPM deployment verifier passes all 12 checks without PHP
stderr. PHPCS, PHPStan and PHPUnit are unavailable in this checkout; they are
not reported as passing. PHP/JavaScript syntax checks, standalone assertions,
operational unit tests and browser/FPM coverage provide the available checks.
