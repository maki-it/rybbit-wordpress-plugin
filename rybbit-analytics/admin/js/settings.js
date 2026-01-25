/* Rybbit Analytics – Settings page behavior */
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

  function setQueryParam(name, value) {
    try {
      var url = new URL(window.location.href);
      if (value === null || value === undefined || value === '') url.searchParams.delete(name);
      else url.searchParams.set(name, value);
      // Preserve hash; URL() keeps it.
      return url.toString();
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

  function updateAboutVersionUI(result) {
    var aboutPanel = document.querySelector('.rybbit-tab-panel[data-tab="about"]');
    if (!aboutPanel) return;

    var latestEl = aboutPanel.querySelector('.rybbit-latest-version');
    var statusEl = aboutPanel.querySelector('.rybbit-update-status');

    if (!latestEl) return;

    if (!result || result.success !== true) {
      latestEl.textContent = 'unknown';
      if (statusEl) {
        var msg = (result && result.data && result.data.message) ? String(result.data.message) : 'Could not check for updates right now.';
        statusEl.textContent = msg;
        statusEl.className = 'rybbit-update-status description';
      }
      return;
    }

    var data = result.data || {};
    if (typeof data.latest === 'string' && data.latest) {
      latestEl.textContent = data.latest;
    } else {
      latestEl.textContent = 'unknown';
    }

    if (!statusEl) return;

    statusEl.className = 'rybbit-update-status description';
    statusEl.innerHTML = '';

    if (data.updateAvailable === true) {
      statusEl.innerHTML =
        '<strong>Update available.</strong> Get the latest release from ' +
        '<a href="https://github.com/maki-it/rybbit-wordpress-plugin/releases" target="_blank" rel="noopener noreferrer">GitHub Releases</a>.';
    } else if (data.updateAvailable === false) {
      statusEl.textContent = 'You’re up to date.';
    }
  }

  function checkLatestVersion() {
    if (!window.rybbitAdmin || !window.rybbitAdmin.ajaxUrl || !window.rybbitAdmin.nonce) {
      updateAboutVersionUI({ success: false, data: { message: 'Missing ajaxUrl/nonce.' } });
      return;
    }

    var aboutPanel = document.querySelector('.rybbit-tab-panel[data-tab="about"]');
    var latestEl = aboutPanel ? aboutPanel.querySelector('.rybbit-latest-version') : null;
    if (latestEl) latestEl.textContent = 'Checking…';

    var form = new FormData();
    form.append('action', 'rybbit_check_latest_version');
    form.append('nonce', window.rybbitAdmin.nonce);

    fetch(window.rybbitAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form
    })
      .then(function(res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function(json) {
        updateAboutVersionUI(json);
      })
      .catch(function(err) {
        updateAboutVersionUI({ success: false, data: { message: 'Update check error: ' + (err && err.message ? err.message : 'unknown') } });
      });
  }

  function initTabs() {
    var tabs = document.querySelectorAll('.rybbit-nav-tab');
    if (!tabs.length) return;

    tabs.forEach(function(t) {
      t.addEventListener('click', function(e) {
        e.preventDefault();
        var tab = t.getAttribute('data-tab');

        setActiveTab(tab);

        if (tab === 'about') {
          checkLatestVersion();
        }
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

    // If About is the initial tab, trigger the check on load.
    if (initial === 'about') {
      checkLatestVersion();
    }
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

  ready(function() {
    initTabs();
    initUserMetaKeyToggle();
    initIdentifyPreview();
  });
})();