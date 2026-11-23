import { test, expect, goto, stateDir } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

const charts = {
  evolutionChart: 'evolution_data',
  edoovillageEvolutionChart: 'edoovillage_evolution',
  hubEvolutionChart: 'hub_evolution',
  dootripEvolutionChart: 'dootrip_evolution',
  teamPostEvolutionChart: 'team_post_evolution',
  commentEvolutionChart: 'comment_evolution_data',
  wikiActivityChart: 'wiki_activity',
  nodeEvolutionChart: 'node_evolution_data',
  dootripKmEvolutionChart: 'dootrip_km_data',
};

test('historical totals include older records, boundaries, inactive months and empty history', async () => {
  const { stdout } = await promisify(execFile)('python3', [
    path.join(stateDir, '../tools/drush.py'), 'integration', 'php:script',
    path.join(__dirname, 'support/cumulative-statistics.php'),
  ], { timeout: 45000 });
  expect(JSON.parse(stdout.split('CUMULATIVE_JSON:')[1].trim()).passed).toBe(9);
});

for (const language of ['en', 'es']) {
  test(`cumulative charts render historical totals in ${language} @responsive`, async ({ page }, info) => {
    expect((await goto(page, `/${language}/statistics/advanced`, info)).status()).toBe(200);
    for (const [id, key] of Object.entries(charts)) {
      const canvas = page.locator(`#${id}`);
      await canvas.scrollIntoViewIfNeeded();
      await expect(canvas).toBeVisible();
      await expect(canvas.locator('xpath=ancestor::div[contains(@class,"chart-container")]/h3')).toContainText(language === 'es' ? 'Acumulado' : 'Cumulative');
      const result = await page.evaluate(({ id, key }) => {
        const browser = window as any;
        const chart = browser.Chart.getChart(id);
        let data = browser.drupalSettings.labdoo_statistics[key];
        if (key === 'dootrip_km_data') data = data.evolution;
        return { values: data.values, rendered: Array.from(chart.data.datasets[0].data) };
      }, { id, key });
      expect(result.rendered).toEqual(result.values);
      expect(result.values).toHaveLength(key === 'dootrip_km_data' ? 5 : 12);
      for (let i = 1; i < result.values.length; i++) {
        expect(result.values[i]).toBeGreaterThanOrEqual(result.values[i - 1]);
      }
    }
  });
}
