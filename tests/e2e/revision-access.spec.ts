import { test, expect, goto, covers, stateDir, networkGuard } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

let fixture: { id: number; old_revision: number };
test.beforeAll(async () => {
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/prepare-revision-access.php')], { timeout: 30000 });
  fixture = JSON.parse(fs.readFileSync(path.join(stateDir, 'revision-access-fixture.json'), 'utf8'));
});

for (const role of ['anonymous', 'basic'] as const) {
  test.describe(role + ' revision reader', () => {
    test.use({ role });
    test('historical dootrip revisions are readable without mutation controls', covers('dootrip-public-revisions'), async ({ page }, info) => {
      expect((await goto(page, `/en/node/${fixture.id}/revisions`, info)).status()).toBe(200);
      await expect(page.locator('main .node-revision-table tbody tr')).toHaveCount(2);
      await expect(page.locator('main a[href*="/revert"], main a[href*="/delete"]')).toHaveCount(0);
      expect((await goto(page, `/en/node/${fixture.id}/revisions/${fixture.old_revision}/view`, info)).status()).toBe(200);
      for (const action of ['revert', 'delete']) {
        expect((await goto(page, `/en/node/${fixture.id}/revisions/${fixture.old_revision}/${action}`, info)).status()).toBe(403);
      }
    });
  });
}

test.describe('revision administrator', () => {
  test.use({ role: 'admin' });
  test('authorized controls remain visible and do not leak to an anonymous session', covers('dootrip-public-revisions'), async ({ page, browser, baseURL }, info) => {
    const url = `/en/node/${fixture.id}/revisions`;
    await goto(page, url, info);
    await expect(page.locator('main a[href*="/revert"]')).toHaveCount(1);
    await expect(page.locator('main a[href*="/delete"]')).toHaveCount(1);
    expect((await goto(page, `/en/node/${fixture.id}/revisions/${fixture.old_revision}/revert`, info)).status()).toBe(200);
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    await networkGuard(context);
    const anonymous = await context.newPage();
    await goto(anonymous, url, info);
    await expect(anonymous.locator('main a[href*="/revert"], main a[href*="/delete"]')).toHaveCount(0);
    await context.close();
  });
});
