(function () {
  'use strict';

  var currentScript = document.currentScript;
  var shellHref = currentScript ? (currentScript.getAttribute('data-portal-shell-href') || '') : '';
  var canPrefetch = currentScript ? currentScript.getAttribute('data-portal-shell-prefetch') === '1' : false;
  if (!shellHref) return;

  var shellUrl;
  try {
    shellUrl = new URL(shellHref, window.location.href);
  } catch (_) {
    return;
  }
  if (shellUrl.origin !== window.location.origin) return;

  var norm = function (p) { return p.replace(/^\/index\.php(?=\/)/, '').replace(/\/+$/, '') || '/'; };
  var base = norm(shellUrl.pathname);
  var cur = norm(window.location.pathname);
  if (!cur.startsWith(base + '/')) return;

  var sub = cur.slice(base.length + 1);
  if (/\/pdf$/i.test(sub)) return;

  if (/^(?:(?:announcements|surveys|referrals|assessments\/mi|evaluations|visits|call-slips)(?:\/|$)|settings$)/.test(sub)) {
    document.documentElement.classList.add('portal-shell-hydrating');
    window.__portalHydrationFallbackTimer = window.setTimeout(function () {
      document.documentElement.classList.remove('portal-shell-hydrating');
    }, 4000);

    if (canPrefetch && window.fetch) {
      window.__portalShellPrefetch = {
        href: shellUrl.href,
        promise: window.fetch(shellUrl.href, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow',
          headers: {
            Accept: 'text/html',
            'X-Synapse-Navigation': '1',
            'X-Synapse-Portal-Badge-Sync': '1'
          }
        }).catch(function () { return null; })
      };
    }
  }
})();
