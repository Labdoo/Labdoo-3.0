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
  expect(before.default).toBe('labdoo_next');
});

const routes = [
  ['home', '/en'],
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
    const response = await goto(page, url, info);
    expect(response.status()).toBe(200);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('.ldn-impact .ldn-metric')).toHaveCount(7);
    await expect(page.locator('.ldn-header .ldn-metric')).toHaveCount(0);
    const wrapped = await page.locator('.ldn-metric strong').evaluateAll(es => es.some(el => el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * 1.5));
    expect(wrapped, 'Metric values must remain on a single line').toBe(false);
    expect(response.headers()['cache-control']).not.toContain('no-store');
    expect(response.headers()['x-labdoo-theme']).toBeUndefined();
    await expect(page.locator('.ldn-brand img')).toHaveJSProperty('complete', true);
    expect(await page.locator('.ldn-brand img').evaluate((i: HTMLImageElement) => i.naturalWidth)).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath(`next-${name}.png`), fullPage: false });
  });
}

test('the default theme keeps its design and logo across cached visits @responsive', async ({ page }, info) => {
  await goto(page, '/en', info);
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
  expect((await page.request.get('/en')).headers()['cache-control']).not.toContain('no-store');
  const url = '/en?palette=ocean';
  for (let visit = 0; visit < 2; visit++) {
    const response = await goto(page, url, info);
    expect(response.headers()['cache-control']).not.toContain('no-store');
    expect(response.headers()['cache-control']).toContain('public');
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    const logo = page.locator('.ldn-brand img');
    await expect(logo).toHaveAttribute('src', /labdoo-wordmark-240\.webp$/);
    await expect(logo).toHaveAttribute('srcset', /120w.*240w.*360w/);
    await expect(logo).toHaveAttribute('sizes', '120px');
    await expect.poll(() => logo.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
    expect((await logo.boundingBox())!.width).toBeLessThan(200);
    await expect(page.locator('.ldn-navigation')).not.toBeEmpty();
    await expect(page.locator('.ldn-header .labdoo-quote')).toHaveAttribute('aria-busy', 'false');
    expect((await page.locator('.ldn-shell').first().boundingBox())!.x).toBeGreaterThan(0);
  }
  await page.screenshot({ path: info.outputPath('next-home-cached.png') });
});

test('the default theme is consistent across anonymous sessions', async ({ page, browser, baseURL }, info) => {
  const url = '/en/content/dootronics-dashboard';
  await goto(page, url, info);
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
  expect((await page.request.get(url)).headers()['cache-control']).not.toContain('no-store');
  const other = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  await networkGuard(other);
  const visitor = await other.newPage();
  await goto(visitor, url, info);
  await expect(visitor.locator('body')).toHaveClass(/labdoo-next/);
  await goto(page, url, info);
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
  await other.close();
});

test('navigation and forms do not add a theme selector to URLs', async ({ page }, info) => {
  await goto(page, '/en/content/dootronics-dashboard?combine=000000141', info);
  await expect(page.locator('.ldn-preview')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Back to original' })).toHaveCount(0);
  const links = page.locator('.ldn-navigation a');
  expect(await links.count()).toBeGreaterThan(10);
  for (const href of await links.evaluateAll(es => es.map(e => (e as HTMLAnchorElement).href))) {
    const url = new URL(href);
    expect(url.searchParams.has('theme')).toBe(false);
  }
  await expect(page.locator('form input[name=\"theme\"]')).toHaveCount(0);
});

test('exposed search and sorting work without a theme selector', async ({ page }, info) => {
  await goto(page, '/en/content/dootronics-dashboard', info);
  const id = fixtures(info).nodes['Laptop Main'].title;
  await page.locator('input[name="combine"]').fill(id);
  await page.locator('form').filter({ has: page.locator('input[name="combine"]') }).getByRole('button', { name: /Apply|Search/i }).click();
  expect(new URL(page.url()).searchParams.has('theme')).toBe(false);
  await expect(page.locator('main table')).toContainText(id);
  await page.locator('main table thead a[href*="order="]').first().click();
  expect(new URL(page.url()).searchParams.has('theme')).toBe(false);
  await expect(page.locator('body')).toHaveClass(/labdoo-next/);
});

test('theme query parameters cannot change the configured default', async ({ page }, info) => {
  for (const value of ['theme=gin', 'theme=../../gin', 'theme%5B%5D=labdoo_next', 'theme=labdoo']) {
    expect((await goto(page, '/en/content/dootronics-dashboard?' + value, info)).status()).toBe(200);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
  }
});

test('mobile menu is keyboard accessible and closes with Escape @responsive', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await goto(page, '/en/node/749', info);
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
  await goto(page, '/en/node/748', info);
  await page.locator('.labdoo-gallery-grid a:has(img)').first().click();
  const dialog = page.getByRole('dialog', { name: 'Photo viewer' });
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
});

test.describe('progressive enhancement', () => {
  test.use({ javaScriptEnabled: false });
  test('the theme, content and navigation render without JavaScript', async ({ page }, info) => {
    await goto(page, '/en/node/749', info);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    await expect(page.locator('.ldn-navigation')).toBeVisible();
    await expect(page.locator('.ldn-impact .ldn-metric')).toHaveCount(7);
    await expect(page.locator('h1').first()).toBeVisible();
  });
});

test.describe('administration and print boundaries', () => {
  test.use({ role: 'admin' });
  test('complete admin navigation and header utilities fit without wrapping @responsive', async ({ page }, info) => {
    await goto(page, '/en/node/749', info);
    const header = page.locator('.ldn-header');
    await expect(header.locator('summary', { hasText: 'Superhub users' })).toHaveCount(1);
    for (const width of [1842, 1440, 1280, 1100, 800, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      const mobile = width <= 1100;
      const menu = page.getByRole('button', { name: 'Menu', exact: true });
      let utilities = page.locator('.ldn-header__inner > .ldn-utilities');
      if (mobile) {
        await expect(menu).toBeVisible();
        await menu.click();
        utilities = page.locator('#ldn-navigation .ldn-utilities');
        await expect(utilities).toBeVisible();
      }
      const language = utilities.locator('.chosen-single, select:visible').first();
      const search = utilities.locator('.search-icon-trigger');
      await expect(language).toBeVisible();
      await expect(search).toBeVisible();
      if (!mobile) {
        const l = (await language.boundingBox())!;
        const s = (await search.boundingBox())!;
        expect(Math.abs(l.y - s.y)).toBeLessThan(2);
        expect(l.height).toBe(44);
        expect(s.height).toBe(44);
        expect(l.x + l.width).toBeLessThanOrEqual(s.x);
        expect(s.x + s.width).toBeLessThanOrEqual(width);
        const rows = await page.locator('#ldn-navigation .ldn-menu:not(.ldn-menu--nested) > li').evaluateAll(es => es.map(e => e.getBoundingClientRect().y));
        expect(new Set(rows.map(y => Math.round(y))).size).toBe(1);
        expect(await page.locator('#ldn-navigation').evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
      }
      else {
        await menu.click();
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    }
    await expect(page.locator('.ldn-section-label')).toHaveText('Our shared impact');
    await expect(page.locator('.ldn-section-label span')).toHaveCount(0);
    await expect(page.locator('.footer-acts-image')).toHaveAttribute('src', /footer-acts-transparent-672\.webp$/);
    await expect(page.locator('.footer-acts-image')).toHaveAttribute('srcset', /336w.*672w.*1342w/);
    await expect(page.locator('.footer-acts-image')).toHaveAttribute('height', '74');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await languageFocus(page);
  });
  test('admin routes retain Gin and printable labels keep their print layout', async ({ page }, info) => {
    await goto(page, '/en/admin/content', info);
    await expect(page.locator('body')).not.toHaveClass(/labdoo-next/);
    await goto(page, '/en/dootronics/print-labels?id=000000141', info);
    await expect(page.locator('body')).toHaveClass(/labdoo-next/);
    await expect(page.locator('body')).toHaveClass(/dootronics-print-label-page/);
  });
});

async function languageFocus(page: any) {
  const select = page.locator('.ldn-utilities .chosen-single, .ldn-utilities select:visible').first();
  await select.click();
  await expect(select).toHaveCSS('outline-style', 'none');
  await expect(select).toHaveCSS('border-top-color', 'rgb(36, 92, 64)');
  await page.keyboard.press('Escape');
}
