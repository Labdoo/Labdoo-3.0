# Custom interface translations

Drupal's Locale module stores interface translations in the database. This
directory keeps Labdoo's custom translations in version control so they can be
reviewed and imported into another local or deployed site.

## Generate untranslated strings

From the Drupal project root, preview pending entries first:

```sh
drush labdoo:translations-update --dry-run
```

Then generate or extend the PO files:

```sh
drush labdoo:translations-update
```

The command checks every enabled configurable language except the site's
default language and writes `labdoo_custom.{langcode}.po` files here. It only
adds entries still untranslated in the Locale database, and merges them into
existing files without dropping previously committed translations. Review the
new `msgid` entries and fill in their `msgstr` values before committing.

To limit a run to selected languages, pass comma-separated codes:

```sh
drush labdoo:translations-update --langcodes=es,fr
```

## Import versioned translations

After reviewing and translating the PO files, import them with:

```sh
drush locale:import-all translations/custom --type=customized --override=customized
```

This imports the versioned custom entries and updates existing customized
translations while preserving translations provided by other sources.
