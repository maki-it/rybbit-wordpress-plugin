/**
 * Browser test of the opt-out switch (shortcode and block).
 *
 * Run it with "make test-optout". The script runs in the Playwright Docker image.
 * It needs the test page from tools/tests/optout-pages.php.
 * The dev WordPress uses http://localhost:<WP_PORT> as its site URL.
 * A small proxy inside the container forwards that address to the WordPress container.
 *
 * The mock Rybbit server has no tracking script. So the test checks the switch
 * and the localStorage entry that the real Rybbit script reads.
 */
const net = require('net');
const { chromium } = require('playwright');

const PORT = Number(process.env.WP_PORT || 8080);
const UPSTREAM = { host: 'wordpress', port: 80 };
const BASE = `http://localhost:${PORT}`;
const PAGE_URL = `${BASE}/?pagename=rybbit-optout-test`;

const TEXT_ON = 'Website analytics is turned on for this browser.';
const TEXT_OFF = 'Website analytics is turned off for this browser.';
const LABEL_OFF = 'Turn off analytics';
const LABEL_ON = 'Turn on analytics';

let total = 0;
let failures = 0;

function check(label, condition, detail = '') {
	total += 1;
	if (condition) {
		console.log(`  ok    ${label}`);
		return;
	}
	failures += 1;
	console.log(`  FAIL  ${label}${detail ? ` (${detail})` : ''}`);
}

function startProxy() {
	return new Promise((resolve) => {
		const server = net.createServer((client) => {
			const upstream = net.connect(UPSTREAM.port, UPSTREAM.host);
			client.pipe(upstream).pipe(client);
			const close = () => {
				client.destroy();
				upstream.destroy();
			};
			client.on('error', close);
			upstream.on('error', close);
		});
		server.listen(PORT, '127.0.0.1', () => resolve(server));
	});
}

// The page loads the Rybbit script from the internet. The test does not need it.
async function blockExternal(context) {
	await context.route(
		(url) => url.hostname !== 'localhost',
		(route) => route.abort()
	);
}

function watchErrors(page, problems) {
	page.on('pageerror', (error) => problems.push(`page error: ${error.message}`));
	page.on('console', (message) => {
		if ('error' === message.type() && ! /Failed to load resource/.test(message.text())) {
			problems.push(`console error: ${message.text()}`);
		}
	});
}

const flag = (page, key = 'disable-rybbit') => page.evaluate((k) => window.localStorage.getItem(k), key);
const texts = (page) => page.locator('.integrate-rybbit-optout__status').allTextContents();
const labels = (page) => page.locator('.integrate-rybbit-optout__button').allTextContents();

