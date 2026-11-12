import { expect, type APIRequestContext, type Page } from '@playwright/test';

/** A desktop browser user agent: the redirect counts its click as a person, not as machine traffic. */
export const BROWSER_UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        return !bar || bar.offsetParent === null || !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
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
  await expect(page.getByRole('alert').filter({ hasText: 'created' })).toBeVisible();
  const flash = (await page.getByRole('alert').filter({ hasText: 'created' }).first().textContent()) ?? '';
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
