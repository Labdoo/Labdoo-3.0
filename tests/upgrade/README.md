# Current-checkout regression laboratory

This is a local development tool. It is outside the Drupal document root and binds exclusively to `127.0.0.1`. Do not expose it through Apache, a reverse proxy or a public tunnel. The runner can mutate only the dedicated database clone; it never accepts a site URL or an arbitrary command from the browser.

## Start the existing laboratory

From the active repository checkout (the code under test):

```sh
npm ci --prefix tests/e2e
python3 tests/upgrade/lab.py serve
```

In another terminal:

```sh
node tests/upgrade/dashboard.mjs
```

Open **http://127.0.0.1:18412**. The Drupal clone runs at **http://127.0.0.1:18411**. The English-only Test Lab runs Playwright, k6, and Lighthouse against this isolated local clone only. The dashboard shows test names and descriptions, live output, previous runs, and reports. The progress bar counts completed Playwright tests and Lighthouse route/profile audits; k6 uses an indeterminate bar because its load profile has no fixed iteration count. Runs use one worker, no retries, and retain failure screenshots and traces. Failed, skipped, and unstable tests remain distinct.

## Reconstruct a laboratory

Use the active checkout and its installed dependencies. The runner selects `web/sites/labdoo_e2e` only in dedicated loopback test processes; it never overwrites or includes `web/sites/default/settings.php` or `settings.local.php`. Public uploads and private files are separate from the default site. Do not reuse connection state from another checkout. Prepare a private JSON containing a local Drupal database connection (`source` or `connection` key, with Drupal connection options) and a MySQL administrator defaults file. No credentials belong in Git.

```sh
LABDOO_SOURCE_JSON=/private/source.json \
LABDOO_ADMIN_CNF=/private/admin.cnf \
LABDOO_SOURCE_FILES=/private/source-web/sites/default/files \
python3 tests/upgrade/lab.py prepare
```

Preparation refuses an existing lab. The connection manifest records this checkout; the runner rejects state copied from another root. It creates a `labdoo_e2e_<checkout-hash>` database and a dedicated restricted account for this checkout, a consistent source snapshot, independent settings, the branch configuration, fixture users, content and uploads. The lab profile also enables `labdoo_quotes`, `labdoo_theme_preview` and `labdoo_next` so the optional feature tests run against installed extensions. It never imports into or modifies the source database. The source must be local. Required tools: PHP 8.3+, Composer, MySQL 8, Node, Python, ffmpeg and Chrome at `/usr/bin/google-chrome`.

The clone has a test mail collector, deterministic geocoding and a fixed YouTube oEmbed response. Other outgoing backend HTTP is blocked. Asset aggregation follows the branch configuration. Contributed geocoder presave is disabled to preserve controlled fixture addresses; the deterministic geocoder and custom queue flows remain available. Optional theme block placements are recopied after the base configuration import. The contact CAPTCHA is removed only from the clone. These tests certify the local application flows, not delivery by mail providers, CAPTCHA providers, map providers or YouTube. The gallery creation UI is tested with a separate uid 1 session because its existing implementation restricts that operation to uid 1.

Historical upgrade verification only: an older source with Honeypot schema 8102 may require intermediate 2.1.4 updates. After preparing this checkout's isolated E2E clone, run the following command from the active checkout. It verifies the clone guard, extracts only the pinned 8103 and 8104 hooks to an ignored temporary PHP script, and runs them through Drupal's update-hook executor against the clone. It does not replace or edit the checkout's Honeypot module code. It then rebuilds caches, confirms no updates remain, and checks that the clone schema reached 8104. Do not use it against the default site's database or as part of routine regression runs:

```sh
python3 tests/upgrade/preflight.py
```

This uses the official release archive with a pinned SHA-256, temporarily installs the intermediate module, executes the actual update hooks, restores the current code and checks updates again. It does not advance a schema marker without executing its updates.

## Run tests over time

Use the test site in the active checkout whenever you need to check the current application. `make e2e`, `make k6` and `make lighthouse` no longer select `.e2e-lab/drupal11`. The local runners verify the server checkout identity, so a server left running from another checkout is rejected. Start this checkout's server with `make e2e-serve`. Select a Playwright group, k6 load profile, or Lighthouse audit in the dashboard and run it against the isolated Drupal clone. Individual reports and run history are kept in ignored local state.

The completed Drupal 10/11 migration runs, entity-count integrity check, and compatibility notes are recorded in [RESULTS.md](RESULTS.md). Those migration results document the one-time upgrade verification; the dashboard itself is intended for ongoing regression checks.

Command-line execution is also supported:

```sh
E2E_RUN_ID=before-cli npm --prefix tests/e2e test -- -c upgrade.config.ts
node tests/upgrade/compare.mjs /private/before/results.json /private/after/results.json
node --test tests/upgrade/compare.test.mjs
```

The panel adds version, source commit, tool, suite, and (for Playwright) suite fingerprint metadata. Direct CLI runs retain browser artifacts but do not automatically acquire those labels. All SQL, users/passwords, session cookies, uploaded files, inventories, and reports stay in ignored `tests/upgrade/.local`. They can contain copied private content; do not publish them. Stop the local servers with Ctrl+C when finished.

## Coverage and limits

`inventory.php` discovers enabled menu entries recursively across every menu, active languages, all node bundles and content entity types. `upgrade-navigation.spec.ts` visits the discovered internal destinations and language home/login pages. External links, placeholder links and administrative action URLs are recorded as skipped with an explicit reason. It records the removed Drupal 11 RSS settings form as an expected 404 and checks the site's existing Workflow denial as 403. Destination checks complement the existing browser navigation tests; they do not assert that every administrative menu is visibly expanded at once.

`upgrade-entities.spec.ts` exercises browser creation, read, edit and deletion of all node bundles, wiki pages, users, terms, all media bundles (including actual file uploads), custom blocks and menu entries. Story paragraphs are created through the story form. `upgrade-workflows.spec.ts` covers search, the contact Webform, comments, team membership and personal account pages. Existing tests add role sessions, dashboard filtering and sorting, maps, album workers, cloning, labels/PDF, revisions, wiki ordering, gallery interaction, translations, responsive views and theme behavior.

Internal entities such as geocoder caches, legal acceptance, search tasks, file usage and paragraphs are created through their owning operations rather than through invented administration forms. An inventory entry alone is not proof that its workflow passed. The optional V2 migration suite is kept separate because it needs another private source database.

The report and migration record list remaining baseline defects and verification limits. This suite cannot establish coverage of unknown business rules or prove absence of every possible regression.

## Required change validation

Follow [tests/AGENTS.md](../AGENTS.md) and the [regression-testing skill](../../.agents/skills/regression-testing/SKILL.md). Create/adapt relevant tests, execute the affected regression suites, and inspect their reports before claiming a change is verified. Missing tools or an unprepared test database leave validation incomplete.
