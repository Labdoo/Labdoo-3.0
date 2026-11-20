// Audit the actual open dialog, including its populated autocomplete menu.
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = 'http://127.0.0.1:18411';
const output = process.argv[2];
if (!output) throw new Error('Usage: node global-search-audit.mjs PRIVATE_OUTPUT');
const response = await fetch(`${base}/en`);
if (response.status !== 200 || response.headers.get('x-labdoo-e2e') !== 'integration'
  || response.headers.get('x-labdoo-e2e-root') !== createHash('sha256').update(root).digest('hex')) {
  throw new Error('Not the isolated current-checkout site');
}
const main = process.env.LIGHTHOUSE_MODULE || '/usr/lib/node_modules/lighthouse/core/index.js';
const require = createRequire(main);
const { snapshot } = await import(pathToFileURL(main).href);
const puppeteer = (await import(pathToFileURL(require.resolve('puppeteer-core')).href)).default;
await fs.mkdir(output, { recursive: true, mode: 0o700 });
const summary = [];
// Keep each state independent of Lighthouse's DevTools session lifecycle.
const cases = ['desktop', 'mobile'].flatMap(profile => ['empty', 'suggestions'].flatMap(state => [1, 2, 3].map(run => ({ profile, state, run }))));
for (const { profile, state, run } of cases) {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const page = await browser.newPage();
    await page.setViewport(profile === 'mobile' ? { width: 390, height: 844, isMobile: true, hasTouch: true } : { width: 1440, height: 1000 });
    await page.setRequestInterception(true);
    page.on('request', request => new URL(request.url()).origin === base ? request.continue() : request.abort());
    await page.goto(`${base}/en`, { waitUntil: 'networkidle2' });
    await page.evaluate(() => {
      const menu = document.querySelector('.ldn-menu-toggle');
      if (menu && getComputedStyle(menu).display !== 'none') menu.click();
      document.querySelector('.search-icon-trigger').click();
    });
    await page.waitForSelector('dialog[open] input.form-autocomplete', { visible: true });
    if (state === 'suggestions') {
      await page.type('dialog input.form-autocomplete', 'Dootrip');
      await page.waitForSelector('dialog .ui-menu-item', { visible: true });
    }
    const prefix = `${profile}-${state}-${run}`;
    await page.screenshot({ path: path.join(output, `${prefix}.png`) });
    const result = await snapshot(page, { flags: { output: ['json', 'html'], onlyCategories: ['accessibility'], logLevel: 'error' } });
    const report = result.lhr;
    if (report.runtimeError) throw new Error(report.runtimeError.message);
    await fs.writeFile(path.join(output, `${prefix}.report.json`), result.report[0], { mode: 0o600 });
    await fs.writeFile(path.join(output, `${prefix}.report.html`), result.report[1], { mode: 0o600 });
    summary.push({ profile, state, run, lighthouse_version: report.lighthouseVersion, accessibility: Math.round(report.categories.accessibility.score * 100), failed_audits: Object.entries(report.audits).filter(([, audit]) => audit.score !== null && audit.score < 1 && !['notApplicable', 'manual', 'informative'].includes(audit.scoreDisplayMode)).map(([id]) => id), warnings: report.runWarnings });
    await fs.writeFile(path.join(output, 'summary.json'), JSON.stringify(summary, null, 2), { mode: 0o600 });
    console.log(JSON.stringify(summary.at(-1)));
  } finally { await browser.close(); }
}
