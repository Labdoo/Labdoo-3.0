#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo 'Usage: dootronic_sequence_e2e.sh BASE_URL' >&2
  exit 2
fi

drush=${DRUSH_BIN:-vendor/bin/drush}
read -r count max_label < <("$drush" sqlq "SELECT COUNT(*),MAX(CAST(title AS UNSIGNED)) FROM node_field_data WHERE type=CHAR(100,111,111,116,114,111,110,105,99) AND status=1")
next_label=$("$drush" php:eval '$service=\Drupal::service("labdoo_dootronics.sequence_manager"); echo $service->get(); $service->commit();')

if [[ "$count" != "$max_label" ]] || (( next_label != max_label + 1 )); then
  echo "Sequence mismatch: count=$count max=$max_label next=$next_label" >&2
  exit 1
fi

curl --fail --silent --show-error --output /dev/null "$1/content/000063348"
echo "Dootronic count, maximum label, next label and public page agree."
