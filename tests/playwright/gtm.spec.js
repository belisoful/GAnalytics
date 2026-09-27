// @ts-check
import { expect, test } from '@playwright/test';
import { dataLayer, stubGoogle } from './helpers.js';

/*
 * The Tag Manager application (tests/playwright/app-gtm): a container alone loads gtm.js,
 * marks gtm.start, inserts the noscript frame, and delivers a callback event as a data layer push.
 */

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('a container loads Tag Manager and inserts the noscript frame', async ({ page }) => {
	await page.goto('/app-gtm/index.php?page=Home');
	await expect(page.locator('head script[src*="gtag/js"]')).toHaveCount(0);
	await expect.poll(() => page.evaluate(() => window['__googleScripts'] ?? [])).toEqual(['https://www.googletagmanager.com/gtm.js?id=GTM-E2ETEST']);

	const entries = await dataLayer(page);
	expect(entries).toHaveLength(1);
	expect(entries[0].event).toBe('gtm.js');
	expect(typeof entries[0]['gtm.start']).toBe('number');

	const html = await page.content();
	expect(html).toContain('<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-E2ETEST"');
	expect(html.indexOf('<form')).toBeLessThan(html.indexOf('<noscript><iframe'));
});

test('a callback event is a data layer push for the container', async ({ page }) => {
	await page.goto('/app-gtm/index.php?page=Home');
	await page.getByRole('button', { name: 'Track' }).click();
	await expect(page.getByText('tracked')).toBeVisible();
	const entries = await dataLayer(page);
	expect(entries[1]).toEqual({ event: 'add_to_cart', value: 9.99 });
});
