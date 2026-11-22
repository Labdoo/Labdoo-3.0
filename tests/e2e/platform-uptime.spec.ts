import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('platform uptime executes content fallback and empty aggregate queries', async () => {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/platform-uptime.php'),
  ], { timeout: 45000 });
  const result = JSON.parse(stdout.split('UPTIME_JSON:')[1].trim());
  expect(result).toEqual({ passed: 2, cases: [
    'oldest content timestamp computes uptime', 'empty aggregate returns Unknown',
  ] });
});

for (const language of ['en', 'es']) {
  test(`statistics show platform age in ${language} @responsive`, async ({ page }, info) => {
    expect((await goto(page, `/${language}/statistics/advanced`, info)).status()).toBe(200);
    const banner = page.locator('.platform-uptime-banner');
    await expect(banner).toBeVisible();
    await expect(banner.locator('strong')).toHaveCount(3);
    for (const part of await banner.locator('strong').all()) {
      await expect(part).toHaveText(/^\d+\s+\S+/);
    }
    await expect(banner.locator('small')).toContainText(/\d+/);
    expect(await page.locator('.chart-container').count()).toBeGreaterThan(10);
  });
}
