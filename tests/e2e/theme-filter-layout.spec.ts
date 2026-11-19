import { test, expect, goto } from './helpers';

const dashboards = [
  ['schools', '/es/content/edoovillages-dashboard'],
  ['devices', '/es/content/dootronics-dashboard'],
  ['trips', '/es/content/dootrips-dashboard'],
  ['hubs', '/es/content/hubs-dashboard'],
  ['actions', '/es/actions'],
] as const;

for (const [name, url] of dashboards) {
  test(`sidebar ${name} filters keep labels and controls together @responsive`, async ({ page }, info) => {
    await page.route('**/api/map-points/**', route => route.fulfill({ json: [] }));
    await goto(page, url, info);
    const form = page.locator('.ldn-sidebar .views-exposed-form').first();
    await form.scrollIntoViewIfNeeded();
    await expect(form).toBeVisible();
    const fields = form.locator('div.form-item').filter({ has: page.locator('input[type="text"], select') });
    expect(await fields.count()).toBeGreaterThan(0);
    for (const field of await fields.all()) {
      const geometry = await field.evaluate(element => {
        const box = element.getBoundingClientRect();
        const control = element.querySelector('input, select')!.getBoundingClientRect();
        const formBox = element.closest('form')!.getBoundingClientRect();
        return {
          height: box.height,
          trailingSpace: box.bottom - control.bottom,
          fits: control.left >= formBox.left && control.right <= formBox.right,
        };
      });
      expect(geometry.height, 'A single labelled control must not reserve a tall empty row').toBeLessThan(120);
      expect(geometry.trailingSpace, 'The next field must follow the control without a large blank area').toBeLessThan(24);
      expect(geometry.fits, 'Controls must fit inside the filter panel').toBe(true);
    }
    const choices = await form.locator('.form-type--boolean').evaluateAll(elements => elements.map(element => {
      const control = element.querySelector('input')!.getBoundingClientRect();
      const label = element.querySelector('label')!.getBoundingClientRect();
      const formBox = element.closest('form')!.getBoundingClientRect();
      return {
        top: control.top,
        bottom: Math.max(control.bottom, label.bottom),
        fits: control.left >= formBox.left && label.right <= formBox.right,
        separated: control.right <= label.left,
      };
    }));
    for (let index = 0; index < choices.length; index++) {
      expect(choices[index].fits, 'Checkboxes and their labels must stay inside the panel').toBe(true);
      expect(choices[index].separated, 'Checkboxes must not overlap their labels').toBe(true);
      if (index > 0) expect(choices[index].top, 'Checkbox choices must stack vertically').toBeGreaterThanOrEqual(choices[index - 1].bottom);
    }
    const submit = form.locator('[type="submit"]').first();
    await submit.scrollIntoViewIfNeeded();
    await expect(submit).toBeVisible();
    await submit.focus();
    await expect(submit).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await form.screenshot({ path: info.outputPath(`${name}-filters.png`) });
  });
}
