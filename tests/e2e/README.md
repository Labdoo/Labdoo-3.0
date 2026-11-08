# Labdoo E2E

Browser coverage for the 38 MEJORAS cards (translations excluded), plus baseline regression and responsive checks. Each feature test carries a Todoist task annotation; `support/coverage.py RUN_ID` checks that every card has successful browser coverage.

The integration branch contains all feature branches based on `development` at `1582fd13`, plus corrections discovered by these tests. It is a review branch, not a deployment branch.

## Isolation

Run only in the dedicated `.e2e-lab` worktrees and cloned databases. Never point this harness at a public or production site.

- Baseline: http://127.0.0.1:18380, database `labdoo_e2e_base_20261106`.
- Integration: http://127.0.0.1:18381, database `labdoo_e2e_int_20261106`.
- Dedicated database account has grants only on these clones. Guarded Drush checks the database prefix, Drupal root, and E2E settings before every command.
- HTTP responses must carry the matching `X-Labdoo-E2E` header.
- Mail uses Drupal's test collector; geocoding uses deterministic local responses. Backend external HTTP is blocked. Browser external writes are blocked; QR/tile images use placeholders. External static library/font GET requests are allowed.
- V2 connections enforce session read-only mode. `support/migrate-address.php` requires a populated V2 source; it creates a separate dootronic only in the isolated V3 database. `map-address-contract.php` exercises the actual mapping configuration, mapper, special field service, persistence and browser rendering using controlled source values. This contract check does not certify an entire V2 import.
- Generated accounts, cookies, SQL snapshots, credentials, uploaded files and reports remain in ignored private state. Do not commit them or publish the HTML reports without reviewing their contents.

## Prerequisites and execution

The local orchestrator scripts live in `.e2e-lab/tools`; sanitized, guarded copies are in `support/local-lab`. Copy `support/cards.json` to `.e2e-lab/state/cards.json` when reconstructing task annotations. Both worktrees require independent vendor, core, contrib, theme and uploaded-file copies. Symlinking vendor/core to the original checkout can make Drush bootstrap the original site and is prohibited.

From this directory, after preparing the clones and fixtures:

```sh
npm ci
E2E_RUN_ID=review npm test -- --project=baseline --project=integration --project=mobile
python3 support/coverage.py review
```

Chrome is configured as `/usr/bin/google-chrome`. Baseline runs critical existing routes, permissions and edit forms. Integration additionally tests all cards, including real form submissions, clone/update JSON feedback, album deletion and stale queues, every sortable dashboard column in both directions, wiki weight persistence and book navigation, gallery pagination, and label PDF generation. Mobile repeats the responsive checks at 390×844. Authentication accepts the local legal form and requires a real session cookie for each of seven roles on each clone.

All projects use one worker, no retries, and retain failure screenshots/traces. Reports are written to `.e2e-lab/state/runs/RUN_ID`, including `playwright-report/index.html`, `playwright-results.json`, and the generated card matrix. Only the isolated fixtures are mutated. Album tests also execute the real gallery worker and verify that the original school and its gallery survive.

The album lifecycle check warms the cloned school's actions block before its gallery exists, runs the gallery worker, then reloads in the same administrator session and follows **Go to photo album** to verify it resolves to the new gallery. This covers cache invalidation as well as entity creation; no cache rebuild is performed between those steps.

Tests require the base snapshots and account fixture JSON to remain available; they do not embed credentials or silently create a database connection. To recreate the lab, inspect the local-lab scripts, supply the source connection privately, then clone, prepare sites, import configuration, enable the test module, harden and seed each clone. The scripts are specific to this workspace and intentionally refuse ambiguous state.

## Real V2 address verification

The optional `legacy-v2` project reads a dedicated V2 database populated from a private SQL dump. It is excluded from the regular integration project; select `legacy-v2` when running against the private V2 clone. Prepare its database using `support/local-lab/prepare-v2-import.py` and import the dump with `support/local-lab/import-v2.py`; `LABDOO_E2E_V2_DUMP` can override the default local dump path. Both scripts require the private connection exported to `.e2e-lab/state/external-connection.json` and use the local V2 Docker instance on port 3317. The importer can write only its dedicated database; the migration reader has only SELECT permissions and enforces read-only sessions.

```sh
E2E_RUN_ID=real-v2 npm test -- --project=legacy-v2
```

The test temporarily supplies this source connection inside guarded Drush only. It uses the actual source repository, address mappings and destination repository, checks persisted components against independent SQL results, then checks the rendered node pages. Samples cover different countries and missing postal codes/streets. The real addresses stay in private state and browser artifacts. This verifies the address migration flow, not an import of every V2 content type.

`dashboard-search.spec.ts` guards the former dootronics search 404. It checks the filtered device in the master, table and map displays, public ID search with sorting and navigation, empty results, and submitting/clearing the exposed browser form. Run it with `npm test -- dashboard-search.spec.ts --project=integration`.

`revision-access.spec.ts` prepares a separate dootrip with two revisions. Anonymous and basic users can read its history and historical revision, see no revert/delete links, and receive HTTP 403 on both action routes. An administrator retains the controls and can open the confirmation form without submitting it; a fresh anonymous browser context then checks that those controls remain hidden after the administrator's visit. Run it with `npm test -- revision-access.spec.ts --project=integration`.

`gallery-lightbox.spec.ts` checks the modal photo viewer in desktop and mobile: original image loading, previous/next and arrow keys, Escape with focus restoration, button/backdrop closing, and the three-photo final page. A separate case verifies original links with JavaScript disabled. To also repeat the existing gallery pagination, parent link and edit form checks, run `npm test -- gallery-lightbox.spec.ts visual-and-navigation.spec.ts --project=integration --project=mobile --grep gallery`.

`dootrip-assigned-counter.spec.ts` covers older imports whose assigned-device counter is empty. It creates isolated dootrips with zero/one linked device, removes only their counter field rows to model that legacy state, and verifies the rendered value and its position between capacity and weight. It also checks existing dootrip 16086 without changing it. The display fallback uses the linked-device count only when the stored counter is empty and never saves the node.

## Deployment of the validated improvements

The 38 reviewed feature branches and the dootronics search filter fix are integrated together. Translations remain excluded. After deploying the code, run the site's normal database update and configuration import steps, then rebuild caches:

```sh
vendor/bin/drush updatedb -y
vendor/bin/drush config:import -y
vendor/bin/drush cache:rebuild
```

The update hook installs the wiki page order field; configuration enables the revised displays, gallery formatter and permissions. Existing missing dootronic addresses require rerunning the address field migration from V2 separately. The E2E database/settings and SQL dump remain local test artifacts.
