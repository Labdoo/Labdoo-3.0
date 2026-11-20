import { test, expect, goto, fixtures } from './helpers';

for (const target of ['Laptop Main', 'School', 'Hub Alpha', 'dootronics-dashboard', 'edoovillages-dashboard', 'dootrips-dashboard', 'hubs-dashboard']) {
  test(`desktop main and sidebar panels share a top edge (${target})`, async ({ page }, info) => {
    const path = target.endsWith('-dashboard') ? `/en/content/${target}` : `/en/node/${fixtures(info).nodes[target].id}`;
    await goto(page, path, info);
    const layout = page.locator('.ldn-layout--sidebar');
    await expect(layout).toBeVisible();
    const geometry = await layout.evaluate(element => {
      const main = element.querySelector('.ldn-main > .region-content')!;
      const panels = [...element.querySelectorAll('.ldn-sidebar .block, .ldn-sidebar .views-exposed-form')]
        .filter(panel => panel.getClientRects().length && parseFloat(getComputedStyle(panel).borderTopWidth) > 0);
      return { layout: element.getBoundingClientRect().y, main: main.getBoundingClientRect().y,
        side: Math.min(...panels.map(panel => panel.getBoundingClientRect().y)), panelCount: panels.length };
    });
    expect(geometry.panelCount).toBeGreaterThan(0);
    expect(Math.abs(geometry.main - geometry.side)).toBeLessThanOrEqual(1);
    expect(Math.abs(geometry.main - geometry.layout)).toBeLessThanOrEqual(1);
    const tabs = layout.locator('.horizontal-tabs').first();
    if (await tabs.isVisible()) expect(Math.abs((await tabs.boundingBox())!.y - geometry.side)).toBeLessThanOrEqual(1);
    await layout.screenshot({ path: info.outputPath('aligned-panels.png') });
  });
}

test('chart and map restoration controls reserve space only while visible @responsive', async ({ page }, info) => {
  await goto(page, '/en/content/dootronics-dashboard', info);
  const controls = page.locator('.ldn-main > .restore-controls');
  const region = page.locator('.ldn-main > .region-content');
  const offset = () => region.evaluate(element => element.getBoundingClientRect().y - element.parentElement!.getBoundingClientRect().y);
  await expect.poll(offset).toBe(0);
  for (const target of ['chart', 'map']) {
    await page.locator(`.ldn-main .hide-${target}`).click();
    const restore = controls.locator(`.restore-${target}`);
    await expect(restore).toBeVisible();
    expect((await controls.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await restore.click();
    await expect(restore).not.toBeVisible();
    await expect.poll(offset).toBe(0);
  }
});

for (const role of ['anonymous', 'superadmin'] as const) {
  test.describe(`device action typography as ${role}`, () => {
    test.use({ role });
    test('pickup status matches adjacent actions without changing access @responsive', async ({ page }, info) => {
      await goto(page, `/en/node/${fixtures(info).nodes['Laptop Main'].id}`, info);
      const panel = page.locator('#block-dootronics-actions');
      const pickup = panel.locator('.action').filter({ has: page.locator('img[src$="/truck-off.png"], img[src$="/truck.png"]') });
      if (!await pickup.isVisible()) await panel.locator('.block-actions-toggle').click();
      await expect(pickup).toBeVisible();
      await expect(pickup.locator('a')).toHaveCount(role === 'anonymous' ? 0 : 1);
      const typography = await panel.locator('.actions > .action').evaluateAll(rows => rows.map(row => {
        const style = getComputedStyle(row.querySelector('a') || row);
        return { size: style.fontSize, family: style.fontFamily, lineHeight: style.lineHeight };
      }));
      expect(typography.length).toBeGreaterThan(1);
      for (const style of typography) expect(style).toEqual(typography[0]);
      const alignment = await panel.locator('.actions > .action .icon').evaluateAll(icons => icons.map(icon => icon.getBoundingClientRect().x));
      expect(Math.max(...alignment) - Math.min(...alignment)).toBeLessThanOrEqual(1);
      await panel.screenshot({ path: info.outputPath('actions.png') });
    });
  });
}

for (const language of ['en', 'es']) {
  test(`tabbed device content keeps one panel and its inner shadow (${language}) @responsive`, async ({ page }, info) => {
    await goto(page, `/${language}/node/${fixtures(info).nodes['Laptop Main'].id}`, info);
    const tabs = page.locator('article.node--type-dootronic .horizontal-tabs');
    await expect(tabs).toBeVisible();
    const chrome = await tabs.evaluate(element => {
      const outer = getComputedStyle(element.closest('article')!);
      const inner = getComputedStyle(element);
      const panes = getComputedStyle(element.querySelector('[data-horizontal-tabs-panes]')!);
      return { outerBorder: outer.borderTopWidth, outerPadding: outer.paddingTop, innerBorder: inner.borderTopWidth, innerShadow: panes.boxShadow };
    });
    expect(chrome.outerBorder).toBe('0px');
    expect(chrome.outerPadding).toBe('0px');
    expect(chrome.innerBorder).toBe('1px');
    expect(chrome.innerShadow).not.toBe('none');
    const details = tabs.locator('[data-horizontal-tabs-panes] > details');
    expect(await details.count()).toBeGreaterThanOrEqual(2);
    const buttons = tabs.locator('.horizontal-tab-button a');
    if (await buttons.first().isVisible()) {
      await buttons.nth(1).click();
      await expect(details.nth(1)).toBeVisible();
      await expect(details.first()).not.toBeVisible();
      await buttons.first().click();
      await expect(details.first()).toBeVisible();
    } else {
      await details.nth(1).locator(':scope > summary').click();
      await expect(details.nth(1)).toHaveAttribute('open', '');
    }
    expect(await tabs.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
    await tabs.screenshot({ path: info.outputPath(`tabs-${language}.png`) });
  });
}
