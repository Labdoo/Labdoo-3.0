# Supported geographic dependencies on Drupal 10.6

This follow-up replaces three releases that Drupal's release-history report marks unsupported:

| Package | Before | Tested version |
|---|---|---|
| drupal/geofield | 8.x-1.65 | 10.3.5 |
| drupal/geofield_map | 11.0.5 | 11.1.9 |
| drupal/geolocation | 4.0.0-beta2 | 4.1.0 |

Composer updates only those three packages and their GeoPHP dependency (itamair/geophp 1.7 → 1.12). No core, theme or unrelated dependency version is changed. Composer also refreshes the existing doctrine/common 3.5.0 metadata to flag that package as abandoned; this package/version was already present. It needs a separate dependency review and is not hidden or removed here.

Official compatibility and support information:

- Geofield: https://www.drupal.org/project/geofield — the 8.x-1.x branch is no longer maintained; 10.3.5 supports Drupal ^10.3 and is covered by the security team.
- Geofield Map 11.1.9: https://www.drupal.org/project/geofield_map/releases/11.1.9 — supports Drupal ^10.3; adds optional Key module support for a Google Maps API key.
- Geolocation 4.1.0: https://www.drupal.org/project/geolocation — stable supported release compatible with Drupal ^10.

## Validation

Before changing dependencies, the isolated health database was backed up and gzip integrity checked. All four geographic fields and their eight current/revision SQL tables were inventoried: **711,466 rows**. Column schemas, full ordered-row SHA256 hashes, field definitions, node/revision counts and maxima are identical after the upgrade. There are no new pending database updates and no entity definition changes. No content was created or saved.

The read-only integration test `drush php:script tests/php/geofield-upgrade-regression.php` exercises the custom migration adapter, WKT generator and new GeoPHP library: coordinate axis order, multiple points, rejection of invalid coordinates and trip line generation. Its five assertions pass. The five Views-cache regression assertions also pass.

Browser checks cover public dashboards, search, administration, existing entity forms, Wiki revisions, Webform, both themes and mobile aggregation. Additional checks exercise existing dootronic/trip/school/hub maps and their retained coordinate widget values. Maps in a legacy school's inactive Location tab are opened through that tab before checking the visible Leaflet map. External map tiles and Google API requests stay disabled in isolated tests; deterministic geocoding is used, and no paid geocoding request is made.

## Production update

Preserve the deployed release's settings, cache overrides, optional Next theme and prior content fixes. Use a new verified package and manifest matching this follow-up lock file; do not reuse the preceding health package's SHA. Apply the previously reviewed maintenance/worker-drain and permission safeguards. Stage files with umask0022 so www-data can read them; private evidence/backups stay protected by their enclosing0700 directory.

The tested upgrade has **no data migrations**. Check `updatedb:status` and the entity change list on the destination; do not invent update-hook versions or force a schema change. Clear Drupal caches after switching code and restart FPM, whose opcode cache does not validate file timestamps. Recheck the read-only integration test, maps/widgets and identifiers before opening maintenance.

Coordinate this deployment with address/geometry repair workers: finish or pause them before recording before/after hashes, otherwise legitimate concurrent repairs will change the comparison. A backup from before those repairs must not be restored over newer recovered data merely to roll back this dependency-only change.

After verifying the new runtime, the Drupal release-history report needs metadata for the new versions. A scoped refresh is:

```php
\Drupal::service('update.manager')->refreshUpdateData();
\Drupal::service('update.processor')->fetchData();
```

The first call clears cached project/release metadata and queues release-history fetches. The second processes only the update-fetch queue for its configured time budget. Repeat only `update.processor->fetchData()` while `\Drupal::queue('update_fetch_tasks')->numberOfItems()` is nonzero. These calls fetch public release metadata and update its cache/queue; they do not run cron, install code, import translations or send update-notification mail. The separate `update_cron()` path must not be invoked incidentally.

## Core lifecycle

Drupal 10.6 remains supported only until **9 December 2026**, the end of Drupal10. The official schedule confirms that 10.6 is its final minor release: https://www.drupal.org/about/core/policies/core-release-cycles/schedule?showAll=true . A separately rehearsed Drupal11 migration is needed before that deadline. This change does not attempt an untested major core migration.
