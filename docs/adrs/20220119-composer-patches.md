# Composer patches configuration and best practices

Default `composer-patches` settings can lead to misapplied patches or hidden errors. Using a consistent configuration across projects will reduce errors and save time.

---

date: 2022-02-07

status: accepted

tags:
  - drupal
  - php
  - composer

deciders:
  - Mateu Aguiló Bosch
  - Andrew Berry
  - David Burns
  - Marcos Cano
  - James Sansbury
  - Cathy Theys


---

When using [cweagans/composer-patches](https://github.com/cweagans/composer-patches)
to manage patches in a Drupal project, developers are expected to use
consistent process and configurations for managing project patches.

## Decision

When using [cweagans/composer-patches](https://github.com/cweagans/composer-patches)
to manage patches, use the following configuration:

### Break install if patches don't apply

```
  "composer-exit-on-patch-failure": true,
```

### Use `-p2` as `patchLevel` for Drupal core patches

```
  "patchLevel": {
    "drupal/core": "-p2"
  },
```

### Specify patches in a separate `composer.patches.json` file

```
  "patches-file": "composer.patches.json",
```

### Use the patch remote URL when available

When referring to a remote issue that has a patch on drupal.org, use the
direct URL of the patch. Make sure you start the patch description with the
issue number:

```
  "drupal/replicate_actions": {
    "Issue #3179837: Make sure translations are replicated unpublished as well": "https://www.drupal.org/files/issues/2020-10-30/3179837-2.patch"
  },
```

### Use a local patch to refer to a drupal.org Merge Request without patches

When referring to an issue that doesn't have a patch (only a Merge Request),
never use the GitLab URL with the `.patch` suffix. Follow the
[instructions](https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal/creating-issue-forks-and-merge-requests#s-downloading-a-patch-file-from-a-merge-request)
on how to create a local patch from a Merge Request, and save it in the
`patches/upstream/` directory in your project.

```
  "Issue #2823541: Allow exposed filters AJAX calls to persist query params": "patches/upstream/2823541-41-rerolled-on-9.2.7.patch",
```

### Use local patches for project-specific modifications

When a patch is needed but it can't be contributed back because it's too
specific to the project, store the patch in the `patches/local/` directory:

```
  "Redirect users to the document library after creating documents": "patches/local/redirect-to-doc-library.patch",
```

### Examples

`composer.json` :

```
    "extra": {
        "composer-exit-on-patch-failure": true,
        "patchLevel": {
            "drupal/core": "-p2"
        },
        "patches-file": "composer.patches.json",
    },
```

`composer.patches.json` :

```json
{
    "patches": {
        "drupal/core": {
            "Issue #2921627: Drupal should not use full CSS required marker in forms according to WCAG 2.0": "https://www.drupal.org/files/issues/2021-08-02/2921627-63_0.patch",
            "Issue #2823541: Allow exposed filters AJAX calls to persist query params": "patches/upstream/2823541-41-rerolled-on-9.2.7.patch",
            "Redirect users to the document library after creating documents": "patches/local/redirect-to-doc-library.patch"
        }
    }
}
```

`patches/upstream/README.md` :

```markdown
Store in this directory patches that are generated from GitLab Merge Requests.
Do NOT manually modify the patches that are in this directory. If any changes
or adjustments are needed, store the patch in the `patches/local/` directory.
```

`patches/local/README.md` :

```markdown
Store in this directory patches that are project-specific and not intended to
be contributed back to the community.
```

## Consequences

Developers have a clear set of instructions on how to manage patches with
Composer in Drupal projects.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
