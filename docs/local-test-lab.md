# Local Labdoo test lab

The E2E and performance tools use the isolated Drupal 11 worktree at
`.e2e-lab/drupal11`. It has a dedicated database clone and local-only runners;
it does not run against the active checkout's database.

Prepare the lab once using the instructions in the isolated worktree's
`tests/upgrade/README.md`. Then, from `/var/www/html/labdoo3`:

| Command | Purpose |
| --- | --- |
| `make e2e-serve` | Serve the isolated Drupal clone on `127.0.0.1:18411`; keep this terminal open. |
| `make e2e` | Run the Playwright E2E suite. Use `E2E_ARGS="--grep ..."` to filter. |
| `make e2e-dashboard` | Start the local dashboard on `127.0.0.1:18412`. |
| `make k6` | Run the existing 13 minute k6 load profile against the isolated clone. |
| `make lighthouse` | Run mobile and desktop Lighthouse audits on the standard routes. |
| `make e2e-deps` | Install the Playwright dependencies in the isolated worktree. |

The k6 target is restricted to the test clone origin (`http://127.0.0.1:18411`);
its five routes and p95/error thresholds are retained from the previous
`labdoo-load.js`. Lighthouse and k6 outputs are written under
`.e2e-lab/drupal11/tests/performance/.local/`.

Override `LAB_WORKTREE` if the upgrade worktree lives elsewhere. The Playwright
target expects the clone and its inventory to have already been prepared, and
the Drupal server to be running. The existing `make tests` target continues to
run PHPUnit.
