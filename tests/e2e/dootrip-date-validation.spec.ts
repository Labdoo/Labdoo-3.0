import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

async function fixture(operation: string) {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/dootrip-dates.php'),
  ], { env: { ...process.env, LABDOO_DATE_TEST_OP: operation }, timeout: 45000 });
  return JSON.parse(stdout.split('DATES_JSON:')[1].trim());
}

test.describe('dootrip date validation', () => {
  test.use({ role: 'admin' });
  test('rejects reversed dates without saving and persists corrected dates @responsive', async ({ page }, info) => {
    const original = await fixture('create');
    try {
      await goto(page, `/en/node/${original.nid}/edit`, info);
      const departure = page.locator('[name="field_departure_date[0][value][date]"]');
      const arrival = page.locator('[name="field_arrival_date[0][value][date]"]');
      await departure.fill('2027-06-03');
      await arrival.fill('2027-06-02');
      await page.locator('[name="field_departure_date[0][value][time]"]').fill('12:00:00');
      await page.locator('[name="field_arrival_date[0][value][time]"]').fill('12:00:00');
      await page.locator('main').getByRole('button', { name: 'Save', exact: true }).press('Enter');
      await expect(page.locator('.messages--error')).toContainText('Departure date cannot be later than arrival date.');
      await expect(page).toHaveURL(new RegExp(`/node/${original.nid}/edit`));
      expect(await fixture('read')).toEqual(original);

      await departure.fill('2027-06-01');
      await page.locator('main').getByRole('button', { name: 'Save', exact: true }).press('Enter');
      await expect(page.locator('.messages--error')).toHaveCount(0);
      await expect(page).not.toHaveURL(new RegExp(`/node/${original.nid}/edit`));
      const saved = await fixture('read');
      expect(saved.departure.slice(0, 10)).toBe('2027-06-01');
      expect(saved.arrival.slice(0, 10)).toBe('2027-06-02');
      await goto(page, `/en/node/${original.nid}/edit`, info);
      await expect(departure).toHaveValue('2027-06-01');
      await expect(arrival).toHaveValue('2027-06-02');
    }
    finally {
      await fixture('cleanup');
    }
  });
});
