#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo 'Usage: print_labels_e2e.sh BASE_URL EXISTING_DOOTRONIC_ID' >&2
  exit 2
fi

html=$(curl --fail --silent --show-error "$1/dootronics/print-labels/$2/")
if [[ "$html" != *'dootronics-label-page'* ]] || [[ "$html" != *'dootronic-print-labels'* ]]; then
  echo 'Print label content is missing.' >&2
  exit 1
fi
if [[ "$html" == *'region-sidebar'* ]] || [[ "$html" == *'region-sticky'* ]] || [[ "$html" == *'region-footer'* ]]; then
  echo 'Print label page contains site chrome.' >&2
  exit 1
fi

echo 'Print label page contains labels without site chrome.'
