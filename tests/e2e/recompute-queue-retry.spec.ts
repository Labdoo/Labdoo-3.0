import { test, expect, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('recompute workers retain failed database queue items and consume successful retries', async ({}, info) => {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/recompute-queue-retry.php'),
  ], { timeout: 45000 });
  const report = JSON.parse(stdout.split('QUEUE_RETRY_JSON:')[1].trim());
  expect(report.passed).toBe(2);
  expect(report.cases).toEqual([
    'dootronic: failed save retained, retry consumed, deleted entity terminal',
    'dootrip: failed save retained, retry consumed, deleted entity terminal',
  ]);
  await info.attach('queue-retry-cases', {
    body: JSON.stringify(report, null, 2), contentType: 'application/json',
  });
});
