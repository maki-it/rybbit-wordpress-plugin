/**
 * Takes the WordPress.org screenshots from the local dev WordPress.
 *
 * Run it with "make screenshots". The script runs in the Playwright Docker image.
 * The dev WordPress uses http://localhost:<WP_PORT> as its site URL.
 * A small proxy inside the container forwards that address to the WordPress container.
 */
const net = require('net');
const { chromium } = require('playwright');

const PORT = Number(process.env.WP_PORT || 8080);
const UPSTREAM = { host: 'wordpress', port: 80 };
const BASE = `http://localhost:${PORT}`;
const OUT = process.env.OUT_DIR || '/out';

// Example values per tab. They must not contain real data.
const DEMO = {
	tracking: {
		text: { rybbit_site_id: 'a1b2c3d4e5f6' },
		select: { rybbit_script_loading: 'defer' },
		roles: ['administrator'],
	},
	privacy: {
		select: {
			rybbit_identify_mode: 'pseudonymized',
			rybbit_identify_userid_strategy: 'wp_scoped',
		},
	},
	script: {
		text: {
			rybbit_skip_patterns: '/preview/**\n/cart/*\nre:^/checkout/\\d+$',
			rybbit_mask_patterns: '/account/**\n/orders/*',
		},
	},
	replay: {
		text: {
			rybbit_replay_mask_text_selectors: '.user-name\n#email',
			rybbit_replay_block_selector: '.sensitive-content, #payment-modal',
			rybbit_replay_ignore_selector: '',
		},
	},
};

const SHOTS = [
	{ file: 'screenshot-1.png', tab: 'tracking', withHeader: true, maxHeight: 930 },
	{ file: 'screenshot-2.png', tab: 'privacy', withHeader: true, maxHeight: 930 },
	{ file: 'screenshot-3.png', tab: 'script', withHeader: false, maxHeight: 930 },
	{ file: 'screenshot-4.png', tab: 'replay', withHeader: false, maxHeight: 1300, endField: 'rybbit_replay_mask_input_options' },
];

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

async function fillDemoData(page) {
	for (const [tab, fields] of Object.entries(DEMO)) {
		await page.click(`#rybbit-tab-${tab}`);
		for (const [id, value] of Object.entries(fields.text || {})) {
			await page.fill(`#${id}`, value);
		}
		for (const [id, value] of Object.entries(fields.select || {})) {
			await page.selectOption(`#${id}`, value);
		}
		if (fields.roles) {
			await page.selectOption('#rybbit_excluded_roles', fields.roles);
			await page.uncheck('#rybbit_track_wp_admin');
		}
	}
	await Promise.all([page.waitForURL(/settings-updated=true/), page.click('#submit')]);
}

async function main() {
	const proxy = await startProxy();
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage({
			viewport: { width: 1280, height: 1000 },
			deviceScaleFactor: 1,
			locale: 'en-US',
		});

		await page.goto(`${BASE}/wp-login.php`);
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'admin');
		await page.click('#wp-submit');
		await page.waitForURL(/wp-admin/);

		await page.goto(`${BASE}/wp-admin/options-general.php?page=integrate-rybbit`);
		await fillDemoData(page);
		await page.addStyleTag({ content: '.notice, .update-nag, .error { display: none !important; }' });

		for (const shot of SHOTS) {
			await page.click(`#rybbit-tab-${shot.tab}`);
			await page.evaluate(() => document.activeElement && document.activeElement.blur());
			await page.mouse.move(0, 0);

			const wrap = await page.locator('#wpbody-content .wrap').boundingBox();
			const tabs = await page.locator('.nav-tab-wrapper').boundingBox();
			const x = Math.max(wrap.x - 16, 0);
			const y = shot.withHeader ? wrap.y - 10 : tabs.y - 10;
			let bottom = wrap.y + wrap.height + 10;
			if (shot.endField) {
				// Stop after the table row of this field.
				const row = await page.locator(`#${shot.endField}`).locator('xpath=ancestor::tr').boundingBox();
				bottom = row.y + row.height + 10;
			}
			const clip = {
				x,
				y,
				width: wrap.width + 32,
				height: Math.min(bottom - y, shot.maxHeight),
			};

			await page.screenshot({ path: `${OUT}/${shot.file}`, clip, fullPage: true });
			console.log(`${shot.file}: ${Math.round(clip.width)}x${Math.round(clip.height)}`);
		}
	} finally {
		await browser.close();
		proxy.close();
	}
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
