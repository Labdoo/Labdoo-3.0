# Drupal production health update

The maintenance release updates Drupal core to 10.6.21 and resolves the security advisories present in the previous Composer lock file. Diff, Gin, Gin Toolbar and Firebase JWT require compatible major-version upgrades; the custom Wiki revision form is adjusted for Diff 2.

## Changes

- Reconcile the three existing node field definitions with equivalent SQL columns. The update refuses an unexpected type, column, cardinality or revision/translation layout. It does not save nodes or revisions.
- Keep the Wiki language field revisionable, matching its installed schema and existing revision history.
- Enable CSS and JavaScript aggregation without changing the page-cache lifetime.
- Correct the existing Views null-entity patch: `!$entity === NULL` always discarded the valid entities' cache tags and lifetimes. Preserve valid entities and ignore only absent relationships.
- Restore Honeypot's official 2.1.4 update hooks 8103 and 8104 in the current package so an installation still at schema 8102 can execute the actual missing updates. This creates the hostname index and clears Tour definitions where applicable; it does not advance schema versions without executing updates. Source: https://git.drupalcode.org/project/honeypot/-/raw/2.1.4/honeypot.install . Remove this compatibility patch after every supported site has completed those updates.
- Provide opt-in production settings for state cache and a private file directory outside the document root.
- Remove Webform Bootstrap from the exported enabled-module list. Labdoo inherits from Gin; the Bootstrap 3 integration is unused and its prerequisite is absent. Actual uninstall must be scoped and verified as below.

## Deployment sequence

1. Back up the database, current code/vendor, production settings, public `.htaccess`, and current release identifier. Record node/revision counts and maxima.
2. Deploy the combined release retaining the optional Next theme and earlier fixes. Install the locked dependencies with Composer. The changed core package must receive the corrected Views patch; do not merely copy the patch file over an already installed package.
3. Verify `module_installer->validateUninstall(['webform_bootstrap'])` and `config.manager->findConfigEntityDependenciesAsEntities('module', ['webform_bootstrap'])` are empty on the destination, then run `drush pm:uninstall webform_bootstrap -y`. Do not enable the obsolete Bootstrap 3 theme.
4. Run `drush updatedb -y`. Do not run cron or translation updates as part of this release.
5. Prepare `/mnt/data/labdoo-private`, owned by the web-server account with directory mode 0770. Include `sites/default/settings.production.php` at the end of the destination's existing settings file, preserving all connection secrets and overrides. An alternative path can be supplied through `LABDOO_PRIVATE_FILES_PATH`; it must remain outside the web root. Write Drupal's private `.htaccess` protection through FileSecurity after the stream wrapper is available.
6. Rebuild caches. Confirm state cache is enabled, Twig debug is false, CSS/JS aggregation is true and page/dynamic/render cache backends are active. Preserve the destination's Redis setup.
7. Confirm no pending entity definition changes or database updates, Honeypot schema is at least 8104 and its hostname index exists, and node/revision counts and maxima are unchanged. Run `php -d xdebug.mode=off tests/php/views-cache-regression.php` (five assertions).
8. Check anonymous and authenticated pages, existing edit forms, Wiki revision comparisons, Webform administration, both themes and mobile layout. Fetch generated CSS/JS and check for browser errors. Warm anonymous pages twice and verify cache hits. Review the status report again.

A rollback after database updates must restore both the pre-release database and matching code/dependencies. Restoring only the old branch is insufficient.

## Scope limits

No content or interface translations are executed. Existing duplicate user emails need account-owner review, not invented replacement addresses. A privacy-policy link requires a verified policy URL; the release does not create legal content. Local isolated tests disable external mail and paid geocoding and use database cache backends, so Redis health must also be verified on the destination. The status module may retain stale update/announcement results until its read-only refresh runs; Composer's security audit is checked separately.
