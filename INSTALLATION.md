# Labdoo installation

The maintained instructions are in the **[developer guide](docs/developer-guide.md)**:

- [DDEV setup](docs/developer-guide.md#ddev-path).
- [LAMP setup](docs/developer-guide.md#lamp-path).
- [Setup troubleshooting](docs/developer-guide.md#common-setup-problems).
- [Isolated regression laboratory](tests/upgrade/README.md).

Use the active `composer.lock` for the exact Drupal and dependency versions.
This branch requires PHP 8.3 or later and uses MySQL 8.0 for local validation.
The previous PHP 8.1/MySQL 5.7 instructions, `config/default` references and broad
permission changes are superseded. A local Labdoo site needs an approved
sanitized database snapshot and matching files, obtained privately.

Deployment is separate from installation; follow the
[V3 deployment guide](docs/dev-process/V3CodeDeployment.md) only for an authorized
server operation.
