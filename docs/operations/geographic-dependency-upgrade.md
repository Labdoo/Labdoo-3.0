# Supported geographic dependencies on Drupal 11.4

This release upgrades Geofield, which V3's Drupal release-history report identified as unsupported. The deployed V3 baseline was different from the earlier local health checkout, so only Geofield is changed here; Geofield Map and Geolocation remain at their existing compatible versions.

| Package | V3 before | V3 after |
|---|---|---|
| drupal/geofield | 8.x-1.67 | 10.3.5 |
| drupal/geofield_map | 11.1.9 | 11.1.9 |
| drupal/geolocation | 4.1.0 | 4.1.0 |

Composer changes only `drupal/geofield` from `^1.57` to `^10.3.5`; the lock file pins 10.3.5. No core, theme, GeoPHP or unrelated dependency version is changed.

Official compatibility and support information:

- Geofield: https://www.drupal.org/project/geofield — the 8.x-1.x branch is no longer maintained; 10.3.5 supports Drupal ^10.3 and is covered by the security team.
- Geofield Map 11.1.9: https://www.drupal.org/project/geofield_map/releases/11.1.9 — already present on V3 and compatible with the upgraded Geofield.
- Geolocation 4.1.0: https://www.drupal.org/project/geolocation — already present on V3.

## Validation

Before changing dependencies, the isolated health database was backed up and gzip integrity checked. All four geographic fields and their eight current/revision SQL tables were inventoried: **711,466 rows**. Column schemas, full ordered-row SHA256 hashes, field definitions, node/revision counts and maxima are identical after the upgrade. There are no new pending database updates and no entity definition changes. No content was created or saved.

The read-only integration test `vendor/bin/drush --uri=http://labdoo3.local php:script tests/php/geofield-upgrade-regression.php` exercises the custom migration adapter, WKT generator and Geofield 10.3.5: coordinate axis order, multiple points, rejection of invalid coordinates and trip line generation. Its five assertions pass against the isolated local database on Drupal 11.4.12; it saves no content. This local result does not replace the post-deployment V3 smoke checks.

Browser checks cover public dashboards, search, administration, existing entity forms, Wiki revisions, Webform, both themes and mobile aggregation. Additional checks exercise existing dootronic/trip/school/hub maps and their retained coordinate widget values. Maps in a legacy school's inactive Location tab are opened through that tab before checking the visible Leaflet map. External map tiles and Google API requests stay disabled in isolated tests; deterministic geocoding is used, and no paid geocoding request is made.

## Production update

Preserve the deployed release's settings, cache overrides, optional Next theme and prior content fixes. Use a new verified package and manifest matching this follow-up lock file; do not reuse the preceding health package's SHA. Apply the previously reviewed maintenance/worker-drain and permission safeguards. Stage files with umask0022 so www-data can read them; private evidence/backups stay protected by their enclosing0700 directory.

The dependency upgrade itself has no data migration. On V3, the three reviewed database hooks completed after a private database backup was verified. The Charts CDN requirement was disabled directly with Drush, and C3 and History were uninstalled after confirming the sole History record was present in the backup. No full configuration import was used. The site cache and PHP-FPM were refreshed and the HTTP smoke checks passed.

Drupal 11.4.13 is a separate security-only core update from 11.4.12. Its Composer lock update includes only the core metapackage/components and compatible Guzzle/Symfony patch updates. Keep this update separate from the Geofield compatibility change when reviewing package changes.

Coordinate this deployment with address/geometry repair workers: finish or pause them before recording before/after hashes, otherwise legitimate concurrent repairs will change the comparison. A backup from before those repairs must not be restored over newer recovered data merely to roll back this dependency-only change.

After verifying the new runtime, the Drupal release-history report needs metadata for the new versions. A scoped refresh is:

```php
\Drupal::service('update.manager')->refreshUpdateData();
\Drupal::service('update.processor')->fetchData();
```

The first call clears cached project/release metadata and queues release-history fetches. The second processes only the update-fetch queue for its configured time budget. Repeat only `update.processor->fetchData()` while `\Drupal::queue('update_fetch_tasks')->numberOfItems()` is nonzero. These calls fetch public release metadata and update its cache/queue; they do not run cron, install code, import translations or send update-notification mail. The separate `update_cron()` path must not be invoked incidentally.

## Core lifecycle

Drupal 10.6 remains supported only until **9 December 2026**, the end of Drupal10. The official schedule confirms that 10.6 is its final minor release: https://www.drupal.org/about/core/policies/core-release-cycles/schedule?showAll=true . A separately rehearsed Drupal11 migration is needed before that deadline. This change does not attempt an untested major core migration.
