# Additional Project Information

## Project is built assuming Composer
ALL core, contributed modules or themes are added, updated or removed using Composer.  Do not use the UI or manually copy a module or core update since it will be ignored by the project's .gitignore and will be missing in subsequent deployments.

### Adding a module using Composer
For a given module, such as https://drupal.org/project/bad_judgement, the command is:

`composer require drupal/bad_judgement`

For a module that is alpha, you need to specify the version and@alpha like

`composer require drupal/bad_judgement:^2.0@alpha`

### Identifying what needs updating
To user Composer to identify what needs updating use the command

`composer outdated -D`

The -D limits the search to dependencies contained in the composer.json file.

### Updating a module
To update a module, use the command

`composer update drupal/bad_judgement -W`

The -W is equivalent to --with-all-dependencies and will update other PHP dependencies including other modules, etc in the composer.json file.

### Updating Drupal core
To update Drupal core, use the command

`composer update drupal/core-* -W`

## No One should merge their own pull requests
It is important that all pull requests be reviewed by another developer both for quality and to ensure more than one developer understands what is changing and why.

## Files to be Excluded from Pull Requests

The following files are to be excluded from most pull requests to avoid possible merge conflicts and better manage the enabled modules: 

- composer.json
- composer.lock
- config/default/core.extension.yml

If a change is desired to any of these files, contact the team lead to coordinate making the change so more than one developer doesn't try to merge them at once.

Generally, keep a pull request for changes to these files limited to just these files and don't include other changes.

If you submit a pull request including these files and see a merge conflict, especially in composer.lock, it is generally easier to cancel the pull request and start over.  Composer.lock is almost guaranteed to cause merge conflicts if changed by more than one developer and resolving them is not straight forward since part of the conflict involves a hash calculated from the file contents.

## Keep Pull Requests Small and Focused

In general pull requests should focus on a single subtask or task and should involve the minimum number of files possible and should only incorporate a single item of functionality (e.g. a single module, a single content type, a single module’s configuration).

Keeping pull requests small and focused will allow the reviewer(s) to more quickly review the pull request and approve it or ask for changes.

## Don’t Mix Custom Code and Configuration

Generally if you have custom code it should be submitted in its own pull request and allowed to be reviewed and merged before submitting any configuration that might depend on that custom code. A common reason for build failures is a pull request that has custom code plus configuration, since the custom code isn’t enabled (due to config/default/core.extension.yml not being included) so the configuration dependent on its functionality can’t be imported. Thus the sequence is:

1.  Create the custom module and submit a PR for that module.

2.  When the custom module is approved and merged, create an issue to have the project lead enable the module

3.  When the PR for enabling the module is approved and merged, create a PR for the configuration that is dependent on the custom module.

© 2020-2021. This work is licensed under a [Creative Commons Attribution-ShareAlike 4.0 International License](http://creativecommons.org/licenses/by-sa/4.0/).
