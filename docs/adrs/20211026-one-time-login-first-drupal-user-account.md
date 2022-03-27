# Block the Drupal user 1 account in production environments

The first user account in a Drupal site (often referred to as "administrator" or "user 1") is [granted every permission automatically](https://www.drupal.org/docs/user_guide/en/user-admin-account.html). If the account credentials are compromised, an attacker can easily inject JavaScript to attack site visitors and can likely execute arbitrary PHP code.

---

date: 2022-01-12

status: accepted

tags:
  - drupal
  - security

deciders:
  - Andrew Berry
  - Andy Blum
  - Marcos Cano
  - Mateu Aguiló Bosch
  - Cathy Theys
  - David Burns
  - Zequi Vázquez

---

## Decision

The Drupal admin account will be blocked on production environments. Individually named user accounts will be created and granted appropriate roles as needed. Even though users may have equivalent permissions by being granted the "Administrator" role, admin actions will be logged with the actioning user's identity.

The administrator user account will be unblocked as needed for staging, development, and local environments.

For example, using `drush`:

```console
drush user:unblock <username>
```

In Drush 11 and newer, [the `--uid` flag can be used](https://github.com/drush-ops/drush/pull/4542):

```console
drush user:unblock --uid=1
```

Otherwise for older versions of Drush and Drupal 8 or 9, and when the admin username is unknown, it can be determined with and unblocked with a subcommand:

```console
drush user:unblock "$(drush user:information --uid=1 --fields=name --format=string)"
```

For Drupal 7 and older versions `drush sqlq` can be used to get the user name:

```console
drush sqlq 'SELECT name FROM users WHERE uid=1';
```

The administrator user account will have a long, random password set that is discarded. This will prevent exposing Administrator logins if the account is accidentally unblocked.

## Consequences

Teams may need to add a Drush command to unblock the administrator account when pulling production databases into environments like [Tugboat](https://www.tugboat.qa/). Local development tools also offer ways to automatically unblock accounts with Drush after a database import.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
