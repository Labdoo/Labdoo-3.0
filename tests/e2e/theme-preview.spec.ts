import { test, expect, goto, fixtures, stateDir, networkGuard } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

const cli = promisify(execFile);
async function snapshot() {
  const { stdout } = await cli('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:eval', '$r=\\Drupal::database()->query("SELECT COUNT(*) AS total,MAX(nid) AS last_id FROM node")->fetchAssoc();$r["default"]=\\Drupal::config("system.theme")->get("default");echo "SNAPSHOT:".json_encode($r);']);
  return JSON.parse(stdout.split('SNAPSHOT:')[1].trim());
}
let before: any;
test.beforeAll(async () => { before = await snapshot(); });
test.afterAll(async () => {
  expect(await snapshot()).toEqual(before);
  expect(before.default).toBe('labdoo');
});

const routes = [
  ['dashboard', '/en/content/dootronics-dashboard'],
  ['school', '/en/node/749'],
  ['hub', '/en/node/440'],
  ['dootrip', '/en/node/16086'],
  ['wiki', '/en/content/labdoo-social-network-how-it-works'],
  ['gallery', '/en/node/748'],
  ['team', '/en/team-wall/23'],
] as const;
for (const [name, url] of routes) {
  test(`Next theme preserves ${name} content and fits the viewport @responsive`, async ({ page }, info) => {
    const response = await goto(page, url + '?theme=labdoo_next', info);
    expect(response.status()).toBe(200);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('.ldn-impact .ldn-metric')).toHaveCount(7);
    await expect(page.locator('.ldn-header .ldn-metric')).toHaveCount(0);
    const wrapped = await page.locator('.ldn-metric strong').evaluateAll(es => es.some(el => el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * 1.5));
    expect(wrapped, 'Metric values must remain on a single line').toBe(false);
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.locator('.ldn-brand img')).toHaveJSProperty('complete', true);
    expect(await page.locator('.ldn-brand img').evaluate((i: HTMLImageElement) => i.naturalWidth)).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath(`next-${name}.png`), fullPage: false });
  });
}

test('preview is isolated from the default across anonymous sessions', async ({ page, browser, baseURL }, info) => {
  const url = '/en/content/dootronics-dashboard';
  await goto(page, url, info);
  await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
  await goto(page, url + '?theme=labdoo_next', info);
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
  const other = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  await networkGuard(other);
  const visitor = await other.newPage();
  await goto(visitor, url, info);
  await expect(visitor.locator('body')).not.toHaveClass(/labdoo-next/);
  await goto(page, url, info);
  await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
  await other.close();
});

test('navigation keeps preview mode and the exit link preserves other query parameters', async ({ page }, info) => {
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next&combine=000000141', info);
  const original = page.getByRole('link', { name: 'Back to original' });
  const exit = await original.getAttribute('href');
  expect(exit).not.toContain('theme=');
  expect(exit).toContain('combine=000000141');
  const links = page.locator('.ldn-navigation a');
  expect(await links.count()).toBeGreaterThan(10);
  for (const href of await links.evaluateAll(es => es.map(e => (e as HTMLAnchorElement).href))) {
    const url = new URL(href);
    if (url.origin === new URL(page.url()).origin && !/\/(admin(?:\/|$)|user\/logout)/.test(url.pathname)) {
      expect(url.searchParams.get('theme')).toBe('labdoo_next');
    }
    else {
      expect(url.searchParams.has('theme')).toBe(false);
    }
  }
  await original.click();
  await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
});

test('exposed search and sorting retain the preview parameter', async ({ page }, info) => {
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
  const id = fixtures(info).nodes['Laptop Main'].title;
  await page.locator('input[name="combine"]').fill(id);
  await page.locator('form').filter({ has: page.locator('input[name="combine"]') }).getByRole('button', { name: /Apply|Search/i }).click();
  expect(new URL(page.url()).searchParams.get('theme')).toBe('labdoo_next');
  await expect(page.locator('main table')).toContainText(id);
  await page.locator('main table thead a[href*="order="]').first().click();
  expect(new URL(page.url()).searchParams.get('theme')).toBe('labdoo_next');
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
});

test('unknown and array theme parameters cannot select another theme', async ({ page }, info) => {
  for (const value of ['theme=gin', 'theme=../../gin', 'theme%5B%5D=labdoo_next']) {
    expect((await goto(page, '/en/content/dootronics-dashboard?' + value, info)).status()).toBe(200);
    await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
  }
});

test('mobile menu is keyboard accessible and closes with Escape @responsive', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await goto(page, '/en/node/749?theme=labdoo_next', info);
  const button = page.getByRole('button', { name: 'Menu' });
  await expect(button).toBeVisible();
  await expect(page.locator('#ldn-navigation')).not.toBeVisible();
  await button.click();
  await expect(button).toHaveAttribute('aria-expanded', 'true');
  const summary = page.locator('#ldn-navigation summary').first();
  await summary.focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Escape');
  await expect(button).toHaveAttribute('aria-expanded', 'false');
  await expect(button).toBeFocused();
});

test('gallery lightbox still opens and closes inside the new design', async ({ page }, info) => {
  await goto(page, '/en/node/748?theme=labdoo_next', info);
  await page.locator('.labdoo-gallery-grid a:has(img)').first().click();
  const dialog = page.getByRole('dialog', { name: 'Photo viewer' });
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
});

test.describe('progressive enhancement', () => {
  test.use({ javaScriptEnabled: false });
  test('the theme, content and navigation render without JavaScript', async ({ page }, info) => {
    await goto(page, '/en/node/749?theme=labdoo_next', info);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    await expect(page.locator('.ldn-navigation')).toBeVisible();
    await expect(page.locator('.ldn-impact .ldn-metric')).toHaveCount(7);
    await expect(page.locator('h1').first()).toBeVisible();
  });
});

test.describe('administration and print boundaries', () => {
  test.use({ role: 'admin' });
  test('admin routes and printable labels retain their existing themes', async ({ page }, info) => {
    await goto(page, '/en/admin/content?theme=labdoo_next', info);
    await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
    await goto(page, '/en/dootronics/print-labels?id=000000141&theme=labdoo_next', info);
    await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
    await expect(page.locator('body')).toHaveClass(/dootronics-print-label-page/);
  });
});
