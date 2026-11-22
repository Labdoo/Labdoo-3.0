import { test, expect, fixtures, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

async function run(operation: 'create' | 'cleanup') {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/device-repository.php'),
  ], { env: { ...process.env, LABDOO_DEVICE_TEST_OP: operation } });
  return JSON.parse(stdout.split('DEVICE_JSON:')[1].trim());
}

test.use({ role: 'admin' });
test('external device data persists in the edit form and tolerates unknown battery voltage @responsive', async ({ page }, info) => {
  test.setTimeout(120000);
  try {
    const fixture = await run('create');
    expect((await goto(page, `/en/node/${fixture.nid}/edit`, info)).status()).toBe(200);
    for (const [field, value] of Object.entries({
      field_serial_number: 'E2E-EXTERNAL-SERIAL',
      field_cpu: '2400',
      field_memory: '4096',
      field_current_operating_system: 'E2E Linux',
      field_keyboard_type: 'E2E Spanish',
      field_amp_hours: '4000',
    })) {
      await expect(page.locator(`[name="${field}[0][value]"]`)).toHaveValue(value);
    }

    const label = await page.locator('[name="title[0][value]"]').inputValue();
    expect(label).toMatch(/^\d{9}$/);
    const upload = async (data: Record<string, unknown>, destinationOnly: boolean) => {
      await goto(page, '/en/update-dootronics', info);
      await page.locator('input[type="file"]').setInputFiles({
        name: 'e2e-repository-update.json', mimeType: 'application/json',
        buffer: Buffer.from(JSON.stringify([{ labdoo_id: label, ...data }])),
      });
      await expect(page.locator('main')).toContainText(/e2e-repository-update(?:_\d+)?\.json/);
      await page.locator('[name="edoovillage_only"]').setChecked(destinationOnly);
      await page.locator('main').getByRole('button', { name: 'Upload and execute', exact: true }).click();
      await expect(page.locator('.messages--error')).toHaveCount(0);
      await expect(page.locator('.messages--status').filter({ hasText: 'Dootronics successfully updated' })).toContainText(label);
      expect((await goto(page, `/en/node/${fixture.nid}/edit`, info)).status()).toBe(200);
    };
    await upload({
      serial_no: 'E2E-UPDATED-SERIAL', cpu_speed_mhz: 0, memory_size_mb: 8192,
      os_version: '', keyboard_layout: 'E2E English',
      battery_voltage: 0, battery_energy_mwh: 48000,
    }, false);
    for (const [field, value] of Object.entries({
      field_serial_number: 'E2E-UPDATED-SERIAL', field_cpu: '0', field_memory: '8192',
      field_current_operating_system: '', field_keyboard_type: 'E2E English',
      field_amp_hours: '4000',
    })) {
      await expect(page.locator(`[name="${field}[0][value]"]`)).toHaveValue(value);
    }
    const school = fixtures(info).nodes.School;
    await upload({ edoovillage: school.id, serial_no: 'MUST-NOT-REPLACE' }, true);
    // Drupal quotes autocomplete labels containing commas or quotation marks.
    const destinationLabel = `${school.title} (${school.id})`;
    const encodedLabel = /[,"]/.test(destinationLabel) ? `"${destinationLabel.replaceAll('"', '""')}"` : destinationLabel;
    await expect(page.locator('[name="field_edoovillage_destination[0][target_id]"]')).toHaveValue(encodedLabel);
    await expect(page.locator('[name="field_serial_number[0][value]"]')).toHaveValue('E2E-UPDATED-SERIAL');
  }
  finally {
    await run('cleanup');
  }
});
