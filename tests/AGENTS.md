# Instructions for Labdoo tests

Read the mandatory [regression-testing skill](../.agents/skills/regression-testing/SKILL.md). It defines coverage selection, execution, result inspection and the completion gate for application and test changes.

- Current local harness: [upgrade/README.md](upgrade/README.md) and `e2e/upgrade.config.ts` (desktop/mobile).
- Historical comparison harness: [e2e/README.md](e2e/README.md) and `e2e/playwright.config.ts` (baseline/integration). Do not mix their ports, fixtures, projects or artifact paths.
- Performance tools and measurement constraints: [performance/README.md](performance/README.md).

Preserve fixture isolation and assertions in `e2e/helpers.ts` and the guarded Drupal support scripts. Browser tests must verify user-visible behavior and relevant persistence/access effects, not just HTTP 200 or the presence of a test fixture. Cover known bugs with regression assertions.

For runner/isolation changes, the database-free checks include:

```sh
php tests/upgrade/isolation.test.php
python3 tests/upgrade/lab_test.py
python3 tests/performance/lighthouse_test.py
node --test tests/upgrade/compare.test.mjs tests/upgrade/environments.test.mjs tests/performance/load-profile.test.mjs
```

These checks validate runner boundaries and result handling; they do not replace real Playwright, k6 or Lighthouse runs against a prepared test site.

When repairs are deferred, use the task's chosen tracking destination and the root instructions for recording distinct failures. Include reproduction and evidence without private fixtures or credentials; do not hard-code a tracking service in the shared testing workflow.
