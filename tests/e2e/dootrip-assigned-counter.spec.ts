import { test, expect, goto, covers, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import path from 'node:path';

let samples: { id: number; expected: number }[];
test.beforeAll(async () => {
  await promisify(execFile)('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/prepare-assigned-counter.php')], { timeout: 30000 });
  samples = JSON.parse(fs.readFileSync(path.join(stateDir, 'assigned-counter-fixtures.json'), 'utf8'));
});

for (const count of [0, 1]) {
  test(`legacy dootrip with ${count} linked devices displays its missing assigned counter`, covers('dootrip-display-order'), async ({ page }, info) => {
    const sample = samples.find(s => s.expected === count)!;
    await goto(page, `/en/node/${sample.id}`, info);
    const field = page.locator('.field--name-field-number-of-doojects-assigne');
    await expect(field).toBeVisible();
    await expect(field).toContainText('Number of dootronics assigned');
    await expect(field.locator('.field__item')).toHaveText(String(count));
    const capacity = page.locator('.field--name-field-dootrip-capacity');
    const weight = page.locator('.field--name-field-dootrip-weight');
    expect((await field.boundingBox())!.y).toBeGreaterThan((await capacity.boundingBox())!.y);
    expect((await field.boundingBox())!.y).toBeLessThan((await weight.boundingBox())!.y);
  });
}

test('existing dootrip 16086 displays its assigned device count', covers('dootrip-display-order'), async ({ page }, info) => {
  await goto(page, '/en/node/16086', info);
  await expect(page.locator('.field--name-field-number-of-doojects-assigne .field__item')).toHaveText('1');
});
