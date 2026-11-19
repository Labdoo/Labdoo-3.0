// Authenticated route audits without sending Cookie headers to third parties.
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { pathToFileURL, fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = 'http://127.0.0.1:18411';
const [authFile, job, output] = process.argv.slice(2);
if (!authFile || !output || !/^[a-f0-9-]{36}$/.test(job || '')) throw new Error('Usage: node translation-ui-audit.mjs AUTH_STATE JOB_UUID OUTPUT');
const expected = createHash('sha256').update(root).digest('hex');
const state = JSON.parse(await fs.readFile(authFile, 'utf8'));
const cookies = state.cookies.filter(cookie => /^S?SESS/.test(cookie.name) && cookie.domain === '127.0.0.1');
if (!cookies.length) throw new Error('No isolated loopback operator session');
const routes = { form: '/en/admin/config/regional/labdoo-translations', review: `/en/admin/config/regional/labdoo-translations/${job}/es/review` };
for (const suffix of Object.values(routes)) {
  const response = await fetch(base + suffix, { redirect: 'manual', headers: { Cookie: cookies.map(cookie => `${cookie.name}=${cookie.value}`).join('; ') } });
  if (response.status !== 200 || response.headers.get('x-labdoo-e2e') !== 'integration' || response.headers.get('x-labdoo-e2e-root') !== expected) throw new Error('Invalid isolated authenticated route');
}
// Resolve dependencies from the explicitly installed Lighthouse package.
const main = process.env.LIGHTHOUSE_MODULE || '/usr/lib/node_modules/lighthouse/core/index.js';
const require = createRequire(main);
const lighthouse = (await import(pathToFileURL(main).href)).default;
const desktopConfig = (await import(pathToFileURL(path.join(path.dirname(main), 'config/desktop-config.js')).href)).default;
const puppeteer = (await import(pathToFileURL(require.resolve('puppeteer-core')).href)).default;
const launcher = await import(pathToFileURL(require.resolve('chrome-launcher')).href);
await fs.mkdir(output, { recursive: true, mode: 0o700 });
const summary = [];
for (const [route, suffix] of Object.entries(routes)) {
  for (const profile of ['desktop', 'mobile']) {
    for (let run = 1; run <= 3; run++) {
      const chrome = await launcher.launch({ chromePath: '/usr/bin/google-chrome', chromeFlags: ['--headless', '--no-sandbox', '--disable-dev-shm-usage'] });
      try {
        const browser = await puppeteer.connect({ browserURL: `http://127.0.0.1:${chrome.port}` });
        // Domain-scoped session only; fresh browser cache for every audit.
        await browser.setCookie(...cookies.map(({ name, value, path: cookiePath, httpOnly, secure }) => ({ name, value, domain: '127.0.0.1', path: cookiePath, httpOnly, secure })));
        await browser.disconnect();
        const flags = { port: chrome.port, output: ['json', 'html'], logLevel: 'error', disableStorageReset: true, onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'] };
        const config = profile === 'desktop' ? desktopConfig : undefined;
        const result = await lighthouse(base + suffix, flags, config);
        const report = result.lhr;
        if (report.runtimeError) throw new Error(report.runtimeError.message);
        if (new URL(report.finalDisplayedUrl).pathname !== suffix) throw new Error('Audit left the authenticated route');
        await fs.writeFile(path.join(output, `${route}-${profile}-${run}.report.json`), result.report[0], { mode: 0o600 });
        await fs.writeFile(path.join(output, `${route}-${profile}-${run}.report.html`), result.report[1], { mode: 0o600 });
        summary.push({ route, profile, run, lighthouse_version: report.lighthouseVersion, scores: Object.fromEntries(Object.entries(report.categories).map(([key, value]) => [key, Math.round(value.score * 100)])), metrics: Object.fromEntries(['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift'].map(key => [key, report.audits[key].numericValue])), failed_audits: Object.entries(report.audits).filter(([, audit]) => audit.score !== null && audit.score < 1 && !['notApplicable', 'manual', 'informative'].includes(audit.scoreDisplayMode)).map(([key]) => key), warnings: report.runWarnings });
        await fs.writeFile(path.join(output, 'summary.json'), JSON.stringify(summary, null, 2));
        console.log(JSON.stringify(summary.at(-1)));
      } finally { await chrome.kill(); }
    }
  }
}
