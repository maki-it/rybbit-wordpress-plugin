/* Integrate Rybbit – Settings page behavior */
(function() {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function getQueryParam(name) {
    try {
      var params = new URLSearchParams(window.location.search || '');
      return params.get(name);
    } catch (e) {
      return null;
    }
  }

  function setActiveTab(tabId) {
    var tabs = document.querySelectorAll('.rybbit-nav-tab');
    var panels = document.querySelectorAll('.rybbit-tab-panel');

    tabs.forEach(function(t) {
      var isActive = t.getAttribute('data-tab') === tabId;
      t.classList.toggle('nav-tab-active', isActive);
      t.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    panels.forEach(function(p) {
      var isActive = p.getAttribute('data-tab') === tabId;
      p.style.display = isActive ? '' : 'none';
    });

    try {
      window.sessionStorage.setItem('rybbitActiveTab', tabId);
    } catch (e) {
      // ignore
    }
  }

  function initTabs() {
    var tabs = document.querySelectorAll('.rybbit-nav-tab');
    if (!tabs.length) return;

    tabs.forEach(function(t) {
      t.addEventListener('click', function(e) {
        e.preventDefault();
        var tab = t.getAttribute('data-tab');

        setActiveTab(tab);
      });
    });

    var initial = 'tracking';

    // Prefer explicit querystring (?tab=privacy), then hash (#privacy), then sessionStorage.
    var fromQuery = getQueryParam('tab');
    if (fromQuery) {
      initial = fromQuery.trim();
    } else if (window.location.hash) {
      var fromHash = window.location.hash.replace('#', '').trim();
      if (fromHash) initial = fromHash;
    } else {
      try {
        var stored = window.sessionStorage.getItem('rybbitActiveTab');
        if (stored) initial = stored;
      } catch (e) {
        // ignore
      }
    }

    // Fallback if unknown
    var known = Array.prototype.some.call(tabs, function(t) {
      return t.getAttribute('data-tab') === initial;
    });
    if (!known) initial = tabs[0].getAttribute('data-tab');

    setActiveTab(initial);
  }

  function initUserMetaKeyToggle() {
    var strategy = document.getElementById('rybbit_identify_userid_strategy');
    var wrap = document.querySelector('[data-rybbit-user-meta-key]');
    if (!strategy || !wrap) return;

    function update() {
      var show = strategy.value === 'user_meta';
      wrap.style.display = show ? '' : 'none';
    }

    strategy.addEventListener('change', update);
    update();
  }

  function initIdentifyPreview() {
    var pre = document.getElementById('rybbit_identify_payload');
    if (!pre) return;

    var modeEl = document.getElementById('rybbit_identify_mode');
    var strategyEl = document.getElementById('rybbit_identify_userid_strategy');
    var metaKeyEl = document.getElementById('rybbit_identify_userid_meta_key');

    if (!modeEl || !strategyEl) return;

    var refreshLink = document.querySelector('.rybbit-refresh-payload');

    var timer = null;

    function setText(text) {
      pre.textContent = text;
    }

    function schedule() {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(refresh, 250);
    }

    function refresh(e) {
      if (e && typeof e.preventDefault === 'function') e.preventDefault();

      if (!window.rybbitAdmin || !window.rybbitAdmin.ajaxUrl || !window.rybbitAdmin.nonce) {
        setText('Preview unavailable (missing ajaxUrl/nonce).');
        return;
      }

      setText('Loading preview…');

      var form = new FormData();
      form.append('action', 'rybbit_preview_identify_payload');
      form.append('nonce', window.rybbitAdmin.nonce);
      form.append('identify_mode', modeEl.value);
      form.append('userid_strategy', strategyEl.value);
      form.append('userid_meta_key', metaKeyEl ? metaKeyEl.value : '');

      fetch(window.rybbitAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: form
      })
        .then(function(res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.json();
        })
        .then(function(json) {
          if (!json || json.success !== true) {
            var msg = (json && json.data && json.data.message) ? String(json.data.message) : 'Unknown error';
            setText('No preview available. ' + msg);
            return;
          }
          var payload = json.data ? json.data.payload : null;
          if (!payload) {
            setText('null');
            return;
          }
          setText(JSON.stringify(payload, null, 2));
        })
        .catch(function(err) {
          setText('Preview error: ' + (err && err.message ? err.message : 'unknown'));
        });
    }

    modeEl.addEventListener('change', schedule);
    strategyEl.addEventListener('change', schedule);
    if (metaKeyEl) metaKeyEl.addEventListener('input', schedule);

    if (refreshLink) {
      refreshLink.addEventListener('click', refresh);
    }

    // Initial render
    refresh();
  }

  function initJsonResetButtons() {
    var buttons = document.querySelectorAll('.rybbit-reset-json');
    if (!buttons.length) return;

    function decodeBase64Utf8(b64) {
      if (!b64) return '';
      try {
        // atob gives a binary string; decodeURIComponent trick restores UTF-8.
        var binary = window.atob(b64);
        var bytes = Array.prototype.map.call(binary, function(ch) {
          return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
        }).join('');
        return decodeURIComponent(bytes);
      } catch (e) {
        try {
          return window.atob(b64);
        } catch (e2) {
          return '';
        }
      }
    }

    buttons.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();

        var targetSel = btn.getAttribute('data-rybbit-reset-target');
        if (!targetSel) return;

        var target = document.querySelector(targetSel);
        if (!target) return;

        // Prefer base64 (safe for multiline JSON). Fallback to legacy plain value.
        var valueB64 = btn.getAttribute('data-rybbit-reset-value-b64');
        var value = valueB64 ? decodeBase64Utf8(valueB64) : btn.getAttribute('data-rybbit-reset-value');

        if (value == null) value = '';

        // Confirm if field already has content.
        var existing = (target.value || '').trim();
        if (existing) {
          var ok = window.confirm('Replace the current value with the documented default?');
          if (!ok) return;
        }

        target.value = value;

        try {
          target.dispatchEvent(new Event('input', { bubbles: true }));
          target.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (err) {
          // ignore
        }

        target.focus();
      });
    });
  }

  function initJsonValidation() {
    var form = document.querySelector('form[action="options.php"]');
    if (!form) return;

    var fieldConfigs = [
      {
        id: 'rybbit_replay_mask_input_options',
        label: 'Mask input options',
        allowEmpty: true,
        allowBoolean: false,
        expect: 'object'
      },
      {
        id: 'rybbit_replay_sampling',
        label: 'Sampling',
        allowEmpty: true,
        allowBoolean: false,
        expect: 'object'
      },
      {
        id: 'rybbit_replay_slim_dom_options',
        label: 'SlimDOM options',
        allowEmpty: true,
        allowBoolean: true,
        expect: 'object'
      }
    ];

    function ensureErrorEl(field) {
      var existing = field.parentElement && field.parentElement.querySelector('.rybbit-json-error');
      if (existing) return existing;

      var el = document.createElement('p');
      el.className = 'description rybbit-json-error';
      el.style.color = '#b32d2e';
      el.style.marginTop = '6px';
      el.style.display = 'none';
      field.insertAdjacentElement('afterend', el);
      return el;
    }

    function parseAndValidate(field, cfg) {
      var errEl = ensureErrorEl(field);
      var raw = (field.value || '').trim();

      // reset
      field.classList.remove('rybbit-json-invalid');
      field.removeAttribute('aria-invalid');
      errEl.style.display = 'none';
      errEl.textContent = '';

      if (!raw) {
        if (cfg.allowEmpty) return true;
        errEl.textContent = cfg.label + ' is required.';
        errEl.style.display = '';
        field.classList.add('rybbit-json-invalid');
        field.setAttribute('aria-invalid', 'true');
        return false;
      }

      var lower = raw.toLowerCase();
      if (cfg.allowBoolean && (lower === 'true' || lower === 'false')) {
        return true;
      }

      var parsed;
      try {
        parsed = JSON.parse(raw);
      } catch (e) {
        errEl.textContent = cfg.label + ' must be valid JSON.';
        errEl.style.display = '';
        field.classList.add('rybbit-json-invalid');
        field.setAttribute('aria-invalid', 'true');
        return false;
      }

      if (cfg.expect === 'object') {
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
          errEl.textContent = cfg.label + ' must be a JSON object (e.g. {"key": true}).';
          errEl.style.display = '';
          field.classList.add('rybbit-json-invalid');
          field.setAttribute('aria-invalid', 'true');
          return false;
        }
      }

      return true;
    }

    function validateAll() {
      var allOk = true;
      fieldConfigs.forEach(function(cfg) {
        var field = document.getElementById(cfg.id);
        if (!field) return;
        var ok = parseAndValidate(field, cfg);
        if (!ok) allOk = false;
      });
      return allOk;
    }

    // Live validation
    fieldConfigs.forEach(function(cfg) {
      var field = document.getElementById(cfg.id);
      if (!field) return;
      field.addEventListener('input', function() {
        parseAndValidate(field, cfg);
      });
      field.addEventListener('blur', function() {
        parseAndValidate(field, cfg);
      });

      // Initial state
      parseAndValidate(field, cfg);
    });

    // Gate submit
    form.addEventListener('submit', function(e) {
      if (!validateAll()) {
        e.preventDefault();
        e.stopPropagation();

        // Focus the first invalid field
        var firstInvalid = form.querySelector('.rybbit-json-invalid');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
      }
    });
  }

  ready(function() {
    initTabs();
    initUserMetaKeyToggle();
    initIdentifyPreview();
    initJsonResetButtons();
    initJsonValidation();
  });
})();