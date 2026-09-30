// @ts-check
import { expect, test } from '@playwright/test';
import { gtagCalls, stubGoogle } from './helpers.js';

/*
 * TrackValidationErrors: a postback whose validator fails queues a `form_error` event with the
 * page path, the count and the validator IDs, delivered on the re-rendered page.
 */

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('a failed server validation is reported as a form_error event', async ({ page }) => {
	await page.goto('/app/index.php?page=Register');
	await page.getByRole('button', { name: 'Register' }).click();
	await expect(page.getByText('Name is required')).toBeVisible();

	const calls = await gtagCalls(page);
	expect(calls[3]).toEqual(['event', 'form_error', { form_id: 'Register', error_count: 1, validators: 'nameRequired' }]);
});

test('a valid postback reports nothing', async ({ page }) => {
	await page.goto('/app/index.php?page=Register');
	await page.getByRole('textbox').fill('Ada');
	await page.getByRole('button', { name: 'Register' }).click();
	await expect(page.getByRole('heading', { name: 'Register' })).toBeVisible();
	expect(await gtagCalls(page)).toHaveLength(3);
});
