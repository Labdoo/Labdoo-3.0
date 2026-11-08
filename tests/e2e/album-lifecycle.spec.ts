import { test, expect, fixtures, goto, covers, stateDir, variant } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
const run = promisify(execFile);
async function drush(info: any, args: string[], input: any = {}) {
  const { stdout } = await run('python3', [path.join(stateDir, '../tools/drush.py'), variant(info), ...args], { env: { ...process.env, LABDOO_E2E_COMMAND: JSON.stringify(input) }, timeout: 60000 });
  return stdout;
}
async function entity(info: any, input: any) {
  const stdout = await drush(info, ['php:script', path.join(__dirname, 'support/entity-state.php')], input);
  return JSON.parse(stdout.split('E2E_JSON:')[1].trim());
}
test.describe('school album lifecycle', () => {
  test.use({ role: 'admin' });
  for (const stale of [false, true]) {
    test(`clone school and delete it ${stale ? 'before' : 'after'} the gallery worker`, covers('edoovillage-clone-album', 'edoovillage-album-cleanup'), async ({ page }, info) => {
      test.setTimeout(120000);
      const school = fixtures(info).nodes.School;
      const title = `E2E cloned school ${stale ? 'stale' : 'complete'} ${Date.now()}`;
      const original = (await entity(info, { id: school.id }))[0];
      await goto(page, `/en/clone/${school.id}/quick_clone`, info);
      await expect(page.locator('[name="field_edoovillage_photo_album[0][target_id]"]')).toHaveCount(0);
      await page.locator('[name="field_project_summary[0][value]"]').fill(title);
      await page.locator('[name="field_local_language_s_[0][value]"]').fill('Spanish');
      await page.getByRole('button', { name: 'Save', exact: true }).first().click();
      await expect(page.locator('main')).not.toContainText('An illegal choice has been detected');
      await expect(page).not.toHaveURL(/\/quick_clone/);
      const clones = await entity(info, { path: new URL(page.url()).pathname });
      expect(clones).toHaveLength(1);
      const clone = clones[0];
      expect(clone.id).not.toBe(school.id);
      expect(clone.album).toBeNull();
      // Warm the actions block before the asynchronous album exists.
      await goto(page, `/en/node/${clone.id}`, info);
      await expect(page.getByRole('link', { name: 'Go to photo album', exact: true })).toHaveCount(0);
      let galleries: number[] = [];
      if (!stale) {
        await drush(info, ['queue:run', 'labdoo_gallery_create_or_update', '--time-limit=40']);
        galleries = (await entity(info, { id: clone.id }))[0].galleries;
        expect(galleries).toHaveLength(1);
        expect(galleries).not.toContain(original.album);
        await goto(page, `/en/node/${clone.id}`, info);
        const albumLink = page.getByRole('link', { name: 'Go to photo album', exact: true });
        await expect(albumLink).toBeVisible();
        await albumLink.click();
        expect((await entity(info, { path: new URL(page.url()).pathname }))[0].id).toBe(galleries[0]);
      }
      await goto(page, `/en/node/${clone.id}/delete`, info);
      await page.locator('main').getByRole('button', { name: 'Delete', exact: true }).click();
      expect(await entity(info, { id: clone.id })).toEqual([]);
      await drush(info, ['queue:run', 'labdoo_gallery_create_or_update', '--time-limit=40']);
      for (const id of galleries) expect(await entity(info, { id })).toEqual([]);
      expect((await entity(info, { id: school.id }))[0]).toEqual(original);
      expect((await goto(page, fixtures(info).nodes.Gallery.path, info)).status()).toBe(200);
    });
  }
});
