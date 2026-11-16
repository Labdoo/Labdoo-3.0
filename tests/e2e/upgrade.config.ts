import { defineConfig } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
const state = path.resolve(__dirname, '../upgrade/.local/state');
const targetMode = process.env.E2E_TARGET_MODE || 'isolated';
if (!['isolated', 'readonly'].includes(targetMode)) throw new Error('Unsupported E2E_TARGET_MODE');
if (targetMode === 'isolated' && !fs.existsSync(path.join(state, 'inventory.json'))) throw new Error('Prepare the isolated lab first: tests/upgrade/lab.py prepare');
process.env.E2E_STATE_DIR = state;
if (targetMode === 'isolated') process.env.E2E_LOCAL_PORT = '18411';
const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:18411';
const run = process.env.E2E_RUN_ID || 'manual';
if (!/^[a-zA-Z0-9_-]+$/.test(run)) throw new Error('Invalid run identifier');
const output = path.join(state, 'runs', run);
for (const directory of [output, path.join(state, 'chrome-config'), path.join(state, 'chrome-cache')]) fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
export default defineConfig({
  testDir: '.',
  timeout: 60000,
  expect: { timeout: 12000 },
  workers: 1,
  retries: 0,
  fullyParallel: false,
  outputDir: path.join(output, 'test-results'),
  reporter: [
    ['list'],
    ['html', { outputFolder: path.join(output, 'report'), open: 'never' }],
    ['json', { outputFile: path.join(output, 'results.json') }],
    [path.resolve(__dirname, '../upgrade/progress-reporter.mjs')],
  ],
  use: {
    browserName: 'chromium',
    baseURL,
    ...(process.env.E2E_HTTP_AUTH_USER && process.env.E2E_HTTP_AUTH_PASSWORD
      ? { httpCredentials: { username: process.env.E2E_HTTP_AUTH_USER, password: process.env.E2E_HTTP_AUTH_PASSWORD } }
      : {}),
    actionTimeout: 15000,
    navigationTimeout: 30000,
    viewport: { width: 1440, height: 1000 },
    locale: 'en-GB',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    launchOptions: {
      executablePath: '/usr/bin/google-chrome',
      args: ['--no-sandbox', '--disable-dev-shm-usage'],
      env: { ...process.env, XDG_CONFIG_HOME: path.join(state, 'chrome-config'), XDG_CACHE_HOME: path.join(state, 'chrome-cache') },
    },
  },
  projects: targetMode === 'readonly' ? [
    { name: 'remote-smoke', testMatch: /remote-smoke\.spec\.ts/, metadata: { variant: 'readonly' } },
  ] : [
    { name: 'auth', testMatch: /auth\.setup\.ts/, metadata: { variant: 'integration' } },
    { name: 'desktop', testMatch: /.*\.spec\.ts/, testIgnore: /legacy-address\.spec\.ts/, dependencies: ['auth'], metadata: { variant: 'integration' } },
    { name: 'mobile', testMatch: /.*\.spec\.ts/, testIgnore: /legacy-address\.spec\.ts/, grep: /@responsive/, dependencies: ['auth'], metadata: { variant: 'integration' }, use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
