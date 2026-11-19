# Translation cost review — 2026-11-19

## Scope

Adds a mandatory estimate/review step to the administrative translation form
implemented by `dcc87c84`. Work was developed in `/var/www/html/labdoo3`, whose
initial local HEAD was `8db0c962` with the deployed interface and unrelated
theme work present as working-tree changes. Concurrent theme integration later
advanced the active checkout to `d714a9dd`; final validation uses that checkout
with the restored cost files. The release itself is based on the revision
actually deployed in v3, `dcc87c84`, and excludes the undeployed theme commit. Only the cost feature and its necessary
tests/documentation belong to this follow-up.

The calculator uses the shared PO preparation/catalog workflow and exactly the
same request expansion and application prompt as execution. Nonempty/reviewed
translations are excluded from the chargeable workload. Requests follow the
ten-entry queue batches and count only missing plural forms. Calculation does
not launch a CLI, write PO files, create jobs or contact pricing endpoints.

Input token bounds use prompt UTF-8 bytes divided by four and two, rounded up
per request. Output bounds use the hypothetical source JSON response divided by
four and its full byte length (allowing up to double the source response size at
two bytes per token). These are documented heuristics, not tokenizer counts or
guaranteed bounds. Cost is `(input_tokens * input_rate + output_tokens *
output_rate) / 1,000,000`, in the operator-configured currency, with no exchange
conversion. Display bounds round outward to six decimal places.

Each approved model has optional operator-maintained pricing. Missing/invalid
tariffs show unavailable monetary cost. Subscription profiles show usage/plan
limits without claiming free or unlimited access. Example rates in documentation
and fixtures are fictitious; no current provider prices are asserted.

Starting requires an explicit acknowledgement and a server-side fingerprint
match against the current selection, agent options, pricing and PO inventory.
Changing content without changing its byte length also invalidates review.
JavaScript hides stale summaries and disables Start when controls change; the
server gate also works without JavaScript. Queued inventory, provider reasoning,
CLI context, retries and taxes can change actual usage: this is not an execution
budget or quota mechanism.

## Environment and coverage

All behavioral checks use the guarded isolated clone at
`http://127.0.0.1:18411`, fingerprint
`762b685f4cc75d15703e6a66c299cdc4c18f0db61bf30a9fe81afaaafca6eb0e`.
Installed/locked Drupal core 11.4.13, Drush 13.8.1 and Symfony Process 7.4.22
match. Local PHP is 8.3.36. PHPCS, PHPStan and PHPUnit remain unavailable.

The database-free estimate suite covers existing translations, partial plurals,
batch overhead, currency/rate dimensions, stable fingerprints, same-size content
changes, changed languages/rates, unpriced default models, subscriptions, invalid
rates and empty workloads. The existing fake-CLI contract and Drush integration
cover the extracted shared prompt path and preservation/error semantics.

Browser coverage adds English/Spanish desktop/mobile cost review, no calls or
queued jobs during estimation, explicit acknowledgement, unknown prices, viewport
containment, forged selection changes and inventory/rate invalidation. Existing
translation generation, replay deduplication, import preservation, retry,
ownership, live permission revocation and no-JavaScript scenarios now pass through
the estimate step.

Earlier attempts are retained privately. New message selectors initially assumed
an alert role that the active theme does not render; they now assert the actual
visible error text. A Spanish unavailable-price assertion was aligned with its
existing translation. A later run was interrupted by the local HTTP server
stopping; the server was restarted through the guarded lab command before the
final run. No product assertion or threshold was weakened.

## Reviewed functional results

`translation-cost-regression-20261119` passed **12 cases**, including fresh
role authentication, with zero failures, flaky cases or skips. Both mobile
translation workflows and the cost review passed. The run took approximately
5.7 minutes. JSON and browser reports were inspected.

The database-free suites passed **15 estimate assertions**, **28 agent contract
assertions** and **6 registry assertions**. The load/audit guard suites passed
**8 cases**. PHP syntax on the affected PHP files, standalone JavaScript syntax
and `git diff --check` passed. Missing PHPCS/PHPStan/PHPUnit are not counted as
successful checks.

The concurrent integration temporarily checked out older feature files and
created three conflicts when restoring local work. Only cost-owned conflicts
were resolved from the isolated release copy; unrelated source/staging and the
theme commit were preserved. The first load run is invalid as performance
evidence because source files changed/disappeared during execution.

After restoration, `translation-cost-stable-20261119` passed eight cases. Its
remaining four cases encountered only `ERR_CONNECTION_REFUSED` when the other
local server stopped. An attempted replacement server had failed to bind while
the old server still held the port; the final server was started after the port
was free. The four affected cases then passed in
`translation-cost-nojs-final-20261119` (one desktop case) and
`translation-cost-mobile-final-20261119` (three mobile cases), using the valid
fresh authentication from the stable run with `--no-deps`. There are no remaining
unverified functional cases: all 12 unique cases have passing final evidence.
The interrupted reports remain failures, not successful whole-suite runs.
Cost source hashes are checked before/after the final performance runs.

## Final load and audit results

The final scoped estimate POST run used five virtual users for 30 seconds:
46 requests, 45/45 functional/isolation checks, zero HTTP errors, p95
**4019.14 ms** (limit **2000 ms**), maximum 4252.57 ms. This is a **failed
performance check**. The user explicitly authorized deployment with optimization
recorded as a new Todoist task; thresholds were not changed. The final runner
scopes CSRF/form fields to the translation form, excluding other page forms.
Earlier runs using the wrong form state remain invalid functional measurements.

The stable Lighthouse run completed 12 reports: six navigation audits and six
rendered-estimate accessibility snapshots. Accessibility was 100 in every report,
with no snapshot failed audits or warnings. Navigation performance scores were
99/99/99 desktop and 92/88/93 mobile; median LCP 701 ms and 2799 ms. Snapshots
measure accessibility, not load performance. Shared resource opportunities
remain in navigation reports. No site-wide or production performance claim is
made. Artifacts: `k6-translation-cost-final-20261119.json`,
`lighthouse-translation-cost-stable-20261119/summary.json` and associated private
logs/reports in `tests/performance/.local/`.

No real provider was invoked. The dedicated test fixture was cleaned after
measurement. Production still requires installed/authenticated agents and
approved profiles/pricing; unknown rates are not represented as zero cost.

## Reproduction and artifacts

```sh
php -d xdebug.mode=off tests/php/translation-estimate.php
php -d xdebug.mode=off tests/php/local-translation-agent.php
make e2e LAB_RUN_ID=translation-cost-regression-20261119 E2E_ARGS='translation-ui.spec.ts translation-agent.spec.ts --project=desktop --project=mobile'
node --test tests/performance/load-profile.test.mjs tests/performance/translation-ui-audit.test.mjs
```

Reports stay private in `tests/upgrade/.local/state/runs/<run>/` and
`tests/performance/.local/`. Use `translation-ui-audit.mjs AUTH_STATE JOB_UUID
OUTPUT --cost-review` with the isolated fake-agent fixture: it audits the form
navigation and then the rendered estimate accessibility snapshot, three times
per desktop/mobile profile. Snapshot scores do not measure navigation performance.
Cookies are scoped to the loopback domain. The estimate POST load scenario
`translation-cost-load.js` obtains fresh form/CSRF state per iteration and performs
only pending-preview and cost-estimate actions. It retains the public reference
scenario unchanged and the original latency/error/check thresholds. No load or
audit targets production.

See [operator pricing instructions](../../translations/custom/README.md#estimate-before-starting).
