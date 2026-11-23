import { test, expect, goto } from './helpers';

test('search enhancements load on first use and are reused after closing @responsive', async ({ page }, info) => {
  let loads = 0;
  page.on('request', request => { if (new URL(request.url()).pathname.endsWith('/labdoo/search-assets')) loads++; });
  // Dashboard filters legitimately load core autocomplete before header search.
  // A content page isolates the header search's deferred dependency contract.
  await goto(page, '/en/content/labdoo-social-network-how-it-works', info);
  expect(await page.evaluate(() => Boolean((window as any).Drupal.autocomplete))).toBe(false);
  expect(loads).toBe(0);
  const toggle = page.locator('.ldn-menu-toggle');
  if (await toggle.isVisible()) await toggle.click();
  const trigger = page.locator('.search-icon-trigger');
  await trigger.focus();
  await trigger.press('Enter');
  const input = page.locator('.search-overlay input.form-autocomplete');
  await expect(input).toBeFocused();
  await input.fill('Dootrip');
  await expect(page.locator('.search-api-autocomplete-search .ui-menu-item').first()).toBeVisible();
  expect(loads).toBe(1);
  await input.press('Escape');
  await trigger.click();
  await expect(input).toBeFocused();
  expect(loads).toBe(1);
});

test('dashboard filter autocomplete survives loading global search @responsive', async ({ page }, info) => {
  let loads = 0;
  page.on('request', request => { if (new URL(request.url()).pathname.endsWith('/labdoo/search-assets')) loads++; });
  await goto(page, '/en/content/edoovillages-dashboard', info);
  const filter = page.locator('.ldn-sidebar input.form-autocomplete').first();
  await expect(filter).toBeVisible();
  await filter.fill('Hub');
  await expect(page.locator('.ui-autocomplete:visible .ui-menu-item').first()).toBeVisible();
  await filter.press('Escape');
  await filter.fill('');
  expect(loads).toBe(0);
  expect(await page.evaluate(() => Boolean((window as any).Drupal.behaviors.labdooGlobalSearch))).toBe(false);

  const toggle = page.locator('.ldn-menu-toggle');
  if (await toggle.isVisible()) await toggle.click();
  await page.locator('.search-icon-trigger').click();
  const input = page.locator('.search-overlay input.form-autocomplete');
  await expect(input).toBeFocused();
  await input.fill('Dootrip');
  await expect(page.locator('.search-api-autocomplete-search .ui-menu-item').first()).toBeVisible();
  expect(loads).toBe(1);
  await input.press('Escape');
  if (await toggle.isVisible() && await toggle.getAttribute('aria-expanded') === 'true') await toggle.click();
  await filter.fill('Hub');
  await expect(page.locator('.ui-autocomplete:visible .ui-menu-item').first()).toBeVisible();
  expect(loads).toBe(1);
});

test('a search asset request failure falls back to the ordinary search page @responsive', async ({ page }, info) => {
  await page.route('**/labdoo/search-assets*', route => route.fulfill({ status: 503, contentType: 'application/json', body: '[]' }));
  await goto(page, '/es', info);
  const toggle = page.locator('.ldn-menu-toggle');
  if (await toggle.isVisible()) await toggle.click();
  await page.locator('.search-icon-trigger').click();
  await expect(page).toHaveURL(/\/es\/search-results/);
  await expect(page.locator('main')).toBeVisible();
});
