import { test, expect, fixtures, goto } from './helpers';

for (const [name, url] of [
  ['dootronics', '/en/content/dootronics-dashboard'],
  ['dootrips', '/en/content/dootrips-dashboard'],
  ['hubs', '/en/content/hubs-dashboard'],
  ['schools', '/en/content/edoovillages-dashboard'],
  ['stories', '/en/labdoo-stories'],
  ['contact', '/en/contact'],
] as const) {
  test(`public ${name} route renders without a server error`, async ({ page }, info) => {
    const response = await goto(page, url, info);
    expect(response.status()).toBe(200);
    await expect(page.locator('main')).toBeVisible();
  });
}

for (const key of ['Hub Alpha', 'School', 'Laptop Main', 'Trip', 'Team', 'Team Post', 'Gallery', 'Hub Story']) {
  test(`published ${key} remains readable`, async ({ page }, info) => {
    const node = fixtures(info).nodes[key];
    expect((await goto(page, node.path, info)).status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(node.title);
  });
}

test('anonymous user cannot edit a dootronic', async ({ page }, info) => {
  const id = fixtures(info).nodes['Laptop Main'].id;
  const response = await goto(page, `/en/node/${id}/edit`, info);
  expect(response.status()).toBe(403);
});

test.describe('authenticated regression', () => {
  test.use({ role: 'admin' });
  test('administrator can open the existing entity edit forms', async ({ page }, info) => {
    for (const key of ['Laptop Main', 'School', 'Trip', 'Gallery', 'Team']) {
      const id = fixtures(info).nodes[key].id;
      expect((await goto(page, `/en/node/${id}/edit`, info)).status()).toBe(200);
      await expect(page.locator('form.node-form')).toBeVisible();
    }
  });
  test('search page accepts a query and renders results', async ({ page }, info) => {
    const response = await goto(page, '/en/search-results?search_api_fulltext=Labdoo', info);
    expect(response.status()).toBe(200);
    await expect(page.locator('main')).toBeVisible();
  });
});
