(function () {
  'use strict';

  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function applyDynamicStyles(scope) {
    if (!scope || !scope.querySelectorAll) return;
    scope.querySelectorAll('[data-width-pct]').forEach(function (el) {
      el.style.width = el.getAttribute('data-width-pct') + '%';
    });
    scope.querySelectorAll('[data-height-pct]').forEach(function (el) {
      el.style.height = el.getAttribute('data-height-pct') + '%';
    });
    scope.querySelectorAll('[data-bg]').forEach(function (el) {
      el.style.background = el.getAttribute('data-bg');
    });
    scope.querySelectorAll('[data-fg]').forEach(function (el) {
      el.style.color = el.getAttribute('data-fg');
    });
    scope.querySelectorAll('[data-grid-cols]').forEach(function (el) {
      el.style.gridTemplateColumns = el.getAttribute('data-grid-cols');
    });
  }

  function isDarkTheme() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark') return true;
    if (t === 'light') return false;
    return document.body.classList.contains('dark-mode');
  }

  function niceCeiling(maxVal) {
    if (maxVal <= 4) return 4;
    var exp = Math.pow(10, Math.floor(Math.log10(maxVal)));
    var frac = maxVal / exp;
    var nice = frac <= 1.2 ? 1.2 : frac <= 1.6 ? 1.6 : frac <= 2 ? 2 : frac <= 3 ? 3 : frac <= 4 ? 4 : frac <= 5 ? 5 : frac <= 6 ? 6 : frac <= 8 ? 8 : 10;
    var top = Math.ceil(nice * exp);
    return Math.ceil(top / 4) * 4;
  }

  function allocate100(counts) {
    var sum = counts.reduce(function (a, b) { return a + b; }, 0);
    if (sum <= 0) return counts.map(function () { return 0; });
    var exact = counts.map(function (v) { return (v / sum) * 100; });
    var floors = exact.map(function (v) { return Math.floor(v); });
    var rem = 100 - floors.reduce(function (a, b) { return a + b; }, 0);
    var order = exact.map(function (v, i) { return { i: i, r: v - floors[i] }; })
      .sort(function (a, b) { return b.r - a.r; });
    for (var k = 0; k < rem; k++) {
      floors[order[k].i]++;
    }
    return floors;
  }

  function initAdminDashboard() {
    var opsStage = document.getElementById('adminOpsViz');
    var opsTip = document.getElementById('adminOpsTip');
    var waffleGrid = document.getElementById('adminPopulationWaffle');
    var waffleTip = document.getElementById('adminWaffleTip');
    var cfgEl = document.getElementById('adminDashboardConfig');
    if (!opsStage || !waffleGrid || !cfgEl) return;

    var cfg;
    try {
      cfg = JSON.parse(cfgEl.textContent || '{}');
    } catch (_) {
      return;
    }

    applyDynamicStyles(opsStage.closest('.adm-superadmin-dash') || document);

    var opsRange = cfg.rangeKey === '30d' ? '30d' : '7d';
    var opsMode = 'stacked';
    var popMode = 'directory';
    var hiddenSeries = {};

    var opsWindows = cfg.opsWindows || {};
    var opsDays = [];
    var allSeries = [];

    function loadRangeWindow(key) {
      var win = opsWindows[key] || opsWindows['7d'] || { days: [], clinic: [], guidance: [], bmg: [], priorTotal: null };
      opsDays = Array.isArray(win.days) ? win.days : [];
      allSeries = [
        { name: 'Clinic Visits', key: 'clinic', data: Array.isArray(win.clinic) ? win.clinic : [] },
        { name: 'Guidance Sessions', key: 'guidance', data: Array.isArray(win.guidance) ? win.guidance : [] },
        { name: 'BMG Drum Runs', key: 'bmg', data: Array.isArray(win.bmg) ? win.bmg : [] }
      ];

      var sums = allSeries.map(function (s) {
        return s.data.reduce(function (a, b) { return a + Number(b || 0); }, 0);
      });
      var total = sums[0] + sums[1] + sums[2];
      var dayCount = Math.max(1, opsDays.length);
      var avg = total / dayCount;
      var pkVal = 0;
      var pkDay = '—';
      for (var i = 0; i < opsDays.length; i++) {
        var dSum = Number(allSeries[0].data[i] || 0) + Number(allSeries[1].data[i] || 0) + Number(allSeries[2].data[i] || 0);
        if (dSum > pkVal) { pkVal = dSum; pkDay = String(opsDays[i]); }
      }

      var labelEl = document.querySelector('[data-ops-range-label]');
      var totalEl = document.querySelector('[data-ops-total]');
      var pillEl = document.querySelector('[data-ops-delta-pill]');
      var avgEl = document.querySelector('[data-ops-avg]');
      var pkEl = document.querySelector('[data-ops-peak]');
      var pkDayEl = document.querySelector('[data-ops-peak-day]');
      var noteEl = document.querySelector('[data-ops-delta-note]');

      if (labelEl) labelEl.textContent = (key === '30d' ? '30-Day' : '7-Day') + ' Throughput';
      if (totalEl) totalEl.textContent = total.toLocaleString('en-US');
      if (avgEl) avgEl.textContent = avg.toFixed(1);
      if (pkEl) pkEl.textContent = pkVal.toLocaleString('en-US');
      if (pkDayEl) pkDayEl.textContent = pkDay;

      sums.forEach(function (sumVal, idx) {
        var vEl = document.querySelector('[data-ops-unit-val="' + idx + '"]');
        var sEl = document.querySelector('[data-ops-unit-share="' + idx + '"]');
        if (vEl) vEl.textContent = sumVal.toLocaleString('en-US');
        if (sEl) sEl.textContent = (total > 0 ? ((sumVal / total) * 100).toFixed(1) : '0.0') + '%';
      });

      var prior = win.priorTotal;
      if (prior !== null && prior !== undefined) {
        var pNum = Number(prior);
        var pct = pNum === 0 ? (total > 0 ? 100 : 0) : Math.round(((total - pNum) / pNum) * 100);
        var pctTxt = (pct > 0 ? '+' : '') + pct + '%';
        var cls = pct > 0 ? 'is-up' : (pct < 0 ? 'is-down' : 'is-flat');
        if (pillEl) {
          pillEl.textContent = pctTxt;
          pillEl.className = 'sh-delta-pill ' + cls;
        }
        if (noteEl) noteEl.textContent = pctTxt + ' vs prior ' + key + ' · Click a unit above to isolate';
      } else {
        if (pillEl) pillEl.className = 'sh-delta-pill d-none';
        if (noteEl) noteEl.textContent = 'Click a unit above to isolate series';
      }
    }

    loadRangeWindow(opsRange);

    var dirItems = [
      { label: 'Students', count: Number(cfg.studentsCount || 0) },
      { label: 'Employees (Faculty & Staff)', count: Number(cfg.employeesCount || 0) }
    ];
    var roleLabels = Array.isArray(cfg.roleLabels) ? cfg.roleLabels : [];
    var roleCounts = Array.isArray(cfg.roleCounts) ? cfg.roleCounts : [];
    var roleItems = roleLabels.map(function (label, i) {
      return { label: label, count: Number(roleCounts[i] || 0) };
    });

    function unitPalette() {
      return isDarkTheme()
        ? ['#e05263', '#e4e4e7', '#52525b']
        : ['#800000', '#27272a', '#a1a1aa'];
    }

    function rolePalette() {
      return isDarkTheme()
        ? ['#e05263', '#f4f4f5', '#2dd4bf', '#fbbf24', '#a1a1aa', '#fb7185', '#52525b']
        : ['#800000', '#27272a', '#0f766e', '#b45309', '#52525b', '#be123c', '#a1a1aa'];
    }

    function activeSeriesList() {
      var pal = unitPalette();
      var out = [];
      allSeries.forEach(function (s, i) {
        if (!hiddenSeries[i]) out.push({ idx: i, name: s.name, key: s.key, data: s.data, color: pal[i] });
      });
      if (!out.length) {
        out.push({ idx: 0, name: allSeries[0].name, key: allSeries[0].key, data: allSeries[0].data, color: pal[0] });
      }
      return out;
    }

    function showOpsTooltip(dayIdx, anchorX, anchorY, active, avgVal) {
      if (!opsTip) return;
      var label = opsDays[dayIdx] || '';
      var dayTotal = 0;
      active.forEach(function (s) { dayTotal += Number(s.data[dayIdx] || 0); });
      var diffPct = avgVal > 0 ? Math.round(((dayTotal - avgVal) / avgVal) * 100) : 0;
      var diffCls = diffPct > 4 ? 'is-up' : (diffPct < -4 ? 'is-down' : '');
      var diffTxt = avgVal > 0 ? ((diffPct > 0 ? '+' : '') + diffPct + '% vs avg') : '';

      var rows = active.map(function (s) {
        var v = Number(s.data[dayIdx] || 0);
        var share = dayTotal > 0 ? Math.round((v / dayTotal) * 100) : 0;
        return '<div class="sh-tooltip-row">' +
          '<span class="sh-tooltip-key"><span class="sh-tooltip-dot" data-bg="' + escHtml(s.color) + '"></span>' + escHtml(s.name) + '</span>' +
          '<span class="sh-tooltip-val">' + v.toLocaleString('en-US') + ' <span class="sh-tooltip-share">(' + share + '%)</span></span>' +
        '</div>';
      }).join('');

      opsTip.innerHTML = '<div class="sh-tooltip">' +
        '<div class="sh-tooltip-head"><span>' + escHtml(label) + '</span>' +
          (diffTxt ? '<span class="sh-tooltip-delta ' + diffCls + '">' + escHtml(diffTxt) + '</span>' : '') +
        '</div>' +
        '<div class="sh-tooltip-rows">' + rows + '</div>' +
        (active.length > 1
          ? '<div class="sh-tooltip-total"><span>Day Total</span><span class="sh-tooltip-val">' + dayTotal.toLocaleString('en-US') + '</span></div>'
          : '') +
      '</div>';
      applyDynamicStyles(opsTip);

      var bodyRect = opsStage.parentElement.getBoundingClientRect();
      var tipW = 215;
      var left = anchorX + 14;
      if (left + tipW > bodyRect.width - 8) left = Math.max(8, anchorX - tipW - 14);
      var top = Math.max(8, Math.min(anchorY - 24, bodyRect.height - 135));
      opsTip.style.left = left + 'px';
      opsTip.style.top = top + 'px';
      opsTip.classList.add('is-visible');
      opsTip.setAttribute('aria-hidden', 'false');
    }

    function hideOpsTooltip() {
      if (!opsTip) return;
      opsTip.classList.remove('is-visible');
      opsTip.setAttribute('aria-hidden', 'true');
    }

    function renderOpsStacked() {
      var n = opsDays.length;
      if (n === 0) {
        opsStage.innerHTML = '<div class="sh-viz-empty">No operational telemetry recorded in this window.</div>';
        return;
      }
      var active = activeSeriesList();
      var totals = [];
      var maxDay = 0;
      var peakIdx = -1;
      var sumAll = 0;
      for (var d = 0; d < n; d++) {
        var t = 0;
        active.forEach(function (s) { t += Number(s.data[d] || 0); });
        totals.push(t);
        sumAll += t;
        if (t > maxDay) { maxDay = t; peakIdx = d; }
      }
      var avgVal = sumAll / n;
      var ceil = niceCeiling(Math.max(maxDay, 4));

      var w = Math.max(300, Math.floor(opsStage.clientWidth || 680));
      var h = 286;
      var padL = 34;
      var padR = 12;
      var padT = 30;
      var padB = 26;
      var plotW = Math.max(120, w - padL - padR);
      var plotH = h - padT - padB;
      var yOf = function (val) { return padT + plotH - (val / ceil) * plotH; };

      var parts = [];
      parts.push('<svg class="sh-viz-svg" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" role="img" aria-label="Segmented daily throughput histogram">');

      for (var g = 0; g <= 4; g++) {
        var gv = Math.round((ceil / 4) * g);
        var gy = Math.round(yOf(gv)) + 0.5;
        parts.push('<line class="sh-viz-gridline" x1="' + padL + '" y1="' + gy + '" x2="' + (w - padR) + '" y2="' + gy + '"></line>');
        parts.push('<text class="sh-viz-axis-label" x="' + (padL - 8) + '" y="' + (gy + 3.5) + '" text-anchor="end">' + gv + '</text>');
      }

      var step = plotW / n;
      var barW = n > 14 ? Math.max(6, Math.min(16, step * 0.64)) : Math.max(22, Math.min(44, step * 0.48));
      var segGap = n > 14 ? 2 : 3;
      var segRad = n > 14 ? 2 : 4;
      var labelStep = n > 14 ? Math.ceil(n / 7) : 1;

      for (var i = 0; i < n; i++) {
        var cx = padL + step * i + step / 2;
        var bx = cx - barW / 2;
        var railW = Math.min(step - 4, barW + 18);
        var railX = cx - railW / 2;

        parts.push('<g class="sh-bar-group" data-day-idx="' + i + '" data-cx="' + Math.round(cx) + '">');
        parts.push('<rect class="sh-bar-rail" x="' + railX.toFixed(1) + '" y="' + (padT - 6) + '" width="' + railW.toFixed(1) + '" height="' + (plotH + 10) + '" rx="7"></rect>');

        var nonZero = active.filter(function (s) { return Number(s.data[i] || 0) > 0; });
        var totalVal = totals[i];
        var totalPx = (totalVal / ceil) * plotH;
        var totalGapPx = Math.max(0, nonZero.length - 1) * segGap;
        var availPx = Math.max(0, totalPx - totalGapPx);
        var curY = padT + plotH;

        nonZero.forEach(function (s, sPos) {
          var v = Number(s.data[i] || 0);
          var sh = totalVal > 0 ? (v / totalVal) * availPx : 0;
          if (sh < 2 && v > 0) sh = 2;
          var sy = curY - sh;
          parts.push('<rect class="sh-bar-seg" x="' + bx.toFixed(1) + '" y="' + sy.toFixed(1) + '" width="' + barW.toFixed(1) + '" height="' + sh.toFixed(1) + '" rx="' + segRad + '" fill="' + escHtml(s.color) + '"></rect>');
          curY = sy - (sPos < nonZero.length - 1 ? segGap : 0);
        });

        if (i === peakIdx && maxDay > 0) {
          var peakTopY = curY;
          var pTxt = maxDay.toLocaleString('en-US');
          var pW = Math.max(28, pTxt.length * 7.2 + 12);
          var pY = Math.max(2, peakTopY - 24);
          parts.push('<rect class="sh-viz-peak-bg" x="' + (cx - pW / 2).toFixed(1) + '" y="' + pY.toFixed(1) + '" width="' + pW.toFixed(1) + '" height="18" rx="5"></rect>');
          parts.push('<text class="sh-viz-peak-text" x="' + cx.toFixed(1) + '" y="' + (pY + 12.5).toFixed(1) + '" text-anchor="middle">' + escHtml(pTxt) + '</text>');
        }

        var showLabel = (i % labelStep === 0) || (i === n - 1 && (n - 1) % labelStep >= Math.floor(labelStep / 2));
        if (showLabel) {
          parts.push('<text class="sh-viz-x-label" x="' + cx.toFixed(1) + '" y="' + (h - 6) + '" text-anchor="middle">' + escHtml(opsDays[i]) + '</text>');
        }
        parts.push('</g>');
      }

      if (avgVal > 0) {
        var ay = Math.round(yOf(avgVal)) + 0.5;
        var avgText = 'avg ' + avgVal.toFixed(1);
        var pillW = avgText.length * 6.4 + 12;
        var pillX = w - padR - pillW;
        parts.push('<g class="sh-viz-avg-layer">');
        parts.push('<line class="sh-viz-avg-line" x1="' + padL + '" y1="' + ay + '" x2="' + (pillX - 4) + '" y2="' + ay + '"></line>');
        parts.push('<rect class="sh-viz-avg-pill-bg" x="' + pillX + '" y="' + (ay - 9) + '" width="' + pillW + '" height="18" rx="4"></rect>');
        parts.push('<text class="sh-viz-avg-pill-text" x="' + (pillX + pillW / 2) + '" y="' + (ay + 3.2) + '" text-anchor="middle">' + escHtml(avgText) + '</text>');
        parts.push('</g>');
      }

      parts.push('</svg>');
      opsStage.innerHTML = parts.join('');

      var svg = opsStage.querySelector('.sh-viz-svg');
      if (!svg) return;
      svg.querySelectorAll('.sh-bar-group').forEach(function (grp) {
        grp.addEventListener('mouseenter', function () {
          var idx = Number(grp.getAttribute('data-day-idx'));
          var cxVal = Number(grp.getAttribute('data-cx'));
          svg.classList.add('has-hover');
          svg.querySelectorAll('.sh-bar-group.is-hovered').forEach(function (g) { g.classList.remove('is-hovered'); });
          grp.classList.add('is-hovered');
          showOpsTooltip(idx, cxVal + 16, 42, active, avgVal);
        });
      });
      svg.addEventListener('mouseleave', function () {
        svg.classList.remove('has-hover');
        svg.querySelectorAll('.sh-bar-group.is-hovered').forEach(function (g) { g.classList.remove('is-hovered'); });
        hideOpsTooltip();
      });
    }

    function matrixTone(seriesIdx, ratio) {
      var dark = isDarkTheme();
      if (ratio <= 0) {
        return { bg: dark ? 'rgba(255,255,255,0.03)' : '#f4f4f5', fg: dark ? '#71717a' : '#a1a1aa' };
      }
      var alpha = (0.16 + ratio * 0.78).toFixed(2);
      if (dark) {
        if (seriesIdx === 0) return { bg: 'rgba(224, 82, 99, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#fecdd3' };
        if (seriesIdx === 1) return { bg: 'rgba(228, 228, 231, ' + (0.12 + ratio * 0.68).toFixed(2) + ')', fg: ratio > 0.55 ? '#09090b' : '#fafafa' };
        return { bg: 'rgba(113, 113, 122, ' + (0.2 + ratio * 0.72).toFixed(2) + ')', fg: '#fafafa' };
      }
      if (seriesIdx === 0) return { bg: 'rgba(128, 0, 0, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#7f1d1d' };
      if (seriesIdx === 1) return { bg: 'rgba(39, 39, 42, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#18181b' };
      return { bg: 'rgba(113, 113, 122, ' + (0.12 + ratio * 0.62).toFixed(2) + ')', fg: ratio > 0.5 ? '#ffffff' : '#3f3f46' };
    }

    function renderOpsMatrix() {
      var n = opsDays.length;
      if (n === 0) {
        opsStage.innerHTML = '<div class="sh-viz-empty">No operational telemetry recorded in this window.</div>';
        return;
      }
      var active = activeSeriesList();
      var compact = n > 14;
      var labelCol = compact ? '126px' : '148px';
      var colTpl = labelCol + ' repeat(' + n + ', minmax(' + (compact ? '12px' : '44px') + ', 1fr)) 54px';

      var dayTotals = [];
      var maxDayTotal = 0;
      var sumAll = 0;
      for (var d = 0; d < n; d++) {
        var dt = 0;
        active.forEach(function (s) { dt += Number(s.data[d] || 0); });
        dayTotals.push(dt);
        sumAll += dt;
        if (dt > maxDayTotal) maxDayTotal = dt;
      }
      var avgVal = sumAll / n;

      var html = '<div class="sh-matrix-wrap"><div class="sh-matrix-grid" data-grid-cols="' + escHtml(colTpl) + '">';
      html += '<div></div>';
      for (var c = 0; c < n; c++) {
        var showColLbl = !compact || c % 5 === 0 || c === n - 1;
        html += '<div class="sh-matrix-col-head">' + (showColLbl ? escHtml(opsDays[c]) : '') + '</div>';
      }
      html += '<div class="sh-matrix-col-head is-right">Sum</div>';

      active.forEach(function (s) {
        var laneMax = Math.max(1, Math.max.apply(null, s.data.map(Number)));
        var laneSum = s.data.reduce(function (a, b) { return a + Number(b || 0); }, 0);
        html += '<div class="sh-matrix-lane-label"><span class="sh-swatch" data-bg="' + escHtml(s.color) + '"></span>' + escHtml(s.name) + '</div>';
        for (var j = 0; j < n; j++) {
          var val = Number(s.data[j] || 0);
          var tone = matrixTone(s.idx, val / laneMax);
          html += '<div class="sh-matrix-cell' + (compact ? ' is-compact' : '') + '" data-mx-day="' + j + '" data-bg="' + escHtml(tone.bg) + '" data-fg="' + escHtml(tone.fg) + '" aria-label="' + escHtml(s.name + ' · ' + opsDays[j] + ': ' + val) + '">' + (compact ? '' : val) + '</div>';
        }
        html += '<div class="sh-matrix-total">' + laneSum.toLocaleString('en-US') + '</div>';
      });

      html += '<div class="sh-matrix-lane-label sh-matrix-sum-row sh-matrix-sum-label">Daily Total</div>';
      for (var tIdx = 0; tIdx < n; tIdx++) {
        var isPk = dayTotals[tIdx] === maxDayTotal && maxDayTotal > 0;
        html += '<div class="sh-matrix-sum-cell sh-matrix-sum-row' + (isPk ? ' is-peak' : '') + '">' + (compact ? '' : dayTotals[tIdx]) + '</div>';
      }
      html += '<div class="sh-matrix-total sh-matrix-sum-row">' + sumAll.toLocaleString('en-US') + '</div>';
      html += '</div></div>';

      opsStage.innerHTML = html;
      applyDynamicStyles(opsStage);
      opsStage.querySelectorAll('[data-mx-day]').forEach(function (cell) {
        cell.addEventListener('mouseenter', function () {
          var dIdx = Number(cell.getAttribute('data-mx-day'));
          var cellRect = cell.getBoundingClientRect();
          var bodyRect = opsStage.parentElement.getBoundingClientRect();
          showOpsTooltip(dIdx, cellRect.left - bodyRect.left + cellRect.width / 2, cellRect.top - bodyRect.top, active, avgVal);
        });
        cell.addEventListener('mouseleave', hideOpsTooltip);
      });
    }

    function renderOps() {
      hideOpsTooltip();
      if (opsMode === 'matrix') renderOpsMatrix();
      else renderOpsStacked();
    }

    function hideWaffleTooltip() {
      if (!waffleTip) return;
      waffleTip.classList.remove('is-visible');
      waffleTip.setAttribute('aria-hidden', 'true');
    }

    function renderWaffle() {
      hideWaffleTooltip();
      var useRoles = popMode === 'roles' && roleItems.length > 0;
      var items = useRoles ? roleItems : dirItems;
      var total = items.reduce(function (a, it) { return a + it.count; }, 0);
      var colors = useRoles
        ? rolePalette()
        : (isDarkTheme() ? ['#e05263', '#3f3f46'] : ['#800000', '#d4d4d8']);

      var totalEl = document.querySelector('[data-waffle-total]');
      var unitEl = document.querySelector('[data-waffle-unit]');
      var pillEl = document.querySelector('[data-waffle-pill]');
      var statusEl = document.querySelector('[data-waffle-status]');
      var legendEl = document.querySelector('[data-waffle-legend]');

      if (totalEl) totalEl.textContent = total.toLocaleString('en-US');
      if (unitEl) unitEl.textContent = useRoles ? 'staff accounts' : 'records';
      if (pillEl) pillEl.textContent = useRoles ? (items.length + ' roles · 1 tile = 1%') : '1 tile = 1%';

      var tiles = allocate100(items.map(function (it) { return it.count; }));
      var cellHtml = [];
      if (total <= 0) {
        for (var e = 0; e < 100; e++) {
          cellHtml.push('<span class="sh-waffle-cell" data-bg="var(--sh-border)"></span>');
        }
      } else {
        tiles.forEach(function (cnt, segIdx) {
          var color = colors[segIdx % colors.length];
          var it = items[segIdx];
          var pct = ((it.count / total) * 100).toFixed(1);
          for (var k = 0; k < cnt; k++) {
            cellHtml.push('<span class="sh-waffle-cell" data-seg="' + segIdx + '" data-bg="' + escHtml(color) + '" aria-label="' + escHtml(it.label + ': ' + it.count.toLocaleString('en-US') + ' (' + pct + '%)') + '"></span>');
          }
        });
      }
      waffleGrid.innerHTML = cellHtml.join('');
      applyDynamicStyles(waffleGrid);

      if (legendEl) {
        legendEl.innerHTML = items.map(function (it, segIdx) {
          var color = colors[segIdx % colors.length];
          var pct = total > 0 ? ((it.count / total) * 100).toFixed(1) : '0.0';
          return '<div class="sh-share-item" data-seg-row="' + segIdx + '">' +
            '<div class="sh-share-head">' +
              '<span class="sh-share-name"><span class="sh-swatch" data-bg="' + escHtml(color) + '"></span>' + escHtml(it.label) + '</span>' +
              '<span class="sh-share-nums"><strong>' + it.count.toLocaleString('en-US') + '</strong><span class="sh-share-pct">' + pct + '%</span></span>' +
            '</div>' +
          '</div>';
        }).join('');
        applyDynamicStyles(legendEl);
      }

      var defaultStatus = '<span>Hover any tile or row to inspect</span><span>' + (useRoles ? items.length + ' active roles' : '100 tiles') + '</span>';
      if (statusEl) statusEl.innerHTML = defaultStatus;

      function setFocus(segIdx) {
        if (segIdx === null || segIdx === undefined) {
          waffleGrid.classList.remove('has-focus');
          waffleGrid.querySelectorAll('.is-focus').forEach(function (el) { el.classList.remove('is-focus'); });
          if (legendEl) legendEl.querySelectorAll('.is-active').forEach(function (el) { el.classList.remove('is-active'); });
          if (statusEl) statusEl.innerHTML = defaultStatus;
          return;
        }
        waffleGrid.classList.add('has-focus');
        waffleGrid.querySelectorAll('.sh-waffle-cell').forEach(function (el) {
          el.classList.toggle('is-focus', Number(el.getAttribute('data-seg')) === segIdx);
        });
        if (legendEl) {
          legendEl.querySelectorAll('[data-seg-row]').forEach(function (el) {
            el.classList.toggle('is-active', Number(el.getAttribute('data-seg-row')) === segIdx);
          });
        }
        var it = items[segIdx];
        if (it && statusEl) {
          var pct = total > 0 ? ((it.count / total) * 100).toFixed(1) : '0.0';
          statusEl.innerHTML = '<span><strong>' + escHtml(it.label) + '</strong> · ' + it.count.toLocaleString('en-US') + '</span><span><strong>' + pct + '%</strong> (' + tiles[segIdx] + ' tiles)</span>';
        }
      }

      function showWaffleTooltip(segIdx, cellEl) {
        if (!waffleTip || !cellEl) return;
        var it = items[segIdx];
        if (!it) return;
        var color = colors[segIdx % colors.length];
        var pct = total > 0 ? ((it.count / total) * 100).toFixed(1) : '0.0';
        var unitLabel = useRoles ? 'Accounts' : 'Records';
        var tileCount = tiles[segIdx] || 0;

        waffleTip.innerHTML = '<div class="sh-tooltip">' +
          '<div class="sh-tooltip-head">' +
            '<span class="sh-tooltip-key sh-tooltip-key-strong"><span class="sh-tooltip-dot" data-bg="' + escHtml(color) + '"></span>' + escHtml(it.label) + '</span>' +
            '<span class="sh-tooltip-delta">' + escHtml(pct) + '%</span>' +
          '</div>' +
          '<div class="sh-tooltip-rows">' +
            '<div class="sh-tooltip-row">' +
              '<span class="sh-tooltip-key">' + escHtml(unitLabel) + '</span>' +
              '<span class="sh-tooltip-val">' + it.count.toLocaleString('en-US') + '</span>' +
            '</div>' +
            '<div class="sh-tooltip-row">' +
              '<span class="sh-tooltip-key">Matrix share</span>' +
              '<span class="sh-tooltip-val">' + tileCount + ' / 100 tiles</span>' +
            '</div>' +
          '</div>' +
        '</div>';
        applyDynamicStyles(waffleTip);

        var bodyEl = waffleTip.parentElement;
        if (!bodyEl) return;
        var bodyRect = bodyEl.getBoundingClientRect();
        var cellRect = cellEl.getBoundingClientRect();
        var tipW = waffleTip.offsetWidth || 210;
        var tipH = waffleTip.offsetHeight || 92;
        var cx = cellRect.left - bodyRect.left + cellRect.width / 2;
        var left = Math.max(8, Math.min(cx - tipW / 2, bodyRect.width - tipW - 8));
        var topAbove = cellRect.top - bodyRect.top - tipH - 10;
        var topBelow = cellRect.bottom - bodyRect.top + 10;
        var top = topAbove >= 8 ? topAbove : Math.min(topBelow, Math.max(8, bodyRect.height - tipH - 8));

        waffleTip.style.left = Math.round(left) + 'px';
        waffleTip.style.top = Math.round(top) + 'px';
        waffleTip.classList.add('is-visible');
        waffleTip.setAttribute('aria-hidden', 'false');
      }

      waffleGrid.querySelectorAll('[data-seg]').forEach(function (cell) {
        cell.addEventListener('mouseenter', function () {
          var idx = Number(cell.getAttribute('data-seg'));
          setFocus(idx);
          showWaffleTooltip(idx, cell);
        });
      });
      waffleGrid.onmouseleave = function () {
        setFocus(null);
        hideWaffleTooltip();
      };

      if (legendEl) {
        legendEl.querySelectorAll('[data-seg-row]').forEach(function (row) {
          row.addEventListener('mouseenter', function () { setFocus(Number(row.getAttribute('data-seg-row'))); });
          row.addEventListener('mouseleave', function () { setFocus(null); });
        });
      }
    }

    renderOps();
    renderWaffle();

    if (typeof ResizeObserver === 'function' && !opsStage.dataset.roBound) {
      opsStage.dataset.roBound = 'true';
      var lastW = opsStage.clientWidth;
      var ro = new ResizeObserver(function () {
        if (!opsStage.isConnected) { ro.disconnect(); return; }
        var nextW = opsStage.clientWidth;
        if (Math.abs(nextW - lastW) > 6) {
          lastW = nextW;
          renderOps();
        }
      });
      ro.observe(opsStage);
    }

    if (!opsStage.dataset.themeBound) {
      opsStage.dataset.themeBound = 'true';
      var redrawAll = function () { renderOps(); renderWaffle(); };
      document.addEventListener('synapse:theme-change', redrawAll);
      var mo = new MutationObserver(redrawAll);
      mo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    document.querySelectorAll('[data-ops-range]').forEach(function (btn) {
      if (btn.dataset.rangeBound) return;
      btn.dataset.rangeBound = 'true';
      btn.addEventListener('click', function () {
        var next = btn.getAttribute('data-ops-range');
        if (!next || next === opsRange || !opsWindows[next]) return;
        opsRange = next;
        document.querySelectorAll('[data-ops-range]').forEach(function (b) {
          var active = b.getAttribute('data-ops-range') === opsRange;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-pressed', String(active));
        });
        loadRangeWindow(opsRange);
        renderOps();
        if (window.history && typeof window.history.replaceState === 'function') {
          try {
            var url = new URL(window.location.href);
            url.searchParams.set('range', opsRange);
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
          } catch (_) {}
        }
      });
    });

    document.querySelectorAll('[data-ops-mode]').forEach(function (btn) {
      if (btn.dataset.opsBound) return;
      btn.dataset.opsBound = 'true';
      btn.addEventListener('click', function () {
        var next = btn.getAttribute('data-ops-mode');
        if (!next || next === opsMode) return;
        opsMode = next;
        document.querySelectorAll('[data-ops-mode]').forEach(function (b) {
          var active = b.getAttribute('data-ops-mode') === opsMode;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-pressed', String(active));
        });
        renderOps();
      });
    });

    document.querySelectorAll('[data-ops-series]').forEach(function (btn) {
      if (btn.dataset.seriesBound) return;
      btn.dataset.seriesBound = 'true';
      btn.addEventListener('click', function () {
        var idx = Number(btn.getAttribute('data-ops-series'));
        if (!Number.isInteger(idx)) return;
        var willHide = !hiddenSeries[idx];
        if (willHide) {
          var remaining = allSeries.filter(function (_, i) { return i !== idx && !hiddenSeries[i]; }).length;
          if (remaining === 0) return;
        }
        hiddenSeries[idx] = willHide;
        btn.classList.toggle('is-muted', willHide);
        btn.setAttribute('aria-pressed', String(!willHide));
        renderOps();
      });
    });

    document.querySelectorAll('[data-pop-mode]').forEach(function (btn) {
      if (btn.dataset.popBound) return;
      btn.dataset.popBound = 'true';
      btn.addEventListener('click', function () {
        var next = btn.getAttribute('data-pop-mode');
        if (!next || next === popMode) return;
        popMode = next;
        document.querySelectorAll('[data-pop-mode]').forEach(function (b) {
          var active = b.getAttribute('data-pop-mode') === popMode;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-pressed', String(active));
        });
        document.querySelectorAll('[data-pop-footer]').forEach(function (foot) {
          foot.classList.toggle('d-none', foot.getAttribute('data-pop-footer') !== popMode);
        });
        var titleEl = document.querySelector('[data-pop-title]');
        var subEl = document.querySelector('[data-pop-sub]');
        if (titleEl && subEl) {
          titleEl.textContent = popMode === 'roles' ? 'Privileged Role Spread' : 'Campus Population Mix';
          subEl.textContent = popMode === 'roles' ? '100-tile staff & admin distribution' : '100-tile proportional directory matrix';
        }
        renderWaffle();
      });
    });

    if (cfg.canReadAudit) {
      var ledgerBody = document.querySelector('[data-ledger-rows]');
      if (ledgerBody && !ledgerBody.dataset.filterBound) {
        ledgerBody.dataset.filterBound = 'true';
        var activeDomain = 'all';
        var searchInput = document.querySelector('[data-ledger-search]');
        var emptyRow = ledgerBody.querySelector('[data-ledger-empty-filter]');
        var applyRowFilter = function () {
          var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
          var visible = 0;
          ledgerBody.querySelectorAll('[data-ledger-domain-row]').forEach(function (row) {
            var domainMatch = activeDomain === 'all' || row.getAttribute('data-ledger-domain-row') === activeDomain;
            var textMatch = q === '' || (row.getAttribute('data-ledger-haystack') || '').indexOf(q) !== -1;
            var show = domainMatch && textMatch;
            row.classList.toggle('d-none', !show);
            if (show) visible++;
          });
          if (emptyRow) emptyRow.classList.toggle('d-none', visible > 0);
        };
        document.querySelectorAll('[data-ledger-domain]').forEach(function (chip) {
          chip.addEventListener('click', function () {
            activeDomain = chip.getAttribute('data-ledger-domain') || 'all';
            document.querySelectorAll('[data-ledger-domain]').forEach(function (c) {
              c.classList.toggle('is-active', c === chip);
            });
            applyRowFilter();
          });
        });
        if (searchInput) searchInput.addEventListener('input', applyRowFilter);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminDashboard);
  } else {
    initAdminDashboard();
  }
  document.addEventListener('synapse:page-load', initAdminDashboard);
})();
