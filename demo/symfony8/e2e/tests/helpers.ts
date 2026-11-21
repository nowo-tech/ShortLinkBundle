import { expect, type APIRequestContext, type Page } from '@playwright/test';

/** A desktop browser user agent: the redirect counts its click as a person, not as machine traffic. */
export const BROWSER_UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

/** Hide the Symfony Web Debug Toolbar (dev chrome) so it never appears in a capture. */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
}

/** A code no earlier run used (custom codes stay taken for a year after a delete). */
export function uniqueCode(prefix: string): string {
  return `${prefix}-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e6).toString(36)}`;
}

/** Creates a link through the form and returns its code (the list shows a flash with it). */
export async function createLink(page: Page, target: string, title: string, code?: string): Promise<string> {
  await page.goto('/short-links/new');
  await page.getByLabel('Target URL').fill(target);
  if (code) {
    await page.getByLabel('Custom code').fill(code);
  }
  await page.getByLabel('Title').fill(title);
  await page.getByRole('button', { name: 'New short link' }).click();
  await expect(page.getByTestId('demo-platform-flashes').locator('.nowo-ui-flash').filter({ hasText: 'created' })).toBeVisible();
  const flash = (await page.getByTestId('demo-platform-flashes').locator('.nowo-ui-flash').filter({ hasText: 'created' }).first().textContent()) ?? '';
  const match = flash.match(/Short link (\S+) created\./);
  expect(match).not.toBeNull();
  return match![1];
}

/** Opens /s/{code} like a browser would, without following the 302; returns the response. */
export async function follow(request: APIRequestContext, code: string, referer?: string) {
  return request.get(`/s/${code}`, {
    maxRedirects: 0,
    headers: { 'User-Agent': BROWSER_UA, ...(referer ? { Referer: referer } : {}) },
  });
}
