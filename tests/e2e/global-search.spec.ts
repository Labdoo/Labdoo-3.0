import { test, expect, goto } from './helpers';
import type { Page, TestInfo } from '@playwright/test';

async function openSearch(page: Page, info: TestInfo) {
  await goto(page, '/en', info);
  const menu = page.locator('.ldn-menu-toggle');
  if (await menu.isVisible()) await menu.click();
  await page.locator('.search-icon-trigger').click();
  const input = page.locator('.search-overlay input.form-autocomplete');
  await expect(input).toBeVisible();
  return input;
}

test('global search suggestions can actually be clicked @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  await input.fill('Dootrip');
  const item = page.locator('.search-api-autocomplete-search .ui-menu-item').first();
  await expect(item).toBeVisible();
  const reachable = await item.evaluate(element => {
    const box = element.getBoundingClientRect();
    const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
    return hit !== null && element.contains(hit);
  });
  expect(reachable, 'Suggestions must be above the search overlay and receive pointer events').toBe(true);
  const destination = await item.evaluate(element => (window as any).jQuery(element).data('ui-autocomplete-item').url as string);
  expect(new URL(destination, page.url()).origin).toBe(new URL(page.url()).origin);
  await Promise.all([page.waitForURL(destination), item.click()]);
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
  await expect(page.getByRole('main')).toBeVisible();
});

test('global search displays one loading indicator rather than a tiled image @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const endpoint = new URL((await input.getAttribute('data-autocomplete-path'))!, page.url());
  await page.route(url => url.pathname === endpoint.pathname, async route => {
    await new Promise(resolve => setTimeout(resolve, 800));
    await route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await input.fill('Dootrip');
  await expect(input).toHaveClass(/ui-autocomplete-loading/);
  expect(await input.evaluate(element => getComputedStyle(element).backgroundRepeat)).toBe('no-repeat');
  await expect(input).not.toHaveClass(/ui-autocomplete-loading/);
});

test('global search recovers after an autocomplete HTTP failure @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const endpoint = new URL((await input.getAttribute('data-autocomplete-path'))!, page.url());
  await page.route(url => url.pathname === endpoint.pathname, route => route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }));
  const failed = page.waitForResponse(response => new URL(response.url()).pathname === endpoint.pathname && response.status() === 500);
  await input.fill('Dootrip');
  await failed;
  await expect(input).not.toHaveClass(/ui-autocomplete-loading/);
  expect(await input.evaluate(element => (window as any).jQuery(element).data('ui-autocomplete').pending)).toBe(0);
  await page.unrouteAll({ behavior: 'wait' });
  await input.fill('Dootronics');
  await expect(page.locator('.search-api-autocomplete-search .ui-menu-item').first()).toBeVisible();
});


test('global search traps focus, fits the viewport and restores its trigger @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(input).toBeFocused();
  const bounds = await dialog.boundingBox();
  expect(bounds).not.toBeNull();
  const viewport = page.viewportSize()!;
  expect(bounds!.x).toBeGreaterThanOrEqual(0);
  expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(bounds!.height).toBeLessThan(320);
  for (let i = 0; i < 5; i++) {
    await page.keyboard.press('Tab');
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  }
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(page.locator('.search-icon-trigger')).toBeFocused();
});

test('global search cancels superseded requests and closes cleanly while loading @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const endpoint = new URL((await input.getAttribute('data-autocomplete-path'))!, page.url());
  let oldAborted = false;
  page.on('requestfailed', request => {
    const url = new URL(request.url());
    if (url.pathname === endpoint.pathname && url.searchParams.get('q') === 'Older') oldAborted = true;
  });
  await page.route(url => url.pathname === endpoint.pathname, async route => {
    const term = new URL(route.request().url()).searchParams.get('q');
    await new Promise(resolve => setTimeout(resolve, term === 'Older' ? 1400 : 100));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ label: term, value: term }]) });
  });
  const oldRequest = page.waitForRequest(request => new URL(request.url()).searchParams.get('q') === 'Older');
  await input.fill('Older'); await oldRequest;
  await input.fill('Latest');
  await expect(page.locator('.search-api-autocomplete-search')).toContainText('Latest');
  await expect.poll(() => oldAborted).toBe(true);
  await expect(input).not.toHaveClass(/ui-autocomplete-loading/);
  const next = page.waitForRequest(request => new URL(request.url()).searchParams.get('q') === 'Pending');
  await input.fill('Pending'); await next;
  await page.locator('.search-overlay-close').click();
  await expect(page.getByRole('dialog')).not.toBeVisible();
  expect(await input.evaluate(element => (window as any).jQuery(element).data('ui-autocomplete').pending)).toBe(0);
  await page.locator('.search-icon-trigger').click();
  await expect(input).toBeFocused();
  await page.unrouteAll({ behavior: 'wait' });
});

