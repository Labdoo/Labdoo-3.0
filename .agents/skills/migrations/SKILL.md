---
name: migrations
description: Plan or change Drupal 7 to Labdoo migration, synchronization, revision repair and purge operations.
---

# Migration and repair workflow

Inspect the real command annotations under `labdoo_migrate/src/Commands`, service wiring, mappings and source/destination repositories. Command names and options in historical prose may be obsolete.

Identify the source and destination Drupal roots and databases before execution. Use a read-only source connection where possible. Use isolated destination clones for development and tests; a code task does not authorize modifying live data.

Inspect whether saving entities triggers notifications, queues, search updates or geocoding. Preserve source identifiers, revision authors/timestamps, translations and relationships, and make incremental runs safe to repeat. Direct SQL bypasses entity hooks and requires explicit justification for the affected operation.

Before an authorized data mutation, establish its scope, preview/dry-run behavior if supported, a restorable backup and a post-operation integrity check. Do not invent a `--dry-run` flag: inspect the implementation. Large jobs should have bounded batches and a documented resume strategy.

Migration purge notes (`docs/migration-purge.md`) are available in some checkouts; inspect command code if absent. Fast purge is for explicitly disposable migration data, not routine production entity deletion. Never use `delete-all` or a no-confirm target merely to validate a change.
