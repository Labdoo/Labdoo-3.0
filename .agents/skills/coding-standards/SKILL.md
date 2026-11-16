---
name: coding-standards
description: Apply Labdoo PHP, frontend, caching and access conventions when editing custom modules or themes.
---

# Code conventions

Use Drupal coding standards and the root `.editorconfig`. Write technical documentation, docblocks and comments in English. Preserve translated product content.

For new PHP files, use `declare(strict_types=1);` and type properties, parameters and return values where compatible with Drupal and inherited signatures. See the accepted [strict-types ADR](../../../docs/adrs/20211201-use-strict-types-in-php-code.md) and [type-hints ADR](../../../docs/adrs/20220112-type-hints.md). Do not retrofit unrelated legacy files as part of a focused fix.

Inject dependencies in services, plugins and controllers using their supported factory/container contracts. Procedural hooks may use Drupal service access where injection is unavailable. Keep query and persistence behavior in the affected repository/service when one exists.

Enforce entity and route access, including explicit entity-query access behavior. Use parameterized database queries and Drupal rendering/escaping APIs. Preserve render cache metadata; do not use broad cache disabling as a substitute for correct tags and contexts.

For Sass changes, inspect the affected theme's `Gulpfile.js`, rebuild the intended generated assets and review the diff. The Labdoo theme exports `compileSass`; its default Gulp task also starts a watcher. Confirm `THEME_NAME` before using Makefile theme targets.

Use the [validation matrix](../../../docs/agent-workflow.md#validation) to choose checks. PHPCS/PHPStan are intended quality checks, but their installation is not currently provisioned by this checkout's Composer development dependencies. Report missing tools and existing failures explicitly; do not claim they passed or change dependency manifests merely to make a documentation check run.
