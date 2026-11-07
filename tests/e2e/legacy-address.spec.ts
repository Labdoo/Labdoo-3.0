import { test, expect, goto, covers, stateDir, variant } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

test('real V2 source extraction, address migration and browser rendering agree', covers('dootronic-text-address'), async ({ page }, info) => {
  test.setTimeout(180000);
  expect(variant(info)).toBe('integration');
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/migrate-address.php')], { timeout: 120000, maxBuffer: 1024 * 1024 });
  const fixture = JSON.parse(fs.readFileSync(path.join(stateDir, 'legacy-address-fixtures.json'), 'utf8'));
  expect(fixture.source_read_only).toBe(true);
  expect(fixture.source_database).toBe('labdoo_e2e_v2_20261107');
  expect(fixture.samples.length).toBeGreaterThanOrEqual(6);
  for (const sample of fixture.samples) {
    await test.step(sample.case, async () => {
      expect((await goto(page, sample.path, info)).status()).toBe(200);
      const field = page.locator('.field--name-field-address');
      await expect(field).toBeVisible();
      // Report only component names on failure; real source values stay private.
      const text = (await field.innerText()).normalize('NFC').replace(/\s+/g, ' ');
      for (const component of ['locality', 'postal_code', 'address_line1']) {
        const expected = sample.expected[component].normalize('NFC').replace(/\s+/g, ' ');
        if (expected) expect(text.includes(expected), `${sample.case}: rendered ${component} matches V2`).toBe(true);
      }
      const country = field.locator('.country');
      await expect(country).toBeVisible();
      expect((await country.innerText()).trim().length, `${sample.case}: rendered country`).toBeGreaterThan(0);
    });
  }
});
