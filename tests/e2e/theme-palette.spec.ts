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
const preview = '/en/content/dootronics-dashboard?theme=labdoo_next';

test('anonymous visitors cannot administer palettes or override the site choice', async ({ page }, info) => {
  const response = await goto(page, admin, info);
  expect(response.status()).toBe(403);
  await goto(page, `${preview}&palette=plum`, info);
  await expect(page.locator('.ldn-palette')).toHaveCount(0);
  const active = await php('echo "PALETTE:".\\Drupal::service("labdoo_theme_preview.palettes")->active()["id"];');
  await expect(page.locator('body')).toHaveAttribute('data-ldn-palette', active);
});

test.describe('site palette administration', () => {
  test.use({ role: 'admin' });

  test('create a named palette and select it for all visitors @responsive', async ({ page, browser, baseURL }, info) => {
    await goto(page, admin, info);
    await expect(page.getByLabel('Active palette', { exact: true })).toBeVisible();
    const name = `E2E ocean ${Date.now()}`;
    await page.getByLabel('Create a new palette', { exact: true }).check();
    await page.getByLabel('Palette name', { exact: true }).fill(name);
    await page.getByLabel('Primary color (links and buttons)', { exact: true }).fill('#135a83');
    await page.getByLabel('Page background', { exact: true }).fill('#f0f7fc');
    await page.getByLabel('Card background', { exact: true }).fill('#fef7ef');
    await page.getByLabel('Card background', { exact: true }).blur();
    await page.screenshot({ path: info.outputPath('admin-palette-editor.png'), fullPage: true });
    await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('saved');
    const selected = await page.getByLabel('Active palette', { exact: true }).inputValue();
    expect(selected).toMatch(/^custom_[a-f0-9]{12}$/);
    await expect(page.getByLabel('Active palette', { exact: true }).locator('option:checked')).toHaveText(name);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('admin-palette-created.png') });

    const context = await browser.newContext({ baseURL, javaScriptEnabled: false, viewport: info.project.use.viewport });
    await networkGuard(context);
    const visitor = await context.newPage();
    try {
      await goto(visitor, `${preview}&palette=plum`, info);
      await expect(visitor.locator('body')).toHaveAttribute('data-ldn-palette', selected);
      const colors = await visitor.locator('body').evaluate(el => {
        const css = getComputedStyle(el);
        return [css.getPropertyValue('--ldn-green').trim(), css.getPropertyValue('--ldn-canvas').trim()];
      });
      expect(colors).toEqual(['#135a83', '#f0f7fc']);
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
      await expect(visitor.locator('body')).not.toHaveClass(/labdoo-next/);
    }
    finally {
      await context.close();
    }
    // Duplicate names cannot replace an existing palette.
    await page.getByLabel('Create a new palette', { exact: true }).check();
    await page.getByLabel('Palette name', { exact: true }).fill(name + ' revised');
    await page.getByRole('button', { name: 'Save configuration', exact: true }).click();
    await expect(page.locator('body')).toContainText('A palette with this name already exists.');
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
