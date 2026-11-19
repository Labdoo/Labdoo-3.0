import { test, expect, stateDir, variant } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('translation command uses a selected local agent and preserves PO files on failure', async ({}, info) => {
  test.setTimeout(120000);
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), variant(info), 'php:script',
    path.join(__dirname, 'support/translation-agent.php'),
  ], { timeout: 110000, maxBuffer: 1024 * 1024 });
  expect(stdout).toContain('translation_agent_integration=passed');
});
