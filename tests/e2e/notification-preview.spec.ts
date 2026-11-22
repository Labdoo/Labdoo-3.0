import { test, expect, goto } from './helpers';

for (const [language, acknowledgement] of [
  ['en', 'We received your message'],
  ['es', 'Recibimos tu mensaje'],
]) {
  test(`contact email preview resolves ${language} templates and local language links @responsive`, async ({ page }, info) => {
    const parameters = new URLSearchParams({
      type: 'contact', language,
      NAME: 'Quality notification', EMAIL: 'notification@example.invalid',
      SUBJECT: 'Quality subject', MESSAGE: 'Quality body',
    });
    await goto(page, `/${language}/content/notification-email?${parameters}`, info);
    const main = page.locator('main');
    await expect(main).toContainText(acknowledgement);
    await expect(main).toContainText('Quality body');
    for (const token of ['[LANGUAGE_MENU]', '[MESSAGE]', '[CONTACT_EMAIL]']) {
      await expect(main).not.toContainText(token);
    }
    const links = main.locator('a[href*="notification-email"][href*="language="]');
    await expect(links).toHaveCount(7);
    const expectedLanguages = ['ca', 'zh-hant', 'de', 'en', 'fr', 'nl', 'es'];
    const seen = [];
    for (const href of await links.evaluateAll(elements => elements.map(element => (element as HTMLAnchorElement).href))) {
      const link = new URL(href);
      expect(link.origin).toBe(new URL(page.url()).origin);
      expect(link.searchParams.get('MESSAGE')).toBe('Quality body');
      seen.push(link.searchParams.get('language'));
    }
    expect(seen).toEqual(expectedLanguages);
  });
}
