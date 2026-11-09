# Incremental sync: adversarial acceptance checklist

These are acceptance requirements, not claims that cases already pass. Fixtures
must use separate local E2E source/destination databases. Production and the real
external source are never fixture writers.

## Contracts required before implementing the engine tests

1. Pure planning API and separate apply/resume API, including result/status keys.
2. Per-entity baseline format: source hash, destination hash, mapping version,
   source identity, destination identity, last successful source presence.
3. Meaning of source deletion: reversible unpublish/archive or physical deletion.
   V3-only nodes and locally edited targets must not be deleted implicitly.
4. First-adoption policy for an existing target that differs from current source.
   A missing baseline cannot establish whether differences are stale imports or
   local edits. Identical adoption must write metadata only, never save a node.
5. Fault-injection seam before/after target persistence and before acknowledgement.
6. Durable run bounds: source/type scope, fixed initial ceiling, cursor, retry set,
   entity/batch limits, deletion enumeration completeness, and mapping version.
7. A target-side counter snapshot API or SQL-observable invariants for saves,
   revision allocation, action nodes, queue inserts, files/media, and filesystem.

## Engine acceptance matrix

| Case | Change/setup | Required outcome |
|---|---|---|
| Identical first adoption | Existing target represents identical mapped state | Baseline/identity tracked; no node save, revision, action, queue, file or media write |
| Ambiguous first adoption | Existing target differs without any baseline | Conflict/explicit policy; never silently bless a stale destination as synchronized |
| New source | Source root absent in V3 and tracker | One creation with intended identity and dependencies; one acknowledgement |
| Unchanged repeat | Completed entity, same source and destination | No node/revision/file/media/queue writes |
| Field-only source edit | Change field_data value, leave node.changed unchanged | Detected and applied once |
| Source metadata-only edit | Raw source hash changes but mapped desired state is identical | No unnecessary node save; deliberate metadata baseline handling |
| Translation addition | Add family member or field language without changing root.changed | New translation appears; no unrelated translation overwrite |
| Translation removal | Remove a mapped source translation | Removal follows documented policy, preserving unrelated V3-local translations |
| Field removal | Remove a field row or mark deleted=1 | Explicit removal/empty value, not accidentally preserve stale destination content |
| Source unpublish | Same source exists with status=0 | Update publication state, not confuse with deletion |
| Source deletion | Previously tracked source is absent after complete enumeration | Apply documented deletion policy only to matching unchanged tracked target |
| V3-only target | Destination has no source/tracker relationship | Never a deletion candidate |
| Local edit then deletion | Tracked source disappears; destination changed locally | Conflict; preserve target and history |
| Retyping | Same source NID now belongs to another bundle | Explicit retype/conflict; never treat as deletion followed by a second identity |
| NID collision | Untracked unrelated target already owns intended NID | Conflict before any entity save or auto_increment allocation |
| Ambiguous tracker | One source has inconsistent destination/bundle mappings | Fail closed; no silent remapping |
| Destination disappearance | Tracker exists but target is gone | Explicit missing-target policy; cannot simply report no-op |
| Source-only 3-way change | Source differs from S0, destination still D0 | Apply source and atomically advance successful S/D baseline |
| Destination-only 3-way change | Source still S0, destination differs from D0 | Preserve local edit; do not reset D0 to D1 and erase future conflict detection |
| Simultaneous 3-way change | Source S1 and destination D1 both diverged | Conflict; no target overwrite or baseline acknowledgement |
| Non-overlapping local fields | V3-only field changes outside mapped scope | Preserve field; mapped-source update follows documented projection scope |
| Dry-run | Every operation incl. files, media and deletions | No DB writes, DDL, claims/locks with persistence, state, queues, filesystem changes or external calls |
| Plan/apply source race | Source differs from planned hash | Reject/replan explicitly; never acknowledge a stale plan |
| Plan/apply target race | Target changes after planning or during save | Reject/conflict; no lost local edit |
| Mapping race | Mapping/configuration changes after planning | Old plan cannot apply using new semantics |
| Crash before persistence | Fault before target save | No acknowledgement, retry remains eligible |
| Crash before acknowledgement | Fault after target save inside transaction | Target save and baseline acknowledgement roll back together, or durable recovery proves exactly-once outcome |
| Crash after commit | Simulated process loss after successful commit | Retry recognizes completed entity, no duplicate revisions/media/actions |
| Per-entity failure | Older ID fails, newer ID succeeds | Failure stays retryable; global cursor cannot hide the older failure |
| Batch/limit stop | Stop exactly at entity/time budget | Durable bounded progress; resume neither duplicates nor skips |
| Partial source scan | SQL/schema/fingerprint failure mid-enumeration | No deletion inference from incomplete membership |
| Wrong source instance | Different empty clone/schema with same type names | Source identity mismatch; no mass destination deletion |
| Empty legitimate source | Previously non-empty source type now empty | Explicit policy and complete authoritative scan; bounded deletion safeguards |
| New arrivals during run | Add NID beyond initial ceiling | Excluded this run, detected next run; does not corrupt completion |
| Large data | Many roots and large field/file/translation sets | Keyset batches, bounded memory, no per-node dependency SELECT explosion |
| Existing files | Same logical file URI/content but different local FID | Adopt mapping; no duplicate file/media entities or byte copy |
| Missing or unsafe file | Absent asset, private/public mismatch, traversal/symlink escape | Fail closed or stage safely; dry-run never invokes legacy file-copy helpers |

