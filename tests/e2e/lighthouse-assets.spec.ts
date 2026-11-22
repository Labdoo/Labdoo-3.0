import { test, expect, goto, fixtures } from './helpers';

test('the legacy school URL keeps its permanent same-origin canonical redirect', async ({ request }) => {
  const response = await request.get('/en/node/749', { maxRedirects: 0 });
  expect(response.status()).toBe(301);
  const destination = new URL(response.headers().location, response.url());
  expect(destination.origin).toBe(new URL(response.url()).origin);
  expect(destination.pathname).toBe('/en/content/nepal-kathmandu-handigaun-primary-school-handigaun');
});

test('public navigation starts without a blocking script request or administrative toolbar code @responsive', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await goto(page, '/en', info);
  await expect(page.locator('html')).toHaveClass(/ldn-js/);
  expect(await page.evaluate(() => document.documentElement.classList.contains('touchevents')
    || document.documentElement.classList.contains('no-touchevents'))).toBe(true);
  // Dynamically inserted chart/map scripts do not block the HTML parser.
  const parserBlocking = await page.evaluate(html => {
    const document = new DOMParser().parseFromString(html, 'text/html');
    return document.head.querySelectorAll('script[src]:not([defer]):not([async]):not([type="text/plain"])').length;
  }, await response.text());
  expect(parserBlocking).toBe(0);
  expect(await page.evaluate(() => Boolean((window as any).Drupal.ginToolbar))).toBe(false);
  const toggle = page.locator('.ldn-menu-toggle');
  if (await toggle.isVisible()) {
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  }
  await page.locator('.search-icon-trigger').click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.locator('.search-overlay input.form-autocomplete')).toBeFocused();
  await page.keyboard.press('Escape');
  expect(errors).toEqual([]);
});

for (const role of ['anonymous', 'superadmin'] as const) {
  test.describe(`responsive action artwork as ${role}`, () => {
    test.use({ role });
    for (const target of ['School', 'Hub Alpha', 'Laptop Main']) {
      test(`${target} loads small artwork and preserves links and accessible labels @responsive`, async ({ page }, info) => {
        await goto(page, `/en/node/${fixtures(info).nodes[target].id}`, info);
        const panel = page.locator('.ldn-sidebar .block-actions-block-block').first();
        await panel.scrollIntoViewIfNeeded();
        const toggle = panel.locator('.block-actions-toggle');
        if (!await panel.locator('.block-actions-content').isVisible()) await toggle.click();
        const icons = panel.locator('.action img.icon');
        expect(await icons.count()).toBeGreaterThan(0);
        for (const image of await icons.all()) {
          await image.scrollIntoViewIfNeeded();
          await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
          const data = await image.evaluate((element: HTMLImageElement) => ({
            url: element.currentSrc, width: element.naturalWidth,
            rendered: element.getBoundingClientRect().width, alt: element.getAttribute('alt'),
            link: element.closest('a')?.getAttribute('href'),
          }));
          expect(data.url).toMatch(/\/themes\/custom\/labdoo_next\/images\/[^/]+-(30|60|90)\.webp$/);
          expect(data.width).toBeLessThanOrEqual(90);
          expect(data.rendered).toBeCloseTo(19, 0);
          expect(data.alt).toBe('');
          if (data.link !== undefined) expect(data.link).not.toBe('');
        }
        const semaphore = panel.locator('img.semaphore');
        if (target !== 'Laptop Main') {
          await semaphore.scrollIntoViewIfNeeded();
          await expect(semaphore).toHaveAccessibleName(/Project semaphore:/);
          await expect.poll(() => semaphore.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
          expect(await semaphore.evaluate((element: HTMLImageElement) => element.currentSrc)).toMatch(/semaphore-.*-(120|240|360)\.webp$/);
          expect(await semaphore.getAttribute('width')).toMatch(/^\d+$/);
          expect(await semaphore.getAttribute('height')).toMatch(/^\d+$/);
        }
        await panel.screenshot({ path: info.outputPath('responsive-artwork.png') });
      });
    }
  });
}
