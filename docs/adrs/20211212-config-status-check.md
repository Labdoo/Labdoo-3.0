# Confirm Drupal site configuration is always in a default state

Configuration should never be in an overridden state after configuration import(s) have been completed.

---

date: 2021-12-12

status: accepted

tags:
  - drupal
  - deployment
  - automation
  - devops

deciders:
  - David Burns
  - Andrew Berry
  - Matthew Tift
  - Mateu Aguiló Bosch
  - Marcos Cano

---

When there is overridden configuration it indicates that code and database are not in sync. This creates confusion as to if a branch is safe to merge or a release is safe to deploy.

Overridden configuration can be caused by module updates that alter existing configuration. These are easily overlooked when using an automated package updater like [Dependabot](https://dependabot.com/) or [Violinist.io](https://violinist.io).

## Decision

After running the standard [Drupal build steps](20210924-drupal-build-steps.md) we should 
follow up with `drush config:status` and check the results of that command for `No differences`. If differences are
found we should fail the build.

Example: `[[ $(vendor/bin/drush config:status --format=json --state=Different) == '[]' ]] || exit 1` 

## Consequences

When configuration status checks fail, developers will need to determine how to resolve the error, which often might be as simple as re-exporting configuration and committing the changes.

For example:
  
  - If a module was updated and introduced new configuration, a developer will need to re-export configuration to capture the changes.
  - If a developer missed adding configuration changes to their pull request, they will need to re-create and export the configuration locally and push again.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
