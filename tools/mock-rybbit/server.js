/*
 * Mock of the Rybbit Stats API for local tests.
 *
 * It sends fixed demo data in the same shape as the real API. It has no
 * dependencies. Start it with "make dev" (Docker Compose service "mock-rybbit").
 *
 * API keys:
 *   demo-key       Valid. Returns data.
 *   bad-key        Returns 401.
 *   forbidden-key  Returns 403.
 *   no-scope-key   Returns 403 with the missing permission "analytics:read".
 *   no-sites-key   Works, but the site settings route returns 403 ("sites:read").
 *   slow-key       Returns 429 with a Retry-After header.
 *
 * Site ID "missing" returns 404. Any other site ID works.
 *
 * Helper routes (no key needed):
 *   GET  /__stats  Number of API requests since the last reset.
 *   POST /__reset  Set the counter to 0.
 */
'use strict';

const http = require('node:http');

const PORT = Number(process.env.PORT || 3000);

const VALID_KEY = 'demo-key';

let apiRequests = 0;

/* Small deterministic number generator. The same input gives the same output. */
function hash(text) {
	let h = 2166136261;
	for (let i = 0; i < text.length; i++) {
		h ^= text.charCodeAt(i);
		h = Math.imul(h, 16777619);
	}
	return h >>> 0;
}

function pad(n) {
	return String(n).padStart(2, '0');
}

function formatDate(d) {
	return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
}

function formatDateTime(d) {
	return formatDate(d) + ' ' + pad(d.getUTCHours()) + ':00:00';
}

function parseDate(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/.exec(value || '');
	if (!match) {
		return null;
	}
	return new Date(Date.UTC(+match[1], +match[2] - 1, +match[3], +(match[4] || 0), +(match[5] || 0), +(match[6] || 0)));
}

function sendJson(res, status, body, headers) {
	const text = JSON.stringify(body);
	res.writeHead(status, Object.assign({ 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(text) }, headers || {}));
	res.end(text);
}

/* The time buckets of a request. Returns null if the time parameters are not valid. */
function getBuckets(query) {
	const bucket = query.get('bucket') === 'hour' ? 'hour' : 'day';
	let start;
	let end;

	if (query.has('start_datetime') || query.has('end_datetime')) {
		start = parseDate(query.get('start_datetime'));
		end = parseDate(query.get('end_datetime'));
	} else {
		start = parseDate(query.get('start_date'));
		end = parseDate(query.get('end_date'));
		if (end) {
			// The end date is inclusive.
			end = new Date(end.getTime() + 24 * 3600 * 1000);
		}
	}

	if (!start || !end || end <= start || !query.get('time_zone')) {
		return null;
	}

	const step = bucket === 'hour' ? 3600 * 1000 : 24 * 3600 * 1000;
	const buckets = [];
	for (let t = start.getTime(); t < end.getTime() && buckets.length < 400; t += step) {
		const d = new Date(t);
		const seed = hash(formatDateTime(d));
		const hour = d.getUTCHours();
		// Day time gets more visitors than night time.
		const daylight = bucket === 'hour' ? 1 + Math.sin(((hour - 6) / 24) * Math.PI * 2) * 0.8 : 1;
		const base = bucket === 'hour' ? 6 : 140;
		const users = Math.max(0, Math.round(base * daylight + (seed % (bucket === 'hour' ? 5 : 60))));
		const sessions = users + (seed % 3);
		const pageviews = sessions * 2 + (seed % 7);

		buckets.push({
			time: bucket === 'hour' ? formatDateTime(d) : formatDate(d) + ' 00:00:00',
			sessions,
			pageviews,
			users,
		});
	}

	return buckets;
}

const METRICS = {
	pathname: [
		['/', 412],
		['/blog/hello-world/', 238],
		['/pricing/', 174],
		['/blog/privacy-friendly-analytics/', 131],
		['/contact/', 88],
		['/blog/caf%C3%A9-notes/', 61],
		['/about/', 47],
		['/docs/getting-started/', 32],
	],
	referrer: [
		['google.com', 320],
		['duckduckgo.com', 140],
		['news.ycombinator.com', 96],
		['github.com', 71],
		['mastodon.social', 44],
		['bsky.app', 29],
	],
	country: [
		['DE', 410],
		['US', 220],
		['AT', 96],
		['CH', 74],
		['FR', 52],
		['GB', 41],
		['NL', 30],
		['JP', 12],
	],
	device_type: [
		['Desktop', 540],
		['Mobile', 330],
		['Tablet', 40],
	],
	browser: [
		['Chrome', 410],
		['Safari', 220],
		['Firefox', 170],
		['Edge', 70],
		['Samsung Internet', 25],
	],
};

