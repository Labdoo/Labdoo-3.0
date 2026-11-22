import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import fs from 'node:fs';

test.describe('restricted Redis report', () => {
  test.skip(!fs.existsSync(path.join(stateDir, 'data-protection-private.json')),
    'Requires the dedicated local TLS Redis fixture described in tests/upgrade/README.md.');
  test.use({ role: 'superadmin' });
  test('renders INFO metrics while CONFIG remains forbidden @responsive', async ({ page }, info) => {
    const { stdout } = await promisify(execFile)('python3', [
      path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
      path.join(__dirname, 'support/redis-report.php'),
    ], { timeout: 45000 });
    expect(JSON.parse(stdout.split('REDIS_REPORT_JSON:')[1].trim())).toEqual({
      rendered: true, configuration_denied: true,
    });
    expect((await goto(page, '/en/admin/reports/redis', info)).status()).toBe(200);
    await expect(page.locator('h1').first()).toContainText('Redis');
    await expect(page.locator('main')).toContainText('maxmemory policy');
  });
});

test.describe('Redis report access', () => {
  test.use({ role: 'basic' });
  test('keeps report metrics restricted to authorized accounts', async ({ page }, info) => {
    expect((await goto(page, '/en/admin/reports/redis', info)).status()).toBe(403);
    await expect(page.locator('main')).not.toContainText('maxmemory policy');
  });
});
