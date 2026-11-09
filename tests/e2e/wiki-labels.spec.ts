import { test, expect, goto } from './helpers';

test.describe('wiki public labels', () => {
  test.use({ role: 'admin' });
  test('create page uses Wiki without Mini @responsive', async ({ page }, info) => {
    await goto(page, '/en/wiki-page/add', info);
    await expect(page.locator('h1').first()).toHaveText('Create Wiki Page');
    await expect(page.locator('main')).not.toContainText('mini Wiki');
    await expect(page.locator('form.mini-wiki-page-form')).toBeVisible();
  });
});
