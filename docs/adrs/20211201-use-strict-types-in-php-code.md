# Use strict types in PHP code

PHP's type coercion can cause logical errors within a program. For example, when a float is cast to an int, the decimal portion is lost. We aim to reduce these errors in our code, but they can easily slip through manual code review. Following stricter typing where available can significantly reduce bugs, as shown by [Python's urllib3 type hinting case study](https://sethmlarson.dev/blog/2021-10-18/tests-arent-enough-case-study-after-adding-types-to-urllib3).

---

date: 2021-12-01

status: accepted

tags:
  - php
  - drupal

deciders:
  - Andrew Berry
  - Marcos Cano
  - Mateu Aguiló Bosch
  - James Sansbury

---

## Decision

All new files within an existing PHP code base will [declare strict typing](https://www.php.net/manual/en/language.types.declarations.php#language.types.declarations.strict). All new projects will use strict typing for all PHP code.

To enable strict typing, add the declaration to the first line of the file:

```php
<?php
declare(strict_types=1);
```

A continuous integration check will be created to identify missing declarations on new files. Existing projects may optionally expand this check to all modified files.

Use one or more of the following examples to implement a CI check on a project.

### Use PHPCS to detect files without strict types

Add the following sniff to the project's PHPCS configuration. Use this if your project is already using PHPCS.

```xml
<rule ref="Generic.PHP.RequireStrictTypes"/>
```

### Identify all custom Drupal module code without strict types

```sh
find web/modules/custom -type f -exec sh -c 'head -n 1 {} \
  | grep -Eq "^<\?php" && grep --files-without-match --fixed-strings "declare(strict_types=1" {}' \;
```

### Use PHPCS to detect only changed files without strict types

This example uses GitLab's `$CI_MERGE_REQUEST_TARGET_BRANCH_SHA` environment variable to identify the merge target. Update as needed for the CI tool being used, or replace with `origin/main`.

In `phpcs.strict_types.xml`:

```xml
<?xml version="1.0"?>
<ruleset>
  <rule ref="Generic.PHP.RequireStrictTypes"/>
  <file>web/modules/custom</file>
</ruleset>
```

In the continuous integration check:

```sh
git diff-tree \
   -r \
   -z \
   --name-only \
   --no-commit-id \
   HEAD $CI_MERGE_REQUEST_TARGET_BRANCH_SHA -- web/modules/custom | xargs vendor/bin/phpcs --standard=phpcs.strict_types.xml
```

### Use git to detect files without strict types

Use this if your project cannot use PHPCS.

```sh
git diff-tree \
  -r \
  -z \
  --name-only \
  --no-commit-id \
  HEAD $CI_MERGE_REQUEST_TARGET_BRANCH_SHA -- web/modules/custom \
  | xargs -0 -I{} find {} -type f -exec sh -c 'head -n1 {} \
    | grep -Eq "^<\?php" && grep --files-without-match --fixed-strings "declare(strict_types=1" {}' \;
```

## Consequences

Strict typing changes an implict behaviour to an explicit decision made by the developer. The declaration may seem unneeded in files that do not have any scalar types declared in called function or methods. However, since scalar types may be added at any point in the future, the benefits outweigh the risks.

Developers should consider configuring their IDE to declare strict typing on new files. [File templates](https://www.jetbrains.com/help/phpstorm/using-file-and-code-templates.html) are a good solution for PHPStorm, and [Snippets](https://code.visualstudio.com/docs/editor/userdefinedsnippets) can be configured in Visual Studio Code.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
