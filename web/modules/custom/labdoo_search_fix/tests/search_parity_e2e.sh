#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo 'Usage: search_parity_e2e.sh BASE_URL SEARCH_TERM EXPECTED_CONTENT_TYPE' >&2
  exit 2
fi

# The local development site may use a self-signed or expired certificate.
response=$(curl --insecure --fail --silent --show-error --get \
  --data-urlencode 'display=page_1' \
  --data-urlencode 'filter=search_api_fulltext' \
  --data-urlencode "q=$2" \
  "$1/search_api_autocomplete/search_results")
php -r '
$matches = json_decode($argv[1], TRUE);
$expected_type = $argv[2];
if (!is_array($matches)) {
  fwrite(STDERR, "Autocomplete endpoint did not return JSON suggestions.\n");
  exit(1);
}
$labels = implode("\n", array_column($matches, "label"));
$all_results = FALSE;
foreach ($matches as $match) {
  if (str_contains($match["label"] ?? "", "View all results for") && str_contains($match["url"] ?? "", "search-results")) {
    $all_results = TRUE;
  }
}
if (!str_contains($labels, $expected_type)) {
  fwrite(STDERR, "Autocomplete results are missing the expected content type.\n");
  exit(1);
}
if (!$all_results) {
  fwrite(STDERR, "Autocomplete results are missing the link to all results.\n");
  exit(1);
}
' "$response" "$3"

echo 'Autocomplete shows typed content results and links to the full result list.'
