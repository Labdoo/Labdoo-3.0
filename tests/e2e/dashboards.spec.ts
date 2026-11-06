import { test, expect, fixtures, goto, covers } from './helpers';

test('hub chart renders six distinct status series', covers('hub-node-chart'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes['Hub Alpha'].path, info);
  const chart = page.locator('#block-hub-node-chart');
  await expect(chart.locator('canvas')).toBeVisible();
  const labels = await chart.locator('canvas').evaluate((canvas: any) => {
    const instance = (window as any).Chart?.getChart(canvas);
    return instance?.data.datasets.map((dataset: any) => dataset.label);
  });
  expect(labels).toEqual(['Needed', 'Tagged', 'In transit', 'Delivered (working)', 'Delivered (broken)', 'Recycled']);
});
for (const [name, path, count] of [['dootronics', 'dootronics', 7], ['dootrips', 'dootrips', 7], ['hubs', 'hubs', 8], ['schools', 'edoovillages', 9]] as const) {
  test(`${name} dashboard exposes sortable columns and executes both sort directions`, covers('dashboard-sortable-columns'), async ({ page }, info) => {
    test.setTimeout(120000);
    await goto(page, `/en/content/${path}-dashboard`, info);
    const table = page.locator('main .view table').first();
    await expect(table).toBeVisible();
    const links = table.locator('thead th a[href*="order="]');
    await expect(links).toHaveCount(count);
    const dashboardUrl = page.url();
    const urls = await links.evaluateAll(es => es.map(e => (e as HTMLAnchorElement).getAttribute('href')!));
    for (const url of urls) {
      expect((await goto(page, new URL(url, dashboardUrl).href, info)).status()).toBe(200);
      const order = new URL(url, page.url()).searchParams.get('order')!;
      const active = table.locator('thead th a[href*="order="]').filter({ hasText: await table.locator('thead th a[href*="order="]').evaluateAll((es, order) => es.find(e => new URL((e as HTMLAnchorElement).href).searchParams.get('order') === order)?.textContent || 'MISSING', order) }).first();
      const before = new URL(page.url()).searchParams.get('sort');
      await active.click();
      expect(new URL(page.url()).searchParams.get('sort')).not.toBe(before);
      await expect(table).toBeVisible();
    }
  });
}
