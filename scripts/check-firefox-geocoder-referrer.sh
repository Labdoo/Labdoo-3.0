#!/usr/bin/env bash

set -euo pipefail

MODE="${1:-after}"

ORIGINAL_LOADER="web/modules/contrib/geofield_map/js/geofield_map_gmap_formatter.js"
PATCH_HOOK_FILE="web/modules/custom/labdoo_common/labdoo_common.module"
PATCH_JS_FILE="web/modules/custom/labdoo_common/js/geofield_map_gmap_auth_referrer.secure.js"

case "$MODE" in
  before)
    if grep -q "auth_referrer_policy=origin" "$ORIGINAL_LOADER"; then
      echo "Unexpected state: original contrib loader already includes auth_referrer_policy."
      exit 0
    fi

    echo "Reproduced pre-fix condition: Google Maps loader has no auth_referrer_policy=origin."
    exit 1
    ;;

  after)
    grep -q "geofield_map_gmap_auth_referrer.secure.js" "$PATCH_HOOK_FILE"
    grep -q "auth_referrer_policy=origin" "$PATCH_JS_FILE"
    echo "Post-fix verification passed: auth_referrer_policy patch is wired and present."
    ;;

  *)
    echo "Usage: $0 [before|after]"
    exit 2
    ;;
esac
