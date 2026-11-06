/*!
 * chart-theme.js — theme-aware ApexCharts configuration.
 *
 * ApexCharts writes colours into the SVG at render time and cannot read CSS
 * variables, so theme colours are resolved from the design tokens in
 * tokens.css and handed to the chart as real config values. On a theme change
 * the chart is re-rendered with freshly resolved tokens, which also re-themes
 * the tooltip (the one part of the chart CSS cannot reach reliably).
 *
 * Usage:
 *   var chart = ChartTheme.create(target, function (t) {
 *     return { chart: {...}, series: [...], ... ChartTheme.base(t, {...}) };
 *   });
 *   ChartTheme.initialize(); // retries targets hidden during layout
 */
(function (global) {
  'use strict';

  var TOKENS = [
    '--chart-series-1', '--chart-series-2', '--chart-grid',
    '--chart-axis-label', '--chart-legend-label',
    '--chart-tooltip-bg', '--chart-tooltip-text', '--chart-tooltip-muted',
    '--chart-tooltip-border', '--chart-donut-gap',
    '--chart-cat-1', '--chart-cat-2', '--chart-cat-3',
    '--chart-cat-4', '--chart-cat-5', '--chart-cat-6',
    '--chart-neutral-1', '--chart-neutral-2', '--chart-neutral-3',
    '--chart-neutral-4', '--chart-neutral-5', '--chart-neutral-6',
    '--chart-status-danger', '--chart-status-warning',
    '--chart-status-success', '--chart-status-idle',
    '--surface-color', '--default-color', '--muted-color', '--border-color',
    '--heading-color'
  ];

  var registry = [];
  var resizeFrame = 0;
  var resizeObserver = typeof global.ResizeObserver === 'function'
    ? new global.ResizeObserver(scheduleResize) : null;

  /**
   * Resolve design tokens from the current theme.
   * getComputedStyle returns the *computed* value, so var() indirection in
   * tokens.css is already flattened by the time we read it.
   *
   * Tokens are read from <body>, not <html>: in the legacy body.dark-mode
   * path tokens.css declares the dark palette on the body selector, so
   * reading from the document element would silently return light values.
   * Body inherits everything else from :root, so this is correct for both.
   *
   * Keys are returned without the leading "--chart-"/"--" so callers can use
   * t['series-1'], t['grid'], t['axis-label'] etc.
   */
  function readTokens() {
    var style = getComputedStyle(document.body);
    var out = {};
    for (var i = 0; i < TOKENS.length; i++) {
      var name = TOKENS[i];
      var value = style.getPropertyValue(name).trim();
      if (!value) continue;
      var key = name.replace(/^--/, '');
      // Chart tokens keep their short name; palette tokens keep the full one.
      if (key.indexOf('chart-') === 0) key = key.slice('chart-'.length);
      out[key] = value;
    }
    if (!out['surface-color']) out['surface-color'] = style.backgroundColor || '#ffffff';
    if (!out['default-color']) out['default-color'] = style.color || '#1f2937';
    return out;
  }

  function isDark() {
    var theme = document.documentElement.getAttribute('data-theme');
    if (theme === 'dark') return true;
    if (theme === 'light') return false;
    return document.body.classList.contains('dark-mode');
  }

  /**
   * Shared axis/grid/legend/tooltip configuration for area + line charts.
   * Callers spread this and override what they need.
   */
  function base(tokens, overrides) {
    var t = tokens || readTokens();
    var config = {
      // Do NOT set theme.mode: ApexCharts then forces its own #424242 canvas
      // background and #f6f7f8 foreColor, which overrides the design tokens.
      // Every colour is supplied explicitly below instead.
      chart: {
        fontFamily: 'inherit',
        foreColor: t['axis-label'],
        background: 'transparent',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: true, speed: 400 }
      },
      dataLabels: { enabled: false },
      stroke: { curve: 'smooth' },
      grid: {
        borderColor: t.grid,
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        padding: { left: 4, right: 4 }
      },
      xaxis: {
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { colors: t['axis-label'], fontSize: '11px' } }
      },
      yaxis: {
        labels: { style: { colors: t['axis-label'], fontSize: '11px' } },
        min: 0,
        forceNiceScale: true
      },
      legend: {
        labels: { colors: t['legend-label'] }
      },
      tooltip: {
        theme: isDark() ? 'dark' : 'light',
        style: {
          fontSize: '12px',
          fontFamily: 'inherit'
        }
      },
      markers: {
        size: 0,
        strokeWidth: 2,
        hover: { size: 4 }
      }
    };
    return merge(config, overrides || {});
  }

  /**
   * Categorical palette for multi-slice donuts, in fixed order.
   * These are identity colours that must stay distinct hues in both themes,
   * so they come from --chart-cat-* which the dark block deliberately keeps.
   */
  function categorical(tokens) {
    var t = tokens || readTokens();
    var out = [];
    for (var i = 1; i <= 6; i++) {
      var v = t['cat-' + i];
      if (v) out.push(v);
    }
    return out;
  }

  /**
   * Status palette in dashboard order: danger, warning, success, idle.
   * Hue carries the meaning, so these also stay fixed across themes.
   */
  function statusPalette(tokens) {
    var t = tokens || readTokens();
    return [t['status-danger'], t['status-warning'], t['status-success'], t['status-idle']]
      .filter(Boolean);
  }

  /**
   * Palette for multi-slice status donuts (triage, BMG state, categories).
   * Light mode keeps the semantic hues. Dark mode switches to the neutral
   * ramp so the whole dashboard reads as one calm surface, matching the
   * admin population donut.
   *
   * The ramp is sampled across its full range for the given slice count.
   * Taking the first N entries instead would bunch 3-slice donuts into the
   * light end (adjacent separation 1.28:1); sampling evenly yields 2.3:1
   * and 2.0:1, which is clearly readable.
   *
   * @param {Object} tokens
   * @param {string[]} lightColors semantic hues used in light mode
   */
  function themedSlices(tokens, lightColors) {
    var t = tokens || readTokens();
    if (!isDark()) return lightColors;
    var ramp = [];
    for (var i = 1; i <= 6; i++) {
      var v = t['neutral-' + i];
      if (v) ramp.push(v);
    }
    if (!ramp.length) return lightColors;

    var count = (lightColors && lightColors.length) || ramp.length;
    if (count > ramp.length) count = ramp.length;
    if (count === 1) return [ramp[0]];

    var out = [];
    for (var n = 0; n < count; n++) {
      out.push(ramp[Math.round(n * (ramp.length - 1) / (count - 1))]);
    }
    return out;
  }

  /**
   * Donut configuration. The slice gap uses the card surface so slices read
   * as separated in either theme instead of relying on a hard-coded white.
   */
  function donut(tokens, overrides) {
    var t = tokens || readTokens();
    return merge({
      chart: {
        type: 'donut',
        height: 230,
        fontFamily: 'inherit',
        foreColor: t['default-color']
      },
      dataLabels: { enabled: false },
      // Pie slices are drawn with strokeWidth 0 and take their colour from
      // stroke.colors; the slice gap only appears when stroke.width is set.
      stroke: { width: 2, colors: [t['donut-gap']] },
      legend: { show: false },
      tooltip: {
        theme: isDark() ? 'dark' : 'light',
        // ApexCharts forces fillSeriesColor:true for donuts, which paints the
        // whole series row in the slice colour while the text keeps the theme
        // colour. On a light-grey slice that is white-on-white (1.11:1). Turn
        // it off so the row uses the tooltip surface and the marker carries
        // the series colour instead.
        fillSeriesColor: false,
        marker: { show: true }
      },
      plotOptions: {
        pie: {
          donut: {
            size: '72%',
            labels: {
              show: true,
              name: {
                show: true,
                fontSize: '12px',
                color: t['muted-color']
              },
              value: {
                show: true,
                fontSize: '18px',
                fontWeight: 700,
                color: t['heading-color']
              },
              total: {
                show: true,
                fontSize: '12px',
                color: t['muted-color'],
                label: {
                  color: t['muted-color']
                }
              }
            }
          }
        }
      }
    }, overrides || {});
  }

  /**
   * Deep-merge overrides onto a base config.
   * A one-level merge is not enough here: dashboards override e.g.
   * plotOptions.pie.donut.labels.total, and a shallow merge would replace the
   * whole plotOptions object and silently drop the center label settings.
   * Arrays are replaced wholesale (series, colors) rather than merged.
   */
  function merge(baseConfig, overrides) {
    function isPlainObject(value) {
      return value && typeof value === 'object' && !Array.isArray(value);
    }
    function deep(a, b) {
      var out = {};
      var key;
      for (key in a) {
        if (Object.prototype.hasOwnProperty.call(a, key)) out[key] = a[key];
      }
      for (key in b) {
        if (!Object.prototype.hasOwnProperty.call(b, key)) continue;
        if (isPlainObject(out[key]) && isPlainObject(b[key])) {
          out[key] = deep(out[key], b[key]);
        } else {
          out[key] = b[key];
        }
      }
      return out;
    }
    return deep(baseConfig || {}, overrides || {});
  }

  function measuredWidth(target) {
    if (!target.isConnected || !target.getClientRects().length) return 0;
    var style = getComputedStyle(target);
    var width = target.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
    // The donut renderer subtracts padding and stroke from the available size.
    // Do not render hidden or temporarily collapsed chart containers.
    return isFinite(width) && width > 32 ? Math.floor(width) : 0;
  }

  function destroyEntry(entry) {
    if (entry.destroyed) return;
    entry.destroyed = true;
    registry.splice(registry.indexOf(entry), 1);
    if (resizeObserver) resizeObserver.unobserve(entry.target);
    delete entry.target.dataset.chartThemeRendered;
    if (entry.chart) entry.chart.destroy();
  }

  function updateEntry(entry, themeChanged) {
    if (entry.destroyed) return;
    if (!entry.target.isConnected) { destroyEntry(entry); return; }
    var width = measuredWidth(entry.target);
    if (themeChanged) entry.pending = true;
    if (!width || (!entry.pending && entry.width === width && entry.chart)) return;
    if (entry.busy) { entry.pending = true; return; }
    var options = entry.build(readTokens());
    options.chart = merge(options.chart || {}, {
      width: width,
      // Own resize scheduling here: ApexCharts' window listener otherwise also
      // redraws charts whose page has been detached by progressive navigation.
      redrawOnParentResize: false,
      redrawOnWindowResize: false
    });
    entry.width = width;
    entry.pending = false;
    entry.busy = true;
    var operation;
    try {
      if (!entry.chart) {
        entry.chart = new global.ApexCharts(entry.target, options);
        entry.target.dataset.chartThemeRendered = 'true';
        operation = entry.chart.render();
      } else {
        operation = entry.chart.updateOptions(options, false, false, false);
      }
    } catch (error) {
      entry.busy = false;
      destroyEntry(entry);
      throw error;
    }
    Promise.resolve(operation).then(function () {
      entry.busy = false;
      if (entry.pending && !entry.destroyed) {
        entry.pending = false;
        updateEntry(entry, true);
      }
    }).catch(function (error) {
      entry.busy = false;
      if (!entry.destroyed) {
        destroyEntry(entry);
        console.error('Chart rendering failed.', error);
      }
    });
  }

  function scheduleResize() {
    if (resizeFrame) return;
    resizeFrame = global.requestAnimationFrame(function () {
      resizeFrame = 0;
      registry.slice().forEach(function (entry) { updateEntry(entry, false); });
    });
  }

  /** Retry deferred charts after the dashboard's layout has settled. */
  function initialize() {
    scheduleResize();
  }

  /**
   * Create once per target; hidden containers wait for a measurable width.
   * @param {Element} target chart container
   * @param {Function} build (tokens) => ApexCharts options
   * @returns {Object|null} the ApexCharts instance, or null while deferred
   */
  function create(target, build) {
    if (!target || typeof global.ApexCharts === 'undefined') return null;
    for (var i = 0; i < registry.length; i++) {
      if (registry[i].target === target) return null;
    }
    var entry = { target: target, build: build, chart: null, width: 0,
      busy: false, pending: false, destroyed: false };
    registry.push(entry);
    if (resizeObserver) resizeObserver.observe(target);
    updateEntry(entry, false);
    return entry.chart;
  }

  function refreshAll() {
    registry.slice().forEach(function (entry) { updateEntry(entry, true); });
  }

  global.addEventListener('resize', scheduleResize, { passive: true });
  document.addEventListener('synapse:before-swap', function (event) {
    var root = event.detail && event.detail.root;
    if (!root) return;
    registry.slice().forEach(function (entry) {
      if (root === entry.target || root.contains(entry.target)) destroyEntry(entry);
    });
  });

  document.addEventListener('synapse:theme-change', refreshAll);

  // Some surfaces (the login page, and the legacy body.dark-mode path) change
  // the theme by toggling an attribute or class directly instead of calling
  // setTheme(), so watch both rather than relying on the event alone.
  function themeSignature() {
    return (document.documentElement.getAttribute('data-theme') || '') +
      '|' + (document.body && document.body.classList.contains('dark-mode') ? 'dark' : 'light');
  }
  var lastTheme = themeSignature();
  var observer = new MutationObserver(function () {
    var current = themeSignature();
    if (current === lastTheme) return;
    lastTheme = current;
    refreshAll();
  });
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  if (document.body) observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

  global.ChartTheme = {
    tokens: readTokens,
    isDark: isDark,
    base: base,
    donut: donut,
    categorical: categorical,
    statusPalette: statusPalette,
    themedSlices: themedSlices,
    create: create,
    initialize: initialize,
    refresh: refreshAll
  };
})(window);
