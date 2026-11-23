import { test, expect, goto, stateDir } from './helpers';
import fs from 'node:fs';
import path from 'node:path';

const inventory = JSON.parse(fs.readFileSync(path.join(stateDir, 'inventory.json'), 'utf8'));
// Keep the inventory's action URLs out of read-only layout checks.
const actions = /\/(?:logout|unmasquerade|delete|remove|uninstall|revert|execute|run|flush|cron|rebuild|update\.php)(?:[/?]|$)/;
const routes = [...new Set<string>(inventory.menus
  .filter((entry: any) => !entry.external && /^\/en\//.test(entry.path)
    && !actions.test(entry.path)
    && !/\/admin(?:\/|$)|[?&](?:token|destination)=|\.xml$/.test(entry.path))
  .map((entry: any) => entry.path))];

test.afterEach(async ({ page }, info) => {
  if (info.status !== info.expectedStatus) {
    const overflow = await page.evaluate(() => ({
      viewport: innerWidth, scroll: document.documentElement.scrollWidth,
      elements: [...document.querySelectorAll('body *')].map(element => ({
        tag: element.tagName, classes: element.getAttribute('class'),
        width: element.getBoundingClientRect().width, right: element.getBoundingClientRect().right,
        text: element.textContent?.trim().slice(0, 120),
      })).filter(element => element.right > innerWidth + 1 || element.width > innerWidth),
    }));
    await info.attach('horizontal-overflow', { body: JSON.stringify(overflow), contentType: 'application/json' });
  }
});

test.describe('public menu view parity', () => {
  test.use({ role: 'superadmin' });
  for (const route of routes) {
    test(`${route} preserves content and fits mobile @responsive`, async ({ page }, info) => {
      await page.route('**/api/map-points/**', request => request.fulfill({ json: [] }));
      await page.setViewportSize({ width: 1440, height: 1000 });
      const desktop = await goto(page, route, info);
      expect(desktop.status()).toBe(200);
      const heading = await page.locator('h1').allTextContents();
      await page.setViewportSize({ width: 360, height: 844 });
      await expect(page.locator('main').first()).toBeVisible();
      expect(await page.locator('h1').allTextContents()).toEqual(heading);
      // Wide result tables must scroll within their own region, not the page.
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
      const mobile = await goto(page, route, info);
      expect(mobile.status()).toBe(desktop.status());
      expect(await page.locator('h1').allTextContents()).toEqual(heading);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
    });
  }
  for (const type of ['dootronic', 'hub']) {
    test(`${type} location controls scroll within the mobile form @responsive`, async ({ page }, info) => {
      await page.setViewportSize({ width: 360, height: 844 });
      await goto(page, `/en/node/add/${type}`, info);
      const locations = page.locator('.field--name-field-locations');
      await locations.scrollIntoViewIfNeeded();
      const remove = locations.locator('input[id$="-actions-delete"]').first();
      // Focusing the final column must reveal its control inside the widget.
      await remove.focus();
      await expect(remove).toBeFocused();
      await expect.poll(() => locations.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
      const bounds = await remove.boundingBox();
      expect(bounds!.x).toBeGreaterThanOrEqual(0);
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(361);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    });
  }
});
