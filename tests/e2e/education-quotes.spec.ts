import { test, expect, goto, networkGuard } from './helpers';
import fs from 'node:fs';
import path from 'node:path';

const moduleDir = path.resolve(__dirname, '../../web/modules/custom/labdoo_quotes');
const source = JSON.parse(fs.readFileSync(path.join(moduleDir, 'quotes.json'), 'utf8'));
const languages = fs.readdirSync(path.join(moduleDir, 'translations')).map(name => name.match(/^labdoo_quotes\.(.+)\.po$/)?.[1]).filter(Boolean) as string[];
function translations(language: string): Map<string, string> {
  const po = fs.readFileSync(path.join(moduleDir, 'translations', `labdoo_quotes.${language}.po`), 'utf8');
  return new Map([...po.matchAll(/msgctxt "Labdoo education quotes"\nmsgid ("[^\n]*")\nmsgstr ("[^\n]*")/g)].map(m => [JSON.parse(m[1]), JSON.parse(m[2])]));
}
for (const language of languages) {
  test(`uncached quote endpoint uses ${language} translations and excludes the previous quote`, async ({ request }) => {
    const expected = translations(language);
    let previous = 'mandela';
    for (let i = 0; i < 3; i++) {
      const response = await request.get(`/en/labdoo/education-quote/${language}?exclude=${previous}`);
      expect(response.status()).toBe(200);
      expect(response.headers()['x-labdoo-e2e']).toBe('integration');
      expect(response.headers()['cache-control']).toContain('no-store');
      expect(response.headers()['cache-control']).toContain('private');
      expect(response.headers()['x-drupal-cache']).not.toBe('HIT');
      expect(response.headers()['x-drupal-dynamic-cache']).not.toBe('HIT');
      const data = await response.json();
      expect(data.language).toBe(language);
      expect(data.quote.id).not.toBe(previous);
      const original = source.find(q => q.id === data.quote.id);
      expect(original).toBeTruthy();
      expect(data.quote.text).toBe(expected.get(original.text));
      expect(data.quote.author).toBe(expected.get(original.author));
      expect(data.quote.source).toBe(original.source);
      previous = data.quote.id;
    }
  });
}
for (const language of ['es', 'de', 'ar', 'zh-hans']) {
  test(`widget loads translated AJAX content in ${language} @responsive`, async ({ page }, info) => {
    await goto(page, `/${language}/node/749?theme=labdoo_next`, info);
    const widget = page.locator('.ldn-header .labdoo-quote');
    await expect(widget).toHaveCount(1);
    await expect(widget).toHaveAttribute('aria-busy', 'false');
    await expect(widget).toHaveAttribute('lang', language);
    expect(await widget.getAttribute('data-quote-id')).not.toBe('mandela');
    const id = await widget.getAttribute('data-quote-id');
    const original = source.find(q => q.id === id);
    const expected = translations(language);
    await expect(widget.locator('.labdoo-quote__text')).toHaveText(expected.get(original.text)!);
    await expect(widget.locator('.labdoo-quote__author')).toHaveText(expected.get(original.author)!);
    await expect(widget.locator('.labdoo-quote__author')).toHaveAttribute('href', original.source);
    if (language === 'ar') await expect(widget).toHaveAttribute('dir', 'rtl');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath(`quote-${language}.png`) });
  });
}

test('fresh AJAX quotes replace the same cached page HTML on every load @responsive', async ({ page }, info) => {
  const response = await goto(page, '/en/node/749?theme=labdoo_next', info);
  const html = await response.text();
  const url = page.url();
  const widget = page.locator('.labdoo-quote');
  await expect(widget).toHaveAttribute('aria-busy', 'false');
  let previous = await widget.getAttribute('data-quote-id');
  await page.route(url, route => route.fulfill({
    status: 200,
    headers: { 'content-type': 'text/html; charset=UTF-8', 'x-labdoo-e2e': 'integration', 'cache-control': 'public, max-age=3600' },
    body: html,
  }));
  for (let i = 0; i < 3; i++) {
    await goto(page, url, info);
    await expect(widget).toHaveAttribute('aria-busy', 'false');
    expect(await widget.getAttribute('data-quote-id')).not.toBe(previous);
    previous = await widget.getAttribute('data-quote-id');
  }
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await expect(widget).not.toHaveAttribute('data-quote-id', previous!);
  await expect(widget).toHaveAttribute('aria-busy', 'false');
});

test('AJAX failure leaves a translated quote visible', async ({ page }, info) => {
  await page.route('**/labdoo/education-quote/**', route => route.abort());
  await goto(page, '/es/node/749?theme=labdoo_next', info);
  const widget = page.locator('.labdoo-quote');
  await expect(widget).toHaveAttribute('aria-busy', 'false');
  await expect(widget).toHaveAttribute('data-quote-id', 'mandela');
  await expect(widget.locator('.labdoo-quote__text')).toHaveText(translations('es').get(source[0].text)!);
});
for (const language of ['es', 'ar']) {
  test(`server fallback is translated into ${language} without JavaScript`, async ({ browser, baseURL }, info) => {
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false });
    await networkGuard(context);
    const page = await context.newPage();
    try {
      await goto(page, `/${language}/node/749?theme=labdoo_next`, info);
      await expect(page.locator('.labdoo-quote__text')).toHaveText(translations(language).get(source[0].text)!);
      await expect(page.locator('.labdoo-quote__author')).toHaveText(translations(language).get(source[0].author)!);
    }
    finally { await context.close(); }
  });
}
test('endpoint rejects unknown languages and write methods and tolerates invalid exclusions', async ({ request }) => {
  expect((await request.get('/en/labdoo/education-quote/xx')).status()).toBe(404);
  expect((await request.post('/en/labdoo/education-quote/es')).status()).toBe(405);
  expect((await request.get('/en/labdoo/education-quote/es?exclude[]=mandela')).status()).toBe(200);
});
