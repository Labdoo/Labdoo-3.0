# V3 code deployment

Run on the AWS server in `/mnt/data/labdoo`, as the deployment user:

```sh
bash scripts/deploy-v3-code.sh deploy/v3-prelaunch-20261109
```

The wrapper sets `umask 0022` **before Git replaces files**, refuses unexpected
tracked edits, preserves private `web/sites/default/settings.php`, checks the
readability of custom web code as `www-data`, rebuilds Drupal caches and reloads
PHP-FPM. It then checks the Next home page, dashboard, original theme and fresh
quote endpoint through the **actual PHP-FPM socket**, also as `www-data`.
It does not install dependencies, import configuration or run database updates.

For the first deployment of this wrapper, set `umask 0022` before fetching and
fast-forwarding the release branch, then run the command above. Keep private
backups and their restrictive permissions separate from this shell's code
checkout operations. Never make settings.php or uploads public to fix a code
readability failure.

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
