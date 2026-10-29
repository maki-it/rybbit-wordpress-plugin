/* Integrate Rybbit: analytics dashboard (widget and page). */
(function () {
	'use strict';

	var config = window.rybbitDashboard;
	if (!config) {
		return;
	}

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var SVG_NS = 'http://www.w3.org/2000/svg';
	var locale = document.documentElement.lang || undefined;

	function __(text) {
		return i18n ? i18n.__(text, 'integrate-rybbit') : text;
	}

	function _n(single, plural, number) {
		if (i18n && i18n._n) {
			return i18n._n(single, plural, number, 'integrate-rybbit');
		}
		return number === 1 ? single : plural;
	}

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		if (i18n && i18n.sprintf) {
			return i18n.sprintf.apply(null, [format].concat(args));
		}
		var index = 0;
		return String(format).replace(/%[ds]/g, function () {
			return String(args[index++]);
		});
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function forEach(list, fn) {
		Array.prototype.forEach.call(list, fn);
	}

	/* All text goes through textContent. Data from the API is never parsed as HTML. */
	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function svg(tag, attrs) {
		var node = document.createElementNS(SVG_NS, tag);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function formatNumber(value) {
		try {
			return new Intl.NumberFormat(locale).format(value);
		} catch (e) {
			return String(value);
		}
	}

	function formatPercent(value) {
		var rounded = Math.round(value * 10) / 10;
		return formatNumber(rounded) + '%';
	}

	function pad(number) {
		return number < 10 ? '0' + number : String(number);
	}

	function formatDuration(seconds) {
		var total = Math.max(0, Math.round(seconds));
		var hours = Math.floor(total / 3600);
		var minutes = Math.floor((total % 3600) / 60);
		var rest = total % 60;
		if (hours > 0) {
			return hours + ':' + pad(minutes) + ':' + pad(rest);
		}
		return minutes + ':' + pad(rest);
	}

	/*
	 * Rybbit sends "YYYY-MM-DD HH:MM:SS" in the time zone of the request.
	 * The parts are used as they are. The browser time zone must not change them.
	 */
	function parseTime(text) {
		var match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(text);
		if (!match) {
			return null;
		}
		return new Date(Date.UTC(+match[1], +match[2] - 1, +match[3], +(match[4] || 0), +(match[5] || 0)));
	}

	function formatTime(text, withHour) {
		var date = parseTime(text);
		if (!date) {
			return text;
		}
		var options = withHour ? { timeZone: 'UTC', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } : { timeZone: 'UTC', month: 'short', day: 'numeric' };
		try {
			return new Intl.DateTimeFormat(locale, options).format(date);
		} catch (e) {
			return text;
		}
	}

	function formatHourOnly(text) {
		var date = parseTime(text);
		if (!date) {
			return text;
		}
		return pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes());
	}

	function countryName(code) {
		if (typeof Intl !== 'undefined' && Intl.DisplayNames && /^[A-Za-z]{2}$/.test(code)) {
			try {
				var names = new Intl.DisplayNames(locale ? [locale] : undefined, { type: 'region' });
				return names.of(code.toUpperCase()) || code;
			} catch (e) {
				return code;
			}
		}
		return code;
	}

	function niceCeil(value) {
		if (value <= 1) {
			return 1;
		}
		var magnitude = Math.pow(10, Math.floor(Math.log(value) / Math.LN10));
		var steps = [1, 2, 5, 10];
		for (var i = 0; i < steps.length; i++) {
			if (steps[i] * magnitude >= value) {
				return steps[i] * magnitude;
			}
		}
		return 10 * magnitude;
	}

	/* REST request. Returns a promise. Errors are objects with message, code and retryAfter. */
	function fetchStats(range, view) {
		var separator = config.restUrl.indexOf('?') === -1 ? '?' : '&';
		var url = config.restUrl + separator + 'range=' + encodeURIComponent(range) + '&view=' + encodeURIComponent(view);

		return fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' }
		}).then(function (response) {
			return response.json().then(function (body) {
				return { ok: response.ok, status: response.status, body: body };
			}, function () {
				return { ok: false, status: response.status, body: null };
			});
		}).then(function (result) {
			if (result.ok && result.body) {
				return result.body;
			}
			var body = result.body || {};
			var data = body.data || {};
			var message = body.message || sprintf(__('The request failed (HTTP %s).'), result.status);
			if (body.code === 'rest_cookie_invalid_nonce' || result.status === 401 || result.status === 403) {
				message = __('Your session has expired. Reload the page and try again.');
			}
			throw { message: message, code: body.code || '', retryAfter: data.retry_after || 0 };
		}, function () {
			throw { message: __('The request failed. Check your network connection.'), code: 'network', retryAfter: 0 };
		});
	}

	function renderKpis(overview) {
		var items = [
			{ label: __('Visitors'), value: formatNumber(overview.users) },
			{ label: __('Pageviews'), value: formatNumber(overview.pageviews) },
			{ label: __('Bounce rate'), value: formatPercent(overview.bounce_rate) },
			{ label: __('Avg. visit duration'), value: formatDuration(overview.session_duration) }
		];
		var list = el('div', 'rybbit-kpis');
		items.forEach(function (item) {
			var card = el('div', 'rybbit-kpi');
			card.appendChild(el('span', 'rybbit-kpi__label', item.label));
			card.appendChild(el('span', 'rybbit-kpi__value', item.value));
			list.appendChild(card);
		});
		return list;
	}

	function renderDataTable(series, hourly) {
		var details = el('details', 'rybbit-chart__table');
		details.appendChild(el('summary', '', __('Show data as table')));
		var table = el('table', 'widefat striped');
		var head = el('tr');
		[__('Time'), __('Visitors'), __('Pageviews')].forEach(function (label) {
			head.appendChild(el('th', '', label));
		});
		var thead = el('thead');
		thead.appendChild(head);
		table.appendChild(thead);
		var tbody = el('tbody');
		series.forEach(function (point) {
			var row = el('tr');
			row.appendChild(el('td', '', formatTime(point.time, hourly)));
			row.appendChild(el('td', '', formatNumber(point.users)));
			row.appendChild(el('td', '', formatNumber(point.pageviews)));
			tbody.appendChild(row);
		});
		table.appendChild(tbody);
		details.appendChild(table);
		return details;
	}

	function renderChart(series, hourly) {
		var section = el('div', 'rybbit-chart');
		var count = series.length;
		var max = 1;
		series.forEach(function (point) {
			max = Math.max(max, point.users, point.pageviews);
		});
		var top = niceCeil(max);

		var legend = el('ul', 'rybbit-chart__legend');
		[['rybbit-chart__swatch--visitors', __('Visitors')], ['rybbit-chart__swatch--pageviews', __('Pageviews')]].forEach(function (item) {
			var li = el('li');
			li.appendChild(el('span', 'rybbit-chart__swatch ' + item[0]));
			li.appendChild(document.createTextNode(item[1]));
			legend.appendChild(li);
		});
		section.appendChild(legend);

		var frame = el('div', 'rybbit-chart__frame');
		var axis = el('div', 'rybbit-chart__axis');
		[top, top / 2, 0].forEach(function (value) {
			axis.appendChild(el('span', '', formatNumber(Math.round(value * 10) / 10)));
		});
		frame.appendChild(axis);

		var plot = el('div', 'rybbit-chart__plot');
		plot.tabIndex = 0;
		plot.setAttribute('role', 'group');
		plot.setAttribute('aria-label', __('Chart of visitors and pageviews. Use the left and right arrow keys to read single values.'));

		var width = 600;
		var height = 180;
		var canvas = svg('svg', { viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none', 'aria-hidden': 'true', focusable: 'false' });
		var xAt = function (index) {
			return count === 1 ? width / 2 : (index * width) / (count - 1);
		};
		var yAt = function (value) {
			return height - (value / top) * height;
		};

		[0, 0.5, 1].forEach(function (fraction) {
			canvas.appendChild(svg('line', { x1: 0, x2: width, y1: height * fraction, y2: height * fraction, 'class': 'rybbit-chart__grid' }));
		});

		function path(key) {
			return series.map(function (point, index) {
				return (index === 0 ? 'M' : 'L') + xAt(index).toFixed(1) + ' ' + yAt(point[key]).toFixed(1);
			}).join(' ');
		}

		canvas.appendChild(svg('path', { d: path('users') + ' L' + xAt(count - 1).toFixed(1) + ' ' + height + ' L' + xAt(0).toFixed(1) + ' ' + height + ' Z', 'class': 'rybbit-chart__area' }));
		canvas.appendChild(svg('path', { d: path('pageviews'), 'class': 'rybbit-chart__line rybbit-chart__line--pageviews' }));
		canvas.appendChild(svg('path', { d: path('users'), 'class': 'rybbit-chart__line rybbit-chart__line--visitors' }));

		var marker = svg('line', { x1: 0, x2: 0, y1: 0, y2: height, 'class': 'rybbit-chart__marker' });
		marker.style.display = 'none';
		canvas.appendChild(marker);
		plot.appendChild(canvas);

		var tip = el('div', 'rybbit-chart__tip');
		tip.hidden = true;
		var tipTime = el('strong');
		var tipVisitors = el('span');
		var tipPageviews = el('span');
		tip.appendChild(tipTime);
		tip.appendChild(tipVisitors);
		tip.appendChild(tipPageviews);
		plot.appendChild(tip);
		frame.appendChild(plot);
		section.appendChild(frame);

		var labels = el('div', 'rybbit-chart__x');
		var picks = count > 2 ? [0, Math.floor((count - 1) / 2), count - 1] : series.map(function (point, index) {
			return index;
		});
		picks.forEach(function (index) {
			labels.appendChild(el('span', '', hourly ? formatHourOnly(series[index].time) : formatTime(series[index].time, false)));
		});
		section.appendChild(labels);

		var active = -1;
		function show(index) {
			active = Math.max(0, Math.min(count - 1, index));
			var point = series[active];
			var fraction = count === 1 ? 0.5 : active / (count - 1);
			marker.setAttribute('x1', xAt(active));
			marker.setAttribute('x2', xAt(active));
			marker.style.display = '';
			tipTime.textContent = formatTime(point.time, hourly);
			tipVisitors.textContent = __('Visitors') + ': ' + formatNumber(point.users);
			tipPageviews.textContent = __('Pageviews') + ': ' + formatNumber(point.pageviews);
			tip.style.left = (fraction * 100) + '%';
			tip.classList.toggle('is-flipped', fraction > 0.6);
			tip.hidden = false;
		}
		function hide() {
			active = -1;
			marker.style.display = 'none';
			tip.hidden = true;
		}

		plot.addEventListener('pointermove', function (event) {
			var rect = plot.getBoundingClientRect();
			if (rect.width > 0) {
				show(Math.round(((event.clientX - rect.left) / rect.width) * (count - 1)));
			}
		});
		plot.addEventListener('pointerleave', hide);
		plot.addEventListener('blur', hide);
		plot.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowLeft') {
				show(active < 0 ? count - 1 : active - 1);
			} else if (event.key === 'ArrowRight') {
				show(active < 0 ? count - 1 : active + 1);
			} else if (event.key === 'Escape') {
				hide();
			} else {
				return;
			}
			event.preventDefault();
		});

		section.appendChild(renderDataTable(series, hourly));
		return section;
	}

	var TOP_TITLES = {
		pages: function () { return __('Top pages'); },
		referrers: function () { return __('Top referrers'); },
		countries: function () { return __('Countries'); },
		devices: function () { return __('Devices'); },
		browsers: function () { return __('Browsers'); }
	};

	function topLabel(name, value) {
		if (name === 'referrers' && value === '') {
			return __('Direct or unknown');
		}
		if (name === 'countries') {
			return countryName(value);
		}
		return value === '' ? __('Unknown') : value;
	}

	function renderTopList(name, rows, failed) {
		var card = el('section', 'rybbit-top');
		card.appendChild(el('h3', 'rybbit-top__title', TOP_TITLES[name]()));

		if (failed) {
			card.appendChild(el('p', 'rybbit-top__empty', __('Could not load this list.')));
			return card;
		}
		if (!rows || !rows.length) {
			card.appendChild(el('p', 'rybbit-top__empty', __('No data yet.')));
			return card;
		}

		var list = el('ul', 'rybbit-top__list');
		rows.forEach(function (row) {
			var label = topLabel(name, row.value);
			var item = el('li', 'rybbit-top__row');
			var text = el('span', 'rybbit-top__label', label);
			text.title = label;
			item.appendChild(text);
			item.appendChild(el('span', 'rybbit-top__count', formatNumber(row.count)));
			var bar = el('span', 'rybbit-top__bar');
			var fill = el('span', 'rybbit-top__fill');
			fill.style.width = Math.max(0, Math.min(100, row.percentage)) + '%';
			bar.appendChild(fill);
			item.appendChild(bar);
			list.appendChild(item);
		});
		card.appendChild(list);
		return card;
	}

	function liveText(count) {
		return sprintf(_n('%s visitor online', '%s visitors online', count), formatNumber(count));
	}

	function initDashboard(root) {
		var view = root.getAttribute('data-view') === 'summary' ? 'summary' : 'full';
		var range = root.getAttribute('data-range') || config.defaultRange;
		var body = root.querySelector('.rybbit-dash__body');
		var select = root.querySelector('[data-rybbit-range]');
		var refresh = root.querySelector('[data-rybbit-refresh]');
		var note = root.querySelector('[data-rybbit-updated]');
		var requestId = 0;
		var liveTimer = null;
		var liveBadge = null;
		var liveLabel = null;

		function setLive(count) {
			if (!liveBadge) {
				return;
			}
			if (typeof count !== 'number') {
				liveBadge.hidden = true;
				return;
			}
			liveLabel.textContent = liveText(count);
			liveBadge.hidden = false;
		}

		function scheduleLive() {
			window.clearTimeout(liveTimer);
			liveTimer = window.setTimeout(tickLive, (config.liveInterval || 60) * 1000);
		}

		/* The live count refreshes only while the tab is visible. This saves API requests. */
		function tickLive() {
			if (!document.body.contains(root)) {
				return;
			}
			if (document.hidden) {
				scheduleLive();
				return;
			}
			var current = requestId;
			fetchStats(range, 'live').then(function (data) {
				if (current === requestId) {
					setLive(data.live);
				}
			}, function () {
				/* Keep the last value. The next tick tries again. */
			}).then(function () {
				if (current === requestId) {
					scheduleLive();
				}
			});
		}

		function showStatus(text) {
			clear(body);
			body.appendChild(el('p', 'rybbit-dash__status', text));
		}

		function showError(error) {
			clear(body);
			var notice = el('div', 'notice notice-error inline rybbit-dash__error');
			notice.appendChild(el('p', '', error.message));
			if (error.retryAfter) {
				var minutes = Math.max(1, Math.ceil(error.retryAfter / 60));
				notice.appendChild(el('p', '', sprintf(_n('Try again in about %s minute.', 'Try again in about %s minutes.', minutes), formatNumber(minutes))));
			}
			var actions = el('p');
			var retry = el('button', 'button', __('Try again'));
			retry.type = 'button';
			retry.addEventListener('click', load);
			actions.appendChild(retry);
			var link = el('a', '', __('Check API settings'));
			link.href = config.settingsUrl;
			link.className = 'rybbit-dash__settings-link';
			actions.appendChild(link);
			notice.appendChild(actions);
			body.appendChild(notice);
		}

		function render(data) {
			clear(body);
			var overview = data.overview || {};
			var series = data.series || [];
			var hourly = data.range === '24h';

			liveBadge = el('p', 'rybbit-live');
			liveBadge.appendChild(el('span', 'rybbit-live__dot'));
			liveLabel = el('span');
			liveBadge.appendChild(liveLabel);
			setLive(typeof data.live === 'number' ? data.live : null);
			body.appendChild(liveBadge);

			body.appendChild(renderKpis(overview));

			var hasData = series.some(function (point) {
				return point.users > 0 || point.pageviews > 0;
			});
			if (series.length > 1 && hasData) {
				body.appendChild(renderChart(series, hourly));
			} else if (!hasData && !(overview.pageviews > 0)) {
				body.appendChild(el('p', 'rybbit-dash__status', __('No visits in this period yet.')));
			}

			if (data.warnings && data.warnings.length) {
				body.appendChild(el('p', 'rybbit-dash__warning', __('Some data could not be loaded. Try again later.')));
			}

			if (view === 'full' && data.top) {
				var grid = el('div', 'rybbit-tops');
				Object.keys(TOP_TITLES).forEach(function (name) {
					var failed = data.warnings && data.warnings.indexOf(name) !== -1;
					grid.appendChild(renderTopList(name, data.top[name], failed));
				});
				body.appendChild(grid);
			}

			if (note) {
				var minutes = parseInt(config.cacheMinutes, 10) || 10;
				note.textContent = sprintf(_n('Data is cached for up to %s minute.', 'Data is cached for up to %s minutes.', minutes), formatNumber(minutes));
			}
			scheduleLive();
		}

		function load() {
			requestId++;
			var current = requestId;
			window.clearTimeout(liveTimer);
			if (refresh) {
				refresh.disabled = true;
			}
			showStatus(__('Loading analytics…'));

			fetchStats(range, view).then(function (data) {
				if (current === requestId) {
					render(data);
				}
			}, function (error) {
				if (current === requestId) {
					showError(error);
				}
			}).then(function () {
				if (refresh && current === requestId) {
					refresh.disabled = false;
				}
			});
		}

		if (select) {
			select.addEventListener('change', function () {
				range = select.value;
				load();
			});
		}
		if (refresh) {
			refresh.addEventListener('click', load);
		}
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && liveBadge && document.body.contains(root)) {
				scheduleLive();
			}
		});

		load();
	}

	ready(function () {
		forEach(document.querySelectorAll('[data-rybbit-dash]'), initDashboard);
	});
}());
