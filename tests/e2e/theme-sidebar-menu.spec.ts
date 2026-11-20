import { test, expect, goto, fixtures, networkGuard } from './helpers';

for (const language of ['en', 'es']) {
  test(`sidebar filters have a single panel (${language}) @responsive`, async ({ page }, info) => {
    await goto(page, `/${language}/content/dootronics-dashboard`, info);
    const rejectCookies = page.getByRole('button', { name: 'No, gracias', exact: true });
    if (await rejectCookies.isVisible()) await rejectCookies.click();
    const form = page.locator('.ldn-sidebar .views-exposed-form').first();
    await expect(form).toBeVisible();
    const chrome = await form.evaluate(el => {
      const outer = el.closest('.block')!;
      const css = getComputedStyle(outer);
      return { border: css.borderTopWidth, padding: css.paddingTop };
    });
    expect(chrome).toEqual({ border: '0px', padding: '0px' });
    await expect(form.locator('input[type="text"]').first()).toBeVisible();
    await form.screenshot({ path: info.outputPath(`filters-${language}.png`) });
  });
}

test.describe('cached team actions', () => {
  test.use({ role: 'basic' });
  test('unrelated nodes omit team actions without hiding actual team actions @responsive', async ({ page }, info) => {
    const nodes = fixtures(info).nodes;
    for (const key of ['Laptop Main', 'Team', 'Team Post', 'Laptop Main', 'Team']) {
      await goto(page, `/en/node/${nodes[key].id}`, info);
      const actions = page.locator('#block-team-actions');
      if (key === 'Laptop Main') await expect(actions).toHaveCount(0);
      else {
        await expect(actions).toBeVisible();
        await expect(actions.locator('a').first()).toBeVisible();
      }
    }
  });
});

test('Wiki tree fits its sidebar and wraps long page titles @responsive', async ({ page }, info) => {
  await goto(page, '/en/content/labdoo-social-network-how-it-works', info);
  const tree = page.locator('.mini-wiki-navigation');
  await expect(tree).toBeVisible();
  // Stress the same translated-title layout without mutating content.
  await tree.locator('a').first().evaluate(el => { el.textContent = 'A very long parent page title '.repeat(6) + 'LongUnbrokenTitle'.repeat(6); });
  const geometry = await tree.evaluate(el => {
    const side = el.closest('.ldn-sidebar')!.getBoundingClientRect();
    return [...el.querySelectorAll('a'), el].every(child => {
      const r = child.getBoundingClientRect();
      return r.left >= side.left - 1 && r.right <= side.right + 1 && child.scrollWidth <= child.clientWidth + 1;
    });
  });
  expect(geometry).toBe(true);
  await tree.screenshot({ path: info.outputPath('wiki-tree.png') });
});

test('device action panel has no nested cards or empty team panel @responsive', async ({ page }, info) => {
  await goto(page, `/en/node/${fixtures(info).nodes['Laptop Main'].id}`, info);
  const actions = page.locator('#block-dootronics-actions');
  await expect(actions).toBeVisible();
  const ancestors = await actions.evaluate(el => {
    const result: string[] = [];
    for (let p = el.parentElement; p && !p.classList.contains('ldn-sidebar'); p = p.parentElement) {
      if (p.classList.contains('block')) result.push(getComputedStyle(p).borderTopWidth);
    }
    return result;
  });
  expect(ancestors.every(width => width === '0px')).toBe(true);
  await expect(page.locator('.ldn-sidebar .block').filter({ hasText: /^\s*$/ })).toHaveCount(0);
  await actions.screenshot({ path: info.outputPath('device-actions.png') });
});

for (const javaScriptEnabled of [true, false]) {
  test(`opening a menu closes its sibling (JavaScript ${javaScriptEnabled}) @responsive`, async ({ browser, baseURL }, info) => {
    const context = await browser.newContext({ baseURL, javaScriptEnabled, viewport: info.project.use.viewport });
    await networkGuard(context);
    const page = await context.newPage();
    await goto(page, '/en', info);
    const toggle = page.locator('.ldn-menu-toggle');
    if (javaScriptEnabled && await toggle.isVisible()) await toggle.click();
    const top = page.locator('.ldn-navigation .ldn-menu:not(.ldn-menu--nested) > li > details');
    expect(await top.count()).toBeGreaterThan(1);
    await top.nth(0).locator(':scope > summary').click();
    await expect(top.nth(0)).toHaveAttribute('open', '');
    await top.nth(1).locator(':scope > summary').focus();
    await page.keyboard.press('Enter');
    await expect(top.nth(1)).toHaveAttribute('open', '');
    await expect(top.nth(0)).not.toHaveAttribute('open', '');
    expect(await top.evaluateAll(elements => elements.filter(el => el.hasAttribute('open')).length)).toBe(1);
    await context.close();
  });
}
