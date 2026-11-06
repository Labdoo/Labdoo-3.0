import { test, expect, fixtures, goto, covers } from './helpers';

test.describe('label printing', () => {
  test.use({ role: 'admin' });
  test('selected public IDs print once, preserve order, and use the clean print layout', covers('bulk-print-labels'), async ({ page }, info) => {
    const nodes = fixtures(info).nodes;
    const ids = [nodes['Laptop Main'].title, nodes['Laptop Second'].title];
    await goto(page, `/en/dootronics/print-labels?id=${ids[0]}&id=${ids[1]}&id=${ids[0]}`, info);
    const tags = page.locator('.dootronic-print-labels strong').filter({ hasText: /tag\s+ID:/ });
    await expect(tags).toHaveCount(6);
    expect((await tags.allTextContents()).map(s => s.match(/\d{9}/)![0])).toEqual([ids[0], ids[0], ids[0], ids[1], ids[1], ids[1]]);
    await expect(page.locator('body')).toHaveClass(/dootronics-print-label-page/);
    await expect(page.locator('header, .region-sidebar, .region-footer')).toHaveCount(0);
    const qr = page.locator('.dootronic-print-labels img[src*="qr"]');
    await expect(qr).toHaveCount(6);
    expect(decodeURIComponent((await qr.first().getAttribute('src'))!)).toContain(ids[0]);
    await page.pdf({ path: info.outputPath('selected-labels.pdf'), format: 'A4', printBackground: true });
    await goto(page, `/en/dootronics/print-labels?id=${ids[1]}`, info);
    await expect(tags).toHaveCount(3);
    for (const text of await tags.allTextContents()) expect(text).toContain(ids[1]);
    await goto(page, `/en/content/tag-one-dooject?id_first=${ids[0]}&id_last=${ids[1]}`, info);
    await expect(tags).toHaveCount(6);
  });
  for (const [query, status] of [['id=abc', 400], ['id=999999999', 404], ['id_first=20&id_last=10', 400], ['id_first=1&id_last=1002', 400]] as const) {
    test(`invalid print selection ${query} is rejected`, covers('bulk-print-labels'), async ({ page }, info) => {
      expect((await goto(page, '/en/dootronics/print-labels?' + query, info)).status()).toBe(status);
    });
  }
});

test.describe('bulk dootronics', () => {
  test.use({ role: 'admin' });
  test('cloning two devices and uploading updates reports the actual public IDs', covers('dootronics-bulk-feedback', 'dootronics-confirmation-message'), async ({ page }, info) => {
    test.setTimeout(120000);
    const source = fixtures(info).nodes['Laptop Main'];
    await goto(page, '/en/clone-dootronics', info);
    await page.locator('[name="original_dootronic"]').fill(`${source.title} (${source.id})`);
    await page.locator('[name="dootronics_number"]').fill('2');
    await page.locator('form').filter({ has: page.locator('[name="original_dootronic"]') }).getByRole('button', { name: 'Clone', exact: true }).click();
    const message = page.locator('[role="status"]').filter({ hasText: 'Dootronics successfully cloned' });
    await expect(message).toContainText('Dootronics cloned are:');
    const ids = (await message.innerText()).match(/\d{9}/g) || [];
    expect(ids).toHaveLength(2); expect(new Set(ids).size).toBe(2); expect(ids).not.toContain(source.title);
    for (const id of ids) {
      expect((await goto(page, `/en/laptop/${id}`, info)).status()).toBe(200);
      await expect(page.locator('main')).toContainText('E2E Laptop Main');
    }
    await goto(page, '/en/update-dootronics', info);
    await page.locator('input[type="file"]').setInputFiles({ name: 'e2e-update.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(ids.map(id => ({ labdoo_id: id, state: 'S2' })))) });
    await expect(page.locator('main')).toContainText(/e2e-update(?:_\d+)?\.json/);
    await page.locator('[name="edoovillage_only"]').check();
    await page.locator('main').getByRole('button', { name: 'Upload and execute', exact: true }).click();
    const updated = page.locator('[role="status"]').filter({ hasText: 'Dootronics successfully updated' });
    await expect(updated).toContainText('Dootronics updated are:');
    for (const id of ids) await expect(updated).toContainText(id);
    for (const id of ids) {
      await goto(page, `/en/laptop/${id}`, info);
      await expect(page.locator('.field--name-field-dootronic-status')).toContainText('S2');
    }
  });
});
