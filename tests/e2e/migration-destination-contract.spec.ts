import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes } from 'node:crypto';
import path from 'node:path';

async function run(operation: string, token: string) {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/migration-destination-contract.php'),
  ], { env: { ...process.env, LABDOO_MIGRATION_TEST_OP: operation, LABDOO_MIGRATION_TEST_TOKEN: token }, timeout: 60000 });
  return JSON.parse(stdout.split('MIGRATION_JSON:')[1].trim());
}

test.describe('migration destination contracts', () => {
  test.use({ role: 'admin' });
  test('mapped content, translations, comments and profiles persist and render @responsive', async ({ page }, info) => {
    test.setTimeout(150000);
    const token = randomBytes(8).toString('hex');
    try {
      const fixture = await run('create', token);
      expect(fixture.shared_fields).toBe(true);
      for (const language of ['en', 'es']) {
        expect((await goto(page, `/${language}/node/${fixture.node}`, info)).status()).toBe(200);
        await expect(page.locator('h1').first()).toContainText(fixture.title);
        await expect(page.locator('main')).toContainText(fixture.body);
      }
      await goto(page, `/en/node/${fixture.node}`, info);
      await expect(page.locator('article.comment').filter({ hasText: fixture.comment_body })).toBeVisible();
      await expect(page.locator('article.comment').filter({ hasText: fixture.private_comment_body })).toBeVisible();
      expect((await goto(page, `/en/user/${fixture.user}`, info)).status()).toBe(200);
      await expect(page.locator('h1').first()).toContainText(fixture.user_name);
    }
    finally {
      expect(await run('cleanup', token)).toEqual({ cleaned: true });
    }
  });
});
