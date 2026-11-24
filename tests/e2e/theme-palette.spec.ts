import { test, expect, goto, networkGuard, authPath } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { stateDir } from './helpers';

const cli = promisify(execFile);
async function php(code: string) {
  const { stdout } = await cli('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:eval', code]);
  return stdout.split('PALETTE:')[1]?.trim();
}
let original: any;
test.beforeAll(async () => {
  original = JSON.parse(await php('echo "PALETTE:".json_encode(\\Drupal::config("labdoo_theme_preview.palettes")->getRawData());'));
});
test.afterAll(async () => {
  const encoded = Buffer.from(JSON.stringify(original)).toString('base64');
  await php(`$c=\\Drupal::configFactory()->getEditable('labdoo_theme_preview.palettes');$data=json_decode(base64_decode('${encoded}'),TRUE);if($data){$c->setData($data)->save();}else{$c->delete();}`);
});

const admin = '/en/admin/appearance/labdoo-next/palettes';
const preview = '/en/content/dootronics-dashboard';

test('anonymous visitors cannot administer palettes or override the site choice', async ({ page }, info) => {
  const response = await goto(page, admin, info);
  expect(response.status()).toBe(403);
  await goto(page, `${preview}?palette=plum`, info);
  await expect(page.locator('.ldn-palette')).toHaveCount(0);
  const active = await php('echo "PALETTE:".\\Drupal::service("labdoo_theme_preview.palettes")->active()["id"];');
  await expect(page.locator('body')).toHaveAttribute('data-ldn-palette', active);
});

