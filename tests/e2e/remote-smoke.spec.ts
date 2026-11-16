import { test, expect, goto } from './helpers';

test('remote environment serves its English home page', async ({ page }, info) => {
  const response = await goto(page, '/en', info);
  expect(response.status()).toBe(200);
  await expect(page.locator('body')).toBeVisible();
});

test('remote environment serves its Spanish home page', async ({ page }, info) => {
  const response = await goto(page, '/es', info);
  expect(response.status()).toBe(200);
  await expect(page.locator('body')).toBeVisible();
});

test('remote environment exposes the login form without submitting it', async ({ page }, info) => {
  const response = await goto(page, '/en/user/login', info);
  expect(response.status()).toBe(200);
  await expect(page.locator('input[name="name"]')).toBeVisible();
  await expect(page.locator('input[name="pass"]')).toBeVisible();
});
