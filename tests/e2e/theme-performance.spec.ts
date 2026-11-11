import { test, expect, goto } from './helpers';

test('public maps preserve filtered points, equator coordinates and safe popups @responsive', async ({ page }, info) => {
  const points = [
    { id: 749, lat: 60, lon: -150, title: 'School <strong>one</strong>' },
    { id: 440, lat: 0, lon: 0, title: 'Equator school' },
    { id: 748, lat: -60, lon: 150, title: 'Southern school' },
  ];
  let requested = '';
  await page.route('**/api/map-points/**', async route => {
    requested = route.request().url();
    await route.fulfill({ json: points });
  });
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next&combine=school', info);
  const map = page.locator('.labdoo-map-container').first();
  await map.scrollIntoViewIfNeeded();
  await expect(map.locator('.leaflet-marker-icon:not(.marker-cluster)')).toHaveCount(3);
  expect(new URL(requested).searchParams.get('combine')).toBe('school');
  // Each original title remains text, including markup-like characters.
  let found = false;
  for (const marker of await map.locator('.leaflet-marker-icon:not(.marker-cluster)').all()) {
    await marker.click();
    const popup = map.locator('.leaflet-popup-content');
    await expect(popup).toHaveCount(1);
    if ((await popup.textContent())?.includes('School <strong>one</strong>')) {
      await expect(popup.locator('strong')).toHaveCount(0);
      await expect(popup.locator('a')).toHaveAttribute('href', /\/en\/node\/749/);
      found = true;
      break;
    }
  }
  expect(found).toBe(true);
});

test('charts still render after scrolling with local libraries @responsive', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
  const chart = page.locator('.charts-chartjs').first();
  await chart.scrollIntoViewIfNeeded();
  await expect(page.locator('canvas.charts-chartjs').first()).toBeVisible();
  expect(await page.evaluate(() => {
    const canvas = document.querySelector('canvas.charts-chartjs') as HTMLCanvasElement;
    return Boolean((window as any).Chart.getChart(canvas));
  })).toBe(true);
  expect(errors).toEqual([]);
});

test('a large marker collection completes without dropping points @responsive', async ({ page }, info) => {
  const points = Array.from({ length: 2000 }, (_, i) => ({ id: i + 1, lat: 40 + i / 100000, lon: -3 + i / 100000, title: `School ${i}` }));
  await page.route('**/api/map-points/**', route => route.fulfill({ json: points }));
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
  const map = page.locator('.labdoo-map-container').first();
  await map.scrollIntoViewIfNeeded();
  await expect.poll(async () => map.locator('.leaflet-marker-icon').evaluateAll(markers => markers.reduce((count, marker) => count + (marker.classList.contains('marker-cluster') ? Number(marker.textContent) : 1), 0))).toBe(2000);
});

test('a school map initializes its native provider on scroll and keeps trajectory data @responsive', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  let trajectoryRequested = false;
  await page.route('**/api/node-trajectory/749*', async route => {
    trajectoryRequested = true;
    await route.fulfill({ json: [
      { lat: 27.71678, lon: 85.33, index: 1, date: '2026-11-11' },
      { lat: 40, lon: -3, index: 2, date: '2026-11-12' },
    ] });
  });
  await goto(page, '/en/node/749?theme=labdoo_next', info);
  const wrapper = page.locator('.geolocation-map-wrapper').first();
  const mapId = await wrapper.getAttribute('id');
  await wrapper.scrollIntoViewIfNeeded();
  await expect.poll(async () => page.evaluate(id => {
    const map = (window as any).Drupal.geolocation.maps.initializedMaps.get(id);
    return Boolean(map?.leafletMap);
  }, mapId)).toBe(true);
  await expect.poll(() => trajectoryRequested).toBe(true);
  await expect(wrapper.locator('.labdoo-map-numbered-marker')).toHaveCount(2);
  await expect(wrapper.locator('.leaflet-overlay-pane path')).toHaveCount(1);
  await expect(wrapper.locator('.leaflet-container')).toHaveCount(1);
  await expect(page.locator('.labdoo-map-container.leaflet-container')).toHaveCount(0);
  expect(errors).toEqual([]);
});
