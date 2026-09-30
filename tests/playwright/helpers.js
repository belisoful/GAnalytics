// @ts-check

/**
 * Stubs Google's tag hosts so no request leaves the machine: gtag.js and gtm.js answer
 * with a script that records that it loaded; anything else on those hosts is an empty 204.
 * The inline snippet still pushes into the data layer, which is what the specs assert on.
 * @param {import('@playwright/test').Page} page
 */
export async function stubGoogle(page) {
	await page.route(/https:\/\/(www\.)?googletagmanager\.com\/.*/, (route) => {
		const url = route.request().url();
		if (/\/(gtag\/js|gtm\.js)/.test(url)) {
			return route.fulfill({
				status: 200,
				contentType: 'application/javascript',
				body: `window.__googleScripts = (window.__googleScripts || []).concat(${JSON.stringify(url)});`,
			});
		}
		return route.fulfill({ status: 204, body: '' });
	});
	await page.route(/https:\/\/([a-z0-9-]+\.)*(google-analytics\.com|analytics\.google\.com)\/.*/, (route) => route.fulfill({ status: 204, body: '' }));
}

/**
 * Returns the data layer as plain arrays and objects: a gtag() call is its `arguments`
 * turned into an array, a Tag Manager push stays an object, a Date becomes a string.
 * @param {import('@playwright/test').Page} page
 * @param {string} [name]
 * @returns {Promise<any[]>}
 */
export function dataLayer(page, name = 'dataLayer') {
	return page.evaluate((layer) => {
		const entries = /** @type {any[]} */ (window[/** @type {any} */ (layer)] || []);
		return entries.map((entry) => (typeof entry.length === 'number' && !Array.isArray(entry) ? Array.from(entry) : entry))
			.map((entry) => JSON.parse(JSON.stringify(entry)));
	}, name);
}

/**
 * The gtag calls of the data layer as `[command, ...args]` arrays.
 * @param {import('@playwright/test').Page} page
 */
export async function gtagCalls(page) {
	return (await dataLayer(page)).filter((entry) => Array.isArray(entry));
}
