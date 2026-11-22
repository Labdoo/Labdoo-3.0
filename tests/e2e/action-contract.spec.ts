import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes } from 'node:crypto';
import path from 'node:path';

async function run(operation: string, token: string) {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/action-contract.php'),
  ], { env: { ...process.env, LABDOO_ACTION_TEST_OP: operation, LABDOO_ACTION_TEST_TOKEN: token }, timeout: 60000 });
  return JSON.parse(stdout.split('ACTION_JSON:')[1].trim());
}

test.describe('node action contracts', () => {
  test.use({ role: 'admin' });
  test('four node generators persist navigable actions and mine excludes another owner @responsive', async ({ page }, info) => {
    test.setTimeout(150000);
    const token = randomBytes(8).toString('hex');
    try {
      const fixture = await run('create', token);
      expect(fixture.cases.map(item => item.type)).toEqual(['Dooject', 'Dootrip', 'Edoovillage', 'Hub']);
      expect(fixture.updates.map(item => item.type)).toEqual(['Dooject', 'Dootrip']);
      for (const item of [...fixture.cases, ...fixture.updates]) {
        await goto(page, `/en/node/${item.action}`, info);
        const link = page.locator(`main a[href$="/node/${item.source}"]`).first();
        await expect(link).toBeVisible();
        await link.click();
        await expect(page.locator('h1').first()).toContainText(item.title);
      }
      for (const language of ['en', 'es']) {
        expect((await goto(page, `/${language}/actions`, info)).status()).toBe(200);
        await expect(page.locator('main')).toContainText(fixture.foreign_title);
        expect((await goto(page, `/${language}/actions?mine=1`, info)).status()).toBe(200);
        await expect(page.locator('main')).not.toContainText(fixture.foreign_title);
        for (const item of fixture.cases) {
          await expect(page.locator(`main a[href$="/node/${item.source}"]`).first()).toBeVisible();
        }
      }
    }
    finally {
      expect(await run('cleanup', token)).toEqual({ cleaned: true });
    }
  });
});
