import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { createLink, follow, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Short Link screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('short-link-list and short-link-stats — links with clicks', async ({ page, request }) => {
    const spring = await createLink(page, 'https://example.com/spring-sale?utm_source=flyer', 'Spring sale flyer');
    await createLink(page, 'https://example.org/docs/getting-started', 'Getting started guide');
    await createLink(page, 'https://example.net/newsletter/2026-11', 'November newsletter');
    for (const referer of ['https://news.example/a', 'https://news.example/b', 'https://social.example/p/1', undefined]) {
      expect((await follow(request, spring, referer)).status()).toBe(302);
    }

    await page.goto('/short-links');
    await expect(page.getByTestId('short-link-list')).toContainText('Spring sale flyer');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'short-link-list.png'), fullPage: true });

    await page.getByTestId(`short-link-stats-${spring}`).first().click();
    await expect(async () => {
      await page.reload();
      await expect(page.getByTestId('short-link-breakdown-referrer')).toContainText('news.example');
    }).toPass({ timeout: 15000 });
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'short-link-stats.png'), fullPage: true });
  });
});
