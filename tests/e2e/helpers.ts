import { test as base, expect, BrowserContext, Page, TestInfo } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export type Role = 'anonymous' | 'admin' | 'basic' | 'laptop' | 'hub' | 'school' | 'wiki' | 'team';
export const stateDir = path.resolve(__dirname, '../../../state');
export function variant(info: TestInfo): string { return String(info.project.metadata.variant || 'integration'); }
export function fixtures(info: TestInfo): any {
  const value = JSON.parse(fs.readFileSync(path.join(stateDir, `${variant(info)}-fixtures.json`), 'utf8'));
  if (!value.ready) throw new Error('Fixture seeding is incomplete');
  return value;
}
export function authPath(kind: string, role: string): string { return path.join(stateDir, `auth-${kind}-${role}.json`); }
export async function networkGuard(context: BrowserContext) {
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.hostname === '127.0.0.1' && ['18380', '18381'].includes(url.port)) return route.continue();
    if (['data:', 'blob:'].includes(url.protocol)) return route.continue();
    if (request.method() === 'GET' && ['unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname) && ['script', 'stylesheet', 'font'].includes(request.resourceType())) return route.continue();
    if (request.resourceType() === 'image') return route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZ0AAAAASUVORK5CYII=', 'base64') });
    return route.abort('blockedbyclient');
  });
}
export async function goto(page: Page, url: string, info: TestInfo) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  expect(response, 'A Drupal response is required').not.toBeNull();
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
  const cards = JSON.parse(fs.readFileSync(path.join(stateDir, 'cards.json'), 'utf8'));
  const matching = cards.filter((card: any) => branches.includes(card.branch.replace('feature/', '')));
  if (matching.length !== branches.length) throw new Error(`Unknown card mapping: ${branches}`);
  return { annotation: matching.map((card: any) => ({ type: 'todoist', description: card.id })) };
}
