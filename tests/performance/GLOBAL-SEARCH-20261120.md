# Global search — 2026-11-20

Task: `6j3hfwVRxqffWVPV`. Local release validation is complete. Deployment status, revision and target-runtime evidence are tracked in the
[task](https://app.todoist.com/app/task/6j3hfwVRxqffWVPV).

## Observed failures and changes

The initial three desktop regression cases reproduced three user-visible defects:
real suggestions were visible in the DOM but could not receive pointer events
because the overlay covered them; the input background tiled the loading image;
and an HTTP 500 left the widget pending and its loading class active.

The dedicated `labdoo/global_search` library explicitly depends on Drupal and
Search API Autocomplete initialization. Its per-field attachment is independent
of the containing block's once marker. Requests are cancellable and settle the
widget callback exactly once on success, failure or cancellation. Successful
responses retain Drupal's term/cache semantics and the existing five-suggestion
limit; errors are not cached. Contributed URL selection and keyboard callbacks
remain in place. Closing or unloading a dialog aborts its outstanding request.

The Next theme now renders a compact native dialog with a translated title,
labelled close control, focus containment and focus restoration. Suggestions are
inside the dialog's top layer and remain clickable. Escape does not propagate
into the surrounding mobile navigation, which previously hid the trigger before
focus could return. A real results-page link and a labelled GET form remain
available without JavaScript. Search API and route access are unchanged.

The shared input rule now changes only `background-color`, retaining core's
single, nonrepeating loading background. The existing inline Help links are
underlined to address the school desktop accessibility finding from the preceding
visual task. No backend search configuration, schema, dependencies, indexing or
production data changes are included.

## Environment and evidence

Validation uses the active development checkout at `d714a9dd` with preserved
existing work, guarded loopback site `18411` and its isolated database. The
publication starts at deployed `f4c6a835`. Installed packages match all 205 locked
packages, including Drupal 11.4.13. Local PHP is 8.3.36, Node 25.2.1, Playwright
1.65.0, Lighthouse 12.8.2, Chrome 157.0.8103.38 and k6 2.3.0. The target PHP patch
is different (8.3.6); local results do not certify an identical runtime. PHPCS,
PHPStan and PHPUnit are unavailable and are not reported as passing.

Raw browser traces, screenshots, sessions and audit reports remain private under
`tests/upgrade/.local` and `tests/performance/.local`. Controlled external services,
HTTP guards and captured email remain enabled. There is no production load,
paid provider, real email or live queue processing.

### Focused browser checks

`global-search-focused-v4-20261120`: 22 passed, no failures, flakes or skips;
JSON and embedded HTML reports reviewed. Desktop and mobile cover real pointer
and keyboard suggestion navigation, a single loading indicator, recovery after
HTTP 500, cancellation of superseded/in-flight requests, repeat Drupal behavior
attachment without duplicate requests, compact geometry, keyboard focus,
English/Spanish no-result submission, authenticated access and real results with
JavaScript disabled.

`global-search-debounce-red-20261120`: two additional cases passed, desktop and
mobile. Despite the run name, this was not a reproduced failure: closing before
the debounce elapsed already prevented an HTTP request. No extra fix was made.

Earlier attempts are retained. The initial green run had an incorrect heading
scope in the test; the real page heading sits outside `main`. Subsequent checks
exposed genuine layout/focus issues that were fixed. The JavaScript-disabled
results assertion was corrected to the configured table rows, including a
visible Dootrip result and destination link. A run before cache rebuilding still
loaded stale aggregated JavaScript; its failures are not counted as passing.

### Full regression and publication scope

`global-search-full-20261120` completed all 567 selected cases in 29.7 minutes:
541 passed, no failures or flakes, and no global errors. JSON and embedded HTML
reports agree. Authentication passed once; desktop has 446 passes and 24 skips,
mobile 94 passes and two skips. All 26 skips are expected: four optional local
V2-fixture cases and 22 external/action navigation entries. The frozen source
hashes remained unchanged during this run.

The two subsequently requested visual adjustments (pickup-status typography and
nested tab-panel borders) have focused desktop/mobile regression validation below.
The full run above precedes those narrow CSS changes and does not certify a full
rerun of their final bytes. Public-route Lighthouse comparisons, open-dialog
snapshots and load results are documented below; deployment verification is tracked
in the task linked above. Source/test hashes are retained privately to detect edits
during measurements. The existing unrelated legacy map-library changes in the
active checkout are excluded from the publication; only the new search-library
hunk is applied to the destination YAML. This checkout/publication distinction
limits any claim about unrelated unpublished work.


### Final focused follow-up and Lighthouse

After the requested typography/tab-panel CSS follow-ups,
`panel-and-search-final-20261120` passed all **62 cases**, without failures,
flakes, skips or global errors. JSON/embedded HTML and the action-panel,
desktop/mobile tab screenshots were reviewed. Search code is unchanged from the
full run. Only the scoped visual CSS differs; no full-suite rerun is claimed for
those final CSS bytes.

The 24 final public navigation audits (four routes, desktop/mobile, three runs)
have accessibility, best-practices and SEO **100** in every run, with performance
**97–100**. There are no runtime, console, HTTP-status or resource failures.
All six school-route canonical-redirect warnings are retained. Remaining
performance findings include cache lifetimes, render-blocking resources,
unused CSS/JavaScript, image sizing/delivery and the canonical redirects; these
scores are not a claim that every Lighthouse audit passes.

School desktop accessibility improves from 97 to 100 in all three runs after the
inline Help underline; school mobile remains 100. The previous translation
release's final 24 public audits provide an earlier reference with the same tools
and profiles. They are not a randomized before/after experiment. Median LCP
values below retain all three runs and do not establish a search speedup:

| Route | Profile | Earlier reference LCP | Final LCP |
| --- | --- | ---: | ---: |
| Home | Mobile | 2,136.8 ms | 2,137.1 ms |
| Home | Desktop | 1,096.6 ms | 937.0 ms |
| Dashboard | Mobile | 2,040.4 ms | 2,053.3 ms |
| Dashboard | Desktop | 1,260.9 ms | 949.7 ms |
| School | Mobile | 2,477.8 ms | 2,479.5 ms |
| School | Desktop | 570.4 ms | 570.7 ms |
| Wiki | Mobile | 1,656.3 ms | 1,652.1 ms |
| Wiki | Desktop | 389.5 ms | 390.3 ms |

The initial dialog-audit attempt retained one successful empty-desktop snapshot
(100) before timing out while preparing suggestions after the snapshot. The final
runner starts a fresh browser for each state, avoiding interactions after
Lighthouse's DevTools lifecycle. All **12 final accessibility snapshots** (empty
and real suggestions, desktop/mobile, three runs) score **100**, with no failed
audits, warnings or runtime errors. JSON/HTML reports and desktop/mobile popup
screenshots were reviewed. These snapshots assess the open dialog, not loading
performance. Later indentation-only cleanup preserved every executable line.

