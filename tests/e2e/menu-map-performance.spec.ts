import { test, expect, goto, stateDir, authPath } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

const cli = promisify(execFile);
let data: Record<string, { id: number; title: string; owner: number }>;
async function fixture(operation: string) {
  const { stdout } = await cli('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/menu-map.php')], {
    env: { ...process.env, LABDOO_MENU_MAP_OP: operation }, timeout: 60000,
  });
  return JSON.parse(stdout.split('MENU_MAP:')[1].trim());
}
test.beforeAll(async () => { data = await fixture('prepare'); });
test.afterAll(async () => { await fixture('cleanup'); });
const api = '/en/api/map-points/dootronic';
const params = (values: Record<string, string>) => new URLSearchParams(values).toString();

test('the map API returns the same filtered device as the dashboard', async ({ request }) => {
  const response = await request.get(api + '?' + params({ combine: data.basic.title }));
  expect(response.status()).toBe(200);
  const points = await response.json();
  expect(points).toHaveLength(1);
  expect(points.map((point: any) => point.id)).toEqual([data.basic.id]);
  expect(points[0]).toMatchObject({ lat: 0, lon: 3 });
  expect((await request.get(api + '?combine=E2E-NO-SUCH-MAP-POINT')).status()).toBe(200);
  expect(await (await request.get(api + '?combine=E2E-NO-SUCH-MAP-POINT')).json()).toEqual([]);
});

test('world clusters preserve all accessible point counts with a smaller response', async ({ request }) => {
  const original = await request.get(api);
  const raw = await original.json();
  const response = await request.get(api + '?_map=clusters&zoom=2');
  expect(response.status()).toBe(200);
  const clustered = await response.json();
  expect(clustered.total).toBe(raw.length);
  expect(clustered.visible).toBe(raw.length);
  expect(clustered.points.reduce((sum: number, point: any) => sum + point.count, 0)).toBe(raw.length);
  expect((await response.body()).length).toBeLessThan((await original.body()).length / 10);
  expect(raw.some((point: any) => point.id === data.unpublished.id)).toBe(false);
});

test('zoomed map data preserves individual devices and viewport boundaries', async ({ request }) => {
  const url = api + '?' + params({ _map: 'clusters', zoom: '14', bbox: '2,-1,4,1', combine: data.basic.title });
  const response = await request.get(url);
  expect(response.status()).toBe(200);
  const detail = await response.json();
  expect(detail.visible).toBe(1);
  expect(detail.points).toEqual([expect.objectContaining({ id: data.basic.id, lat: 0, lon: 3, count: 1 })]);
  const outside = await request.get(api + '?' + params({ _map: 'clusters', zoom: '14', bbox: '20,20,21,21', combine: data.basic.title }));
  expect((await outside.json()).points).toEqual([]);
});

test('all dashboard map types support aggregated Views and preserve their totals', async ({ request }) => {
  for (const type of ['dootronic', 'dootrip', 'edoovillage', 'hub']) {
    const url = `/es/api/map-points/${type}`;
    const rawResponse = await request.get(url);
    expect(rawResponse.status(), type).toBe(200);
    const raw = await rawResponse.json();
    expect(raw.length, type).toBeGreaterThan(0);
    const response = await request.get(url + '?_map=clusters&zoom=0');
    expect(response.status(), type).toBe(200);
    const data = await response.json();
    expect(data.total, type).toBe(raw.length);
    expect(data.points.reduce((sum: number, point: any) => sum + point.count, 0), type).toBe(raw.length);
  }
});

test('cached map responses invalidate after coordinates and publication change', async ({ request }) => {
  const url = api + '?' + params({ combine: data.basic.title });
  await request.get(url);
  const cached = await request.get(url);
  expect(cached.headers()['x-drupal-cache']).toBe('HIT');
  await fixture('move');
  expect(await (await request.get(url)).json()).toEqual([expect.objectContaining({ id: data.basic.id, lat: 1, lon: 8 })]);
  await fixture('unpublish');
  expect(await (await request.get(url)).json()).toEqual([]);
  await fixture('publish');
  expect((await (await request.get(url)).json()).map((point: any) => point.id)).toEqual([data.basic.id]);
});

