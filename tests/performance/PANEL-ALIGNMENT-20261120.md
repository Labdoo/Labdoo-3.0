# Main/sidebar panel alignment — 2026-11-20

Follow-up to visual task `6j3fgrpMVHxgJpHV`. Local validation is complete;
deployment status and target-runtime evidence are tracked in the
[task](https://app.todoist.com/app/task/6j3fgrpMVHxgJpHV). The search,
pickup typography and single-border tab panels were already deployed at
`e73a6554c800d56dfb45371d27c068798ab996e1` before this additional user request.

## Reproduction and change

The first visible sidebar and main panels did not share their top edge. The
desktop device case reproduced a 13-pixel difference; School, Hub and the four
dashboards reproduced 18 pixels. Seven geometry checks failed against that state.
The device case also checks the actual tab-panel edge, not only its container.

Computed styles identified inherited spacing from the base themes: 32 pixels
above `.page-content`, five pixels above its content region and a 24-pixel margin
on the inner sidebar form/panel. Chart/map pages additionally inserted an empty
restore-controls row with a ten-pixel bottom margin. Adjacent margins collapse;
their numeric values must not simply be added for every route.

The Next sidebar layout now owns its main/content-region top spacing, and sidebar
forms/panels do not add another top margin. Restore controls own no empty row
margin: each visible restore button supplies the original bottom spacing instead.
Hidden buttons contribute no layout space. Pages without a sidebar retain their
existing main-container top margin. This changes only eight CSS lines; it does
not alter chart/map behavior, permissions, languages, queries or configuration.
The previous single border and inner shadow remain in place.

## Focused validation

- `panel-alignment-red-20261120`: seven failures reproduce the 13–18-pixel
  differences on the device, School, Hub and four dashboards.
- `panel-alignment-focused-20261120`: 63 passes and six failures. Main/sidebar
  margin correction aligns the device; the remaining ten-pixel differences
  identify the empty restore-controls row. No assertions were relaxed.
- `panel-alignment-focused-v2-20261120`: **71 passes**, no failures, flakes,
  skips or global errors. JSON and embedded HTML reports reviewed. Coverage
  includes the seven top-edge checks, actual tab edges where present, desktop/
  mobile hide-and-restore interactions, and the previous search/filter/sidebar/
  typography/tab-panel regressions. Screenshots of the aligned device/dashboard
  panels were reviewed. Raw screenshots remain private.

## Environment and full validation

The active checkout and its guarded isolated site/database are used, with the
same runtime/dependency versions and tooling gaps described in the
[search report](GLOBAL-SEARCH-20261120.md). All 205 installed packages match the
lockfile. Local PHP and target v3 PHP have different patch versions. Source hashes
are frozen privately during the full run. The publication starts at deployed
`e73a6554`; its focused code commit is `9e5ec5e3`.

`panel-alignment-full-20261120` completed in 27.7 minutes: **558 passed**,
**26 intentional skips**, zero failures/flakes/global errors. Authentication
passed once, desktop 458 times and mobile 99 times. The 24 desktop/two mobile
skips are the same four optional local V2-fixture cases and 22 external/action
navigation entries. JSON, embedded HTML and all result statuses were reviewed;
frozen source hashes remained unchanged.

The 24 final public-route Lighthouse audits (desktop/mobile, three runs on four
routes) have accessibility, best-practices and SEO **100**, with performance
**97–100**. All JSON/HTML reports were inspected: no runtime, console, resource
or HTTP errors. Six known canonical school-route redirect warnings are retained.
Other performance findings (cache, render-blocking/unused assets, image delivery)
remain recorded rather than being described as passing audits.

The earlier search release's final audits are a same-tool reference, not a
randomized experiment. Median LCP changed from 2,053.3 to 1,513.1 ms on dashboard
mobile, and from 2,479.5 to 2,573.1 ms on school mobile (+93.6 ms, 3.8%). School
mobile TBT median changed from 0 to 5.5 ms while its performance score remains
97. One final dashboard-mobile run has maximum potential FID 92 ms/TBT 37 ms
and performance 100; it is retained. These small/block-specific changes do not
establish a universal speedup or identify a causal loading regression. All three
runs and medians are preserved in the [sanitized aggregate](panel-alignment-20261120.json). The
previous search endpoint/shared-load results remain historical evidence for the
unchanged request handling; no new request/load benefit is claimed for spacing.

The local development site's caches were rebuilt after identifying its local
root/database, making the adjustment available there. The maintained code-only deployment wrapper and actual PHP-FPM checks are the
target verification contract; private settings/backups must be preserved. The
task linked above records the final deployed reference and actual outcome.