async function main() {
	const proxy = await startProxy();
	const browser = await chromium.launch();
	const problems = [];

	try {
		const context = await browser.newContext({ locale: 'en-US' });
		await blockExternal(context);
		const page = await context.newPage();
		watchErrors(page, problems);

		console.log('Start state');
		await page.goto(PAGE_URL);
		const widgets = page.locator('[data-integrate-rybbit-optout]');
		check('the page has two switches (shortcode and block)', 2 === (await widgets.count()), String(await widgets.count()));
		check('the block wrapper has the block class', 1 === (await page.locator('.wp-block-integrate-rybbit-optout').count()));
		await page.waitForSelector('.integrate-rybbit-optout__button:visible');
		check('both switches say that analytics is on', (await texts(page)).every((t) => TEXT_ON === t), (await texts(page)).join('|'));
		check('both buttons offer to turn it off', (await labels(page)).every((t) => LABEL_OFF === t), (await labels(page)).join('|'));
		check('the status region is visible', await page.locator('.integrate-rybbit-optout__status').first().isVisible());
		check('there is no opt-out flag yet', null === (await flag(page)));

		console.log('Opt out');
		await page.locator('.integrate-rybbit-optout__button').first().click();
		check('the flag is saved', 'true' === (await flag(page)), String(await flag(page)));
		check('the global flag is set', true === (await page.evaluate(() => window.__RYBBIT_OPTOUT__)));
		check('both switches say that analytics is off', (await texts(page)).every((t) => TEXT_OFF === t), (await texts(page)).join('|'));
		check('both buttons offer to turn it on', (await labels(page)).every((t) => LABEL_ON === t), (await labels(page)).join('|'));

		console.log('Reload');
		await page.reload();
		await page.waitForSelector('.integrate-rybbit-optout__button:visible');
		check('the flag survives a reload', 'true' === (await flag(page)));
		check('the switches show the opt-out', (await texts(page)).every((t) => TEXT_OFF === t));

		console.log('Other tab');
		const other = await context.newPage();
		watchErrors(other, problems);
		await other.goto(PAGE_URL);
		await other.waitForSelector('.integrate-rybbit-optout__button:visible');
		check('a new tab shows the opt-out', (await texts(other)).every((t) => TEXT_OFF === t));
		await other.locator('.integrate-rybbit-optout__button').nth(1).click();
		check('the second switch works too', null === (await flag(other)));
		await page.waitForFunction((on) => document.querySelector('.integrate-rybbit-optout__status').textContent === on, TEXT_ON);
		check('the first tab follows the change', (await texts(page)).every((t) => TEXT_ON === t), (await texts(page)).join('|'));
		await other.close();

		console.log('Opt in and keyboard');
		const button = page.locator('.integrate-rybbit-optout__button').first();
		await button.focus();
		await page.keyboard.press('Enter');
		check('the keyboard turns the tracking off', 'true' === (await flag(page)));
		await page.keyboard.press('Space');
		check('the keyboard turns the tracking on again', null === (await flag(page)));
		check('the global flag is cleared', false === (await page.evaluate(() => window.__RYBBIT_OPTOUT__)));

		console.log('Namespace');
		await page.evaluate(() => {
			document.querySelectorAll('[data-integrate-rybbit-optout]').forEach((el) => el.setAttribute('data-namespace', 'my-tracker'));
		});
		await page.locator('.integrate-rybbit-optout__button').first().click();
		check('the key follows the namespace', 'true' === (await flag(page, 'disable-my-tracker')));
		check('the default key stays unused', null === (await flag(page)));
		await page.evaluate(() => window.localStorage.clear());
		await context.close();

		console.log('Blocked storage');
		const blocked = await browser.newContext({ locale: 'en-US' });
		await blockExternal(blocked);
		await blocked.addInitScript(() => {
			Object.defineProperty(window, 'localStorage', {
				get() {
					throw new DOMException('blocked', 'SecurityError');
				},
			});
		});
		const blockedPage = await blocked.newPage();
		watchErrors(blockedPage, problems);
		await blockedPage.goto(PAGE_URL);
		await blockedPage.waitForSelector('.integrate-rybbit-optout__status:visible');
		check('the switch explains the problem', (await texts(blockedPage)).every((t) => /blocks local storage/.test(t)), (await texts(blockedPage)).join('|'));
		check('the buttons stay hidden', 0 === (await blockedPage.locator('.integrate-rybbit-optout__button:visible').count()));
		await blocked.close();

		console.log('No JavaScript');
		const plain = await browser.newContext({ locale: 'en-US', javaScriptEnabled: false });
		await blockExternal(plain);
		const plainPage = await plain.newPage();
		await plainPage.goto(PAGE_URL);
		check('the buttons stay hidden', 0 === (await plainPage.locator('.integrate-rybbit-optout__button:visible').count()));
		check('the status stays hidden', 0 === (await plainPage.locator('.integrate-rybbit-optout__status:visible').count()));
		check('the noscript hint is in the page', /JavaScript is needed/.test(await plainPage.content()));
		await plain.close();

		check('the pages have no JavaScript errors', 0 === problems.length, problems.join(' | '));
	} finally {
		await browser.close();
		proxy.close();
	}

	console.log('');
	if (failures > 0) {
		console.error(`${failures} of ${total} browser checks failed.`);
		process.exit(1);
	}
	console.log(`All ${total} browser checks passed.`);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
