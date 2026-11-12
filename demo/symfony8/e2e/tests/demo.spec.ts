import { test, expect } from '@playwright/test';
import { createLink, follow, uniqueCode } from './helpers';

/** REQ-DEMO-013 — the main path of the management UI: create → open /s/{code} → statistics show the click → CSV. */
test.describe('Short Link demo', () => {
  test('home page links to the management pages', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('enabled');
    await page.getByTestId('demo-open-ui').click();
    await expect(page.getByTestId('short-link-list')).toBeVisible();
  });

  test('create a link, open it, see the click in the statistics and export the CSV', async ({ page, request }) => {
    const code = uniqueCode('e2e');
    expect(await createLink(page, 'https://example.com/e2e-landing?ref=demo', 'E2E landing page', code)).toBe(code);
    await expect(page.getByTestId(`short-link-state-${code}`)).toHaveText('Active');

    const redirect = await follow(request, code, 'https://news.example/post/1');
    expect(redirect.status()).toBe(302);
    expect(redirect.headers()['location']).toBe('https://example.com/e2e-landing?ref=demo');
    expect(redirect.headers()['referrer-policy']).toBe('no-referrer');

    await page.getByTestId(`short-link-stats-${code}`).first().click();
    await expect(page.getByTestId('short-link-stats')).toBeVisible();
    // The click is written after the response (kernel.terminate): reload until it shows.
    await expect(async () => {
      await page.reload();
      await expect(page.getByTestId('short-link-clicks')).toContainText('1');
    }).toPass({ timeout: 15000 });
    await expect(page.getByTestId('short-link-breakdown-referrer')).toContainText('news.example');
    await expect(page.getByTestId('short-link-daily')).toBeVisible();

    const csvHref = await page.getByTestId('short-link-csv').getAttribute('href');
    expect(csvHref).not.toBeNull();
    const csv = await page.request.get(csvHref!);
    expect(csv.status()).toBe(200);
    expect(csv.headers()['content-type']).toContain('text/csv');
    const body = await csv.text();
    expect(body).toContain('day,clicks,machine_clicks');
    expect(body).toMatch(/\n\d{4}-\d{2}-\d{2},1,0\n/);
    expect(body).toContain('news.example,1,0');
  });

  test('a disabled link answers the neutral 404 and a deleted one stays gone', async ({ page, request }) => {
    const code = await createLink(page, 'https://example.com/e2e-toggle', 'E2E toggle');
    const row = page.locator('tr', { has: page.getByTestId(`short-link-state-${code}`) });

    await row.getByRole('button', { name: 'Disable' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Short link disabled.' })).toBeVisible();
    await expect(page.getByTestId(`short-link-state-${code}`)).toHaveText('Disabled');
    expect((await follow(request, code)).status()).toBe(404);

    await page.locator('tr', { has: page.getByTestId(`short-link-state-${code}`) }).getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Short link deleted.' })).toBeVisible();
    await expect(page.getByTestId(`short-link-state-${code}`)).toHaveCount(0);
    expect((await follow(request, code)).status()).toBe(404);
  });

  test('a taken custom code and a private target are refused with field errors', async ({ page }) => {
    const code = uniqueCode('e2e-taken');
    await createLink(page, 'https://example.com/e2e-first', 'E2E first', code);
    await page.goto('/short-links/new');
    await page.getByLabel('Target URL').fill('https://example.com/e2e-second');
    await page.getByLabel('Custom code').fill(code);
    await page.getByRole('button', { name: 'New short link' }).click();
    await expect(page.getByText('This code is not available.')).toBeVisible();
    await page.getByLabel('Target URL').fill('http://192.168.1.1/admin');
    await page.getByLabel('Custom code').fill('');
    await page.getByRole('button', { name: 'New short link' }).click();
    await expect(page.getByText('The target must not point to a private or local address.')).toBeVisible();
  });
});
