#!/usr/bin/env bash
# Run on the v3 server, as the deployment user, from the application checkout.
set -euo pipefail
# Git creates replacement files using this mask. PHP must be able to read them.
umask 0022
project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"
release_branch="${1:-deploy/v3-prelaunch-20261109}"
git check-ref-format --branch "$release_branch" >/dev/null
# Preserve the server's private settings and refuse other tracked local edits.
git diff --quiet -- . ':!web/sites/default/settings.php'
git diff --cached --quiet -- . ':!web/sites/default/settings.php'
settings_before="$(sha256sum web/sites/default/settings.php)"
printf 'Previous revision: '
git rev-parse HEAD
git fetch origin "$release_branch"
git merge --ff-only "origin/$release_branch"
# Check effective access as PHP's user, including traversal and ACLs. Do not
# change settings.php, upload permissions or ownership to make this check pass.
git ls-files -z -- web/modules/custom web/themes/custom |
  sudo -u www-data python3 -c '
import os, sys
paths = [os.fsdecode(p) for p in sys.stdin.buffer.read().split(b"\0") if p]
bad = [p for p in paths if not os.access(p, os.R_OK)]
if bad:
    print("PHP cannot read tracked web code:", *bad[:25], sep="\n", file=sys.stderr)
    raise SystemExit(1)
print(f"PHP read access verified: {len(paths)} tracked files")
'
test "$settings_before" = "$(sha256sum web/sites/default/settings.php)"
vendor/bin/drush cr
sudo systemctl reload php8.3-fpm
sudo -u www-data python3 scripts/verify-v3-fpm.py
printf 'Deployed revision: '
git rev-parse HEAD
