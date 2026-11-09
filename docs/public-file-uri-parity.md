# Repair migrated public-file URIs without allocating content IDs

`scripts/public-file-uri-parity.php` is a standalone maintenance CLI, dry-run by default. It uses the installed site's `external` D7 connection read-only and originals already present in `public://`. It never saves node/media/file entities, creates revisions or changes FIDs/UUIDs/status/owner/timestamps.

Identity must be established by a D7 file migration map, matching UUID, or a single D7 filename/URI. The actual copied file must match D7 size and detected MIME. Generated styles, CSS/JS/PHP and private paths are excluded. Ambiguous names, genuine size/MIME conflicts, missing originals, existing target ownership and collisions inside the plan are skipped. Only known migration-placeholder metadata (68-byte transparent-PNG fallback) or absent size metadata may be replaced with the verified original's size/MIME.

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
