/** Page-owned CSS: prepare inactive assets, then commit with their visible content. */
(function () {
  'use strict';
  if (window.SynapsePageStyles) return;
  const MAX_STYLES = 64;
  const MAX_IMPORTS = 64;
  const MAX_CSS_BYTES = 2 * 1024 * 1024;
  const owners = new Map();

  function ownedNodes(owner) {
    if (!owner) return [];
    if (owners.has(owner)) return owners.get(owner);
    if (owner.matches?.('template[data-page-style-owner="head"]')) {
      if (owner.parentNode?.nodeName !== 'HEAD') throw new Error('Invalid page stylesheet boundary.');
      const nodes = [];
      for (let node = owner.nextSibling; node; node = node.nextSibling) {
        if (node.nodeType === 1 && node.matches('template[data-page-styles-end]')) return nodes;
        nodes.push(node);
      }
      throw new Error('Incomplete page stylesheet boundary.');
    }
    const metadata = owner.querySelector?.(':scope > template[data-page-style-assets]');
    if (metadata) return Array.from(metadata.content.childNodes);
    return Array.from(owner.content?.childNodes || owner.childNodes);
  }

  function copyStyles(nodes) {
    const result = document.createDocumentFragment();
    let count = 0;
    for (const node of nodes) {
      if (node.nodeType === 8 || (node.nodeType === 3 && !node.textContent.trim())) continue;
      if (node.nodeType !== 1 || !node.matches('style, link[rel~="stylesheet"]') || ++count > MAX_STYLES) {
        throw new Error('Invalid page stylesheet content.');
      }
      if (node.id === 'page-styles' || Array.from(node.attributes).some(attribute => /^on/i.test(attribute.name))
          || node.querySelector('script, iframe, object, embed, base')) {
        throw new Error('Invalid page stylesheet content.');
      }
      const copy = document.importNode(node, true);
      copy.removeAttribute('data-page-style-stage');
      result.append(copy);
    }
    return result;
  }

  function collect(root = document, ownerId = 'page-styles') {
    if (root.nodeType === 11) return copyStyles(Array.from(root.childNodes));
    const owner = root.nodeType === 1 ? root : root.getElementById?.(ownerId);
    // Live CSS may use stable import wrappers. Collection retains the original
    // dependency markup so portal clones and later loads do not nest wrappers.
    if (owner?.matches?.('template[data-page-style-owner="head"]') && owner.content.childNodes.length) {
      return copyStyles(Array.from(owner.content.childNodes));
    }
    const metadata = owner?.querySelector?.(':scope > template[data-page-style-assets]');
    return copyStyles(metadata ? Array.from(metadata.content.childNodes) : ownedNodes(owner));
  }

  function cssUrl(value, baseUrl) {
    const url = new URL(value, baseUrl);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) {
      throw new Error('Page styles must stay on this site.');
    }
    url.hash = '';
    return url.href;
  }

  function replaceStyles(target, nodes, metadata) {
    const old = ownedNodes(target);
    if (target.matches?.('template[data-page-style-owner="head"]')) {
      let end = target.nextSibling;
      while (end && !(end.nodeType === 1 && end.matches('template[data-page-styles-end]'))) end = end.nextSibling;
      if (!end) throw new Error('Incomplete page stylesheet boundary.');
      // Reparenting a loaded link/style can discard its import tree, including
      // cache misses. Move only the ownership markers; CSS stays connected in head.
      if (nodes.length) {
        nodes[0].before(target);
        nodes[nodes.length - 1].after(end);
      }
      target.content.replaceChildren(metadata || copyStyles(nodes));
    } else {
      const mirror = document.createElement('template');
      mirror.setAttribute('data-page-style-assets', '');
      mirror.content.append(metadata || copyStyles(nodes));
      const container = target.content || target;
      container.replaceChildren(mirror);
      owners.set(target, nodes);
    }
    old.forEach(node => node.remove());
  }

  // Pane/modal owners live in replaced main or dialog subtrees. Their CSS stays
  // in head but must be released when those owners are removed by another feature.
  new MutationObserver(() => {
    for (const [owner, nodes] of owners) {
      if (owner.isConnected) continue;
      nodes.forEach(node => node.remove());
      owners.delete(owner);
    }
  }).observe(document.documentElement, { childList: true, subtree: true });

  async function stage(fragment, { signal, baseUrl = location.href, timeoutMs = 20000 } = {}) {
    const sourceStyles = Array.from(collect(fragment).childNodes);
    const styles = sourceStyles.map(node => {
      if (!node.matches('link')) return node;
      // Media changes on a loaded <link> can restart cold/imported CSS loads.
      // An import wrapper has stable CSSOM activation while retaining native URL
      // resolution for its nested imports and assets.
      if (node.hasAttribute('integrity') || node.hasAttribute('crossorigin')) {
        throw new Error('This stylesheet requires full-document loading.');
      }
      const href = cssUrl(node.getAttribute('href'), baseUrl);
      const wrapper = document.createElement('style');
      for (const attribute of node.attributes) {
        if (attribute.name === 'media' || attribute.name.startsWith('data-')) wrapper.setAttribute(attribute.name, attribute.value);
      }
      wrapper.textContent = '@import url(' + JSON.stringify(href) + ');';
      return wrapper;
    });
    const controller = new AbortController();
    let committed = false, disposed = false;
    const original = styles.map((node, index) => ({ node, source: sourceStyles[index], media: node.getAttribute('media'),
      disabled: Boolean(sourceStyles[index].disabled) }));
    const abort = () => controller.abort();
    const dispose = () => {
      if (committed || disposed) return;
      disposed = true;
      controller.abort();
      signal?.removeEventListener('abort', abort);
      styles.forEach(node => node.remove());
    };
    if (signal?.aborted) controller.abort();
    else signal?.addEventListener('abort', abort, { once: true });
    const timeout = setTimeout(abort, Math.min(20000, Math.max(1, timeoutMs)));
    const imported = new Map();
    let importCount = 0;

    async function inspectImports(sheet, depth = 0, seen = new Set()) {
      if (!sheet || depth > 12) throw new Error('Page stylesheet imports could not be loaded.');
      if (seen.has(sheet)) return;
      seen.add(sheet);
      let rules;
      try { rules = Array.from(sheet.cssRules); }
      catch (_) { throw new Error('Page stylesheet imports could not be verified.'); }
      for (const rule of rules) {
        if (rule.type !== 3) continue;
        const href = cssUrl(rule.href, sheet.href || baseUrl);
        if (!imported.has(href)) {
          if (++importCount > MAX_IMPORTS) throw new Error('Too many page stylesheet imports.');
          imported.set(href, (async () => {
            // Native load includes dependencies, but a failed @import can still emit
            // load on its parent. Verify status without evaluating imported CSS/text.
            const response = await fetch(href, { credentials: 'same-origin', cache: 'force-cache', redirect: 'error',
              signal: controller.signal, headers: { Accept: 'text/css' } });
            if (!response.ok || response.redirected || !/\btext\/css\b/i.test(response.headers.get('Content-Type') || '')) {
              throw new Error('A page stylesheet import could not be loaded.');
            }
            const size = Number(response.headers.get('Content-Length') || 0);
            if (size > MAX_CSS_BYTES) throw new Error('Page stylesheet import is too large.');
            const text = await response.text();
            if (text.length > MAX_CSS_BYTES) throw new Error('Page stylesheet import is too large.');
          })());
        }
        await imported.get(href);
        await inspectImports(rule.styleSheet, depth + 1, seen);
      }
    }

    function connect(record) {
      const { node } = record;
      node.setAttribute('media', 'not all');
      node.setAttribute('data-page-style-stage', '');
      return new Promise((resolve, reject) => {
        let settled = false;
        function cleanup() {
          node.removeEventListener('load', loaded);
          node.removeEventListener('error', failed);
          controller.signal.removeEventListener('abort', cancelled);
        }
        function finish(error) {
          if (settled) return;
          settled = true;
          cleanup();
          if (error) reject(error); else resolve();
        }
        const loaded = () => finish();
        const failed = () => finish(new Error('A page stylesheet could not be loaded.'));
        const cancelled = () => finish(new Error('Page stylesheet loading was interrupted.'));
        node.addEventListener('load', loaded);
        node.addEventListener('error', failed);
        controller.signal.addEventListener('abort', cancelled, { once: true });
        if (controller.signal.aborted) { cancelled(); return; }
        document.head.append(node);
        if (node.matches('style')) {
          let hasImports = true;
          try { hasImports = Array.from(node.sheet.cssRules).some(rule => rule.type === 3); } catch (_) { /* Native load/error remains authoritative. */ }
          if (!hasImports) finish();
        }
      });
    }

    try {
      await Promise.all(original.map(connect));
      for (const node of styles) await inspectImports(node.sheet);
      if (controller.signal.aborted || disposed) throw new Error('Page stylesheet loading was interrupted.');
      return {
        commit(target) {
          if (committed || disposed || controller.signal.aborted || !target) throw new Error('Page styles are no longer ready.');
          const metadata = document.createDocumentFragment();
          for (const { node, source, media, disabled } of original) {
            const copy = source.cloneNode(true);
            if (media === null) copy.removeAttribute('media'); else copy.setAttribute('media', media);
            copy.disabled = disabled;
            copy.removeAttribute('data-page-style-stage');
            metadata.append(copy);
          }
          replaceStyles(target, styles, metadata);
          for (const { node, media, disabled } of original) {
            if (media === null) node.removeAttribute('media'); else node.setAttribute('media', media);
            if (node.sheet.disabled !== disabled) node.sheet.disabled = disabled;
            node.removeAttribute('data-page-style-stage');
          }
          committed = true;
          signal?.removeEventListener('abort', abort);
        },
        dispose,
      };
    } catch (error) {
      dispose();
      signal?.removeEventListener('abort', abort);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  window.SynapsePageStyles = Object.freeze({ collect, stage,
    nodes(target) { return ownedNodes(target).filter(node => node.nodeType === 1 && node.matches('style, link[rel~="stylesheet"]')); },
    clear(target) { replaceStyles(target, []); },
  });
})();
