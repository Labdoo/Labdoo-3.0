# Developing Labdoo

This guide is for developers and coding agents. Labdoo is a Drupal application;
most domain objects are node bundles, while the Wiki has its own entity. Read
[AGENTS.md](../AGENTS.md), [the workflow](agent-workflow.md) and
[TECHNICAL.md](../TECHNICAL.md) before changing behavior.
The active branch, lockfile and inspected code define the current system.

Read this guide in order for a first setup, or jump to
[DDEV](#ddev-path), [LAMP](#lamp-path), [the code map](#find-your-way-around-the-code),
[regression tests](#prepare-and-use-the-regression-laboratory),
[coding agents](#set-up-a-coding-agent), or
[troubleshooting](#common-setup-problems).

## Choose an isolated development environment

Start with a fresh clone and a dedicated local database. A directory called
`local` can still use live credentials. Obtain an approved sanitized database and
matching public files through the team's private channel; Git contains neither.
Use controlled mail, geocoding and translation providers. Avoid running cron,
queue workers or migrations against an imported production dataset until those
effects have been isolated.

DDEV is the container option. LAMP is supported by the maintained local test
laboratory. The historical DDEV ADR predates the current loopback lab and is not
proof that every current test runs inside a container.

The reviewed checkout locks Drupal 11.4.13 and requires PHP 8.3. Read the actual
`composer.lock` and run platform checks when onboarding rather than assuming this
version remains current. The database target is MySQL 8.0. Frontend tests need
Node, npm, Chrome, Python and the tooling listed in `tests/upgrade/README.md`;
performance suites also need k6 and Lighthouse. Check the lockfiles for pinned
frontend versions. Do not update dependencies as a substitute for installing the
locked versions.

## Before you start

Use the team's repository URL and the branch assigned to your task. For a new
onboarding checkout, the maintained integration branch is `development`:

```sh
git clone --branch development https://github.com/Labdoo/Labdoo-3.0.git labdoo
cd labdoo
git status --short --branch
```

Ask the maintainer for an approved sanitized **Drupal 11** database snapshot,
matching public uploads and any required private-file fixtures. Do not obtain
credentials by copying a production settings file. The Git checkout supplies
code and `config/sync`; it does not supply the Labdoo dataset. A standard Drupal
installation is useful for a runtime check but is not a populated Labdoo site.

The older README referred to `.scripts/dev-start.sh`, which is absent, and
`config/default`, which is not this branch's configuration directory. The old
installation guide also listed PHP 8.1 and MySQL 5.7. Those instructions do not
apply to this Drupal 11 branch; use the paths and checks below.

The existing `.gitpod.yml` and `scripts/dev-setup.sh` retain legacy bootstrap
automation. That script installs a new site and forces a configuration UUID; it
is not the manual DDEV/LAMP path validated here. Do not run it on an imported
Labdoo dataset. Gitpod automation needs its own adaptation to the explicit local
settings used below; it is outside this guide's validated environments.

## DDEV path

### Install or update DDEV

Install Docker and DDEV using the official [platform-specific installation
instructions](https://docs.ddev.com/en/stable/users/install/ddev-installation/).
For an existing installation, follow the [upgrade
instructions](https://docs.ddev.com/en/stable/users/install/ddev-upgrade/), using the
same package manager or installation method. Check `which -a ddev` if an older
binary shadows the updated one. Avoid upgrading databases or deleting existing
DDEV volumes as part of a CLI update.

```sh
docker version
docker context show
ddev version
ddev list
```

With Docker Desktop, keep the checkout in an allowed shared directory or share
its parent through **Settings → Resources → File sharing** before starting.
A `mounts denied` error for `/tmp` or another directory is a host sharing issue;
it is not fixed by changing Drupal settings. See [Docker's settings
reference](https://docs.docker.com/desktop/settings-and-maintenance/settings/).
On Linux, Docker Engine is another option. If both are installed, select the
intended daemon consistently for all DDEV commands; images and volumes belong
to that daemon. Avoid changing the global Docker context during another task.

For example, when Linux Docker Engine is already installed and accessible,
select it for the current terminal before running the DDEV steps:

```sh
export DOCKER_HOST=unix:///var/run/docker.sock
docker version
```

Use that same terminal/environment for subsequent DDEV commands, including
`ddev stop`. Open another terminal for unrelated Docker Desktop projects.

For a dedicated Linux shell using the native Docker Engine, the validated
alternative on this workstation is:

```sh
export DOCKER_HOST=unix:///var/run/docker.sock
docker info --format '{{.Name}} | {{.OperatingSystem}}'
```

Keep that daemon selection for every DDEV command in that shell. Close the shell
or unset `DOCKER_HOST` to return to your configured context. Do not move databases
or reuse volumes between the native daemon and Docker Desktop implicitly.

The maintained `.ddev/config.yaml` requires DDEV **1.25.5 or later**, selects
Drupal 11, PHP 8.3, MySQL 8.0, Nginx/FPM and Composer 2. It uses the current
`database: {type, version}` syntax. The previous `mysql_version`,
`mariadb_version` and `nfs_mount_enabled` options have been removed. Check the
[configuration reference](https://docs.ddev.com/en/stable/users/configuration/config/)
when changing this file. Switching a project's database engine/version requires
its own export and migration; do not change it under an existing volume.

The old Gitpod-specific MTU override is now an inactive
`.ddev/docker-compose.network-mtu.yaml.example`. Copy it to the `.yaml` filename
only in an environment that actually needs MTU 1440; it is not a default for
local Docker networks.

DDEV settings management is **disabled deliberately**: starting a container must
not rewrite the tracked `web/sites/default/settings.php` or an existing host
configuration. Use a separate clone from a LAMP site and create the ignored
`web/sites/default/settings.local.php` before bootstrapping Drupal:

```php
<?php

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('This local site must run inside DDEV.');
}

$databases['default']['default'] = [
  'database' => 'db',
  'username' => 'db',
  'password' => 'db',
  'host' => 'db',
  'port' => '3306',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'prefix' => '',
];
$settings['trusted_host_patterns'] = ['^labdoo3\\.ddev\\.site$'];
$settings['config_sync_directory'] = dirname($app_root) . '/config/sync';
$settings['file_private_path'] = '/var/www/html/private/files';
$settings['hash_salt'] = 'REPLACE_WITH_A_RANDOM_LOCAL_SALT';

// Disable request-triggered cron and capture Drupal mail locally.
$config['automated_cron.settings']['interval'] = 0;
$config['system.mail']['interface'] = ['default' => 'test_mail_collector'];
$config['mailsystem.settings']['modules'] = [];
$config['mailsystem.settings']['defaults'] = [
  'sender' => 'test_mail_collector',
  'formatter' => 'test_mail_collector',
];
$config['geocoder.settings']['geocoder_presave_disabled'] = TRUE;
```

Generate the salt with `openssl rand -hex 32` and paste it into this private
file. The `db` credentials above are DDEV's local development defaults; never use
them for a shared service. If you change the DDEV project name, update the
trusted hostname too. Create `private/files` outside `web`, with access limited
to your local runtime. The committed `settings.php` already includes
`settings.local.php` at the end.

If host Apache already uses ports 80/443, create the ignored
`.ddev/config.local.yaml` before starting:

```yaml
router_http_port: "18480"
router_https_port: "18443"
```

Use the same router ports as other running DDEV projects on that daemon. Keep
this machine-specific override local.

Start containers and install the locked code:

```sh
ddev start
ddev composer install
ddev composer check-platform-reqs
ddev describe
```

Set a task-specific URI variable to the actual project URL shown by
`ddev describe`, including any custom port. The following example assumes the
default project name and HTTPS port:

```sh
export LABDOO_LOCAL_URI=https://labdoo3.ddev.site
```

Pass it explicitly to Drush: settings management is disabled, so Drush may
otherwise report `http://default` or generate an unusable login link.

There are no automatic site-install, database-update or configuration-import
hooks. DDEV mail capture does not intercept SDK calls to Amazon SES, other HTTP
providers or translation CLIs. Import only sanitized data without live provider
credentials; do not enable host/container cron schedules or queue workers. For
work that invokes those integrations, use the guarded regression lab described
below, which replaces external services and blocks outgoing backend HTTP.

### Populate and verify the local site

Import the approved snapshot and uploads into this **new local project**:

```sh
ddev import-db --file=/absolute/private/path/labdoo-sanitized.sql.gz
ddev import-files --source=/absolute/private/path/public-files
```

The files source must contain the contents of `sites/default/files`, not the
whole Drupal checkout. Read [DDEV asset
imports](https://docs.ddev.com/en/stable/users/usage/managing-projects/) for archive
layouts. Importing replaces the project's database/files; use it only for the
local destination you intend to populate.

```sh
ddev drush --uri="$LABDOO_LOCAL_URI" status --fields=drupal-version,php-version,root,uri,db-status,bootstrap
ddev drush --uri="$LABDOO_LOCAL_URI" sql:query 'SELECT DATABASE();'
```

Expect the root `/var/www/html/web`, the container database `db` and successful
bootstrap. Confirm that the imported schema matches the locked code before
continuing. On this identified disposable copy, review and apply pending changes:

```sh
ddev drush --uri="$LABDOO_LOCAL_URI" updatedb:status
ddev drush --uri="$LABDOO_LOCAL_URI" config:status
ddev drush --uri="$LABDOO_LOCAL_URI" updatedb -y
ddev drush --uri="$LABDOO_LOCAL_URI" config:import -y
ddev drush --uri="$LABDOO_LOCAL_URI" cache:rebuild
ddev launch
```

Review the configuration diff first; stop if it unexpectedly removes extensions
or fields. Do not bypass a site UUID or schema error to force an incompatible
snapshot to import. A database from Drupal 7 needs the migration workflow, not
`import-db` followed by a Drupal 11 bootstrap. Use `ddev drush --uri="$LABDOO_LOCAL_URI" user:login` locally
when needed; its output is a private login link. Finish a session with `ddev stop`;
this preserves the local database.

To debug PHP, run `ddev xdebug on`, configure the IDE for port 9003 and map
`/var/www/html` to your checkout; turn it off for timings with `ddev xdebug off`.

## LAMP path

### Provision a dedicated local runtime

Use Apache 2.4, PHP-FPM 8.3, Composer 2 and MySQL 8.0. The locked core also supports
MariaDB 10.6+, but the current test harness targets MySQL. Install packages with
your distribution's package manager after checking available versions; package
names and FPM sockets vary by OS. PHP needs PDO/MySQL, XML/DOM, GD, cURL,
mbstring, OpenSSL and the extensions required by `composer check-platform-reqs`.
Check CLI and FPM separately. Redis is optional for a basic local site; do not
copy Redis settings unless you have provisioned its local service and backend.

```sh
php --version
composer --version
mysql --version
composer install
composer check-platform-reqs
```

Create a virtual host whose document root is this clone's `web` directory:

```apache
<VirtualHost 127.0.0.1:80>
    ServerName labdoo3.local
    DocumentRoot /absolute/path/to/labdoo/web
    <Directory /absolute/path/to/labdoo/web>
        AllowOverride All
        Require local
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
```

Adjust the absolute path and FPM socket, enable Apache's rewrite, proxy and
proxy_fcgi modules, and map `labdoo3.local` to `127.0.0.1` in the hosts file.
Validate with `apachectl configtest` before reloading Apache. Keep databases,
private files, settings, dumps and logs outside publicly served paths. Grant the
runtime write access only to the required upload/private directories; never use
`chmod 777` or the repository's recursive permission target for onboarding.

Using an interactive MySQL administrator session, create a local database and
restricted account (replace the example password privately):

```sql
CREATE DATABASE labdoo_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'labdoo_dev'@'127.0.0.1' IDENTIFIED BY 'REPLACE_PRIVATELY';
GRANT ALL PRIVILEGES ON labdoo_dev.* TO 'labdoo_dev'@'127.0.0.1';
```

Create ignored `web/sites/default/settings.local.php` using the DDEV example
above, with these changes: remove the `IS_DDEV_PROJECT` guard, set the database,
username and password to this local account, use `host => '127.0.0.1'`, trust
`^labdoo3\\.local$`, and use an absolute private-file path outside `web`.
Retain the salt, `config/sync`, mail capture and cron/geocoder overrides. Do not
add an `external` migration connection unless the task needs an isolated source.

Import the approved snapshot with the restricted local account. The password
prompt avoids exposing credentials in shell history:

```sh
mysql --host=127.0.0.1 --user=labdoo_dev --password labdoo_dev < /absolute/private/path/labdoo-sanitized.sql
```

Copy approved public files into `web/sites/default/files` and set scoped runtime
permissions. Confirm the destination before updates:

```sh
vendor/bin/drush --uri=http://labdoo3.local status --fields=drupal-version,php-version,root,uri,db-status,bootstrap
vendor/bin/drush --uri=http://labdoo3.local sql:query 'SELECT DATABASE();'
```

Expect this clone's `web` root and `labdoo_dev` database. Then review/apply the
same `updatedb:status`, `config:status`, `updatedb`, `config:import` and
`cache:rebuild` steps above using `vendor/bin/drush` in place of `ddev drush`.
Open `http://labdoo3.local` and inspect PHP-FPM/Drupal logs. Use the Composer
Drush binary, rather than a potentially incompatible global Drush.

## Common setup problems

| Symptom | Check and next action |
| --- | --- |
| DDEV version constraint fails | Update the CLI; inspect `which -a ddev` for duplicate installations. |
| DDEV reports obsolete configuration | Check local override files as well as `.ddev/config.yaml`; use the current `database` syntax. |
| Web/DB containers do not become healthy | Inspect `ddev logs` / `ddev logs -s db`, Docker resources and existing volumes; do not delete another project's state. |
| Drupal cannot connect | Check the private local settings: `db:3306` inside DDEV, your dedicated connection on LAMP. |
| HTTP 400 / untrusted host | Match `trusted_host_patterns` to the hostname from `ddev describe` or the Apache vhost. |
| Composer reports platform errors | Compare the locked requirements with actual container/host PHP and extensions; do not use `--ignore-platform-reqs`. |
| Configuration UUID/schema mismatch | Obtain a compatible snapshot or follow the reviewed fresh-install/migration procedure; do not force an import. |
| Missing E2E connection manifest | Prepare the lab in this checkout; a working default development site does not prepare its test clone. |
| CSS changes do not appear | Check the selected theme, its build task and Drupal caches; preserve realistic aggregation for performance audits. |

## Find your way around the code

| Path | What to look for |
| --- | --- |
| `web/modules/custom` | Domain modules, routes, services, hooks, queue workers and Drush commands. |
| `web/themes/custom/labdoo_next` | Default public theme in `config/sync/system.theme.yml`; extends `labdoo`. |
| `web/themes/custom/labdoo` | Base theme and its Sass/Gulp sources. |
| `config/sync` | Reviewed Drupal configuration exported for this branch. |
| `translations/custom` | Maintained custom interface translations. |
| `patches` and `composer.json` | Patches applied during locked Composer installation. |
| `tests/e2e`, `tests/upgrade`, `tests/performance` | Browser tests, isolated-site tooling, load and audit runners. |
| `.agents/skills` | Reusable development workflows for humans and agents. |

For example, a dootronic change usually starts in `labdoo_dootronics`: inspect
its `.routing.yml` and `.services.yml`, then
`src/Service/Repository/DootronicRepository.php`, hooks in
`labdoo_dootronics.module` and the relevant compute/queue services. Trace access,
revisions, notifications and cache invalidation before saving an entity. The
bundle is named `laptop` in configuration; a domain term need not be its storage
name. See [TECHNICAL.md](../TECHNICAL.md) for the wider domain map, then verify the
actual implementation.

## Daily changes

```sh
git status --short --branch
git diff
git diff --cached
```

Preserve unrelated work and staging. Inspect the affected module's routing,
service definitions and repository before adding behavior. Most custom services
live under `web/modules/custom`; layouts differ, so follow the actual module.
Read the corresponding `.agents/skills/*/SKILL.md` for architecture, coding,
translations, queues, migrations, Git or deployment work. In particular, keep
interface text translatable with Drupal's PHP/Twig/JavaScript APIs and preserve
cache tags, contexts and max-age; see [coding standards](../.agents/skills/coding-standards/SKILL.md)
and [multilingual guidance](../.agents/skills/multilingual/SKILL.md). Source comments and
technical documentation are English; interface strings remain translatable.

Next CSS is maintained directly in
`web/themes/custom/labdoo_next/css/labdoo-next.css`. Its optional chart/image
builds are documented in `web/themes/custom/labdoo_next/build/README.md`.
The original Labdoo theme compiles Sass with its `compileSass` Gulp task; its
default Gulp task starts a watcher. Its filename is `Gulpfile.js` (capital G). The generic Makefile defaults `THEME_NAME`
to another theme, so inspect the task instead of assuming it targets Next.

## Prepare and use the regression laboratory

The application's default development site and the test clone are separate.
The maintained harness creates `web/sites/labdoo_e2e`, a restricted clone database
and private state under `tests/upgrade/.local`. It verifies the checkout identity
before testing. Follow `tests/upgrade/README.md` for the approved source JSON,
MySQL administrator defaults file and file-source prerequisites; do not invent
credentials or reuse state from another worktree.

```sh
npm ci --prefix tests/e2e
make e2e-serve
```

Keep the server in its own terminal. The isolated Drupal origin is
`http://127.0.0.1:18411`. In another terminal:

```sh
make e2e LAB_RUN_ID=my-change-focused E2E_ARGS="relevant.spec.ts --project=desktop --project=mobile"
```

Use an existing fixture or guarded helper for mutations. Add a failing regression
case before a bug fix where practical. Select shared-flow/full coverage using
`.agents/skills/regression-testing/SKILL.md`, and inspect JSON, HTML and failures.
Do not use `--no-deps` without valid authentication state. The current projects
are desktop/mobile/auth; old integration/baseline examples are historical.

The maintained browser configuration uses host Google Chrome at
`/usr/bin/google-chrome`; Node/npm, Python, PHP, MySQL, ffmpeg, k6 and Lighthouse
must also be available where the host lab runs. DDEV's container site is not the
host loopback test site: the current harness is validated on host Linux/LAMP.
Do not point the suite at a DDEV URL or copy its database manifest to make the
guards accept it. Use [the lab preparation guide](../tests/upgrade/README.md) to
build a separate host clone. This guide does not claim a container-only test
harness is supported.

Use `make k6` for changes to request handling, caching, queries or expected load,
and `make lighthouse` for rendering/assets/navigation/perceived performance.
Inspect the reports and compare equivalent conditions. Do not run browsers and
load/audit measurements concurrently or change application source during them.
Never point these maintained load suites at v3 or production.

PHPCS, PHPStan and PHPUnit are intended checks but are not installed by the
current Composer development manifest. Report that limitation honestly; a
Makefile target or an unexecuted check is not a pass. The lab guide lists
standalone runner/isolation checks, and behavior tests still need real execution.

## Set up a coding agent

Use a separate branch/worktree for a focused change when the working tree is
shared. Give the agent the repository root, the concrete objective, constraints,
authorized operations and acceptance evidence. Make `AGENTS.md` its entry point;
the portable skills live in `.agents/skills`, not the retired `.junie` directory.

A useful agent loop is: inspect branch/environment; load relevant skills; inspect
services/access/cache/translation behavior; reproduce the bug; implement a
focused change; run and inspect required tests; review the exact diff; publish
only the authorized paths and operations. Require progress updates that separate
implemented, verified, published and deployed states. A queued test or a pushed
commit is not completed verification or deployment.

Give tools only the credentials/capabilities the task needs. Keep secrets in the
runtime's private configuration, not prompts, tracked files, command-line
arguments, reports or screenshots. Test fake translation agents and captured mail
instead of paid providers or real recipients. Agent installation/authentication
is separate from giving it permission to mutate live data or deploy.

### Example: Codex CLI

Install Codex using the instructions for your operating system on the
[official CLI page](https://learn.chatgpt.com/docs/codex/cli). The example below
uses flags checked against Codex CLI 0.170.0 on 2026-11-20. Recheck `--help` for
your installed release; this repository does not pin or install an agent client.

```sh
codex --version
codex login
codex --cd /absolute/path/to/your/labdoo-checkout --sandbox read-only --ask-for-approval on-request
```

Complete your organization's approved sign-in flow. Authentication does not grant
permission to deploy or change data. Start with a task such as:

> Read AGENTS.md and its referenced workflow. Identify the active branch,
> preserved changes, locked and installed runtimes, and the isolated test target.
> Explain the affected module and propose the smallest reproducible check.
> Do not modify application data or deploy.

For a first implementation task, give the agent a bounded request, for example:

> Fix the reported device-dashboard filter bug in this checkout. Read AGENTS.md
> and the relevant skills, preserve unrelated work, reproduce it in the isolated
> test site and implement the smallest fix. Run the affected desktop/mobile tests
> and any required performance checks; inspect the reports. Report the diff,
> tested revision and limits. Leave the result local for review.

For an approved implementation task, start Codex with `--sandbox workspace-write`
and the same approval policy. Describe the intended behavior, relevant task or
issue, tests and authorized destination. Start from an isolated branch/worktree
when other work is present. Review proposed commands that access a database,
credentials, network services or directories outside the workspace.

Codex reads repository `AGENTS.md` guidance and supports shared skills in
`.agents/skills`. Other agents should read that index explicitly if they do not
discover it automatically. Keep project rules in the repository and personal
preferences in the agent's user configuration. For Codex, trusted project
`.codex/config.toml` can supply project defaults, while `~/.codex/config.toml`
contains personal defaults; managed policies may impose additional limits.
A minimal personal configuration for this workflow is:

```toml
sandbox_mode = "workspace-write"
approval_policy = "on-request"
```

Use the client's permissions/status view to inspect the effective configuration.
Enable a connector or MCP server only when a task needs it, authenticate with the
appropriate account, and inspect the available tools before giving it an action.
The project's reusable skills do not require Todoist, AWS or another connector
for ordinary development. Keep model choice and account-specific access outside
the shared guide.

Sources: [CLI reference](https://learn.chatgpt.com/docs/developer-commands?surface=cli),
[authentication](https://learn.chatgpt.com/docs/auth),
[configuration](https://learn.chatgpt.com/docs/config-file/config-basic), and
[practical agent guidance](https://learn.chatgpt.com/guides/best-practices).

## Publish and deploy

Prepare a focused commit on the intended branch. Inspect the complete staged
diff, check links and referenced destination files, and verify the remote ref
after an authorized push. Preserve populated/unrelated staging through an
isolated checkout. Never force-push a shared branch without authorization.

v3 code deployment has a separate maintained guide and wrapper in
`docs/dev-process/V3CodeDeployment.md` and `scripts/deploy-v3-code.sh`. It runs on
the server as the deployment user, not as a local onboarding step. It preserves
private settings, checks web-code readability as www-data and verifies the real
FPM socket. Code deployment, Composer install, schema updates, configuration
imports and data repair have different effects; use only the authorized scope.

## Validation record

See [the onboarding validation record](dev-process/DeveloperOnboardingValidation.md)
for the tested versions, commands, checks and limits. Recheck the active lockfile
when using this guide on a later revision.

## Before calling a change complete

Record the tested checkout and dirty scope, installed versus locked versions,
actual commands/run IDs, reviewed results and remaining limitations. State
whether the change is local, pushed or deployed. Keep SQL dumps, cookies,
credentials, uploads and private test reports out of commits.