test('personal map filters and caches stay isolated between accounts', async ({ browser, baseURL }) => {
  const contexts = await Promise.all(['basic', 'laptop'].map(role => browser.newContext({ baseURL, storageState: authPath('integration', role) })));
  try {
    for (const index of [0, 1, 0]) {
      const role = index === 0 ? 'basic' : 'laptop';
      const other = index === 0 ? 'laptop' : 'basic';
      const response = await contexts[index].request.get(api + '?mine=1');
      expect(response.status()).toBe(200);
      expect(response.headers()['cache-control']).toContain('private');
      const ids = (await response.json()).map((point: any) => point.id);
      expect(ids).toContain(data[role].id);
      expect(ids).not.toContain(data[other].id);
      expect(ids).not.toContain(data.unpublished.id);
    }
  } finally { await Promise.all(contexts.map(context => context.close())); }
});

test('map language contexts and user-directory permissions remain enforced', async ({ request, browser, baseURL }) => {
  for (const language of ['en', 'es', 'en']) {
    const response = await request.get(`/${language}/api/map-points/dootronic?` + params({ combine: data.laptop.title }));
    expect(response.status()).toBe(200);
    expect(response.headers()['content-language']).toBe(language);
    expect((await response.json()).map((point: any) => point.id)).toEqual([data.laptop.id]);
  }
  expect((await request.get('/en/api/map-points/labdooer')).status()).toBe(403);
  const admin = await browser.newContext({ baseURL, storageState: authPath('integration', 'admin') });
  try { expect((await admin.request.get('/en/api/map-points/labdooer?_map=clusters&zoom=2')).status()).toBe(200); }
  finally { await admin.close(); }
});

test('a cached user-directory response is revoked when the account loses its role', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ baseURL, storageState: authPath('integration', 'basic') });
  const url = '/en/api/map-points/labdooer?_map=clusters&zoom=0';
  try {
    expect((await context.request.get(url)).status()).toBe(403);
    await fixture('directory-allow');
    expect((await context.request.get(url)).status()).toBe(200);
    const cached = await context.request.get(url);
    expect(cached.status()).toBe(200);
    expect(cached.headers()['cache-control']).toContain('private');
    expect((await cached.json()).total).toBeGreaterThan(0);
    await fixture('directory-deny');
    expect((await context.request.get(url)).status()).toBe(403);
  } finally { await fixture('directory-deny'); await context.close(); }
});

test('invalid map coordinates and zoom return a client error', async ({ request }) => {
  for (const query of ['zoom=19', 'zoom=-1', 'bbox=1,2,3', 'bbox=NaN,0,1,1', 'bbox=-200,0,1,1', 'bbox=0,10,1,-10']) {
    expect((await request.get(api + '?_map=clusters&' + query)).status()).toBe(400);
  }
});

test('a real clustered map opens, shows totals and loads detail on zoom @responsive', async ({ page }, info) => {
  const replies: any[] = [];
  page.on('response', async response => {
    if (response.url().includes('/api/map-points/dootronic')) replies.push({ url: response.url(), data: await response.json() });
  });
  await goto(page, '/en/content/dootronics-dashboard', info);
  const map = page.locator('.labdoo-map-container').first();
  await map.scrollIntoViewIfNeeded();
  await expect(map.locator('.ldn-server-cluster').first()).toBeVisible();
  await expect(map).toHaveAttribute('aria-busy', 'false');
  await expect.poll(() => replies.length).toBeGreaterThan(0);
  const first = replies[0];
  expect(new URL(first.url).searchParams.get('_map')).toBe('clusters');
  expect(first.data.points.reduce((sum: number, point: any) => sum + point.count, 0)).toBe(first.data.total);
  const count = replies.length;
  await map.locator('.ldn-server-cluster').first().click();
  await expect.poll(() => replies.slice(count).some(reply => Number(new URL(reply.url).searchParams.get('zoom')) > Number(new URL(first.url).searchParams.get('zoom')))).toBe(true);
  await expect(map).toHaveAttribute('aria-busy', 'false');
  expect(replies.slice(count).some(reply => Number(new URL(reply.url).searchParams.get('zoom')) > Number(new URL(first.url).searchParams.get('zoom')))).toBe(true);
});
