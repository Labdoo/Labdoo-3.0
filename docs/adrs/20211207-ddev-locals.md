# Use DDEV for local environments

The software development industry is continuing to move to containers both for production and local environments. It's clear that the bulk of new investment in local tooling is going towards container-focused solutions. While containers themselves add some complexity to local environments, they also reduce variance in development environments for teams with multiple developers.

---

date: 2021-12-07

status: accepted

tags:
  - dev-tools
  - drupal
  - devops

deciders:
  - Local Environments Working Group

---

We would like to reduce onboarding time and leverage shared knowledge to improve the local development environment experience for our team.

## Decision

Projects will use [ddev](https://github.com/drud/ddev/) as their default local development environment. Each project will have `.ddev/config.yaml` and related files committed to the project repository.

Alternatives considered include:

- [Lando](https://lando.dev): While functional and with broad community support, we've experienced performance issues with this tool.
- [Vagrant](http://vagrantup.com): Oracle's VirtualBox is not ported to Apple Silicon, and Vagrant requires additional tooling to support containers. Other providers do not have significant community support.
- [MAMP](https://www.mamp.info/): MAMP does not run with containers and is difficult to have consistent environments across a team.

## Consequences

Existing projects may already have an alternate tool configured. In those cases, we will only switch the local environment to DDEV if the development team desires it, or if there is a clear point to make the change, such as when onboarding on a new team.

As well, clients may specify an alternate tool for local environments that they prefer. In those cases, we will recommend ddev, but defer to their preferences.

macOS users will need to use a tool like [colima](https://github.com/abiosoft/colima) or [Docker Desktop](https://www.docker.com/products/docker-desktop) to run containers. Windows users can install the Linux version of Docker inside of [WSL](https://docs.microsoft.com/en-us/windows/wsl/install).

macOS users will want to enable [mutagen](https://ddev.readthedocs.io/en/stable/users/performance/#enabling-mutagen) for code syncing as it offers near-native performance that is an order of magnitude faster than Docker Desktop or sshfs mounts.

Automated tests will assume ddev is available for running tests locally. For example, functional tests may assume ddev-specific configuration such as browser containers, instead of allowing deep customization for individual local environments.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
