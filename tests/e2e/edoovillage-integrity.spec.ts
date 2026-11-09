import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

async function run(op: string) {
  const { stdout } = await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/edoovillage-integrity.php')], { env: { ...process.env, LABDOO_PROGRAM_TEST_OP: op } });
  return JSON.parse(stdout.split('PROGRAM_JSON:')[1].trim());
}
async function follow(page: any, label: string) {
  const toggle = page.locator('.block-edoovillage-actions-block-block .block-actions-toggle');
  if (await toggle.isVisible() && !(await toggle.getAttribute('class'))?.includes('is-active')) await toggle.click();
  await page.locator('.action a').filter({ hasText: label }).click();
}
test('school numbering and next/previous update immediately after cloning @responsive', async ({ page }, info) => {
  await page.context().addCookies([{ name: 'cookie-agreed', value: '0', url: 'http://127.0.0.1:18381' }]);
  // Warm the old neighbor block before creating the replacement for the gap.
  await goto(page, '/en/node/253363', info);
  const fixture = await run('create');
  try {
    expect(fixture.number).toBe(3175);
    expect(fixture.title_length).toBeLessThanOrEqual(255);
    await goto(page, '/en/node/253363', info);
    await follow(page, 'Next edoovillage');
    await expect(page.locator('h1').first()).toContainText('Edoovillage #3175 - Germany, Bonn:');
    await follow(page, 'Next edoovillage');
    await expect(page.locator('h1').first()).toContainText('Edoovillage #3176');
    await follow(page, 'Previous edoovillage');
    await expect(page.locator('h1').first()).toContainText('Edoovillage #3175');
  }
  finally {
    await run('cleanup');
  }
  await goto(page, '/en/node/253363', info);
  await follow(page, 'Next edoovillage');
  await expect(page.locator('h1').first()).toContainText('Edoovillage #3176');
});
