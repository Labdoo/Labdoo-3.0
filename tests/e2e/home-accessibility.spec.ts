import { test, expect, goto } from './helpers';

for (const language of ['en', 'es']) {
  test(`default home has accessible markup and search metadata (${language}) @responsive`, async ({ page }, info) => {
    const remoteMaps: string[] = [];
    page.on('request', request => {
      if (/unpkg\.com\/leaflet/.test(request.url())) remoteMaps.push(request.url());
    });
    await goto(page, `/${language}`, info);
    await expect(page.locator('meta[name="description"]')).toHaveCount(1);
    expect((await page.locator('meta[name="description"]').getAttribute('content'))!.length).toBeGreaterThan(30);
    await expect(page.locator('main img:not([alt]), .view img:not([alt])')).toHaveCount(0);
    await expect(page.locator('.pager__item--ellipsis[role="presentation"]')).toHaveCount(0);
    await expect(page.locator('.pager h4')).toHaveCount(0);
    await expect(page.locator('.chosen-single:not([href])')).toHaveCount(0);
    expect(await page.evaluate(() => (window as any).drupalSettings.eu_cookie_compliance.popup_delay)).toBe(0);
    // The mobile map remains lazy until it reaches the viewport.
    await page.locator('.labdoo-map-container').first().scrollIntoViewIfNeeded();
    await expect.poll(() => page.evaluate(() => (window as any).L?.version)).toBe('1.0.3');
    await expect(page.locator('.labdoo-map-container .leaflet-control-zoom-in').first()).toBeVisible();
    expect(remoteMaps).toEqual([]);
  });
}
