import { test as base, expect, BrowserContext, Page, TestInfo } from '@playwright/test';
import fs from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';

export type Role = 'anonymous' | 'superadmin' | 'admin' | 'basic' | 'laptop' | 'hub' | 'school' | 'wiki' | 'team';
export const stateDir = process.env.E2E_STATE_DIR || path.resolve(__dirname, '../../../state');
export function variant(info: TestInfo): string { return String(info.project.metadata.variant || 'integration'); }
export function fixtures(info: TestInfo): any {
  const value = JSON.parse(fs.readFileSync(path.join(stateDir, `${variant(info)}-fixtures.json`), 'utf8'));
  if (!value.ready) throw new Error('Fixture seeding is incomplete');
  return value;
}
export function authPath(kind: string, role: string): string { return path.join(stateDir, `auth-${kind}-${role}.json`); }
export async function networkGuard(context: BrowserContext) {
  const localPorts = process.env.E2E_LOCAL_PORT ? [process.env.E2E_LOCAL_PORT] : ['18380', '18381', '18411'];
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.hostname === '127.0.0.1' && localPorts.includes(url.port)) return route.continue();
    if (['data:', 'blob:'].includes(url.protocol)) return route.continue();
    if (request.method() === 'GET' && ['unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname) && ['script', 'stylesheet', 'font'].includes(request.resourceType())) return route.continue();
    if (request.resourceType() === 'image') return route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGNQmBlKEmIY1TCqYfhqAABWQw4QqWAGPwAAAABJRU5ErkJggg==', 'base64') });
    return route.abort('blockedbyclient');
  });
}
export async function goto(page: Page, url: string, info: TestInfo) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  expect(response, 'A Drupal response is required').not.toBeNull();
  if (process.env.E2E_LOCAL_PORT === '18411') {
    const expectedRoot = createHash('sha256').update(path.resolve(__dirname, '../..')).digest('hex');
    expect(response!.headers()['x-labdoo-e2e-root'], 'The server must run the checkout containing these tests').toBe(expectedRoot);
  }
  expect(response!.headers()['x-labdoo-e2e'], 'Refusing browser writes outside the isolated site').toBe(variant(info));
  expect(response!.status(), `HTTP status for ${url}`).toBeLessThan(500);
  await expect(page.locator('body')).not.toContainText('The website encountered an unexpected error');
  const cookieChoice = page.getByRole('button', { name: 'No, thanks', exact: true });
  if (await cookieChoice.isVisible()) await cookieChoice.click();
  return response!;
}
export const test = base.extend<{ role: Role; guard: void }>({
  role: ['anonymous', { option: true }],
  storageState: async ({ role }, use, info) => {
    await use(role === 'anonymous' ? { cookies: [], origins: [] } : authPath(variant(info), role));
  },
  guard: [async ({ context }, use) => { await networkGuard(context); await use(); }, { auto: true }],
});
export { expect };

export function covers(...branches: string[]) {
  const cards = JSON.parse(fs.readFileSync(path.join(__dirname, 'support/cards.json'), 'utf8'));
  const matching = cards.filter((card: any) => branches.includes(card.branch.replace('feature/', '')));
  if (matching.length !== branches.length) throw new Error(`Unknown card mapping: ${branches}`);
  return { annotation: matching.map((card: any) => ({ type: 'todoist', description: card.id })) };
}
