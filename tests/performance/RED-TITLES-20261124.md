# Red page titles

Learning page titles now use a separate `title` color (`#c5221f`). Blue links,
buttons and uniform metrics, green sections/filters, orange subheadings and
yellow decoration retain their previous roles. The palette editor exposes this
color with the existing translated Title label. Older saved Learning presets
inherit red when the field is absent; other saved palettes inherit their saved
primary color. All colors retain strict hexadecimal validation.

Environment: active `development` checkout at
`d714a9dd2fe4d5330f6567dbe405d658085c4a1c`, including the existing dirty work
and prior palette/statistics changes. Installed application packages match
`composer.lock`; Drupal 11.4.13, PHP CLI 8.3.36. No deployment or schema/config
import. The already active Learning palette on the local `labdoo3` database was
read after checking its checkout/localhost provenance, then local caches were
refreshed. Actual anonymous Apache/PHP-FPM captures verify red main titles on
`labdoo3.local`: `/tmp/labdoo-red-title-preview/{escritorio,movil}.png`.

Validation against this checkout's isolated E2E site/database:

- `make e2e LAB_RUN_ID=red-titles-20261124 E2E_ARGS='theme-palette.spec.ts theme-preview.spec.ts --grep "palette|complete admin navigation"'`:
  10 expected, zero unexpected/skipped/flaky/global errors. JSON and embedded
  HTML statistics agree. Covers English/Spanish desktop/mobile rendering,
  independent red title editing, no-JavaScript persistence, first-save cache
  invalidation, access and header/focus regression. The broader 37-case theme
  run from the preceding hierarchy revision remains historical evidence.
- Focused PHPUnit: 3 tests, 30 assertions. Covers old custom and built-in saved
  palettes with a changed primary, CSS safety and red normal-text contrast on
  paper/canvas alongside existing role contrast checks.
- PHP lint, Drupal/DrupalPractice PHPCS, level-5 PHPStan and diff checks pass.
- Rebuilt immutable stylesheet; source/published bytes and SHA-256 agree:
  `a14e5d7ab3a690c263c9f4f50f705e011e8120129b402784e408369e90475153`.

Private browser JSON/HTML reports and screenshots were reviewed under
`tests/upgrade/.local/state/runs/red-titles-20261124/`. No query, queue, cache
policy or HTTP handling change was introduced, so k6 was not selected.

Lighthouse 12.8.2 ran once per profile on the same isolated Spanish dootronics
route (`make lighthouse LAB_RUN_ID=red-titles-20261124
LIGHTHOUSE_ARGS='--path /es/content/dootronics-dashboard --runs 1'`). JSON/HTML
reports were reviewed: performance 99 and accessibility/best-practices/SEO 100
on both profiles, contrast audits passing, CLS 0, no runtime errors or warnings.
Desktop LCP/TBT: 958/0 ms; mobile: 2057/36 ms. The previous hierarchy sample had
performance 99 desktop/100 mobile, LCP 948/1511 ms, TBT 0/40.5 ms. Mobile LCP is
higher in this sample; one sample per version does not establish a causal
regression or a statistical performance pass. Existing resource, DOM and loading
opportunities remain visible. Private artifacts are under
`tests/performance/.local/lighthouse-red-titles-20261124/`.
