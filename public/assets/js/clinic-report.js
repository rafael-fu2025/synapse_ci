/** Clinic Periodic & CHED Report: zero-reload horizon switching + custom SVG monthly telemetry. */
(function () {
  'use strict';

  const instances = new Map();
  const SVG_NS = 'http://www.w3.org/2000/svg';

  function niceMax(value) {
    if (value <= 4) return 4;
    const mag = Math.pow(10, Math.floor(Math.log10(value)));
    const norm = value / mag;
    if (norm <= 1.2) return Math.ceil(1.2 * mag);
    if (norm <= 1.5) return Math.ceil(1.5 * mag);
    if (norm <= 2) return 2 * mag;
    if (norm <= 2.5) return Math.ceil(2.5 * mag);
    if (norm <= 3) return 3 * mag;
    if (norm <= 4) return 4 * mag;
    if (norm <= 5) return 5 * mag;
    if (norm <= 6) return 6 * mag;
    if (norm <= 8) return 8 * mag;
    return 10 * mag;
  }

  function svgEl(tag, attrs = {}) {
    const el = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, String(v));
    return el;
  }

  function isoToDmy(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((iso || '').trim());
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
  }

  function routeOf(pathname) {
    return String(pathname || '').replace(/^\/index\.php(?=\/)/, '');
  }

  function shortMonthLabel(label) {
    const m = /^([A-Za-z]{3})\s+\d{4}$/.exec((label || '').trim());
    return m ? m[1] : label;
  }

  function renderMonthlyViz(container, signal) {
    const host = container.querySelector('[data-cr-svg-host]');
    const tooltip = container.querySelector('[data-cr-tooltip]');
    if (!host) return;
    let payload;
    try { payload = JSON.parse(container.dataset.series || '{}'); } catch (_) { return; }
    const series = Array.isArray(payload.series) ? payload.series : [];
    if (!series.length) return;

    const total = Number(payload.total) || series.reduce((acc, item) => acc + (Number(item.count) || 0), 0);
    const avg = Number(payload.avg) || (series.length ? total / series.length : 0);
    const counts = series.map(item => Number(item.count) || 0);
    const maxVal = Math.max(...counts, 1);
    const peakIndex = counts.indexOf(maxVal);
    const yMax = niceMax(maxVal * 1.14);

    let drawEvents = null;
    let lastWidth = 0;

    function draw() {
      drawEvents?.abort();
      drawEvents = new AbortController();
      const drawSignal = drawEvents.signal;

      const measuredW = Math.round(host.clientWidth) || 860;
      lastWidth = measuredW;
      const isCompact = measuredW < 520;
      const minSlot = isCompact ? 46 : 64;
      const W = Math.max(measuredW, series.length * minSlot + (isCompact ? 44 : 58));
      const H = isCompact ? 206 : 244;
      const padL = isCompact ? 34 : 42;
      const padR = isCompact ? 10 : 14;
      const padT = 26;
      const padB = isCompact ? 28 : 32;
      const plotW = W - padL - padR;
      const plotH = H - padT - padB;
      const yFor = val => padT + plotH - (Math.max(0, val) / yMax) * plotH;

      const svg = svgEl('svg', {
        viewBox: `0 0 ${W} ${H}`,
        width: W,
        height: H,
        class: 'cr-viz-svg',
        role: 'img',
        'aria-label': `Monthly encounter volume across ${series.length} ${series.length === 1 ? 'month' : 'months'}, totaling ${total.toLocaleString()} encounters`,
      });
      if (W > measuredW) {
        svg.style.width = `${W}px`;
      } else {
        svg.style.width = '100%';
      }

      // Horizontal gridlines & Y ticks
      const tickCount = 4;
      for (let i = 0; i <= tickCount; i++) {
        const val = Math.round((yMax / tickCount) * i);
        const y = yFor(val);
        svg.appendChild(svgEl('line', {
          x1: padL, y1: y.toFixed(1), x2: W - padR, y2: y.toFixed(1),
          class: i === 0 ? 'cr-viz-axis' : 'cr-viz-grid',
        }));
        const txt = svgEl('text', {
          x: padL - 8, y: (y + 3.5).toFixed(1),
          'text-anchor': 'end',
          class: 'cr-viz-tick',
        });
        txt.textContent = val.toLocaleString();
        svg.appendChild(txt);
      }

      // Bars
      const slotW = plotW / series.length;
      const barW = Math.min(isCompact ? 32 : 56, Math.max(16, Math.floor(slotW * 0.44)));
      const useShortX = slotW < 68;
      const barGroups = [];

      series.forEach((item, idx) => {
        const count = Number(item.count) || 0;
        const cx = padL + slotW * idx + slotW / 2;
        const x = cx - barW / 2;
        const y = yFor(count);
        const h = Math.max(count > 0 ? 4 : 0, padT + plotH - y);
        const isPeak = idx === peakIndex && series.length > 1 && count > 0;

        const g = svgEl('g', { class: 'cr-viz-bar-group', 'data-cr-bar-idx': idx, tabindex: '0' });

        // Full-height hover hit zone for effortless pointer interaction
        g.appendChild(svgEl('rect', {
          x: (padL + slotW * idx).toFixed(1),
          y: padT,
          width: slotW.toFixed(1),
          height: plotH,
          class: 'cr-viz-hit',
        }));

        // Visible bar column (top rounded 3px, crisp flat base)
        const radius = Math.min(3, barW / 2, h);
        const d = h > 0
          ? `M ${x.toFixed(1)} ${(y + h).toFixed(1)} V ${(y + radius).toFixed(1)} Q ${x.toFixed(1)} ${y.toFixed(1)} ${(x + radius).toFixed(1)} ${y.toFixed(1)} H ${(x + barW - radius).toFixed(1)} Q ${(x + barW).toFixed(1)} ${y.toFixed(1)} ${(x + barW).toFixed(1)} ${(y + radius).toFixed(1)} V ${(y + h).toFixed(1)} Z`
          : '';
        if (d) {
          g.appendChild(svgEl('path', {
            d,
            class: isPeak ? 'cr-viz-bar cr-viz-bar--peak' : 'cr-viz-bar',
          }));
        }

        // Clean direct value label above every bar when <= 12 bars
        if (series.length <= 12) {
          const valText = svgEl('text', {
            x: cx.toFixed(1),
            y: Math.max(14, y - 7).toFixed(1),
            'text-anchor': 'middle',
            class: isPeak ? 'cr-viz-val cr-viz-val--peak' : 'cr-viz-val',
          });
          valText.textContent = count.toLocaleString();
          g.appendChild(valText);
        }

        // X-axis label
        const xText = svgEl('text', {
          x: cx.toFixed(1),
          y: H - (isCompact ? 8 : 10),
          'text-anchor': 'middle',
          class: 'cr-viz-xlabel',
        });
        xText.textContent = useShortX ? shortMonthLabel(item.label) : item.label;
        g.appendChild(xText);

        svg.appendChild(g);
        barGroups.push({ g, item, idx, cx, y, count });
      });

      // Dashed Average Reference Line painted cleanly above bars
      if (series.length > 1 && avg > 0) {
        const avgY = yFor(avg);
        svg.appendChild(svgEl('line', {
          x1: padL, y1: avgY.toFixed(1), x2: W - padR, y2: avgY.toFixed(1),
          class: 'cr-viz-avg-line',
        }));
        const avgText = `avg ${avg.toFixed(1)}`;
        const tagW = Math.max(54, avgText.length * 6.4 + 12);
        const tagX = W - padR - tagW;
        const tagY = Math.max(4, avgY - 19);
        svg.appendChild(svgEl('rect', {
          x: tagX.toFixed(1), y: tagY.toFixed(1), width: tagW.toFixed(1), height: 16, rx: 3,
          class: 'cr-viz-avg-bg',
        }));
        const avgLabel = svgEl('text', {
          x: (tagX + tagW / 2).toFixed(1), y: (tagY + 11).toFixed(1),
          'text-anchor': 'middle',
          class: 'cr-viz-avg-text',
        });
        avgLabel.textContent = avgText;
        svg.appendChild(avgLabel);
      }

      host.replaceChildren(svg);

      // Bidirectional hover spotlight between SVG bars and month ledger cells
      const panel = container.closest('.cr-panel');
      const monthCells = panel ? [...panel.querySelectorAll('[data-cr-month-col]')] : [];

      function setFocus(activeIdx) {
        const hasFocus = activeIdx !== null;
        svg.classList.toggle('has-focus', hasFocus);
        barGroups.forEach(({ g, idx }) => {
          g.classList.toggle('is-focused', idx === activeIdx);
        });
        monthCells.forEach(cell => {
          cell.classList.toggle('is-focused', hasFocus && Number(cell.dataset.crMonthCol) === activeIdx);
        });

        if (!tooltip) return;
        if (!hasFocus) {
          tooltip.hidden = true;
          return;
        }
        const entry = barGroups[activeIdx];
        if (!entry) return;
        const share = total > 0 ? ((entry.count / total) * 100).toFixed(1) : '0.0';
        const prev = activeIdx > 0 ? barGroups[activeIdx - 1].count : null;
        let deltaText = '';
        if (prev !== null) {
          const diff = entry.count - prev;
          const sign = diff > 0 ? '+' : '';
          const pct = prev > 0 ? ` (${sign}${((diff / prev) * 100).toFixed(1)}%)` : '';
          deltaText = `${sign}${diff.toLocaleString()}${pct} vs prior month`;
        }
        tooltip.replaceChildren();
        const head = document.createElement('div');
        head.className = 'cr-tt-head';
        head.textContent = entry.item.label;
        const num = document.createElement('div');
        num.className = 'cr-tt-val';
        num.textContent = `${entry.count.toLocaleString()} ${entry.count === 1 ? 'encounter' : 'encounters'}`;
        const meta = document.createElement('div');
        meta.className = 'cr-tt-meta';
        meta.textContent = `${share}% of period` + (deltaText ? ` · ${deltaText}` : '');
        tooltip.append(head, num, meta);
        tooltip.hidden = false;

        const stageRect = container.getBoundingClientRect();
        const svgRect = svg.getBoundingClientRect();
        const leftPx = (entry.cx / W) * svgRect.width + (svgRect.left - stageRect.left);
        const topPx = Math.max(6, (entry.y / H) * svgRect.height - 12);
        const clampedLeft = Math.max(96, Math.min(stageRect.width - 96, leftPx));
        tooltip.style.left = `${clampedLeft}px`;
        tooltip.style.top = `${topPx}px`;
      }

      barGroups.forEach(({ g, idx }) => {
        g.addEventListener('pointerenter', () => setFocus(idx), { signal: drawSignal });
        g.addEventListener('focus', () => setFocus(idx), { signal: drawSignal });
        g.addEventListener('blur', () => setFocus(null), { signal: drawSignal });
      });
      svg.addEventListener('pointerleave', () => setFocus(null), { signal: drawSignal });

      monthCells.forEach(cell => {
        const idx = Number(cell.dataset.crMonthCol);
        cell.addEventListener('pointerenter', () => setFocus(idx), { signal: drawSignal });
        cell.addEventListener('pointerleave', () => setFocus(null), { signal: drawSignal });
      });
    }

    draw();

    if (typeof ResizeObserver === 'function') {
      const ro = new ResizeObserver(() => {
        const nextW = Math.round(host.clientWidth);
        if (nextW > 0 && Math.abs(nextW - lastWidth) > 16) draw();
      });
      ro.observe(host);
      signal.addEventListener('abort', () => {
        ro.disconnect();
        drawEvents?.abort();
      }, { once: true });
    } else {
      signal.addEventListener('abort', () => drawEvents?.abort(), { once: true });
    }
  }

  function mount(root) {
    const endpoint = new URL(root.dataset.endpoint, location.href);
    if (endpoint.origin !== location.origin) return () => {};
    const bodyEl = root.querySelector('[data-cr-body]');
    const customForm = root.querySelector('[data-cr-custom-form]');
    const statusEl = root.querySelector('[data-cr-status]');
    const errorBox = root.querySelector('[data-cr-error]');
    const errorMsg = root.querySelector('[data-cr-error-message]');
    const pdfForm = document.querySelector('[data-cr-pdf-form]');
    const pdfSubmit = pdfForm?.querySelector('[data-cr-pdf-submit]');
    const pdfPresetInput = pdfForm?.querySelector('[data-cr-pdf-preset]');
    const pdfFromInput = pdfForm?.querySelector('[data-cr-pdf-from]');
    const pdfToInput = pdfForm?.querySelector('[data-cr-pdf-to]');
    if (!bodyEl) return () => {};

    const events = new AbortController();
    const { signal } = events;
    let vizEvents = null;
    let fetchController = null;
    let sequence = 0;
    let failedUrl = null;
    let committedPreset = root.querySelector('[data-cr-content]')?.dataset.preset || 'monthly';

    function initViz() {
      vizEvents?.abort();
      vizEvents = new AbortController();
      bodyEl.querySelectorAll('[data-cr-monthly-viz]').forEach(el => renderMonthlyViz(el, vizEvents.signal));
    }

    function syncPresetUi(preset, fromDate, toDate) {
      root.querySelectorAll('[data-cr-preset]').forEach(tab => {
        const active = tab.dataset.crPreset === preset;
        tab.classList.toggle('is-active', active);
        if (active) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
      });
      if (customForm) {
        customForm.hidden = preset !== 'custom';
        const fromIso = customForm.querySelector('#reportFromIso');
        const toIso = customForm.querySelector('#reportToIso');
        const fromDisp = customForm.querySelector('#reportFromDisplay');
        const toDisp = customForm.querySelector('#reportToDisplay');
        if (fromIso && fromDate) {
          fromIso.value = fromDate;
          if (fromDisp) fromDisp.value = isoToDmy(fromDate);
        }
        if (toIso && toDate) {
          toIso.value = toDate;
          if (toDisp) toDisp.value = isoToDmy(toDate);
        }
      }
      if (fromDate && toDate) {
        if (pdfPresetInput) pdfPresetInput.value = preset;
        if (pdfFromInput) pdfFromInput.value = fromDate;
        if (pdfToInput) pdfToInput.value = toDate;
      }
    }

    function setBusy(busy, label = '') {
      bodyEl.setAttribute('aria-busy', String(busy));
      bodyEl.classList.toggle('is-loading', busy);
      if (pdfSubmit && pdfForm?.getAttribute('aria-busy') !== 'true') {
        pdfSubmit.disabled = busy;
      }
      if (statusEl) statusEl.textContent = label;
    }

    async function load(destination, historyMode = 'push') {
      fetchController?.abort();
      const token = ++sequence;
      const controller = new AbortController();
      fetchController = controller;
      const clean = new URL(destination, location.href);
      if (clean.origin !== endpoint.origin) return false;
      clean.pathname = endpoint.pathname;
      clean.searchParams.delete('fragment');
      clean.hash = '';
      failedUrl = new URL(clean);

      if (errorBox) errorBox.hidden = true;
      setBusy(true, 'Updating report…');

      const requestUrl = new URL(clean);
      requestUrl.searchParams.set('fragment', '1');

      try {
        const res = await fetch(requestUrl.href, {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (token !== sequence || controller.signal.aborted) return false;
        if (window.SynapseCsrf?.fromResponse(res)) return false;
        if (!res.ok) {
          const msg = await res.text();
          throw new Error(msg && msg.length < 240 ? msg : 'Unable to load Clinic summary report.');
        }
        const html = await res.text();
        if (token !== sequence || controller.signal.aborted) return false;

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const content = doc.body.firstElementChild;
        if (!content?.matches('[data-cr-content]') || content.querySelector('script, iframe, object, embed')) {
          throw new Error('Unexpected report response. Reload this page.');
        }

        bodyEl.replaceChildren(content);
        committedPreset = content.dataset.preset || clean.searchParams.get('preset') || 'monthly';
        syncPresetUi(committedPreset, content.dataset.from, content.dataset.to);
        initViz();

        if (historyMode !== 'none') {
          if (window.SynapseNavigation?.updateHistory) {
            window.SynapseNavigation.updateHistory(clean.href, historyMode === 'push' ? 'push' : 'replace');
          } else {
            history[historyMode === 'push' ? 'pushState' : 'replaceState'](history.state, '', clean.href);
          }
        }
        failedUrl = null;
        setBusy(false, '');
        return true;
      } catch (err) {
        if (token !== sequence || controller.signal.aborted || err.name === 'AbortError') return false;
        syncPresetUi(committedPreset);
        if (errorMsg) errorMsg.textContent = err.message || 'Unable to load Clinic summary report.';
        if (errorBox) errorBox.hidden = false;
        setBusy(false, '');
        return false;
      } finally {
        if (token === sequence) {
          fetchController = null;
          setBusy(false, '');
        }
      }
    }

    root.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const tab = event.target.closest('[data-cr-preset]');
      if (tab && root.contains(tab)) {
        event.preventDefault();
        const preset = tab.dataset.crPreset;
        if (!preset) return;
        if (preset === 'custom') {
          syncPresetUi('custom');
          customForm?.querySelector('#reportFromDisplay')?.focus();
          return;
        }
        syncPresetUi(preset);
        const nextUrl = new URL(endpoint);
        nextUrl.search = '';
        nextUrl.searchParams.set('preset', preset);
        void load(nextUrl, 'push');
        return;
      }
      if (event.target.closest('[data-cr-retry]') && failedUrl) {
        event.preventDefault();
        void load(failedUrl, 'replace');
      }
    }, { signal });

    customForm?.addEventListener('submit', event => {
      event.preventDefault();
      const nextUrl = new URL(endpoint);
      nextUrl.search = '';
      for (const [k, v] of new FormData(customForm)) {
        if (['preset', 'from', 'to'].includes(k) && typeof v === 'string' && v.trim() !== '') {
          nextUrl.searchParams.set(k, v.trim());
        }
      }
      void load(nextUrl, 'push');
    }, { signal });

    window.addEventListener('popstate', () => {
      if (routeOf(location.pathname) !== routeOf(endpoint.pathname) || !root.isConnected) return;
      void load(location.href, 'none');
    }, { signal });

    initViz();

    return () => {
      fetchController?.abort();
      vizEvents?.abort();
      events.abort();
    };
  }

  function init(scope = document) {
    for (const [root, destroy] of instances) {
      if (!root.isConnected) {
        destroy();
        instances.delete(root);
      }
    }
    const roots = [...scope.querySelectorAll('[data-cr-workspace]')];
    if (scope.matches?.('[data-cr-workspace]')) roots.unshift(scope);
    roots.forEach(root => {
      if (!instances.has(root)) instances.set(root, mount(root));
    });
  }

  document.addEventListener('synapse:page-load', e => init(e.detail?.root || document));
  document.addEventListener('synapse:before-swap', e => {
    const scope = e.detail?.root;
    for (const [root, destroy] of instances) {
      if (!scope || scope === root || scope.contains(root)) {
        destroy();
        instances.delete(root);
      }
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
