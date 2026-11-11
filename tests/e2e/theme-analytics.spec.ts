import { test, expect, goto } from './helpers';

async function observeAnalytics(page: any) {
  const requests: string[] = [];
  await page.route('**/gtag/js?*', async (route: any) => {
    requests.push(route.request().url());
    const id = new URL(route.request().url()).searchParams.get('id');
    // Simulate the provider's queue consumption without sending test visits.
    await route.fulfill({ contentType: 'application/javascript', body: `
      window.__testAnalyticsConfigs = 0;
      window.__testAnalyticsEvents = [];
      function consume(args) {
        if (args[0] === 'config') window.__testAnalyticsConfigs++;
        if (args[0] === 'event' && !window['ga-disable-' + ${JSON.stringify(id)}]) {
          window.__testAnalyticsEvents.push(args[1]);
        }
      }
      window.dataLayer.forEach(consume);
      const push = window.dataLayer.push;
      window.dataLayer.push = function(...args) {
        args.forEach(consume);
        return push.apply(this, args);
      };
    ` });
  });
  return requests;
}

test('opt-in loads analytics once after accepting and stops events after withdrawal @responsive', async ({ page }, info) => {
  const requests = await observeAnalytics(page);
  // The shared navigation helper declines cookies; this test needs the banner.
  const response = await page.goto('/en/content/dootronics-dashboard?theme=labdoo_next', { waitUntil: 'domcontentloaded' });
  expect(response!.headers()['x-labdoo-e2e']).toBe(info.project.metadata.variant);
  expect(response!.status()).toBe(200);
  await expect(page.locator('.labdoo-quote')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('script[data-labdoo-analytics-src]')).toHaveCount(1);
  expect(requests).toHaveLength(0);
  await page.locator('.agree-button').first().click();
  await expect.poll(() => requests.length).toBe(1);
  await expect.poll(() => page.evaluate(() => (window as any).__testAnalyticsConfigs)).toBeGreaterThan(0);
  await page.evaluate(() => (window as any).gtag('event', 'after_accepting'));
  await expect.poll(() => page.evaluate(() => (window as any).__testAnalyticsEvents)).toEqual(['after_accepting']);
  await page.evaluate(() => (window as any).Drupal.eu_cookie_compliance.setStatus('0'));
  await page.evaluate(() => (window as any).gtag('event', 'after_withdrawal'));
  expect(await page.evaluate(() => (window as any).__testAnalyticsEvents)).toEqual(['after_accepting']);
  await page.evaluate(() => (window as any).Drupal.eu_cookie_compliance.setStatus('2'));
  await page.evaluate(() => (window as any).gtag('event', 'after_accepting_again'));
  expect(await page.evaluate(() => (window as any).__testAnalyticsEvents)).toEqual(['after_accepting', 'after_accepting_again']);
  expect(requests).toHaveLength(1);
});

test('returning visitors with consent keep analytics @responsive', async ({ page, context, baseURL }, info) => {
  await context.addCookies([{ name: 'cookie-agreed', value: '2', url: baseURL! }]);
  const requests = await observeAnalytics(page);
  const response = await page.goto('/en/content/dootronics-dashboard?theme=labdoo_next', { waitUntil: 'domcontentloaded' });
  expect(response!.headers()['x-labdoo-e2e']).toBe(info.project.metadata.variant);
  expect(response!.status()).toBe(200);
  await expect.poll(() => requests.length).toBe(1);
  await expect.poll(() => page.evaluate(() => (window as any).__testAnalyticsConfigs)).toBeGreaterThan(0);
  await page.evaluate(() => (window as any).gtag('event', 'returning_visitor'));
  await expect.poll(() => page.evaluate(() => (window as any).__testAnalyticsEvents)).toEqual(['returning_visitor']);
});

test('the original theme keeps its existing analytics loading', async ({ page }, info) => {
  const requests = await observeAnalytics(page);
  await goto(page, '/en/content/dootronics-dashboard', info);
  await expect.poll(() => requests.length).toBe(1);
  await expect(page.locator('script[data-labdoo-analytics-src]')).toHaveCount(0);
});
