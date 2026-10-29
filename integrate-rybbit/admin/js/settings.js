/* Integrate Rybbit: settings page behavior. */
(function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;

	function __(text, domain) {
		return i18n ? i18n.__(text, domain) : text;
	}

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		if (i18n && i18n.sprintf) {
			return i18n.sprintf.apply(null, [format].concat(args));
		}
		var index = 0;
		return String(format).replace(/%s/g, function () {
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

	function getQueryParam(name) {
		try {
			return new URLSearchParams(window.location.search || '').get(name);
		} catch (e) {
			return null;
		}
	}

	/*
	 * Tabs follow the WAI-ARIA tabs pattern.
	 * Only the active tab is in the tab order. Arrow keys, Home and End move between tabs.
	 */
	function initTabs() {
		var tabs = document.querySelectorAll('.rybbit-nav-tab');
		var panels = document.querySelectorAll('.rybbit-tab-panel');
		if (!tabs.length) {
			return;
		}

		function setActiveTab(tabId, focus) {
			forEach(tabs, function (tab) {
				var active = tab.getAttribute('data-tab') === tabId;
				tab.classList.toggle('nav-tab-active', active);
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
				tab.setAttribute('tabindex', active ? '0' : '-1');
				if (active && focus) {
					tab.focus();
				}
			});

			forEach(panels, function (panel) {
				panel.hidden = panel.getAttribute('data-tab') !== tabId;
			});

			try {
				window.sessionStorage.setItem('rybbitActiveTab', tabId);
			} catch (e) {
				// Session storage can be unavailable. The tab still works.
			}
		}

		function isKnown(tabId) {
			return Array.prototype.some.call(tabs, function (tab) {
				return tab.getAttribute('data-tab') === tabId;
			});
		}

		forEach(tabs, function (tab, index) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				setActiveTab(tab.getAttribute('data-tab'), false);
			});

			tab.addEventListener('keydown', function (e) {
				var target = null;
				switch (e.key) {
					case 'ArrowRight':
						target = tabs[(index + 1) % tabs.length];
						break;
					case 'ArrowLeft':
						target = tabs[(index - 1 + tabs.length) % tabs.length];
						break;
					case 'Home':
						target = tabs[0];
						break;
					case 'End':
						target = tabs[tabs.length - 1];
						break;
				}
				if (target) {
					e.preventDefault();
					setActiveTab(target.getAttribute('data-tab'), true);
				}
			});
		});

		// Priority: ?tab=privacy, then #privacy, then the last tab of this browser session.
		var initial = getQueryParam('tab');
		if (!initial && window.location.hash) {
			initial = window.location.hash.replace('#', '');
		}
		if (!initial) {
			try {
				initial = window.sessionStorage.getItem('rybbitActiveTab');
			} catch (e) {
				initial = null;
			}
		}
		initial = initial ? String(initial).trim() : '';
		if (!isKnown(initial)) {
			initial = tabs[0].getAttribute('data-tab');
		}
		setActiveTab(initial, false);

		// The browser cannot show a validation message for a field in a hidden panel.
		// Open the panel of the first invalid field so that the user sees the message.
		var form = document.querySelector('.rybbit-settings-card form');
		if (form) {
			form.addEventListener(
				'invalid',
				function (e) {
					var panel = e.target.closest ? e.target.closest('.rybbit-tab-panel') : null;
					if (panel && panel.hidden) {
						setActiveTab(panel.getAttribute('data-tab'), false);
					}
				},
				true
			);
		}
	}

	function initUserMetaKeyToggle() {
		var strategy = document.getElementById('rybbit_identify_userid_strategy');
		var wrap = document.querySelector('[data-rybbit-user-meta-key]');
		if (!strategy || !wrap) {
			return;
		}

		function update() {
			wrap.hidden = strategy.value !== 'user_meta';
		}

		strategy.addEventListener('change', update);
		update();
	}

	function initIdentifyPreview() {
		var pre = document.getElementById('rybbit_identify_payload');
		var modeEl = document.getElementById('rybbit_identify_mode');
		var strategyEl = document.getElementById('rybbit_identify_userid_strategy');
		var metaKeyEl = document.getElementById('rybbit_identify_userid_meta_key');
		var refreshLink = document.querySelector('.rybbit-refresh-payload');
		var config = window.rybbitAdmin || {};
		var timer = null;

		if (!pre || !modeEl || !strategyEl) {
			return;
		}

		function setText(text) {
			pre.textContent = text;
		}

		function refresh(e) {
			if (e && typeof e.preventDefault === 'function') {
				e.preventDefault();
			}

			if (!config.ajaxUrl || !config.nonce || !window.fetch || !window.FormData) {
				setText(__('Preview is not available.', 'integrate-rybbit'));
				return;
			}

			setText(__('Loading preview...', 'integrate-rybbit'));

			var body = new FormData();
			body.append('action', 'rybbit_preview_identify_payload');
			body.append('nonce', config.nonce);
			body.append('identify_mode', modeEl.value);
			body.append('userid_strategy', strategyEl.value);
			body.append('userid_meta_key', metaKeyEl ? metaKeyEl.value : '');

			window
				.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (res) {
					return res.json().catch(function () {
						throw new Error('HTTP ' + res.status);
					});
				})
				.then(function (json) {
					if (!json || json.success !== true) {
						var message = json && json.data && json.data.message ? String(json.data.message) : __('Unknown error', 'integrate-rybbit');
						/* translators: %s: Error message. */
						setText(sprintf(__('No preview available: %s', 'integrate-rybbit'), message));
						return;
					}
					var payload = json.data ? json.data.payload : null;
					setText(payload ? JSON.stringify(payload, null, 2) : __('Nothing is sent with the current settings.', 'integrate-rybbit'));
				})
				.catch(function (err) {
					/* translators: %s: Error message. */
					setText(sprintf(__('Preview error: %s', 'integrate-rybbit'), err && err.message ? err.message : __('Unknown error', 'integrate-rybbit')));
				});
		}

		function schedule() {
			window.clearTimeout(timer);
			timer = window.setTimeout(refresh, 250);
		}

		modeEl.addEventListener('change', schedule);
		strategyEl.addEventListener('change', schedule);
		if (metaKeyEl) {
			metaKeyEl.addEventListener('input', schedule);
		}
		if (refreshLink) {
			refreshLink.addEventListener('click', refresh);
		}

		refresh();
	}

	/*
	 * Buttons of the Dashboard tab. They call the saved API settings.
	 * The text of the answer goes in with textContent.
	 */
	function initApiTools() {
		var result = document.getElementById('rybbit-api-result');
		var config = window.rybbitAdmin || {};
		var buttons = [
			{ id: 'rybbit-test-api', action: 'rybbit_test_api_connection', busy: __('Testing…', 'integrate-rybbit') },
			{ id: 'rybbit-clear-api-cache', action: 'rybbit_clear_api_cache', busy: __('Clearing…', 'integrate-rybbit') }
		];

		if (!result) {
			return;
		}

		function show(text, isError) {
			result.textContent = text;
			result.classList.toggle('rybbit-api-result--error', !!isError);
			result.classList.toggle('rybbit-api-result--ok', !isError);
		}

		buttons.forEach(function (item) {
			var button = document.getElementById(item.id);
			if (!button) {
				return;
			}

			button.addEventListener('click', function () {
				if (!config.ajaxUrl || !config.nonce || !window.fetch || !window.FormData) {
					show(__('This action is not available.', 'integrate-rybbit'), true);
					return;
				}

				var body = new FormData();
				body.append('action', item.action);
				body.append('nonce', config.nonce);

				button.disabled = true;
				show(item.busy, false);

				window
					.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (res) {
						return res.json().catch(function () {
							throw new Error('HTTP ' + res.status);
						});
					})
					.then(function (json) {
						var message = json && json.data && json.data.message ? String(json.data.message) : __('Unknown error', 'integrate-rybbit');
						show(message, !json || json.success !== true);
					})
					.catch(function (err) {
						show(err && err.message ? err.message : __('Unknown error', 'integrate-rybbit'), true);
					})
					.then(function () {
						button.disabled = false;
					});
			});
		});
	}

	function decodeBase64Utf8(b64) {
		try {
			var binary = window.atob(b64);
			var escaped = Array.prototype.map
				.call(binary, function (ch) {
					return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
				})
				.join('');
			return decodeURIComponent(escaped);
		} catch (e) {
			return '';
		}
	}

	function initResetButtons() {
		forEach(document.querySelectorAll('.rybbit-reset-json'), function (button) {
			button.addEventListener('click', function (e) {
				e.preventDefault();

				var target = document.querySelector(button.getAttribute('data-rybbit-reset-target') || '');
				if (!target) {
					return;
				}

				var value = decodeBase64Utf8(button.getAttribute('data-rybbit-reset-value-b64') || '');
				if ((target.value || '').trim() && !window.confirm(__('Replace the current value with the default?', 'integrate-rybbit'))) {
					return;
				}

				target.value = value;
				target.dispatchEvent(new Event('input', { bubbles: true }));
				target.focus();
			});
		});
	}

	function initJsonValidation() {
		var form = document.querySelector('.rybbit-settings-card form');
		if (!form) {
			return;
		}

		var fields = [
			{ id: 'rybbit_replay_mask_input_options', allowBoolean: false },
			{ id: 'rybbit_replay_sampling', allowBoolean: false },
			{ id: 'rybbit_replay_slim_dom_options', allowBoolean: true }
		];

		function errorElement(field) {
			var id = field.id + '-json-error';
			var el = document.getElementById(id);
			if (el) {
				return el;
			}

			el = document.createElement('p');
			el.id = id;
			el.className = 'description rybbit-json-error';
			el.hidden = true;
			field.insertAdjacentElement('afterend', el);

			var describedBy = field.getAttribute('aria-describedby');
			field.setAttribute('aria-describedby', describedBy ? describedBy + ' ' + id : id);
			return el;
		}

		function setError(field, message) {
			var el = errorElement(field);
			el.textContent = message;
			el.hidden = !message;
			field.classList.toggle('rybbit-json-invalid', !!message);
			if (message) {
				field.setAttribute('aria-invalid', 'true');
			} else {
				field.removeAttribute('aria-invalid');
			}
			return !message;
		}

		function validate(field, cfg) {
			var raw = (field.value || '').trim();
			if (!raw) {
				return setError(field, '');
			}
			if (cfg.allowBoolean && /^(true|false)$/i.test(raw)) {
				return setError(field, '');
			}

			var parsed;
			try {
				parsed = JSON.parse(raw);
			} catch (e) {
				return setError(field, __('This value must be valid JSON.', 'integrate-rybbit'));
			}

			if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
				return setError(field, __('This value must be a JSON object, for example {"key": true}.', 'integrate-rybbit'));
			}

			return setError(field, '');
		}

		var active = [];
		fields.forEach(function (cfg) {
			var field = document.getElementById(cfg.id);
			if (!field) {
				return;
			}
			active.push({ field: field, cfg: cfg });
			field.addEventListener('input', function () {
				validate(field, cfg);
			});
			field.addEventListener('blur', function () {
				validate(field, cfg);
			});
			validate(field, cfg);
		});

		form.addEventListener('submit', function (e) {
			var firstInvalid = null;
			active.forEach(function (item) {
				if (!validate(item.field, item.cfg) && !firstInvalid) {
					firstInvalid = item.field;
				}
			});

			if (firstInvalid) {
				e.preventDefault();
				var panel = firstInvalid.closest('.rybbit-tab-panel');
				var tab = panel ? document.getElementById(panel.getAttribute('aria-labelledby')) : null;
				if (tab && panel.hidden) {
					tab.click();
				}
				firstInvalid.focus();
			}
		});
	}

	/*
	 * Custom event rules. The limits match the PHP limits in Integrate_Rybbit_Options.
	 * The server checks them again when it saves the form.
	 */
	function initEventRules() {
		var wrap = document.querySelector('[data-rybbit-event-rules]');
		var form = document.querySelector('.rybbit-settings-card form');
		if (!wrap || !form) {
			return;
		}

		var list = wrap.querySelector('[data-rybbit-event-list]');
		var template = wrap.querySelector('[data-rybbit-event-template]');
		var addButton = wrap.querySelector('[data-rybbit-event-add]');
		var emptyHint = wrap.querySelector('[data-rybbit-event-empty]');
		if (!list || !template || !addButton) {
			return;
		}

		var MAX_PROP_KEY_LENGTH = 128;
		var MAX_PROPS_BYTES = 2048;
		var maxRules = parseInt(wrap.getAttribute('data-max'), 10) || 50;
		var nextIndex = parseInt(wrap.getAttribute('data-next-index'), 10) || 0;

		function rules() {
			return list.querySelectorAll('[data-rybbit-event-rule]');
		}

		function fieldOf(rule, name) {
			return rule.querySelector('[data-rybbit-event-' + name + ']');
		}

		function byteLength(text) {
			return window.TextEncoder ? new window.TextEncoder().encode(text).length : text.length;
		}

		function errorElement(field) {
			var id = field.id + '-error';
			var el = document.getElementById(id);
			if (el) {
				return el;
			}

			el = document.createElement('p');
			el.id = id;
			el.className = 'description rybbit-json-error';
			el.hidden = true;
			field.parentNode.insertAdjacentElement('afterend', el);

			var describedBy = field.getAttribute('aria-describedby');
			field.setAttribute('aria-describedby', describedBy ? describedBy + ' ' + id : id);
			return el;
		}

		function setError(field, message) {
			var el = errorElement(field);
			el.textContent = message;
			el.hidden = !message;
			field.classList.toggle('rybbit-json-invalid', !!message);
			if (message) {
				field.setAttribute('aria-invalid', 'true');
			} else {
				field.removeAttribute('aria-invalid');
			}
			return !message;
		}

		function propsError(text) {
			var props = {};
			var lines = text.split(/\r\n|\r|\n/);
			var message = __('Use one key=value pair per line. A key can have up to 128 characters.', 'integrate-rybbit');

			for (var i = 0; i < lines.length; i++) {
				var line = lines[i].trim();
				if (!line) {
					continue;
				}

				var pos = line.indexOf('=');
				var key = pos > 0 ? line.slice(0, pos).trim() : '';
				if (!key || key.length > MAX_PROP_KEY_LENGTH) {
					return message;
				}
				props[key] = line.slice(pos + 1).trim();
			}

			if (byteLength(JSON.stringify(props)) > MAX_PROPS_BYTES) {
				return __('The properties are larger than 2 KB.', 'integrate-rybbit');
			}

			return '';
		}

		function isBlank(rule) {
			return ['selector', 'name', 'props'].every(function (name) {
				var field = fieldOf(rule, name);
				return !field || !field.value.trim();
			});
		}

		// strict also reports empty required fields. Without it, only wrong values are reported.
		function validateField(field, strict) {
			var rule = field.closest('[data-rybbit-event-rule]');
			var value = field.value.trim();

			if (!rule || isBlank(rule)) {
				return setError(field, '');
			}

			if (field.hasAttribute('data-rybbit-event-selector')) {
				if (!value) {
					return setError(field, strict ? __('Enter a CSS selector.', 'integrate-rybbit') : '');
				}
				try {
					document.querySelector(value);
				} catch (e) {
					return setError(field, __('This CSS selector is not valid.', 'integrate-rybbit'));
				}
				return setError(field, '');
			}

			if (field.hasAttribute('data-rybbit-event-name')) {
				return setError(field, !value && strict ? __('Enter an event name.', 'integrate-rybbit') : '');
			}

			return setError(field, propsError(value));
		}

		function validateRule(rule, strict) {
			var firstInvalid = null;
			['selector', 'name', 'props'].forEach(function (name) {
				var field = fieldOf(rule, name);
				if (field && !validateField(field, strict) && !firstInvalid) {
					firstInvalid = field;
				}
			});
			return firstInvalid;
		}

		function update() {
			var count = rules().length;
			if (emptyHint) {
				emptyHint.hidden = count > 0;
			}
			addButton.disabled = count >= maxRules;
		}

		addButton.addEventListener('click', function () {
			if (rules().length >= maxRules) {
				return;
			}

			var holder = document.createElement('div');
			holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
			nextIndex++;

			var rule = holder.querySelector('[data-rybbit-event-rule]');
			if (!rule) {
				return;
			}

			list.appendChild(rule);
			update();

			var selector = fieldOf(rule, 'selector');
			if (selector) {
				selector.focus();
			}
		});

		list.addEventListener('click', function (e) {
			var button = e.target.closest ? e.target.closest('[data-rybbit-event-remove]') : null;
			var rule = button ? button.closest('[data-rybbit-event-rule]') : null;
			if (!rule) {
				return;
			}

			// Keep the focus inside the editor after the row is gone.
			var neighbour = rule.nextElementSibling || rule.previousElementSibling;
			var focusTarget = neighbour ? fieldOf(neighbour, 'selector') : null;

			rule.parentNode.removeChild(rule);
			update();
			(focusTarget || addButton).focus();
		});

		list.addEventListener('input', function (e) {
			var rule = e.target.closest ? e.target.closest('[data-rybbit-event-rule]') : null;
			if (!rule) {
				return;
			}

			// Clear old errors while the user types. New errors appear when the field loses focus.
			forEach(rule.querySelectorAll('[aria-invalid]'), function (field) {
				validateField(field, true);
			});
		});

		list.addEventListener('focusout', function (e) {
			if (e.target.matches && e.target.matches('input, textarea')) {
				validateField(e.target, false);
			}
		});

		form.addEventListener('submit', function (e) {
			var firstInvalid = null;
			forEach(rules(), function (rule) {
				var invalid = validateRule(rule, true);
				if (invalid && !firstInvalid) {
					firstInvalid = invalid;
				}
			});

			if (firstInvalid) {
				e.preventDefault();
				var panel = firstInvalid.closest('.rybbit-tab-panel');
				var tab = panel ? document.getElementById(panel.getAttribute('aria-labelledby')) : null;
				if (tab && panel.hidden) {
					tab.click();
				}
				firstInvalid.focus();
			}
		});

		update();
	}

	ready(function () {
		initTabs();
		initUserMetaKeyToggle();
		initIdentifyPreview();
		initApiTools();
		initResetButtons();
		initJsonValidation();
		initEventRules();
	});
})();
