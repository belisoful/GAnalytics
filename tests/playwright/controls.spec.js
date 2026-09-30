// @ts-check
import { expect, test } from '@playwright/test';
import { gtagCalls, stubGoogle } from './helpers.js';

/*
 * The control tracking application (tests/playwright/app-controls): TrackClicks sends the
 * events of data-ga-event attributes, and TrackControls="all" reports tab switches, view changes,
 * wizard steps and grid paging. The realtime counter refreshes by callback.
 */

const URL = '/app-controls/index.php?page=Controls';

/** @param {import('@playwright/test').Page} page */
async function events(page) {
	return (await gtagCalls(page)).filter((call) => call[0] === 'event');
}

test.beforeEach(async ({ page }) => {
	await stubGoogle(page);
});

test('the first request reports the page and no view change', async ({ page }) => {
	const errors = [];
	page.on('console', (message) => message.type() === 'error' && errors.push(message.text()));
	await page.goto(URL);
	expect((await gtagCalls(page)).find((call) => call[0] === 'config')).toEqual(['config', 'G-E2ETEST123']);
	expect(await events(page)).toEqual([]);
	expect(errors, 'the inline scripts run under the nonce CSP').toEqual([]);
});

test('elements with data-ga-event send their events', async ({ page }) => {
	await page.goto(URL);
	await page.locator('#download').click();
	await page.getByText('Plain link').click();
	await page.locator('#size').selectOption('M');
	expect(await events(page)).toEqual([
		['event', 'file_download', { file_name: 'spec.pdf' }],
		['event', 'select_content', {}],
		['event', 'select_size', {}],
	]);
	await expect(page.locator('#coded')).toHaveAttribute('data-ga-event', 'generate_lead');
	await expect(page.locator('#coded')).toHaveAttribute('data-ga-params', '{"source":"code"}');
});

test('a tab switch is a virtual page view; the open tab is not', async ({ page }) => {
	await page.goto(URL);
	await page.getByRole('tab', { name: 'Profile' }).click();
	expect(await events(page)).toEqual([]);
	await page.getByRole('tab', { name: 'Billing' }).click();
	await expect(page.getByText('Billing content')).toBeVisible();
	const calls = await events(page);
	expect(calls).toHaveLength(1);
	expect(calls[0][1]).toBe('page_view');
	expect(calls[0][2].page_location).toMatch(/\?page=Controls#Tabs:Billing$/);
	expect(calls[0][2].page_title).toBe('Controls | Billing');
	await page.getByRole('tab', { name: 'Billing' }).click();
	expect(await events(page)).toHaveLength(1);
});

test('a view change by callback is a virtual page view', async ({ page }) => {
	await page.goto(URL);
	await page.getByRole('button', { name: 'Next view' }).click();
	await expect(page.getByText('Second view')).toBeVisible();
	await expect.poll(async () => (await events(page)).length).toBe(1);
	const [call] = await events(page);
	expect(call[1]).toBe('page_view');
	expect(call[2].page_location).toMatch(/#Views:Second$/);
	expect(call[2].page_title).toBe('Controls | Second');
	expect(await page.evaluate(() => window['__googleScripts'])).toHaveLength(1);
});

test('a wizard step is a wizard_step event on the page the postback renders', async ({ page }) => {
	await page.goto(URL);
	await page.getByRole('button', { name: 'Next', exact: true }).click();
	await expect(page.getByText('Payment step')).toBeVisible();
	expect(await events(page)).toEqual([['event', 'wizard_step', { wizard: 'Checkout', step_index: 2, step_name: 'Payment', step_count: 2 }]]);
});

test('a grid page change is a view_item_list event', async ({ page }) => {
	await page.goto(URL);
	await page.getByRole('link', { name: '2', exact: true }).click();
	await expect(page.getByText('A-3')).toBeVisible();
	expect(await events(page)).toEqual([['event', 'view_item_list', { item_list_id: 'Orders', item_list_name: 'Orders', page: 2 }]]);
});

test('the realtime counter refreshes by callback', async ({ page }) => {
	await page.goto(URL);
	await expect(page.locator('#Live_value')).toHaveText('n/a');
	const callback = await page.waitForRequest((request) => request.method() === 'POST' && (request.postData() ?? '').includes('PRADO_CALLBACK_TARGET=Live'));
	expect(callback).toBeTruthy();
	await page.waitForResponse((response) => response.request() === callback);
	await expect(page.locator('#Live_value')).toHaveText('n/a', { timeout: 2000 });
});
