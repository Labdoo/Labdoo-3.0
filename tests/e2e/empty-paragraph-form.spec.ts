import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test.describe('empty paragraph confirmation', () => {
  test.use({ role: 'admin' });
  test('redirects to the content list without deleting paragraphs @responsive', async ({ page }, info) => {
    const { stdout } = await promisify(execFile)('python3', [
      path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
      path.join(__dirname, 'support/empty-paragraph-form.php'),
    ], { timeout: 45000 });
    const result = JSON.parse(stdout.split('EMPTY_FORM_JSON:')[1].trim());
    expect(result.status).toBe(302);
    expect(result.unchanged_content).toBe(true);
    expect(result.location).toMatch(/\/admin\/config\/natiboo-migrate\/entities-list$/);
    expect((await goto(page, result.location, info)).status()).toBe(200);
    await expect(page.locator('h1').first()).toContainText('List Content');
  });
});
