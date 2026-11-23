import { test, expect, goto } from './helpers';

for (const dashboard of ['edoovillages', 'dootronics', 'dootrips', 'hubs']) {
  for (const width of [320, 360, 390, 768, 1440]) {
    test(`${dashboard} dashboard bars remain visible at ${width}px @responsive`, async ({ page }, info) => {
      await page.setViewportSize({ width, height: 844 });
      await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
      const errors: string[] = [];
      page.on('pageerror', error => errors.push(error.message));
      await goto(page, `/en/content/${dashboard}-dashboard`, info);
      // The placeholder is replaced by a canvas as soon as it enters the viewport.
      await page.evaluate(() => document.querySelector('.charts-chartjs')?.scrollIntoView());
      await expect.poll(() => page.evaluate(() => {
        const canvas = document.querySelector('canvas.charts-chartjs');
        const chart = canvas && (window as any).Chart?.getChart(canvas);
        if (!chart) return false;
        return chart.chartArea.height >= 100 && chart.data.datasets.some((dataset: any, index: number) =>
          dataset.data.some((value: number, point: number) => value > 0 && chart.getDatasetMeta(index).data[point].height > 10));
      })).toBe(true);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      // Resizing an existing chart must retain its plot and fit its new container.
      await page.setViewportSize({ width: width < 600 ? 844 : 360, height: 844 });
      await expect.poll(() => page.evaluate(() => {
        const canvas = document.querySelector('canvas.charts-chartjs') as HTMLCanvasElement;
        const chart = (window as any).Chart.getChart(canvas);
        return chart.chartArea.height >= 100 && canvas.getBoundingClientRect().right <= innerWidth;
      })).toBe(true);
      expect(errors).toEqual([]);
    });
  }
}
