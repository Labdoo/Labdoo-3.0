# Action typography and tabbed panels — 2026-11-20

Follow-up to visual task `6j3fgrpMVHxgJpHV`, requested while validating global
search. Changes and local validation are documented below. Deployment status, revision and target-runtime evidence are tracked in the
[task](https://app.todoist.com/app/task/6j3fgrpMVHxgJpHV).

## Changes and reproduced failures

Pickup status intentionally has no anchor when the current user cannot update
the device. The Next theme sized anchors at 0.84 rem but left plain action text
at the body size. The anonymous desktop/mobile regression reproduced **16 px /
25.6 px line height**, compared with **13.44 px / 18.816 px** for adjacent links.
Action rows now own the shared typography; links inherit it. Plain rows use the
same padding and icon alignment. Permissions, destinations, status and update
behavior are unchanged. The superadmin still receives an action link; anonymous
visitors still receive plain status text.

The article card surrounded a second bordered horizontal-tabs panel. For content
with that panel, the surrounding article/content-box border and padding are now
removed. The inner tabs retain their one-pixel border and pane shadow, with a
subtle fallback when Gin's shadow variable is unavailable. Native mobile details
do not add another surrounding border. Other articles retain their existing card.

## Validation

`panel-consistency-red-20261120`: six failures reproduced the two reported
defects across desktop/mobile and English/Spanish tabs; two authorized-role
typography cases already passed. No failing assertions were removed.

`panel-and-search-final-20261120`: **62 passed**, zero failures, flakes, global
errors or skips. JSON and embedded HTML reports reviewed. This includes the eight
new role/responsive/panel checks, real desktop tab selection and mobile native
details interaction, plus the search, dashboard, sidebar-menu and filter-layout
regression cases. Screenshots of actions and desktop/mobile panels were reviewed.
The source uses the active checkout's guarded, isolated E2E site/database.

The preceding global-search full run had **541 passes / 26 intentional skips**
before these narrow CSS follow-ups. It is not presented as a full rerun of the
follow-up revision. Focused shared-UI coverage above validates these scoped visual
changes. The 24 final public Lighthouse audits have accessibility/best-practices/
SEO 100 and performance 97–100. All 12 final open-search snapshots have
accessibility 100. The final endpoint/shared load passes without HTTP or check
failures. Target-runtime evidence is recorded in the task linked above. See [the search report](GLOBAL-SEARCH-20261120.md) for runtime versions,
tooling gaps and publication-scope limits. Raw screenshots/reports remain private.
