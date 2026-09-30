// @ts-check
import { expect, test } from '@playwright/test';
import { gtagCalls, stubGoogle } from './helpers.js';

/*
 * Events from page code: an ActiveControl callback delivers a queued gtag() call through the
 * callback client, and a consent update both runs on the page and is stored in the consent
 * cookie so the next page's defaults carry it.
 */

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('a callback delivers the tracked event without reloading the tag', async ({ page }) => {
	await page.goto('/app/index.php?page=Home');
	expect(await gtagCalls(page)).toHaveLength(3);

	await page.getByRole('button', { name: 'Track' }).click();
	await expect(page.getByText('tracked')).toBeVisible();

	const calls = await gtagCalls(page);
	expect(calls).toHaveLength(4);
	expect(calls[3]).toEqual(['event', 'add_to_cart', { value: 9.99, currency: 'USD' }]);
	expect(calls.filter((call) => call[0] === 'config')).toHaveLength(1);
	expect(await page.evaluate(() => window['__googleScripts'])).toHaveLength(1);
});

test('a consent update runs on the page and persists for the next request', async ({ page, context }) => {
	await page.goto('/app/index.php?page=Home');
	await page.getByRole('button', { name: 'Grant consent' }).click();
	await expect(page.getByText('consented')).toBeVisible();

	const calls = await gtagCalls(page);
	expect(calls[3]).toEqual(['consent', 'update', { analytics_storage: 'granted' }]);

	const cookie = (await context.cookies()).find((c) => c.name === 'e2e_consent');
	expect(cookie).toBeTruthy();
	expect(JSON.parse(decodeURIComponent(cookie?.value ?? ''))).toEqual({ analytics_storage: 'granted' });
	expect(cookie?.httpOnly).toBe(true);
	expect(cookie?.sameSite).toBe('Lax');

	await page.goto('/app/index.php?page=Home');
	const next = await gtagCalls(page);
	expect(next[0]).toEqual(['consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied' }]);
});
