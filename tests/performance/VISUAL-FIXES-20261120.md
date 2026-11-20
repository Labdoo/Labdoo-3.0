# Sidebar and exclusive menu fixes — 2026-11-20

Task: `6j3fgrpMVHxgJpHV`, including the user's additional request that sibling
navigation submenus must not stay open simultaneously.

## Changes and causes

The outer Drupal block, inner domain actions block and inner content panel each
received independent card styling. Next now removes chrome only from blocks
whose direct child already supplies a filter/action panel. The inner panel,
labels, inputs, links and focus behavior remain present. Other sidebar widgets
retain their existing presentation.

The Wiki tree inherited a fixed 375-pixel domain-widget width despite the smaller
sidebar. Its Next width is now bounded by the container, and links wrap long
translated titles and unbroken words.

The apparently empty card on a dootronic was the Team actions block. Returning
`#markup: ''` still made its render array nonempty to Drupal, which rendered an
empty block wrapper. Its unavailable branches now return cache metadata only,
letting Drupal omit the wrapper. Route/session/permission contexts and the source
node's cache tags are retained, including when its team relationship changes.
Hub and school block access already suppress their unrelated blocks; no generic
CSS rule hides arbitrary empty content.

Main menu details share a native HTML `name` among siblings, with distinct names
for each nested sibling group. Opening a sibling closes the previous one, while
nested groups can retain their ancestor. Native mouse/keyboard behavior works
without JavaScript and preserves the existing mobile menu/Escape handling.

## Scope and environment

The active development checkout remains based on `d714a9dd` with unrelated work
preserved. The performance release `4e2c0594` is the destination/base for this
change. Only the focused visual source/tests and reviewed evidence are copied to
an isolated release worktree. Existing legacy-theme and translation work is not
part of this publication.

Installed and locked Composer packages match (Drupal 11.4.13). Local PHP is
8.3.36; inspected v3 PHP is 8.3.6. Node 25.2.1, Playwright 1.65.0, Lighthouse
12.8.2 and Chrome 157.0.8103.38 are unchanged from the performance validation.
All application tests use this checkout's isolated database/site on loopback
18411, controlled external services and local mail collection. Production is
not load-tested. PHPCS, PHPStan and PHPUnit are unavailable.

## Validation record

`visual-sidebar-red-20261120`: all six new desktop cases failed at their intended
assertions before the fix (two filter languages, tree overflow, nested device
cards, and sibling menu exclusivity with/without JavaScript). JSON and embedded
HTML report statistics were inspected; there were no global errors.

`visual-sidebar-green-20261120`: **12/12 passed**, zero failures, omissions,
flaky cases or global errors across desktop/mobile. JSON/HTML statistics and
filter/action screenshots were reviewed. The final regression also adds a basic
user's device → team → team post → device → cached team journey to check that
omitting unrelated actions does not hide real team actions.

PHP syntax validation of `TeamActionsBlock.php` passes. Completed shared-flow,
Lighthouse and k6 results follow below. Publication verification is recorded in
the task after deployment. Raw reports, screenshots, sessions and fixture data remain
private under `tests/upgrade/.local/state/runs` and `tests/performance/.local`.

### Completed shared-flow regression

`visual-regression-20261120` completed all 298 planned cases: **276 passed,
22 intentional skips, zero failures/flaky cases/global errors**. JSON and the
embedded HTML statistics were inspected. The skips match the navigation
inventory: twenty non-navigation/administrative actions and two external links.
No new visual case was skipped. This run covers the final fourteen focused
cases, five dashboard filter layouts, theme pages and responsive/no-JavaScript
behavior, the complete navigation inventory and languages, team membership,
comments, contact form/local mail and submitted global search. It does not
validate global-search autocomplete, which remains a separate queued task.

### Lighthouse comparison

Thirty new reports were reviewed (two paths before/after and a final dashboard
pass, three mobile plus three desktop measurements per path). The dashboard
reference reuses the final performance-release run at the identical URL/tool
version. The Wiki/device reference temporarily restores the three exact runtime
files from `4e2c0594` in this same isolated checkout, then restores the corrected
files and rebuilds caches before the after runs. Other preserved local work is
identical in both phases. These are local measurements, not public v3 scores.

Runtime, console and HTTP resource failures: **zero**. Twelve warnings are the
same device-node canonical redirect in all before/after profiles. Accessibility
is unchanged: Wiki/dashboard 100, device mobile 96/desktop 97. The device's existing
small-target audit remains visible. No accessibility finding was hidden.

| Path/profile | Median LCP before | Median LCP after | Accessibility before → after |
| --- | ---: | ---: | --- |
| wiki / mobile | 1676 ms | 1580 ms | 100 → 100 |
| wiki / desktop | 407 ms | 404 ms | 100 → 100 |
| device / mobile | 2637 ms | 1881 ms | 96 → 96 |
| device / desktop | 568 ms | 585 ms | 97 → 97 |
| dashboard / mobile | 2030 ms | 2009 ms | 100 → 100 |
| dashboard / desktop | 947 ms | 957 ms | 100 → 100 |

All runs, CLS, TBT and newly flagged audits are retained in
[the sanitized comparison](visual-fixes-20261120.json). Device/dashboard desktop
LCP varies by +18/+10 ms; these small differences do not establish a field-speed
change. CLS medians are unchanged, mobile TBT medians are lower and desktop TBT
remains zero. Wiki desktop gains an occasional forced-reflow flag: roughly
39–50 ms unattributed work and 9–14 ms aggregate-script/jQuery work; the reference
also has a 31-ms mobile unattributed flag. This remaining audit variation is
reported rather than describing every audit as passing.

### Sustained load

`make k6 LAB_RUN_ID=visual-20261120` completed the maintained 13-minute scenario,
reaching 100 VUs: **46,277 requests, 38,584 complete iterations, zero interrupted
iterations, zero HTTP errors, and 115,752/115,752 functional/isolation checks**.
p95 is **8.86 ms**; maximum is **3,661.90 ms**. The existing p95 <2,000 ms,
error rate <2% and 100% checks budgets all pass without changes. These are warm,
local HTTP measurements and do not certify every cold response below two seconds.
The JSON summary and final k6 output were inspected. No browser or source edit
ran concurrently with this measurement.

The first audit orchestration process expired while waiting for E2E completion,
before starting any audit or changing application files. The restarted audit
process completed the 30 reports described above. No interrupted measurement is
counted as a pass.

Publication uses the reviewed source hashes and separate focused worktree. The
maintained deployment wrapper and extra read-only FPM checks verify the resulting
v3 code, named submenu groups, Wiki presence and absence of an empty team panel.
Their actual outcome is recorded in Todoist after execution, rather than being
predicted by this pre-publication report.
