# Learning palette hierarchy revision

The impact metrics now use one uniform primary color, without rotating accent
marks. Main titles are blue with a yellow underline. Section and sidebar titles,
filter labels and quote authors use green; subheadings and the quote rule use
warm orange (`#b45309`). Filter panels repeat the yellow top rule. Existing
semantic artwork, chart data/colors and body text remain unchanged.

Validation used the active `development` checkout at
`d714a9dd2fe4d5330f6567dbe405d658085c4a1c`, including pre-existing dirty work
and the prior palette/statistics changes. All installed application dependency
versions match `composer.lock`; Drupal 11.4.13, PHP CLI 8.3.36. No deployment,
configuration import or data migration was performed.

## Functional and quality checks

- `make e2e LAB_RUN_ID=learning-hierarchy-20261124 E2E_ARGS='theme-palette.spec.ts theme-preview.spec.ts'`:
  37 expected, zero unexpected/skipped/flaky, no global errors. JSON and embedded
  HTML statistics agree. Covers English/Spanish, desktop/mobile, anonymous
  rendering, first-save cache invalidation, custom palette persistence, headings,
  filter labels/rules, uniform metric colors, permissions, navigation and forms.
- `tools/quality/vendor/bin/phpunit -c tools/quality/phpunit.xml tests/phpunit/PaletteManagerTest.php`:
  3 tests, 21 assertions. Added supporting-green contrast on paper/canvas;
  warm-orange subheading contrast also passes the existing normal-text check.
- Targeted Drupal/DrupalPractice PHPCS, PHP syntax and `git diff --check` pass.
- Rebuilt immutable assets; published CSS bytes and SHA-256 match the source:
  `ecd4a1744b1213e89065cf5124775c37a8e12984bc550878f40938025b8b9a1b`.

Browser tests ran against this checkout's isolated E2E database/site on port
18411 with existing mail/HTTP guards. Reports and screenshots were reviewed
under `tests/upgrade/.local/state/runs/learning-hierarchy-20261124/`.

The existing local Apache site `labdoo3.local` was separately verified as this
checkout, local database `labdoo3`, with Learning already active. Its caches
were refreshed to publish the new stylesheet/preset values. Anonymous browser
checks on that real PHP-FPM site confirm the blue page title and green filter
labels. Supplemental desktop/mobile captures are retained privately in
`/tmp/labdoo-learning-hierarchy-preview/`. This local smoke check does not replace
the isolated regression run. External map tiles are blocked in the captures.

No queries, cache policy, queues or request handling changed in this revision,
so k6 was not selected; the prior palette load evidence remains historical.

## Lighthouse

Command: `make lighthouse LAB_RUN_ID=learning-hierarchy-20261124
LIGHTHOUSE_ARGS='--path /es/content/dootronics-dashboard --runs 1'`.
Lighthouse 12.8.2, one run per desktop/mobile profile, same isolated route as the
prior Learning audit. All JSON/HTML route reports and the summary were reviewed.
No runtime errors or warnings; contrast audits pass.

| Profile | Performance before → after | Accessibility | LCP before → after | TBT before → after | CLS |
| --- | --- | --- | --- | --- | --- |
| Mobile | 100 → 100 | 100 | 1514 → 1511 ms | 21.5 → 40.5 ms | 0 |
| Desktop | 99 → 99 | 100 | 965 → 948 ms | 0 → 0 ms | 0 |

Best practices and SEO remain 100. Existing render-blocking, unused-resource,
DOM-size and desktop loading/listener opportunities remain visible; they were
not suppressed. The previous reference is
`tests/performance/.local/lighthouse-learning-20261123/summary.json`, the new
reports are under `tests/performance/.local/lighthouse-learning-hierarchy-20261124/`.
These single samples do not establish a statistical performance improvement or
certify every content page.
