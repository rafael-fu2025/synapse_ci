/** Local, decorative BMG gears. No telemetry, hardware, batch state, or animation. */
(function () {
  'use strict';
  if (window.SynapseBmgGear) { window.SynapseBmgGear.init(); return; }
  const script = document.currentScript;
  const defaultUrl = script?.src ? new URL('bmg-gear-renderer.js', script.src).href : null;
  const configuredUrl = script?.dataset.rendererUrl;
  const label = 'Illustration only \u2014 motor state and RPM are not measured.';
  const instances = new Map();
  const modules = new Map();
  let observer = null;

  function rendererUrl(wrapper) {
    const raw = wrapper.dataset.rendererUrl || configuredUrl || defaultUrl;
    if (!raw) throw Error('Missing renderer URL');
    const url = new URL(raw, document.baseURI);
    if (url.origin !== location.origin || url.username || url.password
      || !/^https?:$/.test(url.protocol)) throw Error('Renderer must be local');
    return url.href;
  }
  function load(url) {
    // Cache rejected imports too: missing assets must not become retry/request loops.
    if (!modules.has(url)) modules.set(url, import(url));
    return modules.get(url);
  }
  function fallback(wrapper, canvas) {
    wrapper.removeAttribute('data-gear-ready');
    canvas.hidden = true;
  }
  function mount(wrapper) {
    if (instances.has(wrapper)) return;
    const canvas = wrapper.querySelector('[data-bmg-gear-canvas]');
    if (!canvas) return;
    wrapper.title = label;
    wrapper.setAttribute('aria-hidden', 'true');
    fallback(wrapper, canvas);
    const state = { handle: null, cancelled: false, canvas };
    instances.set(wrapper, state);
    let url;
    try { url = rendererUrl(wrapper); } catch (_) { return; }
    load(url).then(module => {
      if (state.cancelled || !wrapper.isConnected) return;
      const rawSeed = wrapper.dataset.gearSeed;
      const seed = /^-?\d+$/.test(rawSeed || '') && Number.isSafeInteger(Number(rawSeed)) ? Number(rawSeed) : 0;
      state.handle = module.attachGear(canvas, {
        seed,
        onReady() {
          if (state.cancelled || !wrapper.isConnected) return;
          canvas.hidden = false;
          wrapper.setAttribute('data-gear-ready', '');
        },
        onFail() { fallback(wrapper, canvas); }
      });
    }).catch(() => { if (!state.cancelled) fallback(wrapper, canvas); });
  }
  function remove(wrapper, state) {
    state.cancelled = true;
    state.handle?.dispose();
    fallback(wrapper, state.canvas);
    instances.delete(wrapper);
  }
  function prune() {
    for (const [wrapper, state] of instances) if (!wrapper.isConnected) remove(wrapper, state);
    if (!instances.size) { observer?.disconnect(); observer = null; }
  }
  function init(root = document) {
    prune();
    if (root.matches?.('[data-bmg-gear]')) mount(root);
    root.querySelectorAll?.('[data-bmg-gear]').forEach(mount);
    // Also release resources for direct DOM removals outside the SPA lifecycle.
    if (instances.size && !observer) {
      observer = new MutationObserver(prune);
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }
  function destroy(root = document) {
    for (const [wrapper, state] of instances) {
      if (root === wrapper || root.contains(wrapper)) remove(wrapper, state);
    }
    if (!instances.size) { observer?.disconnect(); observer = null; }
  }
  window.SynapseBmgGear = Object.freeze({ init, destroy });
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root || document));
  window.addEventListener('pagehide', () => destroy());
  window.addEventListener('pageshow', () => init());
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