test('reattaching Drupal behaviors keeps one autocomplete request and one dialog @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const endpoint = new URL((await input.getAttribute('data-autocomplete-path'))!, page.url());
  let requests = 0;
  await page.route(url => url.pathname === endpoint.pathname, route => { requests++; return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }); });
  await page.evaluate(() => { for (let i = 0; i < 3; i++) (window as any).Drupal.attachBehaviors(document); });
  await input.fill('Unique');
  await expect.poll(() => requests).toBe(1);
  await expect(input).not.toHaveClass(/ui-autocomplete-loading/);
  await page.waitForTimeout(600);
  expect(requests).toBe(1);
  await expect(page.getByRole('dialog')).toHaveCount(1);
  await expect(page.locator('.search-api-autocomplete-search')).toHaveCount(1);
});

test('closing search cancels a request still waiting for its debounce @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  const endpoint = new URL((await input.getAttribute('data-autocomplete-path'))!, page.url());
  let requests = 0;
  await page.route(url => url.pathname === endpoint.pathname, route => {
    requests++;
    return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await input.fill('Pending');
  await input.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await page.waitForTimeout(700);
  expect(requests).toBe(0);
  await expect(page.locator('.search-icon-trigger')).toBeFocused();
});

for (const language of ['en', 'es']) {
  test(`global search submits a query without suggestions (${language}) @responsive`, async ({ page }, info) => {
    await goto(page, `/${language}`, info);
    const toggle = page.locator('.ldn-menu-toggle');
    if (await toggle.isVisible()) await toggle.click();
    await page.locator('.search-icon-trigger').click();
    const input = page.locator('.search-overlay input.form-autocomplete');
    const query = 'labdoo-no-match-8a24960b';
    const response = page.waitForResponse(r => r.url().includes('search_api_autocomplete') && r.status() === 200);
    await input.fill(query);
    await response;
    await input.press('Enter');
    await expect(page).toHaveURL(new RegExp(`/${language}/search-results`));
    expect(new URL(page.url()).searchParams.get('search_api_fulltext')).toBe(query);
    await expect(page.locator('main .view-empty')).toBeVisible();
  });
}

test.describe('global search without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('the trigger reaches the real search form and submits a query @responsive', async ({ page }, info) => {
    await goto(page, '/en', info);
    await page.locator('.search-icon-trigger').click();
    await expect(page).toHaveURL(/\/en\/search-results/);
    const form = page.locator('form.ldn-search-fallback');
    await form.locator('input[name="search_api_fulltext"]').fill('Dootrip');
    await form.locator('[type="submit"]').first().click();
    expect(new URL(page.url()).searchParams.get('search_api_fulltext')).toBe('Dootrip');
    const result = page.locator('main .view-search-results tbody tr').first();
    await expect(result).toBeVisible();
    await expect(result.locator('a').first()).toHaveAttribute('href', /.+/);
    await expect(result).toContainText(/Dootrip/i);
  });
});


test('global search selects a real suggestion with the keyboard @responsive', async ({ page }, info) => {
  const input = await openSearch(page, info);
  await input.fill('Dootrip');
  const item = page.locator('.search-api-autocomplete-search .ui-menu-item').first();
  await expect(item).toBeVisible();
  const destination = await item.evaluate(element => (window as any).jQuery(element).data('ui-autocomplete-item').url as string);
  await input.press('ArrowDown');
  await Promise.all([page.waitForURL(destination), input.press('Enter')]);
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
});

test.describe('authenticated global search', () => {
  test.use({ role: 'basic' });
  test('a signed-in user can open, search and close the dialog @responsive', async ({ page }, info) => {
    const input = await openSearch(page, info);
    await input.fill('Dootrip');
    await expect(page.locator('.search-api-autocomplete-search .ui-menu-item').first()).toBeVisible();
    await input.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.locator('.search-icon-trigger')).toBeFocused();
  });
});
