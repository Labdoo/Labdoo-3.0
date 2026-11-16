---
name: multilingual
description: Handle translated interface strings, content and configuration when changing Labdoo PHP, Twig, JavaScript or email behavior.
---

# Multilingual behavior

Write source comments and technical documentation in English. Keep user-facing strings translatable and preserve existing language variants and language negotiation.

- PHP: use `$this->t()` where supported, `t()` in procedural hooks, or `TranslatableMarkup` as appropriate. Translate source messages with placeholders; do not pass arbitrary user input or dynamically assembled messages to translation.
- Twig: use Drupal's `|t` filter or `{% trans %}` blocks and preserve automatic escaping.
- JavaScript: use `Drupal.t()` / `Drupal.formatPlural()` with placeholders rather than hard-coded translated labels.
- Configuration: inspect the actual configuration schema and language overrides under `config/sync`; preserve translatable labels and overrides. Do not export unrelated runtime configuration.
- Email: preserve the template language and fallback behavior under `labdoo_notifications/templates/email`. Tests must use a collector or verified forced test recipients in an isolated environment.

Check the changed flow in English and at least one affected non-English language, including prefixed URLs and fallback behavior where relevant. UI translations are not violations of the English source-comment convention.
