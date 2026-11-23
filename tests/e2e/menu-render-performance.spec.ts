import { test, expect, goto, networkGuard } from './helpers';

test('header controls keep their final layout before footer scripts attach @responsive', async ({ page }, info) => {
  let release!: () => void;
  const blocked = new Promise<void>(resolve => { release = resolve; });
  await page.route('**/files/js/**', async route => {
    if (route.request().url().includes('scope=footer')) await blocked;
    await route.continue();
  });
  try {
    const response = await page.goto('/en/teams-activities', { waitUntil: 'commit' });
    expect(response!.headers()['x-labdoo-e2e']).toBe('integration');
    const logo = page.locator('.ldn-brand img');
    await expect(logo).toHaveJSProperty('complete', true);
    const utilities = page.locator('.ldn-utilities');
    const mobile = (page.viewportSize()?.width || 1440) <= 1100;
    if (mobile) await expect(utilities).toBeHidden();
    else await expect(utilities).toBeVisible();
    await expect(page.locator('h1.page-title')).toBeVisible();
    const before = await page.locator('.ldn-header__inner').boundingBox();
    release();
    await page.waitForLoadState('domcontentloaded');
    await expect.poll(async () => (await page.locator('.ldn-header__inner').boundingBox())!.height).toBe(before!.height);
    if (mobile) await page.locator('.ldn-menu-toggle').click();
    await expect(utilities).toBeVisible();
    await expect(utilities.locator('select').first()).toBeVisible();
  } finally { release(); }
});

test('public footer scripts defer without changing their dependency order @responsive', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await goto(page, '/en/teams-activities', info);
  const scripts = await page.locator('script[src]').evaluateAll(elements => elements.map(element => ({
    src: (element as HTMLScriptElement).src, defer: (element as HTMLScriptElement).defer,
  })));
  const footer = scripts.filter(script => script.src.includes('scope=footer') || script.src.includes('/jquery/'));
  expect(footer.length).toBeGreaterThan(1);
  expect(footer.every(script => script.defer)).toBe(true);
  expect(scripts.findIndex(script => script.src.includes('/jquery/'))).toBeLessThan(scripts.findIndex(script => script.src.includes('scope=footer')));
  expect(errors).toEqual([]);
});

test('the wordmark and activity icons use small responsive artwork @responsive', async ({ page }, info) => {
  await goto(page, '/en/teams-activities', info);
  const logo = page.locator('.ldn-brand img');
  await expect(logo).toHaveJSProperty('complete', true);
  const rendered = await logo.evaluate((image: HTMLImageElement) => ({ src: image.currentSrc, natural: image.naturalWidth, rendered: image.getBoundingClientRect().width }));
  expect(rendered.src).toContain('/labdoo-wordmark-');
  expect(rendered.natural).toBeGreaterThanOrEqual(rendered.rendered);
  expect((await page.request.get(rendered.src)).headers()['content-type']).toContain('image/webp');
  expect((await (await page.request.get(rendered.src)).body()).length).toBeLessThan(6000);
  const icons = page.locator('.ldn-sidebar img[src*="/labdoo_next/images/"]');
  // Mini-missions rotate asynchronously; wait for responsive artwork.
  await expect(icons).not.toHaveCount(0);
  for (const icon of (await icons.all()).slice(0, 4)) {
    await expect(icon).toHaveAttribute('loading', 'lazy');
    await expect(icon).toHaveAttribute('alt');
    const src = await icon.getAttribute('src');
    expect((await (await page.request.get(src!)).body()).length).toBeLessThan(6000);
  }
});

test('activity rows retain their full layout when scrolling and printing @responsive', async ({ page }, info) => {
  await goto(page, '/en/teams-activities', info);
  const rows = page.locator('.view-teams-activities > .view-content > .views-row');
  expect(await rows.count()).toBeGreaterThan(10);
  const last = rows.last();
  expect(await last.evaluate(element => getComputedStyle(element).contentVisibility)).toBe('visible');
  const text = await last.textContent();
  await last.scrollIntoViewIfNeeded();
  await expect(last).toBeInViewport();
  expect(await last.evaluate(element => element.firstElementChild!.checkVisibility({ contentVisibilityAuto: true }))).toBe(true);
  await expect(last).toHaveText(text!);
  await page.emulateMedia({ media: 'print' });
  expect(await last.evaluate(element => getComputedStyle(element).contentVisibility)).toBe('visible');
  expect((await last.boundingBox())!.height).toBeGreaterThan(0);
});

test('activity content is available without JavaScript @responsive', async ({ browser, baseURL }, info) => {
  const context = await browser.newContext({ baseURL, javaScriptEnabled: false, viewport: info.project.use.viewport });
  await networkGuard(context);
  try {
    const page = await context.newPage();
    await goto(page, '/en/teams-activities', info);
    const last = page.locator('.view-teams-activities > .view-content > .views-row').last();
    await last.scrollIntoViewIfNeeded();
    await expect(last).toBeInViewport();
    expect((await last.textContent())?.trim().length).toBeGreaterThan(0);
  } finally { await context.close(); }
});

test('simple dashboard charts avoid the full optional chart bundle @responsive', async ({ page }, info) => {
  const requests: string[] = [];
  page.on('request', request => { if (request.url().includes('/chart.js-4.4.0/dist/')) requests.push(request.url()); });
  await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
  await goto(page, '/en/content/dootronics-dashboard', info);
  await page.locator('.charts-chartjs').first().scrollIntoViewIfNeeded();
  await expect(page.locator('canvas.charts-chartjs').first()).toBeVisible();
  expect(requests.some(url => url.endsWith('/chart-bar.js'))).toBe(true);
  expect(requests.some(url => url.endsWith('/chart.umd.js'))).toBe(false);
});

test('the stories heading reserves image space and uses responsive WebP @responsive', async ({ page }, info) => {
  await goto(page, '/en/labdoo-stories', info);
  const header = page.locator('.view-header img[src*="labdoo-stories-header"]');
  await expect(header).toHaveCount(1);
  await expect(header).toHaveAttribute('src', /labdoo-stories-header-550\.webp/);
  await expect(header).toHaveAttribute('height', /\d+/);
  await expect(header).toHaveAttribute('loading', 'eager');
  await expect(header).toHaveAttribute('srcset', /1100w/);
});
