import { test, expect, goto, networkGuard } from './helpers';

test('results can be scrolled horizontally without JavaScript @responsive', async ({ browser, baseURL }, info) => {
  const context = await browser.newContext({ baseURL, javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
  await networkGuard(context);
  const page = await context.newPage();
  try {
    await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
    const region = page.getByRole('region', { name: 'Results table', exact: true }).first();
    await region.scrollIntoViewIfNeeded();
    await expect(region).toBeVisible();
    expect(await region.evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
    await region.focus();
    await region.press('ArrowRight');
    await expect.poll(() => region.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  } finally { await context.close(); }
});

test('an offscreen dashboard map downloads neither Leaflet nor points before scrolling @responsive', async ({ page }, info) => {
  await page.setViewportSize({ width: 412, height: 823 });
  let points = false;
  let leaflet = false;
  page.on('request', request => {
    if (request.url().includes('/vendor/leaflet-1.0.3/dist/leaflet.js')) leaflet = true;
  });
  await page.route('**/api/map-points/**', route => {
    points = true;
    return route.fulfill({ json: [] });
  });
  await goto(page, '/es/content/dootronics-dashboard?palette=ocean&theme=labdoo_next', info);
  await expect(page.locator('.labdoo-quote')).toHaveAttribute('aria-busy', 'false');
  await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
  const map = page.locator('.labdoo-map-container').first();
  expect((await map.boundingBox())!.y).toBeGreaterThanOrEqual(823);
  expect(points).toBe(false);
  expect(leaflet).toBe(false);
  await map.scrollIntoViewIfNeeded();
  await expect.poll(() => points).toBe(true);
  await expect(map).toHaveClass(/leaflet-container/);
});

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
  const optional: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    if (/chartjs-(adapter-date-fns|plugin-datalabels)/.test(request.url())) optional.push(request.url());
  });
  await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
  const chart = page.locator('.charts-chartjs').first();
  await chart.scrollIntoViewIfNeeded();
  await expect(page.locator('canvas.charts-chartjs').first()).toBeVisible();
  expect(await page.evaluate(() => {
    const canvas = document.querySelector('canvas.charts-chartjs') as HTMLCanvasElement;
    return Boolean((window as any).Chart.getChart(canvas));
  })).toBe(true);
  expect(optional).toEqual([]);
  expect(errors).toEqual([]);
});

test('an AJAX chart can load dates and data labels after the simple dashboard chart @responsive', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
  await goto(page, '/en/content/dootronics-dashboard?theme=labdoo_next', info);
  await page.locator('.charts-chartjs').first().scrollIntoViewIfNeeded();
  await expect(page.locator('canvas.charts-chartjs').first()).toBeVisible();
  await page.evaluate(() => {
    const parent = document.createElement('section');
    parent.style.width = '350px';
    const chart = document.createElement('div');
    chart.id = 'e2e-date-chart';
    chart.className = 'charts-chartjs';
    chart.dataset.chart = JSON.stringify({
      type: 'line',
      data: { datasets: [{ label: 'History', data: [{ x: '2026-11-01', y: 2 }, { x: '2026-11-02', y: 5 }] }] },
      options: { animation: false, scales: { x: { type: 'time', time: { unit: 'day' } } }, plugins: { datalabels: { display: true } } },
    });
    parent.append(chart);
    document.body.append(parent);
    (window as any).Drupal.attachBehaviors(parent, (window as any).drupalSettings);
  });
  await page.locator('#e2e-date-chart').scrollIntoViewIfNeeded();
  await expect(page.locator('canvas#e2e-date-chart')).toBeVisible();
  expect(await page.evaluate(() => {
    const chart = (window as any).Chart.getChart('e2e-date-chart');
    return { scale: chart.scales.x.type, points: chart.getDatasetMeta(0).data.length, labels: chart.config.plugins.some((plugin: any) => plugin.id === 'datalabels') };
  })).toEqual({ scale: 'time', points: 2, labels: true });
  expect(errors).toEqual([]);
});

test('preview HTML uses contextual dynamic caching while remaining private', async ({ request }) => {
  const token = Date.now().toString();
  const a = `/en/content/dootronics-dashboard?theme=labdoo_next&cache_probe=a${token}`;
  const b = `/en/content/dootronics-dashboard?theme=labdoo_next&cache_probe=b${token}`;
  await request.get(a);
  const cached = await request.get(a);
  expect(cached.headers()['x-drupal-dynamic-cache']).toBe('HIT');
  expect(cached.headers()['x-drupal-cache']).not.toBe('HIT');
  expect(cached.headers()['cache-control']).toContain('private');
  expect(cached.headers()['cache-control']).toContain('no-store');
  const html = await (await request.get(b)).text();
  expect(html).toContain(`cache_probe=b${token}`);
  expect(html).not.toContain(`cache_probe=a${token}`);
  const original = await request.get(`/en/content/dootronics-dashboard?cache_probe=a${token}`);
  expect(await original.text()).not.toContain('class="ldn-site"');
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
