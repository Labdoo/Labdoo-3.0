import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

async function run(op: string) {
  const { stdout } = await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/dootrip-integrity.php')], { env: { ...process.env, LABDOO_PROGRAM_TEST_OP: op } });
  return JSON.parse(stdout.split('PROGRAM_JSON:')[1].trim());
}
test('trip clone gets a fresh identifier and holds its allocation until commit @responsive', async ({ page }, info) => {
  const fixture = await run('create');
  try {
    await goto(page, `/en/node/${fixture.nid}`, info);
    await expect(page.locator('h1').first()).toContainText(fixture.title);
  }
  finally {
    await run('cleanup');
  }
});
