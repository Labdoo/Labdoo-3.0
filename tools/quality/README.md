# Local PHP quality tools

This Composer project installs development tools independently of the application
runtime. Its committed lockfile pins Coder/PHPCS, PHPStan with Drupal support and
PHPUnit 11 for PHP 8.3. It never updates the root Drupal lockfile or loads local
site settings during static checks and unit tests.

From the repository root:

```sh
make quality-install
make tests
make phpcs
make phpstan
python3 tests/operations/quality_toolchain_test.py
```

`make tests` runs the custom **unit** tests in the four module directories and
the shared cross-module cases under `tests/phpunit`, as listed in `phpunit.xml`.
This target does not run Kernel, browser, database or performance tests.
PHPCS and PHPStan also include the shared directory. Additional arguments can be
passed with `PHPUNIT_ARGS`, `PHPCS_ARGS` and
`PHPSTAN_ARGS`. The two bootstrap files load the installed project libraries and
Drupal test namespaces, including the procedural helper used by the chart tests;
they do not bootstrap the default site or read its database credentials.

The executable paths are `tools/quality/vendor/bin/phpunit`, `phpcs` and `phpstan`,
not the application's `vendor/bin`. `make phpstan` includes the existing root
`phpstan.neon` at level 5 and the Drupal extension. No baseline or suppression is
added. Existing application findings remain failures; installing tools does not
make those checks pass. See [the validation workflow](../../docs/agent-workflow.md).

The shared recompute cases exercise storage-failure propagation, replay, cache
events, historical payload shapes, deleted entities and corrupt envelopes.
`tests/e2e/recompute-queue-retry.spec.ts` adds real Drupal database queues and the
core single-queue cron processor with fault-injected repositories/computations.
It runs only randomly named test queues, never the full cron runner or existing
application queues. It verifies lease retention after a failed save, removal
after a successful retry, and terminal processing of a deleted entity. Entity
saves and external providers are excluded from this controlled integration case.

PHPCS checks maintained custom source and excludes nested `node_modules` and
`vendor` dependency copies. It does not suppress findings in application source.

Both standalone geography entry points now use actual installed Drupal/Drush
classes. The city/country command runs the five shared PHPUnit cases, checks
their JUnit results and preserves its five `PASS:` lines for existing callers.
The repair validator loads the real command class and exercises only its pure
validation method without a site or database. Both require the isolated quality
dependencies to be installed. Their former placeholder framework definitions
have been removed, so neither script is excluded from PHPStan's type discovery.
The toolchain regression checks their five cases and actual framework methods
alongside application analysis.

Dependency return-type extensions model nullable owners, source forms, PO stream
end-of-file and configuration-override languages from the installed runtime.
A SELECT extension preserves the receiver returned by `forUpdate()`; core's
interface PHPDoc otherwise narrows a SELECT to a condition-only interface.
These corrections retain the original paths, rule levels and framework methods.
`framework-types.php`, negative toolchain fixtures and the isolated dependency
contract E2E test cover the corrected APIs. Dependency upgrades must keep these
checks passing or revise the corrections.

The installation target uses its own Composer home and cache under `.cache`, so
globally saved credentials are not loaded for these public packages. Personal
Composer settings for other projects remain unchanged. Do not paste tokens into
commands, this manifest, reports or application source. Dependencies and generated
caches stay under ignored `vendor` and `.cache` paths.

## Factory constructor contracts

Custom classes with inherited `new static()` factories declare
`@phpstan-consistent-constructor`. Subclasses must keep compatible constructor
parameters so an inherited factory can instantiate them safely. This preserves
late static binding and runtime signatures while enforcing the contract on
subclasses, as described in the [PHPStan guidance](https://phpstan.org/blog/solving-phpstan-error-unsafe-usage-of-new-static).
The toolchain regression check creates incompatible subclasses of every marked
factory and requires constructor compatibility diagnostics for each one. The
existing migration worker and Mini Wiki resource subclasses inherit their
constructors unchanged. These declarations do not ignore diagnostics or change
the configured analysis level.

