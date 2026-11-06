import { test, expect, fixtures, goto, covers, stateDir, variant } from './helpers';

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

test('actual migration mapper persists all four address components and renders them', covers('dootronic-text-address'), async ({ page }, info) => {
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), variant(info), 'php:script', path.join(__dirname, 'support/map-address-contract.php')], { timeout: 30000 });
  const mapped = JSON.parse(fs.readFileSync(path.join(stateDir, 'mapped-address-fixture.json'), 'utf8'));
  await goto(page, mapped.path, info);
  const address = page.locator('.field--name-field-address');
  for (const key of ['locality', 'postal_code', 'address_line1']) await expect(address).toContainText(mapped.expected[key]);
  await expect(address).toContainText('Spain');
});

test('anonymous reader can inspect dootrip revisions without edit actions', covers('dootrip-public-revisions'), async ({ page }, info) => {
  const id = fixtures(info).nodes.Trip.id;
  expect((await goto(page, `/en/node/${id}/revisions`, info)).status()).toBe(200);
  await expect(page.locator('main')).toContainText('Revisions');
  await expect(page.locator('main a[href*="/revert"], main a[href*="/delete"]')).toHaveCount(0);
  expect((await goto(page, `/en/node/${id}/edit`, info)).status()).toBe(403);
});

test('dootronic displays its structured textual address', covers('dootronic-text-address'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes['Laptop Main'].path, info);
  const address = page.locator('.field--name-field-address');
  await expect(address).toContainText('E2E Street 42');
  await expect(address).toContainText('Madrid');
  await expect(address).toContainText('28001');
});

test('dootrip details follow the requested geographic and date order', covers('dootrip-display-order'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes.Trip.path, info);
  const fields = ['field_origin_of_the_trip', 'field_departure_date', 'field_destination_of_the_trip', 'field_arrival_date', 'field_dootripper_s', 'field_status_dootrip', 'field_dootrip_capacity', 'field_number_of_doojects_assigne'];
  let previous = -1;
  for (const field of fields) {
    const el = page.locator('main .field--name-' + field.replaceAll('_', '-')).first();
    await expect(el).toBeVisible();
    const y = (await el.boundingBox())!.y;
    expect(y, field).toBeGreaterThanOrEqual(previous); previous = y;
  }
});

test.describe('administrator forms', () => {
  test.use({ role: 'admin' });
  for (const [key, branch, fields] of [
    ['Laptop Main', 'dootronic-field-descriptions', ['field_edoo_additional_editors', 'field_edoo_additional_notif_em', 'field_edoo_additional_followers', 'field_doojects_additional_notes', 'field_technical_notes']],
    ['School', 'edoovillage-field-descriptions', ['field_edoo_additional_editors', 'field_edoo_additional_notif_em', 'field_edoo_additional_followers', 'field_doojects_additional_notes', 'field_internet_additional_notes']],
  ] as const) {
    test(`${key} fields expose meaningful help text`, covers(branch), async ({ page }, info) => {
      expect((await goto(page, `/en/node/${fixtures(info).nodes[key].id}/edit`, info)).status()).toBe(200);
      for (const field of fields) {
        const wrapper = page.locator(`#edit-${field.replaceAll('_', '-')}-wrapper`);
        const description = page.locator(`[id*="${field.replaceAll('_', '-')}"][id$="description"]`).last();
        await expect(description).toBeAttached();
        expect((await description.innerText()).trim().length, field).toBeGreaterThan(25);
      }
    });
  }
  test('dootrip announcement explains recipients and links to guidance', covers('dootrip-announcement-description'), async ({ page }, info) => {
    await goto(page, `/en/node/${fixtures(info).nodes.Trip.id}/edit`, info);
    const wrapper = page.locator('#edit-field-announce-this-dootrip-wrapper');
    await expect(wrapper).toContainText('managing schools in the destination country');
    await expect(wrapper.locator('a')).toHaveAttribute('href', /automatic-notifications-and-managing-your-dootrips/);
  });
  test('dootrip form follows the requested order and offers geocoding inputs', covers('dootrip-form-order'), async ({ page }, info) => {
    await goto(page, `/en/node/${fixtures(info).nodes.Trip.id}/edit`, info);
    const fields = ['field_dootrip_capacity', 'field_origin_of_the_trip', 'field_departure_date', 'field_number_of_days_before', 'field_destination_of_the_trip', 'field_arrival_date', 'field_number_of_days_after', 'field_dootripper_s'];
    let previous = -1;
    for (const field of fields) {
      const wrapper = page.locator(`#edit-${field.replaceAll('_', '-')}-wrapper`);
      await expect(wrapper).toBeVisible();
      const y = (await wrapper.boundingBox())!.y;
      expect(y, field).toBeGreaterThanOrEqual(previous); previous = y;
    }
    for (const field of ['origin', 'destination']) {
      const wrapper = page.locator(`#edit-field-${field}-of-the-trip-wrapper`);
      await expect(wrapper.locator('input[type="text"][name*="geocode"]')).toBeVisible();
    }
  });
});

test.describe('wiki writer', () => {
  test.use({ role: 'wiki' });
  test('writer sees Add subpage and reaches a prefilled parent form', covers('wiki-add-subpage-access'), async ({ page }, info) => {
    await goto(page, fixtures(info).wiki.Book.path, info);
    const link = page.getByRole('link', { name: 'Add subpage', exact: true });
    await expect(link).toBeVisible();
    await link.click();
    await expect(page.locator('form.mini-wiki-page-form')).toContainText('Parent');
    await expect(page.locator('[name="parent[0][target_id]"]')).toHaveValue(new RegExp(String(fixtures(info).wiki.Book.id)));
  });
  test('saving page weight reorders subpages and sequential navigation', covers('wiki-page-order', 'wiki-subpage-order'), async ({ page }, info) => {
    const wiki = fixtures(info).wiki;
    async function setWeight(weight: string) {
      expect((await goto(page, `/en/wiki-page/${wiki.Zulu.id}/edit`, info)).status()).toBe(200);
      await page.locator('[name="weight[0][value]"]').fill(weight);
      await page.locator('main').getByRole('button', { name: 'Save', exact: true }).click();
      await expect(page.locator('main')).not.toContainText('There is an error');
    }
    try {
      await setWeight('5');
      await goto(page, wiki.Book.path, info);
      await expect(page.locator('.wiki-sequential-navigation a[rel="next"]')).toContainText(wiki.Zulu.title);
      const links = page.locator('.mini-wiki-navigation a');
      if (await links.count()) expect((await links.allTextContents()).indexOf(wiki.Zulu.title)).toBeLessThan((await links.allTextContents()).indexOf(wiki.Alpha.title));
    } finally { await setWeight('20'); }
    await goto(page, wiki.Book.path, info);
    await expect(page.locator('.wiki-sequential-navigation a[rel="next"]')).toContainText(wiki.Alpha.title);
  });
});
