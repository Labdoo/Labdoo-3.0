import { test, expect, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('changed services and factories retain their runtime contracts', async () => {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/quality-service-contracts.php'),
  ], { timeout: 45000 });
  expect(JSON.parse(stdout.split('QUALITY_SERVICES_JSON:')[1].trim())).toEqual({
    services: 10, repository_pairs: 3, blocks: 6, forms: 3,
  });
});