test.describe('site palette administration', () => {
  test.use({ role: 'admin' });

  test('Learning palette uses colored headings and quiet metrics in both languages @responsive', async ({ page, browser, baseURL }, info) => {
    await php('\\Drupal::configFactory()->getEditable("labdoo_theme_preview.palettes")->delete();');
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: info.project.use.viewport });
    await networkGuard(context);
    const visitor = await context.newPage();
    try {
      // Warm the fallback while the configuration does not exist yet.
      await goto(visitor, '/en', info);
      await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', 'forest');
      const cached = await goto(visitor, '/en', info);
      expect(cached.headers()['x-drupal-cache']).toBe('HIT');
      await goto(page, admin, info);
      await page.getByLabel('Active palette', { exact: true }).selectOption('learning');
      await expect(page.getByLabel('Palette name', { exact: true })).toHaveValue('Learning');
      await expect(page.getByLabel('Title', { exact: true })).toHaveValue('#c5221f');
      await expect(page.getByLabel('Supporting color', { exact: true })).toHaveValue('#188038');
      await expect(page.getByLabel('Highlight color', { exact: true })).toHaveValue('#fbbc04');
      await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
      await expect(page.locator('.messages--status')).toContainText('saved');
      for (const language of ['en', 'es']) {
        await goto(visitor, `/${language}`, info);
        await expect(visitor.locator('body')).not.toHaveClass(/user-logged-in/);
        await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', 'learning');
        const metrics = visitor.locator('.ldn-metric');
        const colors = await metrics.locator('strong').evaluateAll(nodes => [...new Set(nodes.map(el => getComputedStyle(el).color))]);
        expect(colors).toEqual(['rgb(25, 103, 210)']);
        expect(await metrics.first().evaluate(el => getComputedStyle(el, '::before').content)).toBe('none');
        await expect(visitor.locator('h1.page-title')).toHaveCSS('color', 'rgb(197, 34, 31)');
        expect(await visitor.locator('h1.page-title').evaluate(el => getComputedStyle(el, '::after').backgroundColor)).toBe('rgb(251, 188, 4)');
        await expect(visitor.locator('.ldn-quote figcaption')).toHaveCSS('color', 'rgb(24, 128, 56)');
        expect(await visitor.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await visitor.screenshot({ path: info.outputPath(`learning-home-${language}.png`) });
        await goto(visitor, `/${language}/content/dootronics-dashboard`, info);
        await expect(visitor.locator('body')).not.toHaveClass(/user-logged-in/);
        await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', 'learning');
        await expect(visitor.locator('.ldn-sidebar .views-exposed-form').first()).toBeVisible();
        const filters = visitor.locator('.ldn-sidebar .views-exposed-form').first();
        await expect(filters).toHaveCSS('border-top-color', 'rgb(251, 188, 4)');
        await expect(filters.locator('label').first()).toHaveCSS('color', 'rgb(24, 128, 56)');
        await visitor.screenshot({ path: info.outputPath(`learning-dashboard-${language}.png`) });
        await goto(visitor, `/${language}/statistics/advanced`, info);
        await expect(visitor.locator('.advanced-statistics-wrapper > h2')).toHaveCSS('color', 'rgb(24, 128, 56)');
        await expect(visitor.locator('.chart-container h3').first()).toHaveCSS('color', 'rgb(180, 83, 9)');
      }
    }
    finally {
      await context.close();
    }
  });

  test('create a named palette and select it for all visitors @responsive', async ({ page, browser, baseURL }, info) => {
    await goto(page, admin, info);
    await expect(page.getByLabel('Active palette', { exact: true })).toBeVisible();
    const name = `E2E ocean ${Date.now()}`;
    await page.getByLabel('Create a new palette', { exact: true }).check();
    await page.getByLabel('Palette name', { exact: true }).fill(name);
    await page.getByLabel('Primary color (links and buttons)', { exact: true }).fill('#135a83');
    await page.getByLabel('Title', { exact: true }).fill('#a32323');
    await page.getByLabel('Supporting color', { exact: true }).fill('#167443');
    await page.getByLabel('Highlight color', { exact: true }).fill('#efb321');
    await page.getByLabel('Page background', { exact: true }).fill('#f0f7fc');
    await page.getByLabel('Card background', { exact: true }).fill('#fef7ef');
    await page.getByLabel('Card background', { exact: true }).blur();
    await page.screenshot({ path: info.outputPath('admin-palette-editor.png'), fullPage: true });
    await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
    await expect(page.locator('.messages--status')).toContainText('saved');
    const selected = await page.getByLabel('Active palette', { exact: true }).inputValue();
    expect(selected).toMatch(/^custom_[a-f0-9]{12}$/);
    await expect(page.getByLabel('Active palette', { exact: true }).locator('option:checked')).toHaveText(name);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('admin-palette-created.png') });

    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, javaScriptEnabled: false, viewport: info.project.use.viewport });
    await networkGuard(context);
    const visitor = await context.newPage();
    try {
      await goto(visitor, `${preview}?palette=plum`, info);
      await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', selected);
      const colors = await visitor.locator('body').evaluate(el => {
        const css = getComputedStyle(el);
        return ['green', 'canvas', 'supporting', 'highlight', 'title'].map(key => css.getPropertyValue(`--ldn-${key}`).trim());
      });
      expect(colors).toEqual(['#135a83', '#f0f7fc', '#167443', '#efb321', '#a32323']);
      expect(await visitor.locator('.ldn-sidebar .views-exposed-form').first().evaluate(el => getComputedStyle(el).backgroundColor)).toBe('rgb(254, 247, 239)');
      await expect(visitor.locator('.ldn-palette')).toHaveCount(0);
      await visitor.screenshot({ path: info.outputPath('custom-palette-public.png') });
      // Saving another choice must invalidate the page's previous palette.
      await page.getByLabel('Active palette', { exact: true }).selectOption('terracotta');
      await expect(page.getByLabel('Palette name', { exact: true })).toHaveValue('Terracotta');
      await expect(page.getByLabel('Primary color (links and buttons)', { exact: true })).toHaveValue('#934225');
      await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
      await goto(visitor, preview, info);
      await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', 'terracotta');
      expect(await visitor.locator('body').evaluate(el => getComputedStyle(el).getPropertyValue('--ldn-green').trim())).toBe('#934225');
      // The saved custom palette remains available after switching away.
      await page.getByLabel('Active palette', { exact: true }).selectOption(selected);
      await expect(page.getByLabel('Palette name', { exact: true })).toHaveValue(name);
      await expect(page.getByLabel('Primary color (links and buttons)', { exact: true })).toHaveValue('#135a83');
      await page.getByLabel('Palette name', { exact: true }).fill(name + ' revised');
      await page.getByLabel('Primary color (links and buttons)', { exact: true }).fill('#5a367d');
      await page.getByLabel('Primary color (links and buttons)', { exact: true }).blur();
      await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
      await goto(visitor, preview, info);
      await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', selected);
      expect(await visitor.locator('body').evaluate(el => getComputedStyle(el).getPropertyValue('--ldn-green').trim())).toBe('#5a367d');
      await expect(page.getByLabel('Active palette', { exact: true }).locator('option:checked')).toHaveText(name + ' revised');
      await goto(visitor, '/en/content/dootronics-dashboard', info);
      await expect(visitor.locator('body')).toHaveClass(/labdoo-next/);
    }
    finally {
      await context.close();
    }
    // Duplicate names cannot replace an existing palette.
    await page.getByLabel('Create a new palette', { exact: true }).check();
    await page.getByLabel('Palette name', { exact: true }).fill(name + ' revised');
    await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
    await expect(page.locator('body')).toContainText('A palette with this name already exists.');
    // A validation response can paint before its behavior script attaches.
    // Wait until the JS editor has replaced the no-JS load button.
    await expect(page.getByRole('button', { name: 'Load palette', exact: true, includeHidden: true })).toBeHidden();
    // Built-in palettes can also be loaded, edited and saved.
    await page.getByLabel('Create a new palette', { exact: true }).uncheck();
    await page.getByLabel('Active palette', { exact: true }).selectOption('plum');
    await expect(page.getByLabel('Palette name', { exact: true })).toHaveValue('Plum');
    await page.getByLabel('Primary color (links and buttons)', { exact: true }).fill('#6a3b77');
    await page.getByLabel('Primary color (links and buttons)', { exact: true }).blur();
    await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
    await expect(page.getByLabel('Primary color (links and buttons)', { exact: true })).toHaveValue('#6a3b77');
    await expect(page.getByLabel('Active palette', { exact: true })).toHaveValue('plum');
  });
  test('load another palette without JavaScript before editing', async ({ browser, baseURL }, info) => {
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false, storageState: authPath('integration', 'admin') });
    await networkGuard(context);
    const page = await context.newPage();
    try {
      await goto(page, admin, info);
      await page.getByLabel('Active palette', { exact: true }).selectOption('ocean');
      await page.getByRole('button', { name: 'Load palette', exact: true }).click();
      await expect(page.getByLabel('Palette name', { exact: true })).toHaveValue('Ocean');
      await expect(page.getByLabel('Primary color (links and buttons)', { exact: true })).toHaveValue('#155e82');
      await page.getByLabel('Primary color (links and buttons)', { exact: true }).fill('#165d80');
      await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
      expect(new URL(page.url()).searchParams.has('edit')).toBe(false);
      await expect(page.getByLabel('Active palette', { exact: true })).toHaveValue('ocean');
      await expect(page.getByLabel('Primary color (links and buttons)', { exact: true })).toHaveValue('#165d80');
    }
    finally {
      await context.close();
    }
  });
});

test.describe('palette permissions', () => {
  test.use({ role: 'basic' });
  test('ordinary users cannot administer palettes', async ({ page }, info) => {
    expect((await goto(page, admin, info)).status()).toBe(403);
  });
});
