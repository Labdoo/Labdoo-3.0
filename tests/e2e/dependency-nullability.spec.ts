import { test, expect, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('installed dependency nullable relations match the static contracts', async () => {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/dependency-nullability.php'),
  ], { timeout: 45000 });
  expect(JSON.parse(stdout.split('NULLABILITY_JSON:')[1].trim())).toEqual({
    missing_owner: true, missing_source_form: true,
    fluent_select: true, po_end_of_file: true, missing_override_language: true,
  });
});
