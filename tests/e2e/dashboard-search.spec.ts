import { test, expect, fixtures, goto, stateDir, variant } from './helpers';

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('master, table and map displays keep the same filtered device', async ({}, info) => {
  const { stdout } = await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), variant(info), 'php:script', path.join(__dirname, 'support/check-dashboard-search.php')], { timeout: 30000 });
  for (const display of ['default', 'page_1', 'block_1']) expect(stdout).toContain('Filtered dashboard display passed: ' + display);
});

const dashboard = '/en/content/dootronics-dashboard';
test('dootronic ID search returns the matching device and preserves sorting', async ({ page }, info) => {
  const device = fixtures(info).nodes['Laptop Main'];
  const response = await goto(page, `${dashboard}?combine=${device.title}`, info);
  expect(response.status()).toBe(200);
  const table = page.locator('main .view-dootronics-dashboard table');
  await expect(table.locator('tbody tr')).toHaveCount(1);
  await expect(table.locator('tbody')).toContainText(device.title);
  await expect(table.locator('tbody')).not.toContainText(fixtures(info).nodes['Laptop Second'].title);
  await table.locator('th a[href*="order="]').first().click();
  expect(new URL(page.url()).searchParams.get('combine')).toBe(device.title);
  await expect(table.locator('tbody tr')).toHaveCount(1);
  await expect(table.locator('tbody')).toContainText(device.title);
  await table.locator('tbody a').filter({ hasText: device.title }).first().click();
  await expect(page.locator('main')).toContainText('E2E Laptop Main');
});

test('unmatched dootronic search is an empty result rather than a 404', async ({ page }, info) => {
  const response = await goto(page, `${dashboard}?combine=E2E-NO-SUCH-DEVICE-928374`, info);
  expect(response.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 }).first()).not.toContainText('Page not found');
  await expect(page.locator('main .view-dootronics-dashboard tbody tr')).toHaveCount(0);
});

test('the exposed search form filters devices and clearing it restores the table', async ({ page }, info) => {
  await goto(page, dashboard, info);
  const form = page.locator('#views-exposed-form-dootronics-dashboard-page-1');
  const search = form.locator('[name="combine"]');
  await search.fill(fixtures(info).nodes['Laptop Main'].title);
  await form.locator('[type="submit"]').first().click();
  const rows = page.locator('main .view-dootronics-dashboard tbody tr');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText(fixtures(info).nodes['Laptop Main'].title);
  await search.fill('');
  await form.locator('[type="submit"]').first().click();
  await expect.poll(() => rows.count()).toBeGreaterThan(1);
});