function getMetric(parameter, limit) {
	const rows = METRICS[parameter];
	if (!rows) {
		return null;
	}
	const total = rows.reduce((sum, row) => sum + row[1], 0);
	const list = rows.slice(0, limit).map((row) => ({
		value: row[0],
		count: row[1],
		percentage: Math.round((row[1] / total) * 10000) / 100,
	}));
	return { data: list, totalCount: rows.length };
}

function handleApi(req, res, url) {
	apiRequests++;

	const auth = req.headers.authorization || '';
	const key = auth.startsWith('Bearer ') ? auth.slice(7) : '';

	if (key === 'slow-key') {
		return sendJson(
			res,
			429,
			{ error: 'Too many requests', scope: 'burst', limit: 50, retryAfter: 30 },
			{ 'Retry-After': '30' }
		);
	}
	if (key === 'forbidden-key') {
		return sendJson(res, 403, { error: 'Forbidden' });
	}
	if (key === 'no-scope-key') {
		return sendJson(res, 403, { error: 'Insufficient scope', required: 'analytics:read' });
	}
	if (key !== VALID_KEY && key !== 'no-sites-key') {
		return sendJson(res, 401, { error: 'Unauthorized' });
	}

	const match = /^\/api\/sites\/([^/]+)(\/.*)?$/.exec(url.pathname);
	if (!match) {
		return sendJson(res, 404, { error: 'Not found' });
	}
	if (decodeURIComponent(match[1]) === 'missing') {
		return sendJson(res, 404, { error: 'Site not found' });
	}

	const path = match[2] || '';
	const query = url.searchParams;

	/* The site settings. Rybbit needs "sites:read" for them. The mock site has the number 29. */
	if (path === '') {
		if (key === 'no-sites-key') {
			return sendJson(res, 403, { error: 'Insufficient scope', required: 'sites:read' });
		}
		return sendJson(res, 200, { id: decodeURIComponent(match[1]), siteId: 29, name: 'Demo site' });
	}

	if (path === '/has-data') {
		return sendJson(res, 200, { hasData: true });
	}

	if (path === '/live-user-count') {
		return sendJson(res, 200, { count: 3 });
	}

	if (path === '/overview' || path === '/overview/time-series') {
		const buckets = getBuckets(query);
		if (!buckets) {
			return sendJson(res, 400, { error: 'Invalid time parameters' });
		}

		if (path === '/overview/time-series') {
			return sendJson(res, 200, { data: buckets });
		}

		const sum = (field) => buckets.reduce((total, item) => total + item[field], 0);
		const sessions = sum('sessions');
		const pageviews = sum('pageviews');
		return sendJson(res, 200, {
			data: {
				sessions,
				pageviews,
				users: Math.round(sum('users') * 0.8),
				pages_per_session: sessions ? Math.round((pageviews / sessions) * 100) / 100 : 0,
				bounce_rate: 42.37,
				session_duration: 128.5,
			},
		});
	}

	if (path === '/metric') {
		if (!getBuckets(query)) {
			return sendJson(res, 400, { error: 'Invalid time parameters' });
		}
		const body = getMetric(query.get('parameter'), Math.min(Number(query.get('limit')) || 10, 50));
		if (!body) {
			return sendJson(res, 400, { error: 'Invalid parameter' });
		}
		return sendJson(res, 200, { data: body });
	}

	return sendJson(res, 404, { error: 'Not found' });
}

const server = http.createServer((req, res) => {
	const url = new URL(req.url, 'http://mock');

	if (url.pathname === '/__stats' && req.method === 'GET') {
		return sendJson(res, 200, { requests: apiRequests });
	}
	if (url.pathname === '/__reset' && req.method === 'POST') {
		apiRequests = 0;
		return sendJson(res, 200, { requests: apiRequests });
	}
	if (url.pathname === '/health') {
		return sendJson(res, 200, { ok: true });
	}

	if (req.method !== 'GET') {
		return sendJson(res, 405, { error: 'Method not allowed' });
	}

	return handleApi(req, res, url);
});

server.listen(PORT, '0.0.0.0', () => {
	console.log('Mock Rybbit API on port ' + PORT);
});
