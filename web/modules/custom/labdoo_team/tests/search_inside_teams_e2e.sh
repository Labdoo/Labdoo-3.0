#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo 'Usage: search_inside_teams_e2e.sh BASE_URL SEARCH_TERM EXPECTED_TITLE' >&2
  exit 2
fi

page=$(curl --fail --silent --show-error "$1/teams-dashboard")
if [[ "$page" != *'Search terms'* ]] || [[ "$page" != *'Team'* ]]; then
  echo 'Team search controls are missing.' >&2
  exit 1
fi

results=$(curl --fail --silent --show-error --get --data-urlencode "combine=$2" "$1/teams-dashboard")
if [[ "$results" != *"$3"* ]]; then
  echo 'Expected team search result was not found.' >&2
  exit 1
fi

echo 'Team search returned the expected conversation.'