## High-risk invariants

- Local-only edits must NOT be accepted as a new destination baseline merely
  because the source did not change. Otherwise the next source edit overwrites
  those local changes without a conflict.
- A deletion sweep needs an authoritative, fully completed membership view. A
  timeout, wrong source configuration or type-filtered absence is not deletion.
- Retyping and translation-root changes affect identity as well as content.
- Source metadata and target content acknowledgements must be atomic with the
  target save; filesystem side effects require independent durable recovery or
  a contract limited to assets already synchronized before content application.
- Hashes must cover mapped current fields, multilingual families, referenced file
  metadata and current locations, independent of node.changed and SQL row order.
- First adoption must not allocate new revisions or incidental action/gallery
  nodes. Explicit per-entity skip flags must actually be honored by all hooks.
- Same-URI file bytes changed without DB metadata cannot be proven from database
  fingerprints alone; document the separate asset synchronization contract.

## Fixtures and isolation

The fixture writer must assert a local host, an exact labdoo_e2e_incremental_*
source database and an exact separate destination database. Engine source access
uses a SELECT-only account/session; setup mutations use another account. Fixture
credentials live only in ignored private state files with mode 0600. Settings
must disable automatic cron and external mail/geocoder/AWS transports. Every
mutating test checks the local labdoo_e2e flag, database prefix and E2E docroot.

## Executed fixture suites (2026-11-09)

Run from the `feature/incremental-sync` worktree at
`.e2e-lab/program/incremental`. Setup uses the existing local E2E administrator
credentials, stores new credentials only under ignored `.e2e-lab/state`, and
never changes the integration site or real V2 database:

```sh
python3 tests/support/incremental-setup.py
php tests/incremental-engine.php
python3 tests/support/incremental-drush.py php:script /var/www/html/labdoo3/.e2e-lab/program/incremental/tests/incremental-source-reader.php
python3 tests/support/incremental-drush.py php:script /var/www/html/labdoo3/.e2e-lab/program/incremental/tests/incremental-adapter.php
python3 tests/support/incremental-drush.py php:script /var/www/html/labdoo3/.e2e-lab/program/incremental/tests/incremental-assets-story.php
```

| Suite | Cases | Evidence |
|---|---:|---|
| Engine / real transactional DeltaStore | 25 | Three-way conflicts, first adoption, resumable bounds, rollback after write, retry language provenance, deletion reappearance, empty/mass-delete guards, changing membership, read-only preview, no per-entity rereads for unchanged batches |
| Real Drupal NodeSyncAdapter | 17 | Source SELECT-only account, one node/revision with no generated actions or queue inserts, tracker identity, adoption/no-op counts, timestamp-only no-op, field update/removal, translation addition/removal, publication/deletion/tombstone/reappearance, preview SELECT-only SQL, collision during planning, regenerated Actions guard, local edit protection |
| Real managed files / story paragraphs | 10 | Virtual projection, one metadata materialization, URI reuse across FIDs, missing/private/traversal/MIME/size rejection, unchanged/reordered paragraph identity and revision preservation, changed paragraph history, removed paragraph history, read-only comparison |
| Raw source reader (separate owner) | 26 | Current fields, locations, translations, files, story collections, bounded SELECT-only snapshots and deterministic normalized fingerprints |

Engine uses its own database `labdoo_e2e_incremental_engine_20261109` and
SQL-backed targets to exercise real transactional rollback. Adapter/helper
suites use `labdoo_e2e_incremental_dest_20261109` and a separate synthetic
`labdoo_e2e_incremental_source_20261109`. The source reader account has SELECT
privileges only. Fixture writers use a different account. Drupal dependencies
are copied where required to prevent Drush resolving the integration site.
All entity changes run inside an outer transaction which is rolled back; test
binary files are removed in `finally`. Schema/setup changes remain only in
these dedicated fixture databases.

The real adapter fixture explicitly makes the `team_post` title/body fields
translatable **inside its isolated transaction** to exercise supported
multilingual content. The current portal configuration makes those fields
shared; differing source translations in shared fields intentionally fail
closed. No production configuration or translations were changed. A local
fixture UID 1 is present for scoped trusted format validation; source owners
remain UID 0. The existing E2E mail collector and blocked external transports
remain active. Search API shutdown can warn about fixture translations removed
by the outer rollback; this does not indicate a missing production entity.

These suites test the stated cases, not every possible legacy source shape.
The checklist remains an acceptance/review matrix. Real source preview is
separate from synthetic write tests. Assets must already be synchronized;
content sync creates metadata only and does not fetch source binaries. Future
managed-file byte changes without metadata changes need independent file sync.
