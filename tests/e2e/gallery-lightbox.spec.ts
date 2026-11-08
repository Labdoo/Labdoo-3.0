import { test, expect, fixtures, goto, covers } from './helpers';

test('gallery lightbox opens originals, navigates and restores focus @responsive', covers('gallery-thumbnail-grid'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes.Gallery.path, info);
  const thumbnails = page.locator('.labdoo-gallery-grid a:has(img)');
  const first = thumbnails.first();
  const original = await first.getAttribute('href');
  const url = page.url();
  await first.click();
  const viewer = page.getByRole('dialog', { name: 'Photo viewer' });
  await expect(viewer).toBeVisible();
  await expect(viewer.locator('img')).toHaveAttribute('src', new URL(original!, url).href);
  await expect.poll(() => viewer.locator('img').evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  await expect(viewer.getByRole('status')).toHaveText('1 of 25');
  expect(page.url()).toBe(url);
  await viewer.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(viewer.locator('img')).toHaveAttribute('alt', 'E2E Photo 02');
  await page.keyboard.press('ArrowLeft');
  await expect(viewer.getByRole('status')).toHaveText('1 of 25');
  await page.keyboard.press('ArrowLeft');
  await expect(viewer.getByRole('status')).toHaveText('25 of 25');
  await page.keyboard.press('Escape');
  await expect(viewer).not.toBeVisible();
  await expect(first).toBeFocused();
});

test('gallery lightbox respects pagination and closes by button or backdrop @responsive', covers('gallery-thumbnail-grid'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes.Gallery.path, info);
  for (let i = 0; i < 2; i++) await page.locator('.field--name-field-images .pager__item--next a').first().click();
  const first = page.locator('.labdoo-gallery-grid a:has(img)').first();
  await first.click();
  const viewer = page.getByRole('dialog', { name: 'Photo viewer' });
  await expect(viewer.getByRole('status')).toHaveText('1 of 3');
  await expect(viewer.locator('img')).toHaveAttribute('alt', 'E2E Photo 51');
  await viewer.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(viewer.locator('img')).toHaveAttribute('alt', 'E2E Photo 52');
  const box = await viewer.boundingBox();
  expect(box!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
  await viewer.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(viewer).not.toBeVisible();
  await first.click();
  await page.mouse.click(1, 1);
  await expect(viewer).not.toBeVisible();
});

test.describe('gallery without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('gallery thumbnail retains a working original link', covers('gallery-thumbnail-grid'), async ({ page }, info) => {
    await goto(page, fixtures(info).nodes.Gallery.path, info);
    const first = page.locator('.labdoo-gallery-grid a:has(img)').first();
    const href = await first.getAttribute('href');
    const response = await page.request.get(href!);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toMatch(/^image\//);
    await first.click();
    await expect(page).toHaveURL(new RegExp('/sites/default/files/'));
  });
});
