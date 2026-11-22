# Agent workflow and validation

Read [AGENTS.md](../AGENTS.md) and the mandatory [regression-testing skill](../.agents/skills/regression-testing/SKILL.md) first. This guide describes repository conventions, not authorization to run operations outside the user's task.

## Environment and side effects

Check the current branch, staged/unstaged changes and the actual environment before running Drupal commands. A local filesystem path does not prove the database is a disposable local clone. Inspect connection provenance without printing credentials. If the target is ambiguous, continue code inspection and ask before dependent mutations.

Use isolated databases and file copies for integration, migration and browser tests. Do not reuse live credentials or symlink dependencies in ways that bootstrap another Drupal root. Disable external effects with the test harness's mail collector, controlled geocoder and HTTP guards. Do not send real email or call paid providers merely to validate a code change.

Keep dumps, private keys, credentials, cookies, personal data and unreviewed reports outside commits and public artifacts. Preserve unrelated local work and staging.

## Validation

Before execution, compare installed runtime/dependency versions with `composer.lock` (for Drupal, inspect the installed core version and the locked core package). Record both in the evidence. If they differ, distinguish current-workspace validation from target-version validation; do not silently install different dependencies or claim the locked target passed. Track a deferred mismatch in the task's selected issue record.

Creating or adapting relevant tests, executing applicable suites and reviewing their reports is required for application changes. Identify exact existing cases when reusing adequate coverage. Choose the applicable suites using the regression-testing skill; report unavailable prerequisites as incomplete validation:

| Change | Checks and evidence |
| --- | --- |
| Documentation or comments | Review the diff and relative links; validate skill frontmatter when changing skills. For code comments, confirm executable code is unchanged. |
| PHP behavior | Run `php -l` on affected PHP/module/install/theme files; run focused tests for the changed behavior. Include access, cache and translation cases when affected. |
| JavaScript | Run `node --check` for compatible standalone scripts, then the relevant browser interaction. Syntax alone does not validate Drupal behavior. |
| Sass/theme assets | Inspect the theme build task, compile affected sources and review generated files; check responsive behavior and keyboard access when relevant. |
| Drupal configuration | Review the exact YAML diff and dependencies; import only into an identified test clone and verify affected routes, access and language overrides. |
| Queues or migrations | Exercise the actual producer/consumer or mapping path in an isolated clone, including repeated execution, missing entities and the relevant failure case. |
| Deployment | Follow the maintained deployment guide on the target branch; validate as the actual web runtime, including PHP-FPM where applicable. |

PHPCS and PHPStan level 5 are project quality checks. The isolated [quality toolchain](../tools/quality/README.md) has its own Composer manifest and lockfile under `tools/quality`; it does not update the application's dependencies. `phpstan.neon` covers custom modules and themes. From the repository root, use:

```sh
make quality-install
make tests
make phpcs
make phpstan
```

The tools live in `tools/quality/vendor/bin`, rather than the application's `vendor/bin`. `make tests` invokes the existing custom unit suite through `tools/quality/phpunit.xml`; it does not run Kernel or browser tests. Check availability before execution, report missing prerequisites, and distinguish existing failures from regressions. Do not claim a check passed merely because a tool was installed or a target exists. Preserve actual failures; do not add suppressions or relax rules to obtain a passing result.

The maintained suites live in `tests`. Use [tests/upgrade/README.md](../tests/upgrade/README.md) and the local `upgrade.config.ts` for current-checkout regression. [local-test-lab.md](local-test-lab.md) describes the Makefile entry points. The application and tests must come from the active checkout; the E2E site and database remain isolated. Historical baseline/integration configurations use different ports and must not silently substitute for current-code results. Record the tested revision, dirty changes, environment, commands and reviewed reports. Lighthouse execution has no automatic score gate; compare its actual metrics and audits. k6 must satisfy both performance thresholds and functional/isolation checks.

## Operations

Code-only deployment, dependency installation, database updates, configuration import, reindexing and data repair have distinct effects. Inspect the requested operation before selecting a command. `make full-deploy` runs multiple mutations; `make deploy` runs Drush deploy tasks. Neither is a substitute for a code-only deployment wrapper.

The maintained V3 deployment guide, where present, is `docs/dev-process/V3CodeDeployment.md` with `scripts/deploy-v3-code.sh`. It documents the separation of public code readability from private backup/settings permissions and validation through PHP-FPM. Read the version on the target branch; historical commands and release branch names are not defaults for future deployments.

Avoid blanket permission changes: `make fix-permissions` recursively changes modes throughout the checkout. Inspect ownership and required runtime access and fix the scoped cause instead. Never expose settings, backups or private uploads to make a code-readability check pass.

For an authorized destructive data operation, confirm the actual target and scope, use a supported preview where available, establish a restorable backup and define integrity checks. Stop on an unexpected environment or failed prerequisite rather than expanding to a broader repair.

## Maintaining these instructions

Keep stable project rules in `AGENTS.md`, reusable task guidance in `.agents/skills`, and operational details/evidence in `docs` or the relevant test directory. Link instead of copying instructions. When changing an established convention, update its callers and identify superseded historical guidance. Check whether referenced files are committed on the destination branch before publishing documentation.
