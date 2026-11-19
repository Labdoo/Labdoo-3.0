# Labdoo agent instructions

These instructions apply throughout this repository. Follow the user's task and runtime permissions; repository guidance does not authorize unrelated operations. If a nested `AGENTS.md` is added, apply its more specific guidance to that subtree. When prose contradicts the active branch's implementation, inspect the code and report the mismatch before relying on the disputed command.

## Start here

- Inspect the branch, destination when relevant, and staged/unstaged changes. Preserve unrelated work and staging.
- Compare installed runtime/dependency versions with the lockfile before claiming compatibility with the target version. Record mismatches; results against the installed runtime do not certify a different locked version.
- Read [the workflow and validation guide](docs/agent-workflow.md) before choosing tests or operational commands.
- Use [TECHNICAL.md](TECHNICAL.md) for the domain/module map, then inspect the affected module's code and service definitions. Historical setup notes and command examples are not proof of current behavior.
- Keep source comments, docblocks, skills and technical documentation in English. Preserve and support translated product content.

## Shared skills

Project skills live in `.agents/skills`, independently of a particular editor. Agents that support skill discovery may load them automatically; other agents should read the relevant `SKILL.md` from this index. Do not assume every agent supports the same discovery mechanism.

| Task | Read |
| --- | --- |
| Domain behavior, entities and service boundaries | [architecture](.agents/skills/architecture/SKILL.md) |
| PHP/frontend edits, access, caching and style | [coding-standards](.agents/skills/coding-standards/SKILL.md) |
| Commits, reviews and pushes | [git-conventions](.agents/skills/git-conventions/SKILL.md) |
| Interface/content/configuration translation | [multilingual](.agents/skills/multilingual/SKILL.md) |
| Queue producers, payloads and workers | [queue-philosophy](.agents/skills/queue-philosophy/SKILL.md) |
| Migration, synchronization, revision repair and purge | [migrations](.agents/skills/migrations/SKILL.md) |
| Regression coverage, E2E, k6, Lighthouse and result review | [regression-testing](.agents/skills/regression-testing/SKILL.md) |
| Deployment, runtime permissions and configuration operations | [operations](.agents/skills/operations/SKILL.md) |

`.junie` is a retired instruction location. Maintain shared guidance here and in `.agents/skills`; any remaining historical tool state is not the instruction source.

## Mandatory regression validation

Before changing application code or test infrastructure, read [.agents/skills/regression-testing/SKILL.md](.agents/skills/regression-testing/SKILL.md). Creating/adapting applicable tests, executing them and inspecting their reports are part of the task, not an optional follow-up. Existing tests may be reused when they assert the precise behavior; identify them and explain coverage. Documentation-only changes require proportionate validation as described in the skill.

The maintained suites are in `./tests`: Playwright E2E, k6 and Lighthouse. Test the active checkout's code using its isolated test site/database. Do not substitute results from a different worktree or a remote deployment for local change validation. Follow [tests/AGENTS.md](tests/AGENTS.md) when working on tests.

Do not declare a behavior change verified while required tests are failing, unexecuted, skipped unexpectedly or their reports are unreviewed. If prerequisites are unavailable, state the exact blocker and the verification still outstanding. Never weaken tests, hide failures or relax performance budgets to produce a passing result.

## Pending test failures

When the user asks to defer repairs, record each distinct unresolved failure in the issue tracker or tracking document selected for the task. Search for duplicates and include the affected test, observed/expected result, reproduction command, tested revision/environment and a sanitized evidence reference. Keep tracker-specific configuration out of shared skills. Filing an issue does not turn a failed or unexecuted test into a pass; report the remaining verification limits explicitly.

## Boundaries

- A local checkout can connect to live data. Establish the Drupal root, database and environment before mutations; do not print secrets to establish them.
- Use isolated clones and controlled external services for tests. Do not send real email, process live queues or call paid providers as a side effect of ordinary validation.
- A code edit or push does not imply authorization to deploy, import configuration, update schema, repair data or delete entities. Perform operations within the user's requested scope.
- Keep secrets, SQL dumps, private settings, uploads, sessions and private reports out of commits. Avoid recursive permission changes; public code readability and private data permissions have different requirements.
- Use explicit paths/hunks for focused commits and inspect their complete content. Do not commit an already populated index wholesale. Adding a previously untracked file is broader than translating its comments.

## Completion evidence

Run checks proportionate to the change using the [validation matrix](docs/agent-workflow.md#validation). Report what changed, what was checked and material limits. Missing PHPCS/PHPStan/PHPUnit or unavailable fixtures must be reported, not treated as successful checks. When publishing, verify that referenced project files exist on the destination branch and verify the resulting remote ref.
