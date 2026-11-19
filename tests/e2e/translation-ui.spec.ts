import type { Page } from '@playwright/test';
import { test, expect, goto, stateDir, variant, authPath, networkGuard } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

const execute = promisify(execFile);
const route = '/admin/config/regional/labdoo-translations';

async function fixture(op: string, id = ''): Promise<any> {
  const { stdout } = await execute('python3', [path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script', path.join(__dirname, 'support/translation-ui.php')], {
    env: { ...process.env, LABDOO_TRANSLATION_UI_OP: op, LABDOO_TRANSLATION_UI_JOB: id }, timeout: 90000, maxBuffer: 1024 * 1024,
  });
  const result = stdout.split('\n').find(line => line.startsWith('RESULT:'));
  return result ? JSON.parse(result.slice(7)) : {};
}

async function reviewEstimate(page: Page) {
  await page.getByRole('button', { name: 'Estimate cost', exact: true }).click();
  await expect(page.getByRole('table', { name: 'Estimated usage and cost', exact: true })).toBeVisible();
  await page.locator('input[name="confirm_estimate"]').check();
}

test.describe('administrative translation jobs', () => {
  test.use({ role: 'basic' });
  test.beforeEach(async () => { await fixture('prepare'); });
  test.afterEach(async () => { await fixture('cleanup'); });

  test('cost review counts selected work without calling an agent @responsive', async ({ page }, info) => {
    test.setTimeout(120000);
    await goto(page, '/es' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('input[name="languages[fr]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await page.locator('select[name="model"]').selectOption('test-model', { force: true });
    await expect(page.getByRole('button', { name: 'Start translation', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Estimate cost', exact: true }).click();
    const review = page.locator('[data-translation-estimate]');
    await expect(review).toContainText('USD');
    await expect(review).toContainText('2026-11-19');
    await expect(review.getByRole('table')).toContainText('Total');
    const total = review.getByRole('row', { name: /^Total/ });
    await expect(total.locator('td').nth(1)).toHaveText('30');
    await expect(total.locator('td').nth(2)).toHaveText('4');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2)).toBe(true);
    expect((await fixture('inspect')).calls).toBe(0);
    expect((await fixture('inspect')).queue).toBe(0);
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.getByText('Review and confirm the estimated usage and billing information before starting.', { exact: true }).first()).toBeVisible();
    expect((await fixture('inspect')).queue).toBe(0);
    await page.locator('select[name="model"]').selectOption('', { force: true });
    await expect(page.getByRole('button', { name: 'Start translation', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Estimate cost', exact: true }).click();
    await expect(page.locator('[data-translation-estimate]')).toContainText('monetary cost cannot be estimated');
    await expect(page.getByRole('table', { name: 'Estimated usage and cost', exact: true })).toContainText('No disponible');
    expect((await fixture('inspect')).calls).toBe(0);
    expect((await fixture('inspect')).translations).toEqual({});
  });

  test('changed inventory, selection and rates require a fresh estimate', async ({ page }, info) => {
    test.setTimeout(180000);
    await goto(page, '/en' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await page.locator('select[name="model"]').selectOption('test-model', { force: true });
    await reviewEstimate(page);
    const body = await page.locator('form#labdoo-translation-jobs').evaluate(form => Object.fromEntries(new FormData(form as HTMLFormElement).entries()) as Record<string, string>);
    const changed = await page.request.post('/en' + route, { form: { ...body, start: 'Start translation', model: '' } });
    expect(await changed.text()).toContain('Estimate again before starting');
    expect((await fixture('inspect')).queue).toBe(0);
    await goto(page, '/en' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await page.locator('select[name="model"]').selectOption('test-model', { force: true });
    await reviewEstimate(page);
    await fixture('add-pending');
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.getByText('The selection, pending translations or pricing have changed. Estimate again before starting.', { exact: true }).first()).toBeVisible();
    expect((await fixture('inspect')).queue).toBe(0);
    await reviewEstimate(page);
    await fixture('pricing-change');
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.getByText('The selection, pending translations or pricing have changed. Estimate again before starting.', { exact: true }).first()).toBeVisible();
    expect((await fixture('inspect')).calls).toBe(0);
    expect((await fixture('inspect')).queue).toBe(0);
  });

  test('preview, background translation, private review and confirmed import @responsive', async ({ page }, info) => {
    test.setTimeout(180000);
    await goto(page, '/en' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('input[name="languages[fr]"]').check();
    await page.getByRole('button', { name: 'Check pending strings', exact: true }).click();
    const pending = page.getByRole('table', { name: 'Pending strings' });
    await expect(pending).toContainText('15');
    expect((await fixture('inspect')).calls).toBe(0);
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await page.locator('select[name="model"]').selectOption('test-model', { force: true });
    await reviewEstimate(page);
    const body = await page.locator('form#labdoo-translation-jobs').evaluate(form => Object.fromEntries(new FormData(form as HTMLFormElement).entries()) as Record<string, string>);
    body.start = 'Start translation';
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'queued');
    const jobUrl = page.url(); const id = new URL(jobUrl).pathname.split('/').pop()!;
    expect((await fixture('inspect')).calls).toBe(0);
    // Replaying a submitted form must not create another paid job.
    await page.request.post('/en' + route, { form: body });
    await goto(page, '/en' + route, info);
    await expect(page.getByRole('link', { name: /^Job created/ })).toHaveCount(1);
    await goto(page, jobUrl, info);
    const translated = await fixture('drain');
    expect(translated.calls).toBe(4);
    expect(translated.translations).toEqual({});
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'review');
    const finished = await page.request.get(jobUrl + '/status');
    expect(finished.headers()['cache-control']).toContain('no-store');
    expect((await finished.json()).completed).toBe(30);
    const downloadUrl = await page.getByRole('link', { name: 'Download es PO', exact: true }).getAttribute('href');
    const download = await page.request.get(downloadUrl!);
    expect(download.status()).toBe(200);
    expect(download.headers()['content-disposition']).toContain('labdoo_custom.es.po');
    const po = await download.text();
    expect(po).toContain('msgctxt "labdoo-translation-ui-e2e"');
    expect(po).toContain('msgid_plural');
    expect(po).toContain('Traducción');
    const rootLeak = await page.request.get(`/en/system/files/labdoo-translations/${id}/output.es.po`);
    expect(rootLeak.status()).toBe(403);
    await page.getByRole('link', { name: 'Review es', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Translation preview' })).toContainText('<strong>text</strong>');
    await expect(page.locator('.translation-preview strong')).toHaveCount(0);
    await page.getByRole('link', { name: 'Back to job', exact: true }).click();
    await page.getByRole('link', { name: 'Import es', exact: true }).click();
    expect((await fixture('inspect')).translations).toEqual({});
    await page.request.post(page.url(), { form: { form_id: 'labdoo_translation_action', confirm: '1', op: 'Confirm' } });
    expect((await fixture('inspect')).queue).toBe(0);
    expect((await fixture('inspect')).translations).toEqual({});
    await fixture('protect');
    await page.locator('form#labdoo-translation-action [type="submit"]').click();
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'importing');
    const imported = await fixture('drain');
    expect(Object.keys(imported.translations.es)).toHaveLength(15);
    expect(imported.translations.es[imported.first_lid]).toBe('Manual translation @name <strong>text</strong>');
    expect(imported.translations.fr).toBeUndefined();
    expect(imported.calls).toBe(4);
    await fixture('duplicate', id);
    expect((await fixture('drain')).calls).toBe(4);
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'review');
    await expect(page.getByRole('table')).toContainText('Imported');
  });

  test('failed batches resume after confirmation and preserve completed work @responsive', async ({ page }, info) => {
    test.setTimeout(180000);
    await goto(page, '/es' + route, info);
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await reviewEstimate(page);
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'queued');
    const id = new URL(page.url()).pathname.split('/').pop()!;
    await fixture('step');
    expect((await fixture('step')).calls).toBe(1);
    await expect(page.locator('[data-job-progress]')).toHaveAttribute('value', '10');
    await expect(page.locator('[data-language-progress="es"]')).toHaveText('10 / 15');
    await fixture('fail');
    expect((await fixture('step')).calls).toBe(2);
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'failed');
    await expect(page.getByRole('alert')).toContainText('Completed batches are preserved');
    await fixture('clear-failure');
    await page.getByRole('link', { name: 'Retry unfinished work', exact: true }).click();
    await page.locator('form#labdoo-translation-action [type="submit"]').click();
    expect((await fixture('drain')).calls).toBe(3);
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'review');
    const download = await page.getByRole('link', { name: 'Download es PO', exact: true }).getAttribute('href');
    await fixture('tamper', id);
    expect((await page.request.get(download!)).status()).toBe(409);
    expect((await page.request.get('/es' + route + '/' + id + '/import/es')).status()).toBe(409);
    await fixture('restore', id);
    expect((await page.request.get(download!)).status()).toBe(200);
  });

  test('anonymous and other operators cannot read or import another operator job', async ({ page, browser, baseURL }, info) => {
    await goto(page, '/en' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await reviewEstimate(page);
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'queued');
    const jobUrl = page.url();
    for (const state of [undefined, authPath(variant(info), 'wiki')]) {
      const context = await browser.newContext({ baseURL, storageState: state });
      await networkGuard(context);
      try {
        for (const suffix of ['', '/status', '/es/download', '/es/review', '/import/es']) {
          const response = await context.request.get(jobUrl + suffix);
          expect(response.status(), suffix).toBe(403);
        }
      } finally { await context.close(); }
    }
    const outsider = await browser.newContext({ baseURL, storageState: authPath(variant(info), 'laptop') });
    try { expect((await outsider.request.get('/en' + route)).status()).toBe(403); }
    finally { await outsider.close(); }
  });

  test('a running worker stops before invoking an agent when its operator loses permission', async ({ page }, info) => {
    await goto(page, '/en' + route, info);
    await page.locator('input[name="languages[es]"]').check();
    await page.locator('select[name="agent"]').selectOption('fixture', { force: true });
    await expect(page.locator('select[name="model"] option[value="test-model"]')).toHaveCount(1);
    await reviewEstimate(page);
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
    await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'queued');
    const jobUrl = page.url(); const id = new URL(jobUrl).pathname.split('/').pop()!;
    const result = await fixture('revoke-during-drain', id);
    expect(result.calls).toBe(0);
    expect(result.job_state).toBe('failed');
    expect((await page.request.get(jobUrl + '/status')).status()).toBe(403);
  });

  test('the workflow remains usable without JavaScript', async ({ browser, baseURL }, info) => {
    test.setTimeout(120000);
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false, storageState: authPath(variant(info), 'basic') });
    await networkGuard(context);
    try {
      const page = await context.newPage();
      await goto(page, '/en' + route, info);
      await page.locator('input[name="languages[es]"]').check();
      await page.locator('select[name="agent"]').selectOption('fixture');
      await page.getByRole('button', { name: 'Check pending strings', exact: true }).click();
      await expect(page.getByRole('table', { name: 'Pending strings' })).toContainText('15');
      await page.locator('select[name="model"]').selectOption('test-model');
      await reviewEstimate(page);
    await page.getByRole('button', { name: 'Start translation', exact: true }).click();
      await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'queued');
      await fixture('drain');
      await page.getByRole('link', { name: 'Refresh status', exact: true }).click();
      await expect(page.locator('[data-translation-job]')).toHaveAttribute('data-translation-job', 'review');
      await expect(page.getByRole('link', { name: 'Download es PO', exact: true })).toBeVisible();
    }
    finally { await context.close(); }
  });
});
