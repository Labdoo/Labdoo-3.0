# Developer onboarding validation — 2026-11-20

Task: `6j3hXxMFVrmr9w9V`. This record covers the English developer/agent guide,
its entry points and DDEV configuration. Application behavior is unchanged.

## Published scope

- `docs/developer-guide.md`: DDEV and LAMP setup, private configuration, approved
  datasets, environment checks, module map, daily work, isolated tests, agent
  setup, review and deployment boundaries.
- README and INSTALLATION point to that guide. Earlier development notes and the
  DDEV ADR retain their historical content with current-workflow references.
- `.ddev/config.yaml`: Drupal 11, PHP 8.3, MySQL 8.0, Composer 2, DDEV >= 1.25.5,
  loopback bindings and explicitly disabled automatic settings management.
- The Gitpod MTU override is an inactive `.yaml.example`; its opt-in `.yaml` and
  local/generated settings are ignored. Private file storage is already ignored.

## Runtime and dependency evidence

The active source checkout is `development` at `d714a9dd` with preserved work;
publication starts at deployed application revision `c05ff188`. Only the reviewed
documentation/configuration paths are included. The shared default site's
settings and unrelated staging are preserved.

The earlier DDEV installation reported 1.24.7; the installation present during
this validation is **1.25.5**. The official release archive and the executable
extracted from it were compared with the installed binary:

- Archive SHA-256: `dff7c63e9a64d7d4ca7a93a35fcfa15fa67d6402ecf95a37d645fb2a98a4f30c`.
- Installed binary SHA-256: `5cfbbd24829a539f53ca96b6c30dd762aa247b473d38e6352ae05871b61612c6`.
- [Official DDEV release](https://github.com/ddev/ddev/releases/tag/v1.25.5).

Validation used the dedicated project `labdoo-onboarding-check` in a temporary
copy, with a project-name/port override (HTTP 18480, HTTPS 18443). Its Composer
manifest, lockfile and DDEV base configuration match the active checkout.
Docker commands selected the native Linux daemon explicitly through
`DOCKER_HOST=unix:///var/run/docker.sock`; the global Docker context remains
`desktop-linux`. Other projects were not restarted or reconfigured.

| Component | Observed result |
| --- | --- |
| DDEV | 1.25.5 |
| Native Docker Engine | 29.9.3, Ubuntu host |
| PHP CLI and FPM in DDEV | 8.3.36 |
| MySQL server in DDEV | 8.0.46 |
| Drupal installed and locked | 11.4.13 |
| Installed packages versus lockfile | All 205 match |
| Composer platform requirements | Passed |
| Final DDEV restart | Passed; web and DB healthy |
| Final Compose render | Inactive MTU example excluded |
| Drupal bootstrap / database | Successful / Connected, database `db` |
| Loopback HTTP | 200 |
| HTTPS | 200; local CA and hostname verified without disabling verification |

`ddev version` also displays a default MariaDB image. That generic default is
not the project's selected database; the actual MySQL server was checked with
`SELECT VERSION(), DATABASE()`.

## Executed checks

All DDEV commands below selected the native daemon and the dedicated temporary
project, not the shared LAMP site's database:

```sh
ddev version
ddev exec php --version
ddev exec php-fpm8.3 --version
ddev composer check-platform-reqs
ddev mysql -N -e 'SELECT VERSION(), DATABASE();'
ddev restart
ddev debug compose-config
ddev drush --uri=http://labdoo-onboarding-check.ddev.site:18480 status --fields=drupal-version,php-version,root,uri,db-status,bootstrap
```

Before the runtime installation, the selected Docker project/root, container
connection, empty `db` schema and local mail controls were asserted. A **minimal
Drupal** profile was installed in that empty disposable database. Generated
account credentials remained in a private log, never command arguments or this
record. The final configuration was restarted and successful bootstrap checked
again. HTTP and HTTPS were then verified against the loopback origin.

The guide's PHP settings example passes `php -l`. Its Apache virtual-host example
passes a standalone `apache2 -t` check after substituting the checkout path and
loading the required installed modules; no host Apache configuration was installed
or reloaded. All 35 Markdown relative paths/anchors and 23 external references pass review.
The obsolete Codex best-practices URL was corrected. YAML, TOML examples and the
focused diff pass review. Router port bindings were confirmed on 127.0.0.1.
The disposable project was stopped after verification, preserving its database.

Codex CLI 0.170.0 `--help` and login help were inspected against official
configuration/authentication documentation. No sign-in or agent execution was
performed. The guide remains usable by agents that read Markdown explicitly.

## Limits and private evidence

The minimal Drupal rehearsal verifies the container runtime, locked dependencies,
settings, database connection and HTTP/FPM stack. It is **not a full Labdoo dataset
import or a container-only certification of the application test suite**. A new
developer must obtain an approved sanitized Drupal 11 snapshot and matching files
privately. Configuration import/schema updates against that dataset remain
separate reviewed operations. No production data, mail, paid provider or live
queue was used for this rehearsal.

The maintained application suites run on the host's guarded clone at 18411.
Their separate application validation is recorded in
[the translation latency report](../../tests/performance/TRANSLATION-LATENCY-20261120.md).
Legacy Gitpod prebuild automation was not adapted or certified; its site-install
script remains outside the validated manual setup paths.

Fresh browser/load/audit runs would not add behavioral evidence for these prose
and DDEV-only changes; the actual DDEV lifecycle and documented configuration
examples were checked instead. PHPCS, PHPStan and PHPUnit remain unavailable.

Private logs, generated credentials, Compose output, HTTP responses and runtime
summaries stay in ignored `tests/performance/.local/onboarding-20261120`. The
resulting commit and v3 runtime outcome are recorded in the task after deployment.
