import { test, expect, stateDir, variant } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('dootrip weight configuration preserves existing values over 100 characters', async ({}, info) => {
  const script = path.join(__dirname, 'support/check-dootrip-weight-storage.php');
  const drush = path.join(stateDir, '../tools/drush.py');
  const { stdout } = await promisify(execFile)('python3', [drush, variant(info), 'php:script', script], { timeout: 30000 });
  expect(stdout).toContain('weight_storage_limit=255');
  expect(stdout).toContain('weight_column_limit=255');
  expect(stdout).toContain('values_over_100=301');
  expect(stdout).toContain('maximum_existing_value=110');
});
