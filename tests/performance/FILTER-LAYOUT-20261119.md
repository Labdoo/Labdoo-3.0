# Sidebar filter layout correction — 2026-11-19

The sidebar inherited a vertical flex layout, while Labdoo Next applied the 180 px flex basis intended for horizontal exposed forms to every form item. Each sidebar field therefore reserved 180 px of height. The corrected sidebar uses natural field heights (70 px for the Spanish school's single-control fields), stretches controls to the available width and stacks boolean options with their labels inside the panel. The school filter panel now measures 426 px at a 1440×1000 viewport.

The CSS is scoped to `.labdoo-next .ldn-sidebar .views-exposed-form`. Main-content horizontal forms retain their existing layout. Labdoo Next maintains this CSS directly; it has no Sass source or package/Gulp build pipeline. Existing Labdoo generated CSS and Sass changes were preserved.

## Validation environment

- Branch `development`, revision `d714a9dd2fe4d5330f6567dbe405d658085c4a1c`, with pre-existing application/test/theme changes preserved.
- Installed Drupal and lockfile: 11.4.13; installed Composer package versions match the lockfile. PHP 8.3.36, Node 25.2.1, Playwright 1.65.0, Lighthouse 12.8.2, Chrome 157.0.8103.38.
- Current checkout at `http://127.0.0.1:18411`, isolated `sites/labdoo_e2e` and database `labdoo_e2e_762b685f4cc7`; identity and isolation guards passed. The default site was not changed. Only the isolated clone's caches were rebuilt.
- Auth setup passed in `filter-layout-red-20261119`, establishing the role state reused for these runs. No dependency setup was bypassed before the role state was available.
- The regression assertion reproduced the original defect in `filter-layout-red-confirm-20261119`: school field height was 180 px against a maximum of 120 px. An earlier selector draft was corrected before this reproduction. An intermediate browser run was interrupted during iteration and is not counted as final validation.

## Browser coverage

New coverage: `tests/e2e/theme-filter-layout.spec.ts` checks schools, devices, trips, hubs and actions on desktop/mobile, in Spanish. It checks compact labelled fields, absence of large trailing gaps, controls fitting the panel, stacked checkbox choices without clipping or overlap, keyboard focus of Apply, and viewport overflow. Screenshots were retained and the corrected school panel was visually inspected.

Existing coverage reused: `dashboard-search.spec.ts` (filtering, clearing, autocomplete and sorting), `theme-preview.spec.ts` (theme layout, navigation, responsive content and form operation), and `theme-palette.spec.ts` (palette and form appearance, including no-JavaScript rendering).

```sh
make e2e LAB_RUN_ID=filter-layout-final-20261119 \
  E2E_ARGS='theme-filter-layout.spec.ts theme-preview.spec.ts theme-palette.spec.ts dashboard-search.spec.ts --project=desktop --project=mobile --no-deps'
```

Final Playwright statistics: `{"startTime": "2026-11-19T19:36:55.432Z", "duration": 167344.179, "expected": 50, "skipped": 0, "unexpected": 0, "flaky": 0}`.

The JSON report, HTML report and screenshots were reviewed under `tests/upgrade/.local/state/runs/filter-layout-final-20261119/`. The initial reproduction's screenshot/trace are in `filter-layout-red-confirm-20261119/`.

## Lighthouse comparison

The representative affected route `/es/actions` was audited sequentially before and after the fix, with three runs per mobile/desktop profile, fresh browsers, identical tool versions and warm server asset caches. All six reports per version were reviewed, including runtime errors, warnings and failed audits. Scores are diagnostic observations, not an automatic pass of every audit.

| Version | Profile | All performance scores | Median LCP s | Median TBT ms | Median CLS |
| --- | --- | --- | --- | --- | --- |
| before | mobile | 97, 97, 98 | 2.280 | 21.0 | 0.0574 |
| before | desktop | 100, 100, 100 | 0.499 | 0.0 | 0.0106 |
| after | mobile | 97, 98, 98 | 2.303 | 10.0 | 0.0574 |
| after | desktop | 100, 100, 100 | 0.497 | 0.0 | 0.0106 |

Mobile median LCP changed from 2.280 s to 2.303 s (+23 ms, approximately 1%); the after-run observations remain within the before-run range. Desktop median LCP changed from 0.499 s to 0.497 s. Median performance scores are 97→98 on mobile and 100→100 on desktop. These small timing differences do not establish a performance improvement or deterioration. No runtime errors or run warnings occurred; accessibility, best practices and SEO scored 100 in all before/after reports. Existing failed image/asset audits remain.

Private JSON/HTML reports and all summary metrics: `tests/performance/.local/filter-layout-before/` and `tests/performance/.local/filter-layout-after/`.

No production performance claim is made: this server uses PHP CLI with OPcache disabled. Existing image-size, image-cache and unused-asset audits are outside this visual fix. No k6 scenario was run because this change does not alter queries, request handling or caching behavior. PHPCS/PHPStan/PHPUnit are not installed in the checkout and were not reported as passes; no PHP changed. `git diff --check` passed.

No production deployment or implementation of the broader page-load optimization task was performed.
