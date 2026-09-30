// @ts-check
import { expect, test } from '@playwright/test';
import { dataLayer, gtagCalls, stubGoogle } from './helpers.js';

/*
 * The gtag.js application (tests/playwright/app): the tag on a page with a THead, the
 * consent defaults, the content group, the CSP header and nonce, and a page without a THead.
 */

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('the head carries the async gtag.js script and the configuration snippet', async ({ page }) => {
	const response = await page.goto('/app/index.php?page=Home');
	expect(response?.ok()).toBeTruthy();

	const file = page.locator('head script[src*="googletagmanager.com/gtag/js"]');
	await expect(file).toHaveCount(1);
	await expect(file).toHaveAttribute('src', 'https://www.googletagmanager.com/gtag/js?id=G-E2ETEST123');
	await expect(file).toHaveAttribute('async', '');
	expect(await page.evaluate(() => window['__googleScripts'])).toEqual(['https://www.googletagmanager.com/gtag/js?id=G-E2ETEST123']);

	const calls = await gtagCalls(page);
	expect(calls[0]).toEqual(['consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied' }]);
	expect(calls[1][0]).toBe('js');
	expect(calls[2]).toEqual(['config', 'G-E2ETEST123', { content_group: 'Home' }]);
	expect(calls).toHaveLength(3);
});

test('the Content Security Policy allows the tag and the inline scripts carry the nonce', async ({ page }) => {
	const response = await page.goto('/app/index.php?page=Home');
	const csp = response?.headers()['content-security-policy'] ?? '';
	expect(csp).toContain("default-src 'self' 'nonce-");
	expect(csp).toMatch(/script-src 'self' 'nonce-[^']+' https:\/\/\*\.googletagmanager\.com/);
	expect(csp).toMatch(/connect-src [^;]*https:\/\/\*\.google-analytics\.com/);
	expect(csp).toMatch(/img-src [^;]*https:\/\/\*\.googletagmanager\.com/);
	const nonce = /'nonce-([^']+)'/.exec(csp)?.[1];
	expect(nonce).toBeTruthy();

	const inline = page.locator('head script:not([src])');
	expect(await inline.count()).toBeGreaterThan(0);
	for (const script of await inline.all()) {
		// Browsers hide the nonce attribute from the DOM; the property still carries it.
		expect(await script.evaluate((el) => /** @type {HTMLScriptElement} */ (el).nonce)).toBe(nonce);
	}
	// A CSP that blocked the snippet would leave the data layer empty.
	expect((await gtagCalls(page)).length).toBeGreaterThan(0);
});

test('a page without a THead gets the tag at the beginning of its form', async ({ page }) => {
	await page.goto('/app/index.php?page=NoHead');
	await expect(page.locator('head script[src*="gtag/js"]')).toHaveCount(0);
	await expect(page.locator('form script[src*="gtag/js"]')).toHaveCount(1);
	const calls = await gtagCalls(page);
	expect(calls.map((call) => call[0])).toEqual(['consent', 'js', 'config']);
	expect(calls[2]).toEqual(['config', 'G-E2ETEST123', { content_group: 'NoHead' }]);
});

test('the raw data layer has one entry per call', async ({ page }) => {
	await page.goto('/app/index.php?page=Home');
	expect((await dataLayer(page)).length).toBe(3);
});
