# Labdoo 3.0

Labdoo coordinates donated devices, volunteer transport, hubs and educational
projects. This branch targets Drupal 11; `composer.lock` pins the dependencies.

## New developers and coding agents

Start with the **[developer guide](docs/developer-guide.md)**. It covers:

- Preparing a dedicated local site with DDEV or LAMP.
- Importing an approved sanitized dataset and checking the selected environment.
- Finding custom modules, themes and configuration.
- Running the isolated Playwright, k6 and Lighthouse laboratory.
- Setting up a coding agent, repository guidance, permissions and shared skills.
- Reviewing changes and distinguishing local work, publication and deployment.

The guide replaces the historical Gitpod/DDEV setup instructions and the missing
`.scripts/dev-start.sh` reference. Installation uses locked Composer dependencies
and `config/sync`; a standard Drupal install alone does not recreate Labdoo data.

## Project references

| Reference | Purpose |
| --- | --- |
| [AGENTS.md](AGENTS.md) | Contributor and agent entry point, task skills and environment boundaries. |
| [TECHNICAL.md](TECHNICAL.md) | Domain and custom-module overview. |
| [Workflow and validation](docs/agent-workflow.md) | Required checks and operational scope. |
| [Regression laboratory](tests/upgrade/README.md) | Prepare this checkout's isolated test site and fixtures. |
| [Local test commands](docs/local-test-lab.md) | Playwright, k6, Lighthouse and the local dashboard. |
| [Code review](docs/dev-process/CodeReviewProcess.md) | Review scope, conventions and evidence. |
| [V3 deployment](docs/dev-process/V3CodeDeployment.md) | Separate, authorized server deployment procedure. |
| [Onboarding validation](docs/dev-process/DeveloperOnboardingValidation.md) | Versions, executed setup checks and remaining limits. |

Shared skills live in [.agents/skills](.agents/skills). Historical notes in
[labdoo-development-notes.md](docs/labdoo-development-notes.md) and the ADRs explain
past decisions; verify commands against the active implementation before use.

The historical Gitpod starter was based on
[shaal/ddev-gitpod](https://github.com/shaal/ddev-gitpod), with contributions from
[shaal](https://github.com/shaal) and [rfay](https://github.com/rfay).
