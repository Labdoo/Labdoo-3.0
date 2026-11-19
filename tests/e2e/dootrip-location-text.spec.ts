import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

test.skip(
  !fs.existsSync(path.join(stateDir, 'v2-readonly-connection.json')),
  'Requires the prepared, local, read-only V2 fixture described in tests/e2e/README.md.',
);

test('existing dootrips show origin and destination in the correct order @responsive', async ({ page }, info) => {
  await page.context().addCookies([{ name: 'cookie-agreed', value: '0', url: 'http://127.0.0.1:18381' }]);
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/backfill-dootrip-regression.php')]);
  for (const nid of [16086, 16136]) {
    expect((await goto(page, '/en/node/' + nid, info)).status()).toBe(200);
    const fields = ['field-origin-of-the-trip', 'field-departure-date', 'field-destination-of-the-trip', 'field-arrival-date'];
    const boxes = [];
    for (const field of fields) {
      const element = page.locator('.field--name-' + field).first();
      await expect(element).toBeVisible();
      boxes.push((await element.boundingBox())!.y);
    }
    expect(boxes).toEqual([...boxes].sort((a, b) => a - b));
    if (nid === 16086) {
      await expect(page.locator('.field--name-field-number-of-doojects-assigne .field__item')).toHaveText('1');
    }
  }
});
