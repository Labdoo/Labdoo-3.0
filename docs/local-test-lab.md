# Local Labdoo test lab

The E2E, k6 and Lighthouse runners use `./tests` and the application code in the active checkout. The local server selects a separate Drupal site at `web/sites/labdoo_e2e`, with its own restricted database clone, uploads and private state. The default site's settings and database are not used by the test processes.

The server returns a hashed checkout identity that the local runners verify before accepting results. Restart `make e2e-serve` from this checkout if another server is using port 18411.

The web dashboard offers the local clone and any extra targets listed in the
root `.env` file. Remote targets must use HTTPS and run only the read-only
Playwright smoke suite. Add an environment as follows:

```dotenv
LABDOO_TEST_ENVIRONMENTS=local,staging
LABDOO_TEST_ENV_STAGING_NAME=Staging
LABDOO_TEST_ENV_STAGING_URL=https://staging.example.org
LABDOO_TEST_ENV_STAGING_MODE=readonly
LABDOO_TEST_ENV_STAGING_ALLOW_LIGHTHOUSE=1
LABDOO_TEST_ENV_STAGING_ALLOW_K6=0
```

Optional `LABDOO_TEST_ENV_STAGING_HTTP_USER` and
`LABDOO_TEST_ENV_STAGING_HTTP_PASSWORD` support HTTP Basic Authentication for
the read-only Playwright and k6 requests. Lighthouse remains disabled for
Basic Auth targets to avoid forwarding its authorization header to third-party
assets.
The dashboard never accepts a free-form URL. k6 remains disabled for remote
targets unless explicitly enabled because its load profile creates sustained
traffic. Keep credentials in the ignored `.env` file.

Prepare the lab once using [tests/upgrade/README.md](../tests/upgrade/README.md). Then, from `/var/www/html/labdoo3`:

| Command | Purpose |
| --- | --- |
| `make e2e-serve` | Serve the isolated Drupal clone on `127.0.0.1:18411`; keep this terminal open. |
| `make e2e` | Run the Playwright E2E suite. Use `E2E_ARGS="--grep ..."` to filter. |
| `make e2e-dashboard` | Start the local dashboard on `127.0.0.1:18412`. |
| `make k6` | Run the existing 13 minute k6 load profile against the isolated clone. |
| `make lighthouse` | Run mobile and desktop Lighthouse audits on the standard routes. |
| `make e2e-deps` | Install the Playwright dependencies in this checkout. |

The k6 target uses the test clone by default; the dashboard permits a remote
target only when `ALLOW_K6=1` is set for that named HTTPS environment in `.env`.
Its five routes and p95/error thresholds are retained from the previous
`labdoo-load.js`. Lighthouse and k6 outputs are written under
`tests/performance/.local/`.

The Playwright target requires the isolated site and fixtures to be prepared and this checkout's Drupal server to be running. `LAB_ROOT` resolves to the current directory; routine commands do not select a hidden upgrade worktree. `make tests` remains the PHPUnit target where its toolchain is installed.

See [the regression-testing skill](../.agents/skills/regression-testing/SKILL.md) for mandatory coverage and report-review requirements.
