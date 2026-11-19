# Administrative translation UI validation — 2026-11-19

## Scope and environment

This change implements Todoist task `6j3Wq7grcR3qxvfV` in the `development`
checkout at `/var/www/html/labdoo3`, based on `8db0c962` plus local changes.
Other theme work and earlier BUGS fixes were already present in this shared
working tree; they are not part of this implementation. Nothing was deployed,
committed, pushed or imported into the default/live site.

All integration operations use the guarded local clone at
`http://127.0.0.1:18411`. Its response fingerprint matches this checkout:
`762b685f4cc75d15703e6a66c299cdc4c18f0db61bf30a9fe81afaaafca6eb0e`.
Installed Drupal core and `composer.lock` both specify 11.4.13; PHP is 8.3.36,
Drush 13.8.1 and Symfony Process 7.4.22. Playwright is 1.65.0 with Node 25.2.1.
PHPCS, PHPStan and PHPUnit executables are unavailable in this checkout.

## Implementation and behavior

The restricted form is `/admin/config/regional/labdoo-translations`, under
Regional and language configuration. `TranslationWorkflow` now shares inventory,
catalog lookup, PO merging and translation between Drush and the web queue.
`TranslationAgentRegistry` resolves only approved server profiles and models.
`TranslationJobs` stores owner-scoped metadata, checksummed private files and
checkpoints; `TranslationJobWorker` consumes the dedicated queue. Forms,
controller endpoints and the private-file hook enforce authorization and
explicit CSRF-protected actions. Status polling is read-only; manual refresh
and form submissions also work without JavaScript.

