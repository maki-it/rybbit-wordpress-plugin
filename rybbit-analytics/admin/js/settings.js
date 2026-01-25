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

  ready(function() {
    initTabs();
    initUserMetaKeyToggle();
  });
})();
