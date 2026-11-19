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
When the portable `labdoo_translations` catalog service is enabled, reviewed
catalog translations are excluded from pending exports. With an agent selected,
they also fill matching empty entries in older PO files before any agent call.

To limit a run to selected languages, pass comma-separated codes:

```sh
drush labdoo:translations-update --langcodes=es,fr
```

## Translate with a local agent

Select an installed, authenticated CLI explicitly. Without `--agent`, the
command continues to export untranslated entries only. These examples run the
CLI locally; the selected CLI may send strings to its configured model provider.

```sh
vendor/bin/drush labdoo:translations-update --langcodes=es --agent=claude
vendor/bin/drush labdoo:translations-update --langcodes=fr --agent=codex
vendor/bin/drush labdoo:translations-update --langcodes=de --agent=gemini
vendor/bin/drush labdoo:translations-update --langcodes=es --agent=custom --agent-binary=/path/to/translation-wrapper
```

Use `--agent-model=MODEL` to choose the model, `--agent-binary=/absolute/path`
to override the executable, `--batch-size=20` to control request size and
`--agent-timeout=180` to set each request's timeout in seconds. `--dry-run`
never invokes an agent or writes files, including when `--agent` is supplied.

The `custom` adapter supports other local agents through an executable wrapper.
It receives the prompt on stdin and must write the requested JSON object to
stdout, with diagnostics on stderr and a nonzero exit status on failure. When
`--agent-model` is supplied, the wrapper receives `--model MODEL` as arguments.

The command fills missing translations in the merged PO file and retains
nonempty translations. Requests contain strings, context and plural indices.
Target plural headers for new files come from the bundled `labdoo_translations`
catalog where available; existing PO headers are retained. Review plural rules
when exporting languages outside that catalog.

Agents run in a private temporary directory, with prompts on stdin and arguments
passed without a shell. Responses must be JSON with all requested IDs and
preserve placeholders, HTML markup and URLs. Invalid responses, process failures
and timeouts leave the current language's PO file unchanged. Earlier languages
may already have been written. Review generated translations before committing
or importing; this command never imports them into Drupal.

CLI contract checks use fake executables and incur no provider calls:

```sh
php -d xdebug.mode=off tests/php/local-translation-agent.php
```

CLI flags were checked against the installed Claude Code 2.1.328, Codex CLI
0.169.0 and Gemini CLI 0.62.0 help. Gemini's JSON envelope is decoded using its
documented `response` field; see the [official headless-mode reference](https://geminicli.com/docs/cli/headless/).

## Administrative translation jobs

Open `/admin/config/regional/labdoo-translations` (with the site's language
prefix). The restricted `administer labdoo translations` permission grants
access; each operator can access only their own jobs, with a user 1 override.
Checking pending strings does not invoke an agent. Choose target languages,
an approved agent and optionally one of its approved models, then explicitly
start a background job. The status page polls for progress and also supports
manual refresh without JavaScript. Review pages display escaped text; downloads
are private PO attachments. Import requires a separate confirmation form.

Configure executable profiles in the server's private settings, for example:

```php
$settings['labdoo_translation_agents'] = [
  'claude' => [
    'label' => 'Claude',
    'adapter' => 'claude',
    'binary' => '/absolute/path/to/claude',
    'models' => ['APPROVED_MODEL'],
  ],
  'codex' => [
    'label' => 'Codex',
    'adapter' => 'codex',
    'binary' => '/absolute/path/to/codex',
    'models' => [],
  ],
  'gemini' => [
    'label' => 'Gemini',
    'adapter' => 'gemini',
    'binary' => '/absolute/path/to/gemini',
    'models' => [],
  ],
];
```

Replace paths and model names with installed, approved values. Profiles with
unavailable executables are omitted. An empty model selection uses the CLI's
configured default. The `custom` adapter also accepts a wrapper using the stdin
and JSON contract documented above. Browsers cannot supply arbitrary executables
or model arguments. No profiles are enabled by default.

These agents run on the server executing the worker, not on the operator's
personal computer. Install and authenticate them under the worker's operating
system account. That account needs read access to approved executables and
read/write access to Drupal's private storage. Configure `file_private_path`
outside the web document root. Job directories are private and checksummed;
generic Drupal private-file URLs cannot serve these files.

Run the dedicated queue with the same Drupal root, site URI and private settings
as the web application, using a lease longer than the 120-second agent timeout:

```sh
vendor/bin/drush queue:run labdoo_translation_jobs --lease-time=300 --time-limit=240
```

Drupal cron can also process this queue. Scheduling or deploying a worker is an
operator action; adding this interface does not install a new scheduler. Queue
work is bounded to one preparation, ten PO entries or one import batch per item.
The UI and Drush command share the inventory and translation workflow.
Restart long-running workers after changing server profiles or role permission
configuration, since these Drupal settings can be cached within a CLI process.

Completed translation batches survive a handled failure; retry requires another
confirmation. An interrupted process may repeat its unfinished batch under the
queue's at-least-once delivery semantics. Owner permission and target language
availability are rechecked before processing. Agent profiles/models are checked
again when translating. Revoked access prevents further provider calls.

Generated results remain private until reviewed; generation never imports them.
Confirmed imports fill missing Locale values while preserving existing native,
manual and customized translations, including values added after generation.
Imports commit in batches, refresh Locale caches and resume unfinished batches
after failure; they do not provide a transaction across the whole job. Result
files remain available for review/download. There is no automatic retention
purge in this initial interface.

## Import versioned translations from the CLI

After reviewing and translating the PO files, import them with:

```sh
drush locale:import-all translations/custom --type=customized --override=customized
```

This imports the versioned custom entries and updates existing customized
translations while preserving translations provided by other sources.
