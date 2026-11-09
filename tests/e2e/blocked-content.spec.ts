import { test, expect, goto, fixtures, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

async function video(op: string) {
  const { stdout } = await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/blocked-video.php')], { env: { ...process.env, LABDOO_PROGRAM_TEST_OP: op } });
  return JSON.parse(stdout.split('PROGRAM_JSON:')[1].trim());
}
for (const role of ['anonymous', 'basic', 'team', 'admin'] as const) {
  test.describe(`team sidebar as ${role}`, () => {
    test.use({ role });
    test('About this team remains visible and uses the canonical page @responsive', async ({ page }, info) => {
      await page.context().addCookies([{ name: 'cookie-agreed', value: '0', url: 'http://127.0.0.1:18381' }]);
      await goto(page, `/en/team-wall/${fixtures(info).nodes.Team.id}`, info);
      const block = page.locator('.block-team-actions-block-block');
      await expect(block).toBeVisible();
      const toggle = block.locator('.block-actions-toggle');
      if (await toggle.isVisible() && !(await toggle.getAttribute('class'))?.includes('is-active')) await toggle.click();
      const link = block.getByRole('link', { name: 'About this team', exact: true });
      await expect(link).toBeVisible();
      const href = (await link.getAttribute('href'))!;
      expect(href).toContain('/content/');
      expect(href).not.toContain('/team/about/');
      await link.click();
      await expect(page.locator('h1').first()).toContainText(fixtures(info).nodes.Team.title);
      await expect(block).toBeVisible();
    });
  });
}
test('embedded Media and legacy videos use a large responsive player @responsive', async ({ page }, info) => {
  const fixture = await video('create');
  try {
    await goto(page, fixture.path, info);
    for (const selector of ['.media--type-remote-video iframe', '.labdoo-video-embed iframe']) {
      const frame = page.locator(selector).first();
      await expect(frame).toBeVisible();
      const box = await frame.boundingBox();
      expect(box!.width).toBeGreaterThan(info.project.name === 'mobile' ? 200 : 600);
      expect(box!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
      expect(Math.abs(box!.width / box!.height - 16 / 9)).toBeLessThan(0.03);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(page.viewportSize()!.width);
  }
  finally {
    await video('cleanup');
  }
});
