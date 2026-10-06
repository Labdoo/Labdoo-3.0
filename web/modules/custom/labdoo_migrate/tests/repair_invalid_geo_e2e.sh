#!/usr/bin/env bash
set -euo pipefail

DRUSH_BIN="${DRUSH_BIN:-vendor/bin/drush}"
RESULT="$($DRUSH_BIN labdoo:migrate-repair-invalid-geo --types=hub --limit=1 --dry-run)"
grep -q 'Geo repair finished (dry-run)' <<< "$RESULT"
grep -q 'Report file:' <<< "$RESULT"
printf '%s\n' 'PASS: Drupal 7/10 geo repair command runs in dry-run mode.'
