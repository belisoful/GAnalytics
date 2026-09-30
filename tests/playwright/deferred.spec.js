// @ts-check
import { expect, test } from '@playwright/test';
import { gtagCalls, stubGoogle } from './helpers.js';

/*
 * A deferred event survives a redirect: the Login page's postback queues a deferred `login`
 * event and redirects to Home, where the event is delivered at the end of the form.
 */

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('an event queued before a redirect arrives on the next page', async ({ page }) => {
	await page.goto('/app/index.php?page=Login');
	expect(await gtagCalls(page)).toHaveLength(3);

	await page.getByRole('button', { name: 'Log in' }).click();
	await expect(page).toHaveURL(/page=Home/);
	await expect(page.getByRole('heading', { name: 'Home' })).toBeVisible();

	const calls = await gtagCalls(page);
	expect(calls[2]).toEqual(['config', 'G-E2ETEST123', { content_group: 'Home' }]);
	expect(calls[3]).toEqual(['event', 'login', { method: 'form' }]);
	expect(calls).toHaveLength(4);

	await page.goto('/app/index.php?page=Home');
	expect(await gtagCalls(page)).toHaveLength(3);
});
