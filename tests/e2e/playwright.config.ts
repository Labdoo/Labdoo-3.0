import { defineConfig } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';

const state = path.resolve(__dirname, '../../../state');
const runDir = path.join(state, 'runs', process.env.E2E_RUN_ID || 'current');
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(state, 'chrome-config'), { recursive: true });
fs.mkdirSync(path.join(state, 'chrome-cache'), { recursive: true });
export default defineConfig({
  testDir: '.',
  timeout: 60000,
  expect: { timeout: 12000 },
  workers: 1,
  retries: 0,
  fullyParallel: false,
  outputDir: path.join(runDir, 'test-results'),
  reporter: [['list'], ['html', { outputFolder: path.join(runDir, 'playwright-report'), open: 'never' }], ['json', { outputFile: path.join(runDir, 'playwright-results.json') }]],
  use: {
    browserName: 'chromium',
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
  projects: [
    { name: 'setup-baseline', testMatch: /auth\.setup\.ts/, metadata: { variant: 'baseline' }, use: { baseURL: 'http://127.0.0.1:18380' } },
    { name: 'setup-integration', testMatch: /auth\.setup\.ts/, metadata: { variant: 'integration' }, use: { baseURL: 'http://127.0.0.1:18381' } },
    { name: 'baseline', testMatch: /regression\.spec\.ts/, dependencies: ['setup-baseline'], metadata: { variant: 'baseline' }, use: { baseURL: 'http://127.0.0.1:18380' } },
    { name: 'integration', testMatch: /.*\.spec\.ts/, testIgnore: /legacy-address\.spec\.ts/, dependencies: ['setup-integration'], metadata: { variant: 'integration' }, use: { baseURL: 'http://127.0.0.1:18381' } },
    { name: 'legacy-v2', testMatch: /legacy-address\.spec\.ts/, dependencies: ['setup-integration'], metadata: { variant: 'integration' }, use: { baseURL: 'http://127.0.0.1:18381' } },
    { name: 'mobile', testMatch: /.*\.spec\.ts/, grep: /@responsive/, dependencies: ['setup-integration'], metadata: { variant: 'integration' }, use: { baseURL: 'http://127.0.0.1:18381', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
