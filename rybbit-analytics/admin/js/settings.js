/* Rybbit Analytics – Settings page behavior */
(function() {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
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
        setActiveTab(t.getAttribute('data-tab'));
      });
    });

    var initial = 'tracking';

    // Prefer hash (#privacy), then sessionStorage.
    if (window.location.hash) {
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
    var inflight = null;

    function setText(text) {
      pre.textContent = text;
    }

    function schedule() {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(refresh, 250);
    }

    function refresh(e) {
      if (e) e.preventDefault();

      if (!window.rybbitAdmin || !window.rybbitAdmin.ajaxUrl || !window.rybbitAdmin.nonce) {
        setText('Preview unavailable (missing ajaxUrl/nonce).');
        return;
      }

      // Abort previous request if possible.
      if (inflight && typeof inflight.abort === 'function') {
        try { inflight.abort(); } catch (err) { /* ignore */ }
      }

      setText('Loading preview…');

      var form = new FormData();
      form.append('action', 'rybbit_preview_identify_payload');
      form.append('nonce', window.rybbitAdmin.nonce);
      form.append('identify_mode', modeEl.value);
      form.append('userid_strategy', strategyEl.value);
      form.append('userid_meta_key', metaKeyEl ? metaKeyEl.value : '');

      inflight = fetch(window.rybbitAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: form
      })
        .then(function(res) {
          return res.json();
        })
        .then(function(json) {
          if (!json || json.success !== true) {
            setText('No preview available.');
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
          setText('Preview error.');
        });
    }

    modeEl.addEventListener('change', schedule);
    strategyEl.addEventListener('change', schedule);
    if (metaKeyEl) metaKeyEl.addEventListener('input', schedule);

    if (refreshLink) refreshLink.addEventListener('click', refresh);

    // Initial render
    refresh();
  }

  ready(function() {
    initTabs();
    initUserMetaKeyToggle();
    initIdentifyPreview();
  });
})();