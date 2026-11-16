---
name: regression-testing
description: Create or adapt Labdoo regression coverage, run the applicable Playwright, k6 and Lighthouse suites in tests, and inspect their results before completing application changes.
---

# Regression testing is part of the change

Read `tests/upgrade/README.md`, `tests/e2e/upgrade.config.ts` and `tests/performance/README.md` for the active checkout. All paths below are relative to the repository root. Use `tests/e2e/playwright.config.ts` only for an explicitly intended historical baseline/integration comparison; its projects and ports differ from the current lab configuration.

## Required coverage

For each application behavior change, add or adapt meaningful tests in `tests` as part of the implementation. For a bug fix, reproduce the failure with a test before fixing it where practical, then demonstrate that the corrected behavior passes. If existing coverage already asserts the precise behavior and regression, identify and run those cases; do not add redundant tests just to change a test file.

Cover observable outcomes, including applicable access roles, languages, responsive states, persistence, caching and error paths. Use the existing fixtures and guarded helpers. Do not weaken assertions, remove coverage, skip failing cases, increase retries or relax budgets to make a change appear successful. Intentional behavior changes require corresponding expectations and an explanation.

| Impact | Required execution |
| --- | --- |
| Any application behavior | Focused E2E cases, followed by the existing regression coverage of affected/shared flows. Run the full local E2E suite for shared infrastructure, broad changes or uncertain impact. |
| Frontend rendering, navigation, assets, accessibility or perceived performance | Relevant desktop/mobile E2E and Lighthouse routes/profiles. Add/adapt route coverage when existing routes do not represent the changed flow. |
| Queries, caching, queues, request handling or expected load | Relevant E2E plus the k6 scenario; adapt routes/checks if the change affects an uncovered request path. Preserve a comparable reference scenario. |
| Test runners, isolation or result interpretation | Focused runner/guard tests and the applicable end-to-end tool executions when their lab prerequisites are available. |
| Documentation or comments only | Link/diff validation and applicable syntax/skill validation. Explain why browser/load/audit runs do not add behavioral evidence. |

A user explicitly requesting a suite requires running it regardless of this impact selection. Do not automatically run a 13-minute load test for an unrelated documentation edit.

## Test the changed checkout

Use the suites and application code in the active checkout. `make e2e`, `make k6` and `make lighthouse` resolve their runners from this repository, not a hidden upgrade worktree. Keep data isolated using `web/sites/labdoo_e2e`, its restricted database account and private state under `tests/upgrade/.local`.

Prepare the local lab as documented, then keep `make e2e-serve` running in another terminal. Preparation requires private local source/admin inputs; do not invent credentials, reuse another worktree's connection manifest or overwrite the default site's settings. Retain the mail collector, controlled external services and database/HTTP guards. The local response's checkout identity must match the tests. Remote smoke results do not establish regression safety for uncommitted local changes. Never load-test production.

Examples after preparation, from the repository root:

```sh
make e2e LAB_RUN_ID=change-focused E2E_ARGS="dashboard-search.spec.ts --project=desktop"
make e2e LAB_RUN_ID=change-regression
make k6 LAB_RUN_ID=change-load
make lighthouse LAB_RUN_ID=change-audit LIGHTHOUSE_ARGS="--runs 3"
```

Use unique run IDs per attempt and explicit selections appropriate to the change. Do not use `--no-deps` unless the required authentication state is already valid for this run. The current configuration uses `desktop`/`mobile`, not the historical `integration` project.

## Inspect evidence, not just exit codes

- Playwright: inspect `tests/upgrade/.local/state/runs/<run>/results.json`, the HTML report and failure traces/screenshots. Count expected, unexpected, flaky, skipped and missing cases separately. An empty selection, unexpected skip or an expected-failure annotation is not proof of the required behavior. Use `node tests/upgrade/compare.mjs <before>/results.json <after>/results.json` for compatible references; review added/renamed cases explicitly when the suite changes.
- k6: inspect `tests/performance/.local/k6-<run>.json`, thresholds, request/check failures, p95 latency and sample volume. The current budgets are p95 below 2000 ms, failed requests below 2%, and all functional/isolation checks passing. A runner exiting successfully does not by itself establish a comparable performance result.
- Lighthouse: inspect `tests/performance/.local/lighthouse-<run>/summary.json` and route/profile JSON/HTML reports. Check runtime errors, warnings, failed audits, accessibility and performance metrics, not only scores. The runner has no automatic regression budget: successful execution is not a performance pass. Compare the same routes, profiles, tool version and conditions before/after; report all runs and medians for repeated audits, never only the best result. Investigate deterioration and explain any remaining uncertainty.

Fix regressions introduced by the change and rerun the failed cases and affected regression coverage. Classify existing failures against a comparable reference rather than hiding them. If a reference is unavailable, state that the current run does not establish a before/after performance comparison.

## Deferred failures

When the user chooses to repair failures later, record each distinct unresolved issue in the tracker or tracking document selected for the task. Check for duplicates. Include the affected cases, observed and expected behavior, reproduction command, checkout/revision, environment and a sanitized evidence reference. Group cases only when a shared cause is supported by evidence. Distinguish product defects, fixture/environment problems and runner errors; do not assert an unverified root cause.

Keep the workflow independent of any tracking provider. If the selected destination is unavailable, retain a local issue record and report that synchronization is pending. Filing an issue does not make a failed, skipped or unexecuted check pass. Do not postpone regressions introduced by the current change unless the user explicitly asks to defer those too.

## Completion gate

Before declaring the application change verified, report the tested checkout/revision (including dirty changes), target, commands/run IDs, changed or reused tests, counts, performance/audit comparisons and private artifact paths. Tests must finish and their reports must be reviewed; starting a runner or pointing at a dashboard is insufficient.

If tools, credentials, fixtures or a prepared clone are unavailable, perform independent checks that remain possible and report validation as incomplete with the exact blocker. Do not report an unexecuted suite as passed or claim that nothing regressed. This skill is an agent instruction; it does not create an automated CI merge gate.
