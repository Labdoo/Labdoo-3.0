import { test, expect, fixtures, goto, covers } from './helpers';

test('wiki paragraphs are dark gray and headings black @responsive', covers('wiki-text-color'), async ({ page }, info) => {
  await goto(page, fixtures(info).wiki.Book.path, info);
  const body = page.locator('.field--name-body').first();
  await expect(body.locator('p').first()).toHaveCSS('color', 'rgb(68, 68, 68)');
  await expect(body.locator('h2').first()).toHaveCSS('color', 'rgb(0, 0, 0)');
});

test('wiki line length is bounded and stays inside the viewport @responsive', covers('wiki-reading-width'), async ({ page }, info) => {
  await goto(page, fixtures(info).wiki.Book.path, info);
  const body = page.locator('.field--name-body').first();
  const measurements = await body.evaluate(el => ({ width: el.getBoundingClientRect().width, right: el.getBoundingClientRect().right, max: getComputedStyle(el).maxWidth, viewport: innerWidth }));
  expect(measurements.max).not.toBe('none');
  expect(measurements.width).toBeLessThanOrEqual(800);
  expect(measurements.right).toBeLessThanOrEqual(measurements.viewport + 1);
});

test('wiki tag label and values share a line @responsive', covers('wiki-inline-tags'), async ({ page }, info) => {
  await goto(page, fixtures(info).wiki.Book.path, info);
  const field = page.locator('.field--name-field-tags');
  await expect(field).toHaveClass(/field--label-inline/);
  await expect(field).toContainText('E2E Tag One');
  await expect(field).toContainText('E2E Tag Two');
  const label = await field.locator('.field__label').boundingBox();
  const value = await field.locator('.field__item').first().boundingBox();
  expect(label && value).toBeTruthy();
  expect(Math.abs(label!.y - value!.y)).toBeLessThan(8);
});

test('wiki activity block is absent @responsive', covers('wiki-hide-activity'), async ({ page }, info) => {
  await goto(page, fixtures(info).wiki.Book.path, info);
  await expect(page.locator('.block-views-blockactions-block-1')).toHaveCount(0);
});

test('contact activity block is absent @responsive', covers('contact-hide-activity'), async ({ page }, info) => {
  await goto(page, '/en/contact', info);
  await expect(page.locator('.block-views-blockactions-block-1')).toHaveCount(0);
});

test('anonymous wiki reader has no Add subpage action', covers('wiki-add-subpage-access'), async ({ page }, info) => {
  await goto(page, fixtures(info).wiki.Book.path, info);
  await expect(page.getByRole('link', { name: 'Add subpage', exact: true })).toHaveCount(0);
});

