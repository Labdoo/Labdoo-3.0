---
name: git-conventions
description: Prepare focused Labdoo commits, reviews and pushes while preserving unrelated staged and working-tree changes.
---

# Git workflow

Inspect the branch, remote and both staged and unstaged changes before preparing a commit. Confirm the requested destination; do not infer it from an old document mentioning `main` or `develop`.

Preserve unrelated edits and staging. Stage explicit paths or hunks and inspect the complete staged diff before committing. If the index already contains unrelated work, use an isolated checkout or worktree rather than committing that index or resetting the user's staging.

If an edited file does not exist on the destination branch, distinguish a comment-only edit from adding the whole file. Do not silently include pre-existing additions with a narrow change.

Keep commits to one logical change with a descriptive message. Changes to Composer or Drupal configuration are appropriate when required by the task; explain their purpose and validation. Do not include SQL dumps, credentials, private keys, sessions, personal data or private test reports.

Read [CodeReviewProcess.md](../../../docs/dev-process/CodeReviewProcess.md) before preparing a review. Push, merge and deployment are separate actions: perform the action the user authorized, and verify the resulting remote ref after a push. Do not force-push shared branches unless explicitly authorized.
