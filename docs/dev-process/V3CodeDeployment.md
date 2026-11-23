# V3 code deployment

Run on the AWS server in `/mnt/data/labdoo`, as the deployment user, with the
checkout on `development`:

```sh
git switch development
bash scripts/deploy-v3-code.sh
```

For the initial connection, create the local tracking branch with
`git switch --track origin/development` if it does not exist yet. The wrapper
refuses to run when the checkout is on a different branch.

The wrapper sets `umask 0022` **before Git replaces files**, refuses unexpected
tracked edits, preserves private `web/sites/default/settings.php`, checks the
readability of custom web code as `www-data`, rebuilds Drupal caches and reloads
PHP-FPM. It then checks the configured Next default on the home page and five main
listing destinations, clustered device/school maps and the fresh quote endpoint
through the **actual PHP-FPM socket**, also as `www-data`.
It does not install dependencies, import configuration or run database updates.

The wrapper sets `umask 0022` before fetching and fast-forwarding
`origin/development`. Keep private backups and their restrictive permissions
separate from this shell's code checkout operations. Never make settings.php
or uploads public to fix a code-readability failure.

## Current verification contract

`verify-v3-fpm.py` expects `labdoo_next` by default, matching this branch's
configured public theme. It accepts the original and responsive wordmark
filenames, follows canonical redirects within the same origin, rejects PHP
stderr and non-200 destinations, checks clustered-map totals and requires fresh
quotes to remain private and uncached. `--expect-theme labdoo` is only for an
environment explicitly configured to use the original theme; it does not switch
the theme through a URL query parameter.

The verifier has no connection side effects when imported. Its database-free
checks are `python3 tests/operations/verify_v3_fpm_test.py`. Its `--root`,
`--socket`, `--host`, `--scheme` and `--isolated` arguments allow execution through
an independently configured test PHP-FPM pool; `--isolated` requires the current
checkout identity and E2E response header. Never point integration tests at the
default production socket.

## Permission incident on 12 November 2026

A deployment used `umask 077` for both private backup files and Git operations.
Git consequently replaced eight public code files with mode `0600`, owned by
`ubuntu`. PHP-FPM runs as `www-data`: it could not load the Next theme helpers
and later failed to load `PreviewResponseSubscriber.php`, producing HTTP 500.
Checks performed as the deployment user incorrectly passed.

The affected public code files were restored to `0644`; the private settings
were preserved. Drupal caches were rebuilt and PHP reloaded. Read-only checks
through PHP-FPM returned HTTP 200 for all six cases without PHP stderr. Future
checks must use the runtime user and PHP-FPM rather than a PHP server running
as the deployment user.

## Immutable static-asset headers

After deploying the content-addressed assets, enable the reviewed Apache header
configuration on V3 (the existing CloudFront maximum TTL stays at 300 seconds):

```sh
sudo install -m 0644 scripts/runtime/immutable-static-cache.conf /etc/apache2/conf-available/labdoo-immutable-static-cache.conf
sudo a2enconf labdoo-immutable-static-cache
sudo apache2ctl -t
sudo systemctl reload apache2
```

Reload only after the syntax check succeeds. The configuration requires the
already enabled `mod_headers`. It grants browser caching for one year only to
successful content-addressed assets and Drupal's content-fingerprinted CSS/JS
aggregates, including compressed variants. Shared caches remain bounded at five
minutes. Existing explicit cache restrictions, authentication errors, HTML and
ordinary uploads do not receive this policy. Vendor package copies preserve
relative marker-image paths.

Verify file hashes and runtime readability, real PHP-FPM routes, the effective
Apache configuration and authenticated/unauthenticated asset headers. Synthetic
Basic Auth coverage is in `tests/operations/immutable_cache_test.py`. Without
staging credentials, external authenticated verification remains outstanding;
never disable staging authentication or WAF rules to complete a check.

Rollback the header change with `sudo a2disconf labdoo-immutable-static-cache`,
then validate Apache syntax and reload. Retain published content hashes when
rolling source back. Authentication rotation still requires invalidating cached
static prefixes at CloudFront under the existing staging policy; browser copies
of public code are deliberately immutable. No user data is published this way.