test('wiki previous and next links stay within their book', covers('wiki-sequential-navigation'), async ({ page }, info) => {
  const wiki = fixtures(info).wiki;
  await goto(page, wiki.Book.path, info);
  await page.getByRole('link', { name: `Next page: ${wiki.Alpha.title}`, exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(wiki.Alpha.title);
  await page.getByRole('link', { name: `Next page: ${wiki.Zulu.title}`, exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(wiki.Zulu.title);
  await expect(page.getByRole('link', { name: /^Next page:/ })).toHaveCount(0);
  await page.getByRole('link', { name: `Previous page: ${wiki.Alpha.title}`, exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(wiki.Alpha.title);
});

test('Edoovillages dashboard uses the expected capitalization', covers('edoovillages-naming'), async ({ page }, info) => {
  await goto(page, '/en/content/edoovillages-dashboard', info);
  await expect(page.getByRole('heading', { level: 1 }).first()).toHaveText('Edoovillages Dashboard');
});

test('school semaphore is reduced to at most 120px @responsive', covers('dootrip-semaphore-size'), async ({ page }, info) => {
  await goto(page, fixtures(info).nodes.School.path, info);
  const image = page.locator('img.semaphore').first();
  await expect(image).toBeVisible();
  expect((await image.boundingBox())!.width).toBeLessThanOrEqual(120);
});

test('hub action describes the hub story and preserves the school wording', covers('hub-story-label'), async ({ page }, info) => {
  const nodes = fixtures(info).nodes;
  await goto(page, nodes['Hub Alpha'].path, info);
  const link = page.getByRole('link', { name: 'Read the story about this hub', exact: true });
  await expect(link).toBeVisible();
  await link.click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(nodes['Hub Story'].title);
  await goto(page, nodes.School.path, info);
  await expect(page.getByRole('link', { name: 'Read the story about this edoovillage', exact: true })).toBeVisible();
});

test('gallery thumbnails paginate as 25, 25 and 3 pictures @responsive', covers('gallery-thumbnail-grid'), async ({ page }, info) => {
  const gallery = fixtures(info).nodes.Gallery;
  await goto(page, gallery.path, info);
  const grid = page.locator('.labdoo-gallery-grid');
  await expect(grid.locator('img')).toHaveCount(25);
  await expect(grid.getByAltText('E2E Photo 01', { exact: true })).toBeVisible();
  expect(await grid.locator('a').first().getAttribute('href')).toContain('/sites/default/files/');
  await page.locator('.field--name-field-images .pager__item--next a').first().click();
  await expect(grid.locator('img')).toHaveCount(25);
  await expect(grid.getByAltText('E2E Photo 26', { exact: true })).toBeVisible();
  await page.locator('.field--name-field-images .pager__item--next a').first().click();
  await expect(grid.locator('img')).toHaveCount(3);
  await expect(grid.getByAltText('E2E Photo 53', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
});

test('gallery links back to its school', covers('gallery-parent-navigation'), async ({ page }, info) => {
  const nodes = fixtures(info).nodes;
  await goto(page, nodes.Gallery.path, info);
  await page.locator('.gallery-parent a').click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(nodes.School.title);
});

test('stories list shows hub and school stories with header and help link', covers('stories-list'), async ({ page }, info) => {
  const nodes = fixtures(info).nodes;
  await goto(page, '/en/labdoo-stories', info);
  await expect(page.getByRole('link', { name: nodes['Hub Story'].title, exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: nodes['School Story'].title, exact: true })).toBeVisible();
  await expect(page.locator('img[src*="labdoo-stories-header"]')).toBeVisible();
  await expect(page.getByRole('link', { name: /Click here for help on Labdoo Stories/ })).toHaveAttribute('href', '/content/labdoo-stories');
});

test.describe('administrator views and forms', () => {
  test.use({ role: 'admin' });
  test('global statistics appear above the page title @responsive', covers('statistics-position'), async ({ page }, info) => {
    await goto(page, fixtures(info).nodes['Hub Alpha'].path, info);
    const stats = page.locator('#block-labdoo-statistics');
    await expect(stats).toHaveCount(1);
    const box = await stats.boundingBox();
    const title = await page.getByRole('heading', { level: 1 }).first().boundingBox();
    expect(box && title).toBeTruthy();
    expect(box!.y).toBeLessThan(title!.y);
  });
  test('object labels use a compact inline layout @responsive', covers('dootronic-inline-fields', 'object-inline-fields'), async ({ page }, info) => {
    const nodes = fixtures(info).nodes;
    for (const key of ['Laptop Main', 'Trip', 'Hub Alpha', 'School']) {
      await goto(page, nodes[key].path, info);
      expect(await page.locator('.field--label-inline').count()).toBeGreaterThan(0);
    }
    await goto(page, nodes['Laptop Main'].path, info);
    const model = page.locator('.field--name-field-model');
    const label = await model.locator('.field__label').boundingBox();
    const value = await model.locator('.field__item').first().boundingBox();
    expect(label && value).toBeTruthy();
    expect(Math.abs(label!.y - value!.y)).toBeLessThan(8);
  });
  test('school album reference stays hidden on create, edit and view', covers('edoovillage-hide-album', 'edoovillage-hide-album-edit'), async ({ page }, info) => {
    const school = fixtures(info).nodes.School;
    for (const url of ['/en/node/add/edoovillage', `/en/node/${school.id}/edit`, school.path]) {
      await goto(page, url, info);
      await expect(page.locator('[name^="field_edoovillage_photo_album"]')).toHaveCount(0);
      await expect(page.locator('.field--name-field-edoovillage-photo-album')).toHaveCount(0);
    }
  });
  test('school details and form have no tabs @responsive', covers('edoovillage-single-page'), async ({ page }, info) => {
    const school = fixtures(info).nodes.School;
    await goto(page, school.path, info);
    await expect(page.locator('.horizontal-tabs, .vertical-tabs, .field-group-tabs-wrapper')).toHaveCount(0);
    await expect(page.locator('.field--name-field-location')).toBeVisible();
    await expect(page.locator('main')).not.toContainText('e2e-private@example.invalid');
    await goto(page, `/en/node/${school.id}/edit`, info);
    await expect(page.locator('.horizontal-tabs, .field-group-tabs-wrapper')).toHaveCount(0);
  });
  test('gallery edit form omits Preview', covers('gallery-hide-preview'), async ({ page }, info) => {
    await goto(page, `/en/node/${fixtures(info).nodes.Gallery.id}/edit`, info);
    await expect(page.getByRole('button', { name: /Preview/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save', exact: true }).first()).toBeVisible();
  });
  test('team post title matches the V2 red @responsive', covers('team-title-color'), async ({ page }, info) => {
    await goto(page, fixtures(info).nodes['Team Post'].path, info);
    await expect(page.getByRole('heading', { level: 1 }).first()).toHaveCSS('color', 'rgb(255, 0, 0)');
  });
  test('team About action uses the canonical friendly URL', covers('team-nice-urls'), async ({ page }, info) => {
    const team = fixtures(info).nodes.Team;
    await goto(page, `/en/team-wall/${team.id}`, info);
    const link = page.getByRole('link', { name: 'About this team', exact: true });
    await expect(link).toBeVisible();
    const href = (await link.getAttribute('href'))!;
    expect(href).not.toContain('/team/about/');
    expect(href).toContain('/content/');
    await link.click();
    await expect(page.getByRole('heading', { level: 1 }).first()).toContainText(team.title);
  });
});
