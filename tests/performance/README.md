# Labdoo Next Lighthouse checks

This benchmark compares anonymous Labdoo Next pages on the isolated integration
copy. It does not audit the Basic Auth response from AWS or bypass authentication.
There are three fresh-browser runs per route/profile, performed sequentially to
avoid CPU contention. Report the median, not the best run.

## Reproduce

Requirements: an isolated integration site on loopback port 18381 with header
`X-Labdoo-E2E: integration`, its real content/database, Chrome, and Lighthouse
12.8.2. The baseline is development commit `861c784e`. Use identical content,
Chrome/Lighthouse versions, aggregation settings and transport for both revisions.
Do not use the production database for browser tests.

1. Copy the revision's custom code into the isolated site. Set both
   `system.performance.css.preprocess` and `system.performance.js.preprocess` to
   `true`, including any settings.php overrides, and rebuild Drupal caches. This
   matches the live release's aggregation. Back up and restore the isolated
   configuration/settings around the benchmark.
2. Start the PHP site on port 18381 using the local-lab server documented under
   `tests/e2e/support/local-lab`. Start the transport in a separate terminal:

   ```sh
   python3 tests/performance/serve.py
   ```

3. Run the audit with a private output directory outside Git:

   ```sh
   python3 tests/performance/lighthouse.py --output /tmp/labdoo-lighthouse --runs 3
   ```

The runner checks HTTP 200 and the isolation header, warms server-side local CSS/JS
aggregates, and then runs Lighthouse's default mobile profile and desktop preset.
It does not warm the browser cache, stub network resources, accept cookies before
measurement, remove analytics, or suppress audits. The school route includes the
existing node-to-alias redirect in both revisions. The homepage currently renders
the dashboard; it is measured independently.

The PHP development server does not implement Drupal's Apache `.htaccess`. The
read-only loopback proxy adds gzip and static expiry consistently for both
revisions. This approximates the origin transport, not CloudFront or AWS latency.
External analytics and map tiles are real network requests and introduce variance.
Raw reports can contain third-party URL parameters: retain them privately; only
scores, metrics and run metadata are committed in `before.json` / `after.json`.

## Regression coverage

Run from the isolated site's `tests/e2e`, with seeded test users and auth states:

```sh
E2E_RUN_ID=lighthouse-regression npm test -- education-quotes.spec.ts theme-palette.spec.ts theme-preview.spec.ts theme-performance.spec.ts --project=integration --project=mobile --no-deps
```

The new checks exercise deferred chart rendering, filtered map URLs, coordinates
on the equator, text-only marker titles and complete insertion of 2,000 points, and native school maps with numbered
trajectory markers and a polyline after deferred initialization.
Existing checks cover twenty quote languages, AJAX/no-JS fallback, hidden pending
quotes, compact author placement and natural widget height, palette admin,
anonymous access, menus, filters, content, gallery, printable labels and admin
theme boundaries.

See [RESULTS.md](RESULTS.md) for measured medians and remaining constraints.
