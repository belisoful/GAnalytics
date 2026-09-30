// @ts-check
import { expect, test } from '@playwright/test';
import { gtagCalls, stubGoogle } from './helpers.js';

/*
 * Basic consent mode: no Google tag and no gtag call before consent; a grant by callback loads
 * the tag into the page through the loader function (no eval, under a nonce CSP), and a grant by
 * postback renders the page with the tag.
 */

const HOME = '/app-basic/index.php?page=Home';

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('no tag, no data layer and no Google request before consent', async ({ page }) => {
	const google = [];
	page.on('request', (request) => {
		if (/google/.test(request.url())) {
			google.push(request.url());
		}
	});
	await page.goto(HOME);
	expect(await page.evaluate(() => typeof window['gtag'])).toBe('undefined');
	expect(await page.evaluate(() => typeof window['pradoGAnalyticsLoadTag'])).toBe('function');
	await page.getByRole('button', { name: 'Track' }).click();
	await expect(page.getByText('tracked')).toBeVisible();
	expect(await page.evaluate(() => window['dataLayer'])).toBeUndefined();
	expect(google).toEqual([]);
});

test('a grant by callback loads the tag in place, then sends the update', async ({ page }) => {
	const errors = [];
	page.on('pageerror', (error) => errors.push(error.message));
	page.on('console', (message) => {
		if (message.type() === 'error') {
			errors.push(message.text());
		}
	});
	await page.goto(HOME);
	await page.getByRole('button', { name: 'Grant by callback' }).click();
	await expect(page.getByText('consented')).toBeVisible();
	await expect.poll(() => page.evaluate(() => window['__googleScripts'])).toEqual(['https://www.googletagmanager.com/gtag/js?id=G-E2EBASIC12']);
	const calls = await gtagCalls(page);
	expect(calls[0]).toEqual(['consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied' }]);
	expect(calls[1][0]).toBe('js');
	expect(calls[2]).toEqual(['config', 'G-E2EBASIC12']);
	expect(calls[3]).toEqual(['consent', 'update', { analytics_storage: 'granted' }]);
	expect(errors).toEqual([]);

	await page.getByRole('button', { name: 'Track' }).click();
	await expect.poll(async () => (await gtagCalls(page)).length).toBe(5);
	expect((await gtagCalls(page))[4]).toEqual(['event', 'early_click']);

	await page.goto(HOME);
	expect(await page.evaluate(() => window['__googleScripts'])).toHaveLength(1);
	expect((await gtagCalls(page))[0]).toEqual(['consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied' }]);
});

test('a grant by postback renders the page with the tag', async ({ page }) => {
	await page.goto(HOME);
	await page.getByRole('button', { name: 'Grant by postback' }).click();
	await page.waitForLoadState();
	await expect.poll(() => page.evaluate(() => window['__googleScripts'])).toHaveLength(1);
	const calls = await gtagCalls(page);
	expect(calls[0]).toEqual(['consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied' }]);
	expect(calls.at(-1)).toEqual(['consent', 'update', { analytics_storage: 'granted' }]);
});
