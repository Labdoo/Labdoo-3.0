# Local Labdoo test lab

The E2E, k6 and Lighthouse runners use `./tests` and the application code in the active checkout. The local server selects a separate Drupal site at `web/sites/labdoo_e2e`, with its own restricted database clone, uploads and private state. The default site's settings and database are not used by the test processes.

The server returns a hashed checkout identity that the local runners verify before accepting results. Restart `make e2e-serve` from this checkout if another server is using port 18411.

The web dashboard and all test runners support only this isolated local clone.
They do not accept remote targets or credentials.

Prepare the lab once using [tests/upgrade/README.md](../tests/upgrade/README.md). Then, from `/var/www/html/labdoo3`:

| Command | Purpose |
| --- | --- |
| `make e2e-serve` | Serve the isolated Drupal clone on `127.0.0.1:18411`; keep this terminal open. |
| `make e2e` | Run the Playwright E2E suite. Use `E2E_ARGS="--grep ..."` to filter. |
| `make e2e-dashboard` | Start the local dashboard on `127.0.0.1:18412`. |
| `make k6` | Run the existing 13 minute k6 load profile against the isolated clone. |
| `make lighthouse` | Run mobile and desktop Lighthouse audits on the standard routes. |
| `make e2e-deps` | Install the Playwright dependencies in this checkout. |

The k6 target is restricted to the isolated test clone. Its five routes and
p95/error thresholds are retained from the previous
`labdoo-load.js`. Lighthouse and k6 outputs are written under
`tests/performance/.local/`.

The Playwright target requires the isolated site and fixtures to be prepared and this checkout's Drupal server to be running. `LAB_ROOT` resolves to the current directory; routine commands do not select a hidden upgrade worktree. `make tests` remains the PHPUnit target where its toolchain is installed.

See [the regression-testing skill](../.agents/skills/regression-testing/SKILL.md) for mandatory coverage and report-review requirements.
