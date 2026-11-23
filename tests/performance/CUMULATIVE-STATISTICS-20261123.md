# Cumulative advanced statistics validation

The nine temporal charts on `/statistics/advanced` now show historical totals
through each displayed month/year end, including records older than the visible
window. The existing activity-based monthly window and five-year distance window
are preserved. Titles identify cumulative data using a translated suffix in all
18 module catalogs. The Italian catalog also needed two invalid apostrophe escapes
removed to pass catalog validation.

Environment: `development`, HEAD `d714a9dd2fe4d5330f6567dbe405d658085c4a1c`
with existing unrelated dirty changes and this change. PHP CLI 8.3.36,
Node 25.2.1, installed and locked Drupal 11.4.13. All installed application
package versions match `composer.lock`. The active checkout's dedicated
`sites/labdoo_e2e` database was used with the existing guards and controlled
external services. No default-site mutations or deployment were performed.

## Functional and quality evidence

- `make e2e LAB_RUN_ID=cumulative-charts-20261123 E2E_ARGS='cumulative-statistics.spec.ts platform-uptime.spec.ts'`:
  11 expected, zero unexpected, skipped or flaky; no global errors. JSON and
  embedded HTML report statistics agree.
- New `tests/e2e/cumulative-statistics.spec.ts` verifies rendered Chart.js data,
  monotonic totals and localized titles in English/Spanish on desktop/mobile.
- New `tests/e2e/support/cumulative-statistics.php` executes the actual controller
  queries against connection-local temporary fixtures: historical starting totals,
  midnight/month-end boundaries, inactive months, empty histories and fractional
  cumulative annual kilometers. All nine cases pass. Before implementation the
  dootronic case failed with monthly counts instead of the expected historical
  totals, reproducing the previous behavior.
- Existing `platform-uptime.spec.ts` provides affected-page regression coverage.
- `php -l` passes for the controller and fixture; targeted Drupal/DrupalPractice
  PHPCS and level-5 PHPStan pass. All 18 PO catalogs pass `msgfmt --check`.
- `git diff --check` passes. Existing changes in the controller were retained;
  the index was not staged or committed.

Private Playwright artifacts:
`tests/upgrade/.local/state/runs/cumulative-charts-20261123/{results.json,report/index.html}`.

## Load evidence

Command:

```sh
k6 run --summary-export tests/performance/.local/k6-cumulative-20261123.json \
  -e LABDOO_EXPECTED_ROOT=/var/www/html/labdoo3 \
  tests/performance/platform-uptime-load.js
```

Five users, 30 seconds, alternating English/Spanish statistics pages: 151 requests,
600/600 functional/isolation checks, zero failed requests, p95 21.65 ms
(budget 2000 ms). All thresholds pass. This reuses the existing route profile
with ordinary page caching; it is not a cold-query or maximum-capacity comparison.

## Lighthouse evidence and limits

Audited `/en/statistics/advanced` with Lighthouse 12.8.2, same checkout, route,
profiles and local conditions. Initial commands were `make lighthouse
LAB_RUN_ID=cumulative-before-20261123` and `LAB_RUN_ID=cumulative-after-20261123`,
each with `LIGHTHOUSE_ARGS='--path /en/statistics/advanced --runs 1'`.
Mobile variability was investigated with two further runs per implementation:
`cumulative-mobile-repeat-20261123` and `cumulative-reference-repeat-20261123`,
using `--profiles mobile --runs 2`. The reference repeated the prior monthly
query bounds and titles temporarily, preserving the other working changes; the
final cumulative source files were restored byte-for-byte and clone caches rebuilt.

| Profile/metric | Previous behavior | Cumulative behavior |
| --- | --- | --- |
| Desktop performance (one run each) | 100 | 100 |
| Desktop LCP / TBT (ms) | 651 / 17 | 651 / 39 |
| Mobile performance, all runs | 84, 81, 77 | 76, 82, 74 |
| Mobile performance median | 81 | 76 |
| Mobile LCP, all runs (ms) | 1512, 2053, 2048 | 2020, 2060, 2090 |
| Mobile TBT, all runs (ms) | 630, 730, 1007 | 1122, 658, 1308 |
| Mobile median LCP / TBT (ms) | 2048 / 730 | 2060 / 1122 |

All runs have accessibility 95, best practices 100, SEO 100 and zero CLS.
JSON and corresponding HTML reports exist; runtime errors and warnings are
absent. The existing contrast/heading-order, caching, render-blocking and unused
asset findings remain. Mobile main-thread blocking varies substantially in both
implementations; the trace identifies existing Chart.js and the unchanged
aggregated frontend script among the main costs. LCP can be the header quote or
introductory paragraph. The cumulative median performance is lower in these
samples; this is not a demonstrated mobile performance pass or proof of a causal
regression. No animation, asset or performance-budget changes were made to mask it.

Private summaries and JSON/HTML reports are under
`tests/performance/.local/lighthouse-cumulative-{before,after,mobile-repeat,reference-repeat}-20261123/`.
Only the focused page and affected-page regression cases were selected, rather
than the unrelated full E2E suite. No production, migration or deployment behavior
is certified by these local results.