### Autocomplete load and local-domain check

The guarded endpoint scenario discovers the actual autocomplete path in the
rendered form and requests real Dootrip/Dootronics suggestions. Five users for
30 seconds produced **136 requests / 135 iterations**, **540/540 checks**,
zero HTTP failures, p95 **177.03 ms** and maximum **303.55 ms**. The unchanged
budgets pass (p95 < 2,000 ms, HTTP failures < 2%, all checks). This establishes
backend stability for this workload; it is not a before/after latency claim.

The user also reported that the magnifier on `labdoo3.local` navigated to the
fallback results route. Read-only inspection showed stale theme/library metadata:
the previous parent template was rendered and neither search behavior was loaded.
After confirming the default local root/database, a cache-only rebuild installed
the new template/library. A guarded, read-only browser smoke confirmed popup
opening without navigation, input focus, close/focus restoration and zero script
errors. Local TLS was not certified: this specific loopback diagnostic bypassed
an expired development certificate. The isolated regression site and production
settings were not changed by that rebuild.


### Sustained shared load and deployment contract

The unchanged 13-minute shared-flow ramp, `global-search-shared-20261120`, passes
all original budgets: **46,411 requests**, **38,633 iterations**, peak **100 VUs**,
**115,899/115,899 checks**, zero HTTP failures and p95 **7.86 ms**. Median is
4.19 ms; maximum **2,459.79 ms** is retained. Passing p95 does not promise every
request below two seconds. No performance budget, retry or error assertion was
relaxed. This is isolated local load, not production-capacity certification.

The focused code commits are `cf88cef1` (search) and `d8d7ae02` (visual follow-ups).
[Sanitized aggregate evidence](global-search-20261120.json) retains every final
navigation/snapshot run, reference medians, counts, thresholds and practical
limits. Complete raw artifacts remain private. Application/test hashes were
checked after measurement, and unrelated root staging/work is preserved.

Publication uses a separate checkout based on the deployed reference. The
maintained code-only wrapper must preserve private settings/backups, rebuild
caches and verify actual `www-data`/PHP-FPM responses. Additional English/Spanish
read-only smoke checks assert the labelled dialog, real fallback form, actual
same-origin suggestions and search-results table. The deployment task records
the actual target outcome; local tests do not replace it. External public HTTP
access may be protected by Basic Auth and is not substituted for a browser
regression of the production deployment.