The initial implementation executes agents on the worker server. Private
settings must define executable profiles; private storage must be outside the
document root. The worker account needs its own CLI installation/authentication.
See [operator instructions](../../translations/custom/README.md#administrative-translation-jobs).
No real provider was invoked: tests use executable fake agents and owned Locale
fixtures. The clone's runtime portable catalog module is disabled; the Drush
integration injects a controlled catalog to verify reviewed-value preservation.

Generation preserves existing PO values and validates placeholders, Twig, HTML,
URLs, context and plurals. Handled errors retain completed batches and require
an explicit retry. A process crash can repeat the unfinished batch under normal
queue delivery semantics. Import fills missing values, preserves existing
native/manual values and refreshes Locale caches. Imports commit in batches;
there is no whole-job database rollback or automatic file retention purge.

## Functional evidence

The added `translation-ui.spec.ts` exercises English/Spanish, desktop/mobile,
pending counts without provider calls, profile/model selection, form replay
deduplication, background processing and progress, escaped review, private PO
downloads, generic-file denial, invalid confirmation, confirmed import preserving
a later manual translation, idempotent/missing queue items, failed batch retry,
checksum rejection, cross-operator/anonymous access, externally revoked role
membership during a running queue process and
operation without JavaScript. The existing `translation-agent.spec.ts` tests
the shared Drush path, reviewed catalog preservation, repeated execution and
failure leaving the existing PO unchanged.

Earlier attempts are retained privately. The first found a real cached-form
dependency serialization issue, fixed by using protected injected properties.
Subsequent attempts corrected AJAX input retention, a JSON fixture shape and a
localized confirmation selector. The combined regression revealed overlapping
fixture executions and a CLI test inventory affected by concurrently rendered
strings. The CLI test now scopes inventory to its owned context while retaining
the actual command, workflow, catalog and fake executable. No assertion, retry
budget or performance threshold was relaxed.

The final combined run `translation-ui-final-20261119` passed all **9 cases**
(including authentication), with zero unexpected failures, flaky cases or skips.
Its JSON report records 397 seconds. The final focused run
`translation-ui-artifact-final-20261119` passed its additional case (55.6 seconds):
a modified PO is rejected on both download and import confirmation. This run
reused the valid authentication state from the combined run with `--no-deps`.
An earlier attempt at this extra assertion appended the action path after a
query string; the final test requests the explicit route instead.

An additional warm-worker regression reproduced stale owner caching: after a
separate process removed the operator role between queue items, the original
worker still made two fake-agent calls. `TranslationJobs::process()` now uses
`loadUnchanged()` before each item. `translation-ui-owner-final-20261119` then
passed: zero agent calls after revocation, failed job state and denied status
access. This run took 26 seconds and reused the valid operator authentication.
`translation-ui-final-flow-20261119` also passed the complete desktop
preview/generation/review/import path after this cache fix. Both focused reports
contain one expected case, zero failures, zero flaky cases and zero skips.
Role permission/server profile configuration changes still require restarting
long-running CLI workers because Drupal settings can be process-cached.

The fake CLI contract suite passed **28 assertions** and server profile/model
validation passed **6 assertions**. PHP syntax passed on 14 affected files, YAML
parsing on all 7 module YAML files, and standalone JavaScript syntax checks
passed. Load/audit runner guard tests passed **7 cases**; the existing Lighthouse
runner tests passed **3 cases**. `git diff --check` passed. PHPCS, PHPStan and
PHPUnit remain unavailable, rather than being counted as successful checks.

## Load and browser audits

The authenticated status scenario `translation-status-load.js` ran for 30
seconds with five virtual users: **130 requests**, **387/387 checks**, zero
failed requests, p95 **311.18 ms** and maximum **449.94 ms**. All original
thresholds were retained and passed: p95 below 2000 ms, errors below 2%, and
100% functional/isolation checks. The fake job was already in review, so this
load did not invoke a provider or import translations.

Lighthouse **12.8.2** completed **12 authenticated audits**, three fresh Chrome
runs for each route/profile, sequentially after the load test. All audits scored
**100 accessibility** and **100 best practices**, with no runtime errors or
warnings. The desktop preset is the installed Lighthouse desktop configuration;
mobile uses its default configuration. Both use a fresh browser cache and a
loopback-domain session, preserving authentication across the navigation.

| Route/profile | Performance scores, all runs | Median score | Median LCP | Median CLS |
| --- | --- | --- | --- | --- |
| Selection form, desktop | 98, 97, 97 | 97 | 673 ms | 0.00110 |
| Selection form, mobile | 88, 84, 87 | 87 | 3310 ms | 0.05500 |
| PO review, desktop | 95, 98, 98 | 98 | 672 ms | 0.00110 |
| PO review, mobile | 92, 85, 87 | 87 | 2778 ms | 0.05443 |

SEO was 92 on desktop and 100 on mobile. Complete failed-audit lists are
retained in `summary.json` and individual JSON/HTML reports. Outstanding
opportunities include document latency/compression, blocking aggregate CSS,
unused aggregate JavaScript/CSS, image delivery/cache TTL, and desktop
non-crawlable navigation anchors. The reports identify shared page resources
and the local HTML response; they do not isolate a before/after cause. These
measurements do not certify that all performance audits pass. The new routes
have no pre-implementation equivalent, and the shared working tree contains
concurrent theme changes, so no performance improvement or site-wide regression
claim is made. Desktop form and mobile review screenshots were inspected.

The performance fixture and its private PO files/role were cleaned after the
runs. No production data, live queue or real translation provider was used.

## Reproduction and private artifacts

```sh
make e2e LAB_RUN_ID=translation-ui-final-20261119 E2E_ARGS='translation-ui.spec.ts translation-agent.spec.ts --project=desktop --project=mobile'
make e2e LAB_RUN_ID=translation-ui-artifact-final-20261119 E2E_ARGS='translation-ui.spec.ts --project=desktop --grep "failed batches" --no-deps'
php -d xdebug.mode=off tests/php/local-translation-agent.php
php -d xdebug.mode=off tests/php/translation-agent-registry.php
```

Playwright JSON, HTML and traces remain under
`tests/upgrade/.local/state/runs/translation-ui-final-20261119/`.
Load summaries and complete audit reports remain under
`tests/performance/.local/`; they must not be committed because they may contain
session-specific or third-party data.

For authenticated performance measurements, prepare the guarded translation UI
fixture, seed one job, drain only its dedicated queue with the fake agent, and
retain the operator's private Playwright authentication state. Run
`translation-status-load.js` with the local status path, checkout root and
session cookie supplied through the environment (never command-line literals).
Then run `translation-ui-audit.mjs AUTH_STATE JOB_UUID OUTPUT`; it audits form
and escaped review routes three times per desktop/mobile profile in fresh
Chrome instances, with only a domain-scoped loopback session. It never attaches
authentication headers to third-party resources. Clean the fixture afterwards.
The existing public k6 scenario remains unchanged for reference. This scoped
change measures the newly added uncached status path; it does not rerun the
unaffected public-route load scenario or claim a site-wide load comparison.

These new routes have no pre-implementation counterpart. Their measurements
establish current local behavior, not a before/after performance improvement or
production/provider compatibility certification.
