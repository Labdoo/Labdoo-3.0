import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

const v2Connection = path.join(stateDir, 'v2-readonly-connection.json');
const legacyFixtures = path.join(stateDir, 'legacy-address-fixtures.json');
test.skip(
  !fs.existsSync(v2Connection) || !fs.existsSync(legacyFixtures),
  'Requires the prepared, local, read-only V2 fixture described in tests/e2e/README.md.',
);

test('existing dootronics recover real V2 addresses without allocating IDs @responsive', async ({ page }, info) => {
  test.setTimeout(180000);
  // Consent UI is outside this address regression; keep it out of the viewport.
  await page.context().addCookies([{ name: 'cookie-agreed', value: '0', url: 'http://127.0.0.1:18381' }]);
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/backfill-address-regression.php')]);
  const fixtures = JSON.parse(fs.readFileSync(legacyFixtures, 'utf8'));
  for (const sample of fixtures.samples) {
    expect((await goto(page, '/en/node/' + sample.source_id, info)).status()).toBe(200);
    const field = page.locator('.field--name-field-address');
    await expect(field).toBeVisible();
    const text = (await field.innerText()).normalize('NFC').replace(/\s+/g, ' ');
    for (const component of ['address_line1', 'locality', 'postal_code']) {
      const expected = sample.expected[component].normalize('NFC').replace(/\s+/g, ' ');
      if (expected) expect(text.includes(expected), sample.case + ': ' + component).toBe(true);
    }
  }
});
