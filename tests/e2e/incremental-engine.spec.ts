import { test, expect, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('incremental manifests preserve transactions, retries, conflicts and array fetch results', async ({}, info) => {
  test.setTimeout(120000);
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.resolve(__dirname, '../incremental-engine.php'),
  ], { timeout: 110000 });
  const start = stdout.search(/\{\s*"passed":/);
  expect(start, 'The guarded engine must return a completed report').toBeGreaterThanOrEqual(0);
  const report = JSON.parse(stdout.slice(start));
  expect(report.passed).toBe(27);
  expect(report.cases).toHaveLength(27);
  expect(new Set(report.cases).size).toBe(27);
  expect(report.cases).toEqual(expect.arrayContaining([
    'first adoption equal has zero content writes and retry idempotence',
    'postwrite crash rolls back destination and baseline; retry skips committed entity',
    'local edit is preserved without resetting baseline; later source edit conflicts',
    'manifest listing keeps array rows, pagination, filters and decoded details',
    'pending dependencies keep newest array rows and isolate source types',
  ]));
  await info.attach('incremental-engine-cases', {
    body: JSON.stringify(report, null, 2), contentType: 'application/json',
  });
});

test('migration reads preserve sequence selection, user preview and auto-increment guards', async ({}, info) => {
  test.setTimeout(120000);
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.resolve(__dirname, '../incremental-fetch-modes.php'),
  ], { timeout: 110000 });
  const start = stdout.search(/\{\s*"passed":/);
  expect(start, 'The guarded migration reads must return a completed report').toBeGreaterThanOrEqual(0);
  const report = JSON.parse(stdout.slice(start));
  expect(report.passed).toBe(10);
  expect(report.cases).toHaveLength(10);
  expect(new Set(report.cases).size).toBe(10);
  expect(report.cases).toEqual(expect.arrayContaining([
    'sequence gaps select the matching source node without writes',
    'user preview reads profile and locations without leaking private source values',
    'preview role policy still blocks source administrator privileges',
    'prefixed and database-qualified metadata detect sequence drift without row changes',
  ]));
  await info.attach('migration-read-cases', {
    body: JSON.stringify(report, null, 2), contentType: 'application/json',
  });
});
