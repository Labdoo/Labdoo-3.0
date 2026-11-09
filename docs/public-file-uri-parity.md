# Repair migrated public-file URIs without allocating content IDs

`scripts/public-file-uri-parity.php` is a standalone maintenance CLI, dry-run by default. It uses the installed site's `external` D7 connection read-only and originals already present in `public://`. It never saves node/media/file entities, creates revisions or changes FIDs/UUIDs/status/owner/timestamps.

Identity must be established by a D7 file migration map, matching UUID, or a single D7 filename/URI. The actual copied file must match D7 size and detected MIME. Generated styles, CSS/JS/PHP and private paths are excluded. Ambiguous names, genuine size/MIME conflicts, missing originals and collisions inside the plan are skipped. Existing target ownership is compared after normalizing repeated slashes: the old FID/URI is retained and an independent hardlink entry is required instead of assigning two FIDs the same physical pathname. Only known migration-placeholder metadata (68-byte transparent-PNG fallback) or absent size metadata may be replaced with the verified original's size/MIME.

The plan also corrects 1×1/absent image dimensions on current field rows and their current revision, with exact row guards. Historical revisions and editorial alt/title fields are preserved. File and referencing-entity cache tags are invalidated after each batch commits.

```sh
# Create this private directory separately before running the commands.
php scripts/public-file-uri-parity.php \
  --root=/var/www/html/labdoo/web \
  --manifest-path=/home/ubuntu/private-file-repair/plan-unique.json

# Review the plan first. Use --fids=123,456 for a controlled initial sample.
php scripts/public-file-uri-parity.php \
  --root=/var/www/html/labdoo/web \
  --apply --fids=123,456 \
  --backup-path=/home/ubuntu/private-file-repair/backup-unique.jsonl

php scripts/public-file-uri-parity.php \
  --root=/var/www/html/labdoo/web \
  --rollback=/home/ubuntu/private-file-repair/backup-unique.jsonl --apply
```

`--source-root` defaults to the read-only V2 mount, for at most eight checksum samples up to2MiB each. `--limit` bounds the number of proposed repairs. Output/backup names must be new and outside the web root; they are0600. Every before-image is flushed and fsynced before writes. Transactions contain at most100 files; changed URIs/metadata or image rows are protected against concurrent edits. A batch error rolls that batch back; previous committed batches are recoverable from the same backup. A crash may leave backup records for unapplied work; rollback guards skip entries whose after-image is not present. Rollback also refuses subsequent editorial image changes.

The JSON summary prints counts, while private manifests/backups retain source identity and physical-path evidence. URI repair alone does not rewrite literal `<img src>` URLs stored in HTML. A separate guarded filesystem-alias phase is needed to preserve those old public URLs; do not claim those links fixed before that phase and HTTP verification.

Validation on an isolated E2E database:

```sh
python3 .e2e-lab/tools/drush.py integration php:script \
  /absolute/path/to/worktree/tests/public-file-uri-parity.php
```

The regression uses temporary file/media fixtures, performs apply and guarded rollback, checks current versus historical dimensions, conflict handling, private URI protection and unchanged node/revision counts and maximum IDs. It makes no external HTTP requests. Never run that fixture script against production.

## Preserve literal old URLs with relative aliases

After metadata repair, run `scripts/public-file-uri-aliases.php` using that repair's **backup JSONL**, not its dry-run manifest:

```sh
php scripts/public-file-uri-aliases.php \
  --root=/var/www/html/labdoo/web \
  --from=/home/ubuntu/private-file-repair/backup-unique.jsonl

php scripts/public-file-uri-aliases.php \
  --root=/var/www/html/labdoo/web \
  --from=/home/ubuntu/private-file-repair/backup-unique.jsonl \
  --apply --backup-path=/home/ubuntu/private-file-repair/aliases-unique.jsonl
```

This is a separate filesystem phase, not a database/filesystem transaction. It creates relative symlinks between verified public paths; where another FID already owns the canonical pathname, it creates an independent hardlink in the same filesystem while retaining the old managed URI. Both modes preserve `<img src>` literals without rewriting HTML, consuming content IDs or copying the originals again. Computed symlinks may contain `../` to reach another directory inside the same public root; user-provided URIs with parent-traversal segments are rejected. PHP/private/cache paths, escaping parent symlinks, different existing files, changed file metadata and changed originals are protected.

An existing real image is never overwritten. Only the exact known68-byte transparent PNG may be replaced, after its bytes/stat are durably backed up. A short-lived same-directory hold preserves its inode while a no-clobber symlink is created. A failure stops the phase; already created aliases remain recorded and can be rolled back. If interrupted after holding a placeholder but before creating the link, rollback can safely restore that exact held inode. An interruption after link creation but before its applied inode was durably recorded is reported as an unconfirmed conflict, rather than deleting a guessed symlink.

Use an account with write permission on the specific legacy directories and private backup directory; do not broaden the whole public tree's permissions. Verify Apache's existing symlink policy and HTTP200/content for old and canonical URLs. The script does not weaken `.htaccess` or server configuration. New directories are left in place on rollback rather than deleting potentially shared directories.

Rollback aliases **before** metadata:

```sh
php scripts/public-file-uri-aliases.php \
  --root=/var/www/html/labdoo/web \
  --rollback=/home/ubuntu/private-file-repair/aliases-unique.jsonl --apply
# Then use the metadata rollback command above.
```

Symlink rollback only removes the exact recorded relative link with matching inode/device/ctime. Hardlink rollback requires matching inode/device/link-count and a still-existing canonical entry with the same inode; it refuses to remove the last surviving copy or a path whose canonical owner has deleted/replaced its entry. Subsequent real files or replacement links are retained as conflicts. Placeholder bytes, owner, group, mode and mtime are restored; original canonical files are never removed by alias rollback. Keep both backups and report any unresolved intents/conflicts for manual review. If alias rollback reports conflicts, stop before metadata rollback until those conflicts have been reviewed.

`tests/public-file-uri-aliases.php` verifies dry-run, original-byte reads through old and canonical paths, local HTTP200 for both URLs, idempotence, placeholder restoration, concurrency and traversal/private protections, interrupted-placeholder recovery, normalized ownership, independent hardlink lifetime, last-copy rollback protection and unchanged node/revision counters.
