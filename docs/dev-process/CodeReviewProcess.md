# Code review process

Follow [AGENTS.md](../../AGENTS.md) and the [validation guide](../agent-workflow.md). This process replaces the historical Bitbucket/BLT instructions; confirm the actual repository and destination branch for each review.

## Scope and integration

- State the concrete problem, resulting behavior and how it was validated.
- Keep the diff focused; exclude unrelated staging, generated reports, private data and credentials.
- Review dependency and configuration changes when required by the feature. Explain why they belong in the change rather than applying a blanket exclusion.
- Compare against the intended destination branch. Use an isolated checkout/worktree when the current workspace contains unrelated work.
- Describe missing tools, existing failures and untested paths honestly. A Makefile target or a past successful run is not current validation evidence.
- Push, merge and deployment require their own task scope; review approval alone does not imply deployment.

## Custom code checklist

1. Follow Drupal standards, the project EditorConfig, strict typing and compatible type hints for new PHP code.
2. Keep comments and technical documentation in English and user-facing strings translatable. Use the appropriate PHP, Twig and JavaScript translation APIs and placeholders.
3. Preserve route/entity access and test the affected roles. Escape rendered user content, parameterize queries and avoid logging sensitive data.
4. Preserve cache tags, contexts and max-age. Check for cache-dependent privilege leaks and stale output after entity changes.
5. Inspect entity hooks, revisions, translations, notifications, queue effects and search tracking when changing persistence.
6. Keep queue workers safe to retry and compatible with existing payloads. Review migration idempotence and source/destination boundaries.
7. Avoid duplicate services, hard-coded runtime configuration, debug output, conflict markers and unexplained commented-out code.
8. For UI changes, check responsive layout, keyboard behavior and meaningful accessibility effects. Review generated asset changes alongside their sources.
9. Run the focused checks warranted by the change in an isolated environment. Inspect whether tests actually exercise the changed revision and behavior.
10. Update relevant documentation and call out any separate configuration, schema, deployment or repair step.

## Review environment

Read the E2E guide on the branch under review, where available. Use cloned databases and files, guarded Drupal commands and controlled external services. Do not test entity submissions, migrations or queue processing against a public/live site.

After validation, report the revision, relevant results and practical limitations. Preserve unrelated branches, worktrees and local changes; cleanup is limited to disposable resources created for the review.
