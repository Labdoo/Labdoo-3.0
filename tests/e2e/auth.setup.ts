import { test as setup, expect } from '@playwright/test';
import { authPath, fixtures, goto, networkGuard, variant } from './helpers';

setup('authenticate isolated test roles through the login form', async ({ browser, baseURL }, info) => {
  setup.setTimeout(180000);
  const data = fixtures(info);
  for (const [role, account] of Object.entries<any>(data.accounts)) {
    const context = await browser.newContext({ baseURL });
    await networkGuard(context);
    const page = await context.newPage();
    await goto(page, '/en/user/login', info);
    await page.locator('input[name="name"]').fill(account.name);
    await page.locator('input[name="pass"]').fill(account.password);
    await Promise.all([
      page.waitForURL(url => !url.pathname.endsWith('/user/login'), { waitUntil: 'domcontentloaded' }),
      page.locator('form.user-login-form').getByRole('button', { name: 'Log in', exact: true }).click(),
    ]);
    await expect(page.locator('body')).not.toContainText('Unrecognized username or password');
    if (new URL(page.url()).pathname.endsWith('/legal_accept')) {
      await page.locator('input[name="legal_accept"]').check();
      await Promise.all([
        page.waitForURL(url => !url.pathname.endsWith('/legal_accept'), { waitUntil: 'domcontentloaded' }),
        page.locator('main').getByRole('button', { name: 'Confirm', exact: true }).click(),
      ]);
    }
    expect((await context.cookies()).some(cookie => /^S?SESS/.test(cookie.name)), `Session established for ${role}`).toBeTruthy();
    await goto(page, `/en/user/${account.id}`, info);
    await expect(page.locator('body')).toContainText(account.name);
    await context.storageState({ path: authPath(variant(info), role) });
    await context.close();
  }
});
