# Labdoo Next asset builds

The CSS is maintained directly in `../css/labdoo-next.css`. These builds produce
optional JavaScript, responsive artwork and immutable copies of public assets;
they do not compile CSS.

## Bar chart bundle

Run from this directory:

```sh
npm ci --ignore-scripts
npm run build
```

The lockfile pins Chart.js 4.4.0 and esbuild 0.25.12. `chart-bar.js` registers
only bar controllers, their scales and standard legend/tooltip/title/color
plugins. The original, same-version UMD bundle remains available for advanced
charts. `lazy-charts.js` upgrades before loading date or data-label plugins and
preserves existing chart instances and their IDs. Keep the existing upstream
Chart.js license when rebuilding `../vendor/chart.js-4.4.0/dist/chart-bar.js`.

## Responsive artwork

With `cwebp` installed:

```sh
python3 build-images.py
```

This derives lossless WebP variants from the existing theme's PNG icons and
footer/story artwork and the original Next wordmark. Alpha transparency and
source artwork are retained. The original wordmark's attribution and license
remain documented in `../ASSETS.md`. The generated files live in `../images`;
keep the originals for reproducible builds and compatibility.

The same command builds the action icons at 30/60/90 pixels and every existing
English school/hub semaphore at 120/240/360 pixels. Their `picture` elements
keep the PNG fallback. Rebuild all derivatives after changing their sources;
publish source and generated files together.

## Immutable publication

After changing any theme image, CSS or JavaScript, run from this directory:

```sh
python3 build-static.py
```

Commit the sources, generated `../static` files and manifest together.
Individual
assets use SHA-256 content paths; vendor packages use a package digest and keep
relative CSS/JavaScript/image paths and upstream licenses. Existing hashes are
retained so cached pages continue to work. Do not overwrite immutable files or
delete published hashes during routine builds. Rebuild JavaScript bundles and
responsive artwork first when their source changes.

The manifest is resolved by the shared `labdoo_asset` Twig function and theme
asset hooks. Drupal 11.4.13 already fingerprints CSS/JavaScript aggregate inputs
by file content; aggregate URLs need no additional version query parameter.
