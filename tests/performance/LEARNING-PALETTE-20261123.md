# Learning palette validation

Learning is a selectable Labdoo Next preset with a light canvas, readable blue
links/buttons, green supporting details, yellow decorative highlights and red
accents. Short impact marks use all four colors. Existing palettes retain their
saved values; optional supporting/highlight fields fall back to primary/accent
for older saved palettes. Both fields are editable and translated in all twenty
portable catalog languages. No configuration import or deployment was performed.
The isolated preview site is left on Learning for review.

Environment: active `development` checkout at
`d714a9dd2fe4d5330f6567dbe405d658085c4a1c`, including pre-existing dirty work,
the cumulative-statistics change and this change. Installed package versions
match `composer.lock`; Drupal 11.4.13, PHP CLI 8.3.36, Node 25.2.1. Browser and
load checks use only this checkout's isolated site at `127.0.0.1:18411`, with
mail and HTTP guards. No production or default-site configuration was changed.

## Checks and reviewed evidence

- `tools/quality/vendor/bin/phpunit -c tools/quality/phpunit.xml tests/phpunit/PaletteManagerTest.php`:
  3 tests, 17 assertions. Covers older saved palettes, CSS injection rejection
  and WCAG normal-text contrast for Learning's text/button combinations.
- PHP lint, targeted Drupal/DrupalPractice PHPCS and level-5 PHPStan pass for
  the changed PHP. `git diff --check` passes.
- `php tests/portable-translations-core.php`: 22 cases pass. Direct catalog
  lookups confirm both new editor labels resolve in all 20 languages.
- `python3 web/themes/custom/labdoo_next/build/build-static.py` publishes the
  stylesheet. Its source bytes, published bytes and SHA-256 directory agree:
  `ce985f0d0408762c6351829d80934e3bd4cd17e0bf61134a3996803a8261768e`.
- Initial `learning-palette-20261123`: 37 expected, zero unexpected/skipped/flaky.
  Review of screenshots revealed the new visitor context inherited an admin
  session. The palette tests now explicitly create anonymous contexts.
- `learning-public-20261123`: 3 expected, 1 unexpected, zero skipped/flaky. This
  exposed cached Forest markup on the first save of missing palette config.
  Drupal core invalidates configuration tags on updates but not on creation.
  The form now explicitly invalidates its own config tags on that first save.
- `learning-cache-fixed-20261123`: 34 expected, 2 unexpected, zero skipped/flaky.
  Both Learning cases now warm an anonymous Forest page with a cache HIT, save
  Learning, then verify uncached/current colors in English and Spanish on
  desktop/mobile. Custom supporting/highlight colors persist without JavaScript.
  The two failures were the same existing focus assertion expecting Forest's
  fixed green even when Learning was active; actual focus used Learning blue.
- The focus assertion now checks the rendered palette's primary color, preserving
  the outline and exact border-color requirements. Focused rerun
  `learning-focus-final-20261123`: 2 expected, zero unexpected/skipped/flaky.
  All 36 selected functional cases are covered by the 34 passing cases and the
  two corrected, rerun focus cases; the full 36-case run was not repeated after
  this test-only correction. No unresolved functional failures remain.

Commands for browser coverage:

```sh
make e2e LAB_RUN_ID=learning-cache-fixed-20261123 \
  E2E_ARGS='theme-palette.spec.ts theme-preview.spec.ts --no-deps'
make e2e LAB_RUN_ID=learning-focus-final-20261123 \
  E2E_ARGS='theme-preview.spec.ts --grep "complete admin navigation" --no-deps'
```

Authentication was established by the initial run before using `--no-deps`.
JSON and embedded HTML statistics agree for all four runs; no global errors.
Failure contexts/screenshots and final anonymous screenshots were inspected.
Artifacts remain private under `tests/upgrade/.local/state/runs/<run>/`.

Standalone anonymous Spanish preview captures are in
`/tmp/labdoo-learning-preview/{escritorio,movil}.png`. External map tiles are
blocked in this isolated capture; local chart data and clustered markers remain
visible. The screenshot capture declines optional cookies through the actual UI.

## Load and Lighthouse

The existing `platform-uptime-load.js` profile was reused against the statistics
route, which shares the theme and palette service. Five users over 30 seconds:
131 requests, zero HTTP failures, all 520 isolation/functional checks passing,
p95 16.88 ms (budget 2000 ms). Maximum response time was 4.71 seconds, so this
is not a claim that every cold request is fast. The profile exercises ordinary
page caching; palette-save invalidation itself is covered by the browser test.
Private summary: `tests/performance/.local/k6-learning-20261123.json`.

Lighthouse 12.8.2 audited `/es/content/dootronics-dashboard` with Learning active,
one fresh-browser run per profile using:

```sh
make lighthouse LAB_RUN_ID=learning-20261123 \
  LIGHTHOUSE_ARGS='--path /es/content/dootronics-dashboard --runs 1'
```

| Profile | Performance | Accessibility | Best practices | SEO | LCP | TBT | CLS |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Mobile | 100 | 100 | 100 | 100 | 1514 ms | 21.5 ms | 0 |
| Desktop | 99 | 100 | 100 | 100 | 965 ms | 0 ms | 0 |

The JSON summaries and route JSON/HTML reports were reviewed. No runtime errors,
warnings or failed contrast audits. Non-scoring findings include render-blocking,
unused assets and DOM size; desktop also lists preconnect/LCP discovery and
passive-listener opportunities. These were not suppressed. There is no comparable
fresh pre-change reference for this route, so these measurements do not establish
a before/after performance improvement or certify every page. Private artifacts:
`tests/performance/.local/lighthouse-learning-20261123/`.

The loopback test server and its TLS Redis fixture remain running to allow local
review. Their transport configuration applies only to the isolated E2E site;
the previous local profile is retained privately as
`tests/upgrade/.local/state/data-protection-private.json.learning-backup`.
