# Translation estimate latency — 2026-11-20

Task: `6j3cwRH4G57jGqq3`. Local release validation is complete: full E2E,
repeated estimate load, 84 Lighthouse measurements and sustained shared-flow load.
The focused publication preserves unrelated work. The resulting deployed commit
and target-runtime outcome are recorded in the [task](https://app.todoist.com/app/task/6j3cwRH4G57jGqq3).

## Diagnosis and change

The existing five-user, 30-second estimate-only scenario reproduced the failure:
p95 **2,886.88 ms**, 61 requests, all 60 checks passing and no HTTP failures.
Its older 4,019.14-ms result belongs to the historical release conditions, not
the current comparable reference. Neither scenario starts a translation agent.

Two CPU costs were demonstrated:

- Drupal's `PoDatabaseReader` repeatedly removes the first item from its full
  result array. The workflow now iterates the same Locale storage result once,
  retaining the exact untranslated conditions and `PoItem::setFromArray`
  mapping, empty-source handling, contexts, plural preference and deduplication.
  A guarded component comparison produced identical hashes for all 17,324
  Spanish and 11,397 French records. Reader/foreach times were 294/113 ms and 166/93 ms.
  There is no limit or cached pending inventory. Locale storage is injected;
  legacy direct constructors keep the lookup previously done by the core reader.
- Portable catalogs repeatedly validated the same file data across requests,
  including catalogs loaded while rendering shared language/navigation chrome.
  A three-run component profile took 283/317/284 ms to validate the 20 language
  catalogs. Validated source/language maps now use `cache.data`, with a key that
  includes the current source and language SHA-256, filename and validation
  schema version. Entries expire after 24 hours. File content is checked before
  reuse; validation results are bound to the actual bytes parsed, including
  concurrent file replacements. Invalid/missing files are not cached.

Only validated portable file data is shared. Native Locale translations, pending
inventory, agent/model prices, estimates and cost confirmations remain fresh.
Native translations still pass their existing validation and take precedence.
Request-local snapshots still refresh on `reset()`. No permissions, theme
negotiation, access checks or agent execution rules were changed.

## Environment and method

The active checkout is development at `d714a9dd` plus preserved existing work;
the publication worktree starts at deployed `b60ed0cb`. Six reviewed source/test
files are tracked by a private SHA-256 manifest. Composer installed versions
match the lockfile, including Drupal 11.4.13. Local PHP is 8.3.36, Node 25.2.1,
Playwright 1.65.0, k6 2.3.0, Lighthouse 12.8.2 and Chrome 157.0.8103.38. Target v3 PHP is 8.3.6;
local results do not certify an identical PHP patch. PHPCS, PHPStan and PHPUnit
are unavailable and are not reported as passing.

All tests run against this checkout's guarded local site on 18411 and its isolated
database. Fake agents, controlled HTTP and local mail capture remain enabled.
The private operator cookie is passed through the environment, never argv or
reports. No production load, real mail, paid provider or live queue is involved.
The fixture inspection after initial measurements confirmed zero agent calls,
queued work or created translations; it was cleaned before the full E2E run.

The unchanged k6 scenario obtains fresh forms/CSRF state for GET, pending preview
and estimate. It includes its initial setup request. Budgets remain p95 < 2,000 ms,
HTTP errors < 2% and 100% functional/isolation checks. After code/cache rebuilding,
the first comparable pass has p95 **1,731.57 ms** (40.0% lower), 94 requests,
93/93 checks and zero HTTP failures. Maximum is 3,528.55 ms; this does not promise
every cold request below two seconds. The independent post-regression repetition
also passes: p95 **1,804.21 ms**,
maximum **1,983.06 ms**, 91 requests / 30 iterations, 90/90 checks and zero HTTP
failures. Relative to the comparable reference, the two post-change p95 values
are 40.0% and 37.5% lower. The fixture again reports zero agent calls, queued
work or created translations.

### Separate FPM profiling

A private front controller outside the public web root uses the same checkout
and isolated site through a separate two-worker PHP-FPM pool. OPcache and the
effective fixture operator are checked. Request-local instrumentation preserves
the fixture's original post-filter and records session start, workflow methods,
render phases and SQL. It never stores cookies, submitted forms or SQL values in
the sanitized results. Three sequential samples per action are retained.

This diagnostic pool is separate from the unchanged four-worker CLI server used
for the k6 comparison; its timings are not substituted for that load result.
SQL time overlaps the instrumented workflow/render phases and must not be added
to them. Exclusive event totals avoid double-counting nested calls.

| Action | Handle before → after | Workflow before → after | Render before → after | SQL before → after |
| --- | ---: | ---: | ---: | ---: |
| GET | 910 → 631 ms | 0 → 0 ms | 762 → 488 ms | 84 → 82 ms |
| Preview | 1,507 → 968 ms | 535 → 259 ms | 795 → 561 ms | 216 → 216 ms |
| Estimate | 1,475 → 941 ms | 548 → 264 ms | 774 → 523 ms | 215 → 220 ms |

These are medians, not cold guarantees. Query counts increase by 21 per action
because persistent catalog reuse needs cache lookups (581→602,629→650,630→651).
The improvement is CPU/iteration/validation work, not a demonstrated SQL latency
reduction. Warm bootstrap medians are approximately 3–4 ms. Session-start medians
are 0.3–0.6 ms; PO-file reads take roughly 6–10 ms for the selected languages.

A separate five-request, same-cookie diagnostic through the two-worker pool
records session-start times 0.30–0.56 ms and termination 1.3–1.4 ms. End-to-end waits
rise from 723 ms to 2,155 ms while handler times stay 666–725 ms, consistent with
waiting for those workers. This supports worker queuing rather than an exclusive
session lock as the observed wait source; it is not a benchmark of a different
production session backend. Direct inspection confirms the tested session
handler uses database reads, not native file-session locking.

A separate 100-sample calculation diagnostic uses the real estimator with the
same prepared snapshots and resolved entries, replacing only the inventory/PO
lookup stage. Selection, pricing, rows, totals and fingerprint match a complete
calculation. The median is **0.219 ms**, p95 **0.396 ms**, maximum **0.568 ms**.
This isolates pricing lookup, fingerprinting, batching, prompt sizing and cost
math; it excludes SQL, Locale and PO reads. It confirms that the arithmetic itself
is not the observed request bottleneck for this controlled workload.

Two initial private profiling attempts were setup failures (stale FPM socket and
an included guard overwriting a temporary path variable); no missing diagnostic
file is counted as a measurement. The corrected pool validates OPcache per sample.
Its startup-only OPcache warnings are retained privately; they are not application
HTTP failures. SQL logging uses the retained logger object because Drupal's
`Database::getLog()` ends that log key.

## Regression coverage

- The new catalog cache test fails before implementation at the cross-instance
  reuse assertion. It now passes reuse, same-size/same-mtime file replacement,
  content-version changes, source changes, contexts, native override updates,
  malformed/deleted files and invalid placeholder handling.
- Existing portable-translation core assertions: 22 passed. Its loader now uses
  this checkout's vendor directory rather than the historical integration
  worktree. Assertions were preserved.
- Translation estimate standalone assertions: 15 passed. PHP syntax checks and
  `git diff --check` pass.
- Full current-checkout E2E `translation-latency-full-20261120`: 543 selected,
  517 passed, 26 intentional skips, zero unexpected/flaky cases or global errors,
  27.1 minutes. JSON and embedded HTML report statistics agree. Counts are auth
  1 passed; desktop 434 passed / 24 skipped; mobile 82 passed / 2 skipped.
  Skips are four cases requiring the optional read-only V2 fixture and 22 external,
  placeholder or administrative-action menu inventory entries. Existing checks
  cover estimate invalidation, protected translations, permissions, resumed jobs,
  responsive flows and operation without JavaScript.
- The initial 72 Lighthouse measurements are complete: 48 public navigations,
  12 authenticated form navigations and 12 estimate accessibility snapshots.
  Every run retains JSON and HTML reports. All are Lighthouse 12.8.2; no runtime,
  console or HTTP resource failure is reported. Public accessibility is unchanged:
  100 except school/desktop 97 (`link-in-text-block`, the inline Help link).
  Form and estimate accessibility is 100 in every run. Six canonical school
  redirect warnings occur in each public cohort. This is a pre-existing UI
  finding, retained for the queued visual/search work rather than attributed to
  this cache change.
- One dashboard LCP comparison initially required further investigation:
  mobile medians 1,511 → 2,040 ms; desktop 1,123 → 1,261 ms. Main-document
  TTFB remains approximately 6–9 ms. Mobile LCP candidates alternate between
  the asynchronously refreshed quote and consent heading; desktop candidates
  are map tiles. The browser assets are unchanged. A second dashboard cohort
  reverses the measurement order; all original runs are retained.
- The initial audit orchestrator ended with signal exit 143 after 69 measurements.
  A private copy of the current runner resumed exactly the three missing slots
  (two accessibility snapshots and one mobile form navigation). The nine existing
  administrative results were preserved, and the completed cohort has 12 unique
  slots. No application source changed during that resumption.
- The additional 12 dashboard navigations are complete and reviewed. With order
  reversed (optimized first, reference second), mobile LCP medians are
  2,048.69 → 2,055.79 ms; desktop 1,129.33 → 1,195.86 ms. Accessibility stays 100
  and no runtime/console/HTTP status failures occur. This supports substantial
  variability in the initial mobile comparison, not a demonstrated improvement
  of frontend rendering. The optimized mobile repetition includes scores
  99/74/96 and TBT 9.34/1,282.61/187.82 ms; reference scores are 99/96/97 with
  TBT 113.50/188.92/155.00 ms. The outlier is retained: long tasks include
  unattributed work, page scripting and existing chart code. These diagnostics
  do not prove that the backend cache caused or resolved that browser CPU spike.
- Maintained 13-minute shared-flow k6 `translation-latency-shared-20261120`:
  46,186 HTTP requests, 38,470 iterations, all 115,410 functional/isolation checks
  passing, zero HTTP failures and peak 100 VUs. p95 is **13.92 ms**, median 6.60 ms,
  maximum **5,587.03 ms**. All unchanged thresholds pass. The scenario includes
  cold setup following a cache rebuild; warm anonymous page responses dominate
  its p95. The retained maximum does not certify cold requests below two seconds.
  This shared-flow run is distinct from the authenticated estimate benchmark.

Private evidence is under `tests/performance/.local` (`translation-profile-*`,
`translation-concurrent-session.json`, `k6-translation-latency-*`, component logs)
and `tests/upgrade/.local/state/runs/translation-latency-full-20261120`.
Private diagnostic/review helper sources are retained in the ignored
`tests/performance/.local/translation-latency-tools` directory. Raw reports,
sessions, traces and fixture content must not be committed.

## Publication scope

The active checkout also contains preserved work outside these six owned files,
including the original Labdoo theme and decorative activity-image markup. Those
changes are excluded from this publication. Email-template byte differences from
the published base are CRLF normalization, not a translation-content change.
The E2E suite validates the identified active checkout, not a clean reproduction
of every file on v3; the owned implementation is copied by matching hashes to the
focused publication worktree. Remote runtime smoke checks remain a separate gate.
The target verification uses the maintained code-only wrapper, its actual
`www-data` PHP-FPM checks, translation service injection checks, preserved
anonymous denial on the translation administration route, and exact source/test/
evidence hashes and Git refs. No production estimate load or agent execution is
part of those checks. This report records local evidence; the task records the
subsequent deployment result.

## Lighthouse medians

All original runs and the counterbalanced dashboard cohort are retained in the
sanitized JSON aggregate. The table uses the original three-run cohort for each
route/profile; repeat-dashboard results and its outlier are discussed above.

| Route/profile | Performance before → after | LCP before → after (ms) | TBT before → after (ms) | Accessibility before → after |
| --- | ---: | ---: | ---: | ---: |
| dashboard/desktop | 98 → 97 | 1123 → 1261 | 0.0 → 0.0 | 100 → 100 |
| dashboard/mobile | 100 → 99 | 1511 → 2040 | 26.1 → 22.4 | 100 → 100 |
| home/desktop | 98 → 98 | 1147 → 1097 | 0.0 → 0.0 | 100 → 100 |
| home/mobile | 99 → 99 | 2110 → 2137 | 7.9 → 20.4 | 100 → 100 |
| school/desktop | 100 → 100 | 572 → 570 | 0.0 → 0.0 | 97 → 97 |
| school/mobile | 97 → 97 | 2426 → 2478 | 0.0 → 2.0 | 100 → 100 |
| wiki/desktop | 100 → 100 | 388 → 390 | 0.0 → 0.0 | 100 → 100 |
| wiki/mobile | 100 → 100 | 1654 → 1656 | 0.0 → 0.0 | 100 → 100 |
| form/desktop | 99 → 100 | 657 → 666 | 0.0 → 0.0 | 100 → 100 |
| form/mobile | 93 → 93 | 2782 → 2782 | 2.0 → 0.0 | 100 → 100 |
