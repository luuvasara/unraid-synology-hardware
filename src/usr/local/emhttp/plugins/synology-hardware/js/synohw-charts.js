/* synohw-charts.js - history graphs on the synology-hardware settings page (2026.10.25f; DX1215 level chart 2026.10.25h).
 * Written: 2026-10-25. Charting library: uPlot 1.6.32 (MIT, https://github.com/leeoniya/uPlot), vendored in
 * vendor/uplot-1.6.32/ from the npm tarball uplot-1.6.32.tgz (sha1 c800a63b432bad692d6d746f44f0882aa73a49ae = npm dist.shasum).
 * No CDN, no build step: the NAS may be offline.
 *
 * Data: include/status.php (POST, Unraid's CSRF prefilter adds the token): action=history for a full range, and the page's
 * own 5 s poll carries range/since so new samples are appended (uPlot has no append API: setData() with the grown arrays
 * on the same instance, which redraws the canvas but keeps the chart, legend state and cursor).
 * Colours: CSS custom properties on #synohw-graphs (sheets/SynologyHW.css); nothing here hard-codes a colour.
 * Accessibility: series toggles are native checkboxes in a legend table that also shows the value under the cursor with its
 * unit; the plots take keyboard focus (Left/Right move the cursor one sample, Home/End jump, Escape leaves); the tables on
 * the page ("All sensors, last 24 h") are the text alternative.
 * 2026.10.25j: tabbed page. The temperatures are split by unit: the main unit's chart (CPU, cores, its own disks, the running
 * DS table's steps) and the expansion unit's chart (its disks, the DX1215 table's rows); each unit's fan chart below its
 * temperatures; two legend tables. All four charts share one time axis and one cursor (sync key synohw-hist). The poll
 * parameters are only added while the History tab (or, for the sweep, the DS2415+ tab) is visible. */
(function (window, $) {
  'use strict';
  var URL_ = '/plugins/synology-hardware/include/status.php';
  var RANGES = { 3600: '1 h', 21600: '6 h', 86400: '24 h' };
  var GAP = 150;          // s without a sample = a gap (the daemon writes one every 30 s), the line breaks there
  var WHY = { d: 'the disk step is higher', c: 'the CPU step is higher', b: 'disk and CPU give the same step', f: 'floor 70 % (a source unreadable)', n: 'no temperature at all: 99 %',
              m: 'MANUAL duty', a: 'MANUAL raised to 99 %: ALERT band', '-': 'reason not recorded (line from before 2026.10.25f)' };
  /* DX1215 (2026.10.25h): level chars from the history (f m l s = full middle low stop, n = not controlled, - = not recorded) */
  var DXNUM = { s: 0, l: 1, m: 2, f: 3 }, DXNAME = ['stop', 'low', 'middle', 'full'];
  var DXWHY = { a: 'DX1215 table on the hottest DX disk', s: 'all DX disks asleep: low', d: 'no DX disk found: at least middle', r: 'a DX disk without a reading: at least middle',
                x: 'a DX disk at the 61 °C SHUTDOWN row: full', A: 'fan alarm: full', m: 'MANUAL level', k: 'LINKED to the DS2415+ duty', n: 'synology_dx module not loaded',
                o: 'no DX1215 master', '-': 'not recorded' };
  var DASH = [[], [6, 3], [2, 3], [8, 3, 2, 3], [1, 2], [10, 4], [4, 4], [3, 6]];

  var S = {               // state
    range: 86400, hist: null, meta: null, cores: false, cpuThr: false, hidden: {}, charts: {}, sweep: null, loading: false,
    hoverIdx: null
  };
  function el(id) { return document.getElementById(id); }
  function tok(name) {
    var root = el('synohw-graphs') || document.documentElement;
    return window.getComputedStyle(root).getPropertyValue(name).trim();
  }
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function hhmm(t, sec) { var d = new Date(t * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + (sec ? ':' + pad(d.getSeconds()) : ''); }
  function dur(s) { s = Math.max(0, Math.round(s)); if (s < 120) return s + ' s'; if (s < 7200) return Math.round(s / 60) + ' min'; return Math.floor(s / 3600) + ' h ' + Math.round((s % 3600) / 60) + ' min'; }
  function C(v) { return v == null ? '-' : v + ' °C'; }

  /* light or dark: decided from the colour the page really paints (works for every Unraid theme, also custom ones) */
  function scheme() {
    var bg = window.getComputedStyle(document.body).backgroundColor || '';
    var m = bg.match(/\d+(\.\d+)?/g);
    if (!m || m.length < 3) return 'dark';
    var l = (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255;
    return l > 0.5 ? 'light' : 'dark';
  }

  /* ---------- series model ---------- */
  function diskKeys() { return S.hist ? Object.keys(S.hist.disks) : []; }
  function isDx(k) { var m = diskInfo(k); return !!(m && m.box === 'dx'); }
  function dsKeys() { return diskKeys().filter(function (k) { return !isDx(k); }); }
  function dxKeys() { return diskKeys().filter(isDx); }
  function boxName(b) { return (S.meta && S.meta.box_names && S.meta.box_names[b]) || (b === 'dx' ? 'Expansion unit' : 'Main unit'); }
  function visible(e) { return !!(e && e.offsetParent !== null); }
  function coreKeys() { return S.hist ? Object.keys(S.hist.cores) : []; }
  function diskInfo(k) { return (S.meta && S.meta.disks && S.meta.disks[k]) || null; }
  function diskName(k) {
    var m = diskInfo(k);
    if (!m) return k + ' (not present now)';
    return k + (m.model ? ' · ' + m.model : '');
  }
  function coreName(k) { return 'CPU ' + ((S.meta && S.meta.cores && S.meta.cores[k]) || k); }
  /* fixed colour per entity, in the order the history lists them: CPU = slot 1, disks slots 2..8, more disks grey + dashed */
  function diskColour(i) { return i < 7 ? tok('--synohw-series-' + (i + 2)) : tok('--synohw-series-other'); }
  function diskSlot(k) { return diskKeys().indexOf(k); }   // colour follows the disk (its place in the whole history), not its chart

  /* temperature rows: [t, cpu, cores..., disks...] with gap rows inserted */
  function tempData() {   // main unit: [t, cpu, cores..., its disks...]
    var h = S.hist, dk = dsKeys(), ck = coreKeys(), cols = [[], []], i, j;
    for (j = 0; j < ck.length + dk.length; j++) cols.push([]);
    for (i = 0; i < h.t.length; i++) {
      if (i > 0 && h.t[i] - h.t[i - 1] > GAP) { cols[0].push(h.t[i - 1] + 1); for (j = 1; j < cols.length; j++) cols[j].push(null); }
      cols[0].push(h.t[i]); cols[1].push(h.cpu[i]);
      for (j = 0; j < ck.length; j++) cols[2 + j].push(h.cores[ck[j]][i]);
      for (j = 0; j < dk.length; j++) cols[2 + ck.length + j].push(h.disks[dk[j]][i]);
    }
    return cols;
  }
  function dxTempData() {   // expansion unit: [t, its disks...]
    var h = S.hist, dk = dxKeys(), cols = [[]], i, j;
    for (j = 0; j < dk.length; j++) cols.push([]);
    for (i = 0; i < h.t.length; i++) {
      if (i > 0 && h.t[i] - h.t[i - 1] > GAP) { cols[0].push(h.t[i - 1] + 1); for (j = 1; j < cols.length; j++) cols[j].push(null); }
      cols[0].push(h.t[i]);
      for (j = 0; j < dk.length; j++) cols[1 + j].push(h.disks[dk[j]][i]);
    }
    return cols;
  }
  function dxVal(c) { return Object.prototype.hasOwnProperty.call(DXNUM, c) ? DXNUM[c] : null; }
  function hasDx() { var h = S.hist; return !!(h && h.dx && /[smlf]/.test(h.dx)); }
  function dxData() {
    var h = S.hist, cols = [[], [], []], i;
    for (i = 0; i < h.t.length; i++) {
      if (i > 0 && h.t[i] - h.t[i - 1] > GAP) { cols[0].push(h.t[i - 1] + 1); cols[1].push(null); cols[2].push(null); }
      cols[0].push(h.t[i]); cols[1].push(dxVal(h.dx.charAt(i))); cols[2].push(dxVal(h.dxw.charAt(i)));
    }
    return cols;
  }
  function dutyData() {
    var h = S.hist, cols = [[], [], []], i;
    for (i = 0; i < h.t.length; i++) {
      if (i > 0 && h.t[i] - h.t[i - 1] > GAP) { cols[0].push(h.t[i - 1] + 1); cols[1].push(null); cols[2].push(null); }
      cols[0].push(h.t[i]); cols[1].push(h.duty[i] != null && h.duty[i] >= 0 ? h.duty[i] : null); cols[2].push(h.want[i]);
    }
    return cols;
  }
  /* sample index in S.hist for a chart x value (charts contain gap rows, the history does not) */
  function histIdx(t) {
    var h = S.hist, lo = 0, hi = h.t.length - 1, mid;
    if (hi < 0) return null;
    while (lo < hi) { mid = (lo + hi) >> 1; if (h.t[mid] < t) lo = mid + 1; else hi = mid; }
    return h.t[lo] === t ? lo : null;
  }

  /* ---------- thresholds drawn on the temperature chart ---------- */
  function thresholds() {
    var tb = S.meta && S.meta.tables && S.meta.tables[S.meta.mode]; if (!tb) return [];
    var out = [], i;
    for (i = 1; i < tb.disk.length; i++) out.push({ v: tb.disk[i][0], text: 'disk ' + tb.disk[i][0] + ' °C → fan ' + tb.disk[i][1] + ' %', kind: 'disk' });
    out.push({ v: tb.disk_alert, text: 'disk alert ' + tb.disk_alert + ' °C', kind: 'alert' });
    for (i = 1; i < tb.cpu.length; i++) out.push({ v: tb.cpu[i][0], text: 'CPU ' + tb.cpu[i][0] + ' °C → fan ' + tb.cpu[i][1] + ' %' + (tb.cpu[i][0] === tb.cpu_alert ? ', CPU alert' : ''), kind: tb.cpu[i][0] === tb.cpu_alert ? 'alert' : 'cpu' });
    return out;
  }
  /* the DX1215 table's rows (scemd.xml:357-370): middle, full, SHUTDOWN */
  function thresholdsDx() {
    var tb = S.meta && S.meta.dx_table; if (!tb) return [];
    var out = [], i;
    for (i = 1; i < tb.steps.length; i++) out.push({ v: tb.steps[i][0], text: 'DX disk ' + tb.steps[i][0] + ' °C → ' + tb.steps[i][1], kind: 'disk' });
    out.push({ v: tb.shutdown, text: 'DX SHUTDOWN row ' + tb.shutdown + ' °C', kind: 'alert' });
    return out;
  }
  function drawThresholdsDx(u) { drawLines(u, thresholdsDx(), 'DX1215 table'); }
  function drawThresholds(u) { drawLines(u, thresholds(), S.meta.mode); }
  function drawLines(u, list, tag) {
    var ctx = u.ctx, dpr = window.devicePixelRatio || 1, l = u.bbox.left, w = u.bbox.width, lastY = -1e9;
    ctx.save();
    ctx.font = (11 * dpr) + 'px ' + (tok('--synohw-font') || 'sans-serif');
    ctx.textAlign = 'right'; ctx.textBaseline = 'bottom';
    list.sort(function (a, b) { return b.v - a.v; }).forEach(function (t) {
      var sc = u.scales.y; if (t.v < sc.min || t.v > sc.max) return;
      var y = Math.round(u.valToPos(t.v, 'y', true)) + 0.5;
      ctx.strokeStyle = tok(t.kind === 'alert' ? '--synohw-alert' : '--synohw-threshold');
      ctx.lineWidth = dpr; ctx.setLineDash([5 * dpr, 4 * dpr]);
      ctx.beginPath(); ctx.moveTo(l, y); ctx.lineTo(l + w, y); ctx.stroke();
      ctx.setLineDash([]);
      if (y - lastY > 13 * dpr) {   // never print two labels on top of each other; the line still shows
        ctx.fillStyle = tok(t.kind === 'alert' ? '--synohw-alert-text' : '--synohw-ink-muted');
        ctx.fillText(t.text + ' (' + tag + ')', l + w - 4 * dpr, y - 2 * dpr);
        lastY = y;
      }
    });
    ctx.restore();
  }
  /* sweep periods: a band behind the series, labelled once */
  function drawSweepBands(u) {
    var h = S.hist; if (!h || !h.sweep) return;
    var ctx = u.ctx, dpr = window.devicePixelRatio || 1, i = 0, a, b, x0, x1, top = u.bbox.top, hgt = u.bbox.height;
    ctx.save();
    while (i < h.t.length) {
      if (!h.sweep[i]) { i++; continue; }
      a = h.t[i]; while (i < h.t.length && h.sweep[i]) { b = h.t[i]; i++; }
      x0 = u.valToPos(a, 'x', true); x1 = u.valToPos(b, 'x', true);
      ctx.fillStyle = tok('--synohw-sweep-band'); ctx.fillRect(x0, top, Math.max(2 * dpr, x1 - x0), hgt);
      ctx.fillStyle = tok('--synohw-ink-muted'); ctx.font = (11 * dpr) + 'px ' + (tok('--synohw-font') || 'sans-serif');
      ctx.textAlign = 'left'; ctx.textBaseline = 'top'; ctx.fillText('fan sweep', x0 + 4 * dpr, top + 3 * dpr);
    }
    ctx.restore();
  }

  /* DX1215 fan alarm periods (@dxal > 0): a red band, labelled once at its foot */
  function drawDxAlarm(u) {
    var h = S.hist; if (!h || !h.dxal) return;
    var ctx = u.ctx, dpr = window.devicePixelRatio || 1, i = 0, a, b, x0, x1, top = u.bbox.top, hgt = u.bbox.height;
    ctx.save();
    while (i < h.t.length) {
      if (!h.dxal[i]) { i++; continue; }
      a = h.t[i]; while (i < h.t.length && h.dxal[i]) { b = h.t[i]; i++; }
      x0 = u.valToPos(a, 'x', true); x1 = u.valToPos(b, 'x', true);
      ctx.fillStyle = tok('--synohw-alarm-band'); ctx.fillRect(x0, top, Math.max(2 * dpr, x1 - x0), hgt);
      ctx.fillStyle = tok('--synohw-alert-text'); ctx.font = (11 * dpr) + 'px ' + (tok('--synohw-font') || 'sans-serif');
      ctx.textAlign = 'left'; ctx.textBaseline = 'bottom'; ctx.fillText('fan alarm', x0 + 4 * dpr, top + hgt - 3 * dpr);   // bottom: the threshold labels sit at the top right
    }
    ctx.restore();
  }

  /* ---------- chart construction ---------- */
  function axes(unit, splits) {
    var ink = tok('--synohw-ink-muted'), grid = tok('--synohw-grid'), font = '12px ' + (tok('--synohw-font') || 'sans-serif');
    var y = { stroke: ink, font: font, grid: { stroke: grid, width: 1 }, ticks: { stroke: grid, width: 1 }, size: 56,
              label: unit, labelFont: font, labelSize: 18, values: function (u, v) { return v.map(function (x) { return x == null ? '' : x; }); } };
    if (splits) y.splits = function () { return splits; };
    return [{ stroke: ink, font: font, grid: { stroke: grid, width: 1 }, ticks: { stroke: grid, width: 1 },
              values: [[3600, '{HH}:{mm}', '\n{D}.{M}.', null, '{HH}:{mm}', null, null, null, 0], [60, '{HH}:{mm}', '\n{D}.{M}.', null, '{HH}:{mm}', null, null, null, 0], [1, '{HH}:{mm}:{ss}', null, null, null, null, null, null, 0]] }, y];
  }
  function size(id) { var w = el(id).clientWidth || 800; return { width: Math.max(320, w), height: id === 'synohw-chart-duty' ? 170 : id === 'synohw-chart-dx' ? 140 : id === 'synohw-sweep-duty' ? 200 : id === 'synohw-chart-dxtemp' ? 240 : 300 }; }

  function makeTemp() {
    var ck = coreKeys(), dk = dsKeys(), series = [{}], ink = tok('--synohw-series-1');
    series.push({ label: 'CPU (hottest sensor)', stroke: ink, width: 2, show: !S.hidden.cpu, spanGaps: false, value: function (u, v) { return C(v); } });
    ck.forEach(function (k, i) { series.push({ label: coreName(k), stroke: ink, width: 1, dash: DASH[(i % 7) + 1], show: S.cores && !S.hidden['core:' + k], spanGaps: false }); });
    dk.forEach(function (k) { var i = diskSlot(k); series.push({ label: diskName(k), stroke: diskColour(i), width: 2, dash: i < 7 ? [] : DASH[(i - 7) % 7 + 1], show: !S.hidden['disk:' + k], spanGaps: false }); });
    series.forEach(function (x, i) { if (i) x.points = { show: false }; });
    var opts = {
      ...size('synohw-chart-temp'), series: series, axes: axes('°C'), legend: { show: false },
      scales: { x: { time: true, range: xRange }, y: { range: yRangeTemp } },
      cursor: { sync: { key: 'synohw-hist' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { drawClear: [drawSweepBands], draw: [drawThresholds], setCursor: [onCursor] }
    };
    return new window.uPlot(opts, tempData(), el('synohw-chart-temp'));
  }
  function makeDxTemp() {
    var series = [{}];
    dxKeys().forEach(function (k) { var i = diskSlot(k); series.push({ label: diskName(k), stroke: diskColour(i), width: 2, dash: i < 7 ? [] : DASH[(i - 7) % 7 + 1], show: !S.hidden['disk:' + k], spanGaps: false, points: { show: false } }); });
    var opts = {
      ...size('synohw-chart-dxtemp'), series: series, axes: axes('°C'), legend: { show: false },
      scales: { x: { time: true, range: xRange }, y: { range: yRangeDx } },
      cursor: { sync: { key: 'synohw-hist' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { drawClear: [drawDxAlarm], draw: [drawThresholdsDx], setCursor: [onCursor] }
    };
    return new window.uPlot(opts, dxTempData(), el('synohw-chart-dxtemp'));
  }
  function yRangeDx(u, min, max) {
    var lo = min == null ? 20 : min, hi = max == null ? 45 : max, tb = S.meta && S.meta.dx_table;
    hi = Math.max(hi + 3, tb ? tb.shutdown + 3 : 64);
    return [Math.floor(Math.min(lo - 3, 25) / 5) * 5, Math.ceil(hi / 5) * 5];
  }
  function makeDuty() {
    var stepped = window.uPlot.paths.stepped({ align: 1 });
    var opts = {
      ...size('synohw-chart-duty'), axes: axes('fan %', [0, 20, 40, 70, 99]), legend: { show: false },
      series: [{}, { label: 'Fan duty sent', stroke: tok('--synohw-ink'), width: 2, paths: stepped, points: { show: false }, spanGaps: false, show: !S.hidden.duty },
               { label: 'Table duty (what the table chose)', stroke: tok('--synohw-ink-muted'), width: 1, dash: [4, 3], paths: stepped, points: { show: false }, spanGaps: false, show: !S.hidden.want }],
      scales: { x: { time: true, range: xRange }, y: { range: [0, 100] } },
      cursor: { sync: { key: 'synohw-hist' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { drawClear: [drawSweepBands], setCursor: [onCursor] }
    };
    return new window.uPlot(opts, dutyData(), el('synohw-chart-duty'));
  }
  function makeDx() {
    var stepped = window.uPlot.paths.stepped({ align: 1 }), ax = axes('DX level', [0, 1, 2, 3]);
    ax[1].values = function (u, v) { return v.map(function (x) { return DXNAME[x] || ''; }); };
    ax[1].size = 64;
    var opts = {
      ...size('synohw-chart-dx'), axes: ax, legend: { show: false },
      series: [{}, { label: 'DX1215 level (read back)', stroke: tok('--synohw-ink'), width: 2, paths: stepped, points: { show: false }, spanGaps: false, show: !S.hidden.dx },
               { label: 'DX1215 level wanted', stroke: tok('--synohw-ink-muted'), width: 1, dash: [4, 3], paths: stepped, points: { show: false }, spanGaps: false, show: !S.hidden.dxw }],
      scales: { x: { time: true, range: xRange }, y: { range: [-0.4, 3.4] } },
      cursor: { sync: { key: 'synohw-hist' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { drawClear: [drawSweepBands, drawDxAlarm], setCursor: [onCursor] }
    };
    return new window.uPlot(opts, dxData(), el('synohw-chart-dx'));
  }
  function xRange() { var now = (S.hist && S.hist.now) || Date.now() / 1000; return [now - S.range, now]; }
  function yRangeTemp(u, min, max) {
    var lo = min == null ? 20 : min, hi = max == null ? 50 : max, tb = S.meta && S.meta.tables && S.meta.tables[S.meta.mode];
    hi = Math.max(hi + 3, tb ? tb.disk_alert + 3 : 60);
    if (S.cpuThr && tb) hi = Math.max(hi, tb.cpu[tb.cpu.length - 1][0] + 3);
    return [Math.floor(Math.min(lo - 3, 25) / 5) * 5, Math.ceil(hi / 5) * 5];
  }

  /* ---------- legend (native checkboxes) + value under the cursor ---------- */
  function legendRows() {
    var rows = [], ck = coreKeys(), dk = dsKeys(), xk = dxKeys();
    function diskRow(k, chart, idx, sect) {
      var m = diskInfo(k), i = diskSlot(k);
      rows.push({ key: 'disk:' + k, chart: chart, idx: idx, sect: sect, name: diskName(k), colour: diskColour(i), dash: i < 7 ? [] : DASH[(i - 7) % 7 + 1], disk: k,
                  box: m ? (m.box === 'dx' ? 'dx' : m.box === 'ds' ? 'ds' : 'unknown') : 'unknown', boxText: m ? '' : 'not present now',
                  src: m ? ((m.serial ? 'serial ' + m.serial + ', ' : '') + m.device + (m.via ? ', ' + m.via : '')) : 'history key ' + k });
    }
    rows.push({ key: 'cpu', chart: 'temp', idx: 1, sect: 'ds', name: 'CPU (hottest sensor)', colour: tok('--synohw-series-1'), dash: [], box: 'ds', src: 'coretemp, max of ' + ck.length + ' sensor(s)' });
    ck.forEach(function (k, i) { rows.push({ key: 'core:' + k, chart: 'temp', idx: 2 + i, sect: 'ds', name: coreName(k), colour: tok('--synohw-series-1'), dash: DASH[(i % 7) + 1], box: 'ds', src: k, core: true }); });
    dk.forEach(function (k, i) { diskRow(k, 'temp', 2 + ck.length + i, 'ds'); });
    rows.push({ key: 'duty', chart: 'duty', idx: 1, sect: 'ds', name: 'Fan duty sent', colour: tok('--synohw-ink'), dash: [], box: 'ds', src: 'V<nn> to the MCU (no tach: the speed itself cannot be read)' });
    rows.push({ key: 'want', chart: 'duty', idx: 2, sect: 'ds', name: 'Table duty (what the table chose)', colour: tok('--synohw-ink-muted'), dash: [4, 3], box: 'ds', src: 'differs from "sent" during a fan sweep and in DS_MODE MANUAL' });
    xk.forEach(function (k, i) { diskRow(k, 'dxtemp', 1 + i, 'dx'); });
    if (hasDx()) {
      rows.push({ key: 'dx', chart: 'dx', idx: 1, sect: 'dx', name: 'Fan level (read back)', colour: tok('--synohw-ink'), dash: [], box: 'dx', src: '/sys/kernel/synology_dx/unit/fan_level after the decision (no RPM exists: 2-bit CPLD level)' });
      rows.push({ key: 'dxw', chart: 'dx', idx: 2, sect: 'dx', name: 'Fan level wanted', colour: tok('--synohw-ink-muted'), dash: [4, 3], box: 'dx', src: 'what the daemon decided (mode, table, overrides); red band = fan alarm bit set' });
    }
    return rows;
  }
  /* the same badge the PHP side draws (synohw_badge): icon + the unit's name in text */
  function badge(b, extra) {
    var k = b === 'ds' || b === 'dx' ? b : 'unknown', name = k === 'unknown' ? 'unit unknown' : boxName(k);
    return '<span class="synohw-badge synohw-badge-' + k + '">' + (k !== 'unknown' ? '<svg class="synohw-icon" aria-hidden="true" focusable="false" width="16" height="16"><use href="#synohw-i-' + k + '"/></svg>' : '')
      + esc(name) + (extra ? ' <span class="synohw-badge-role">' + esc(extra) + '</span>' : '') + '</span>';
  }
  function swatch(r) {
    var d = r.dash && r.dash.length ? 'stroke-dasharray="' + r.dash.join(' ') + '"' : '';
    return '<svg class="synohw-swatch" width="28" height="10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" stroke="' + esc(r.colour) + '" stroke-width="' + (r.core ? 1.5 : 3) + '" ' + d + '/></svg>';
  }
  function buildLegend() {
    var rows = legendRows();
    ['ds', 'dx'].forEach(function (sect) {
      var box = el('synohw-legend-' + sect); if (!box) return;
      var mine = rows.filter(function (r) { return r.sect === sect; });
      if (!mine.length) { box.innerHTML = ''; return; }
      var o = '<table class="unraid synohw-legend"><thead><tr><td>Show</td><td>Series</td><td>At the cursor</td><td>Unit</td><td>Source</td></tr></thead><tbody>';
      mine.forEach(function (r) {
        var on = r.core ? S.cores && !S.hidden[r.key] : !S.hidden[r.key], id = 'synohw-ser-' + r.key.replace(/[^A-Za-z0-9_-]/g, '_');
        o += '<tr' + (r.core && !S.cores ? ' hidden' : '') + ' data-key="' + esc(r.key) + '"><td><input type="checkbox" id="' + id + '" data-key="' + esc(r.key) + '"' + (on ? ' checked' : '') + '></td>'
           + '<td><label for="' + id + '">' + swatch(r) + esc(r.name) + '</label></td><td class="synohw-val" data-key="' + esc(r.key) + '">-</td><td>' + badge(r.box, r.boxText) + '</td><td class="whitespace-normal">' + esc(r.src) + '</td></tr>';
      });
      box.innerHTML = o + '</tbody></table>';
    });
    S.rows = rows;
    $('.synohw-legend-box input[type=checkbox]').on('change', function () {
      var k = $(this).data('key'), r = rows.filter(function (x) { return x.key === k; })[0];
      S.hidden[k] = !this.checked;
      var u = S.charts[r.chart]; if (u) u.setSeries(r.idx, { show: this.checked });
      if ((r.chart === 'temp' || r.chart === 'dxtemp') && u) u.redraw(false);
    });
    updateValues(S.hoverIdx);
  }
  /* text for one series at history sample i (exact value + unit, or why there is none) */
  function valueText(r, i) {
    var h = S.hist; if (i == null || !h) return '-';
    if (r.key === 'cpu') return C(h.cpu[i]);
    if (r.key === 'duty') return h.duty[i] == null || h.duty[i] < 0 ? 'none sent yet' : h.duty[i] + ' %' + (h.sweep[i] ? ' (sweep step ' + h.sweep[i] + ')' : '');
    if (r.key === 'want') { var w = h.want[i], md = { L: 'LOW', H: 'HIGH' }[h.mode.charAt(i)]; return w == null ? '-' : w + ' %' + (md ? ' (' + md + ' table: ' + WHY[h.why.charAt(i)] + ')' : ''); }
    if (r.key === 'dx' || r.key === 'dxw') {
      var c = (r.key === 'dx' ? h.dx : h.dxw).charAt(i), v = dxVal(c), al = h.dxal[i];
      if (v == null) return c === 'n' ? 'not controlled (' + DXWHY[h.dxy.charAt(i)] + ')' : 'not recorded';
      return DXNAME[v] + (r.key === 'dxw' ? ' (' + (DXWHY[h.dxy.charAt(i)] || '?') + ')' : (al ? ' · ALARM fan' + (al === 3 ? ' 1+2' : al === 2 ? ' 2' : ' 1') : ''));
    }
    if (r.core) return C(h.cores[r.key.slice(5)][i]);
    if (r.disk != null) {
      var st = h.dst[r.disk].charAt(i), v = h.disks[r.disk][i];
      return st === 'n' ? C(v) : st === 's' ? 'standby (not woken)' : st === 'u' ? 'spun up, no reading' : 'not in this sample';
    }
    return '-';
  }
  function updateValues(i) {
    if (!S.rows) return;
    var h = S.hist, at = i == null ? 'latest sample' : 'sample';
    var last = i == null && h && h.t.length ? h.t.length - 1 : i;
    S.rows.forEach(function (r) { $('.synohw-legend-box td.synohw-val[data-key="' + r.key + '"]').text(valueText(r, last)); });
    $('#synohw-legend-at').text(h && last != null && h.t[last] ? (at + ' at ' + hhmm(h.t[last], true)) : '');
  }
  function onCursor(u) {
    var i = u.cursor.idx, t = i == null ? null : u.data[0][i], hi = t == null ? null : histIdx(t);
    S.hoverIdx = hi;
    updateValues(hi);
    tooltip(u, hi);
  }
  function tooltip(u, i) {
    var tip = u.root.querySelector('.synohw-tip');
    if (!tip) { tip = document.createElement('div'); tip.className = 'synohw-tip'; tip.setAttribute('aria-hidden', 'true'); u.over.appendChild(tip); }
    if (i == null || u.cursor.left == null || u.cursor.left < 0) { tip.style.display = 'none'; return; }
    var chart = Object.keys(S.charts).filter(function (c) { return S.charts[c] === u; })[0], o = '<b>' + esc(hhmm(S.hist.t[i], true)) + '</b>';
    S.rows.forEach(function (r) {
      if (r.chart !== chart || !u.series[r.idx] || !u.series[r.idx].show) return;
      o += '<br>' + swatch(r) + esc(r.name) + ': <b>' + esc(valueText(r, i)) + '</b>';
    });
    tip.innerHTML = o; tip.style.display = 'block';
    var x = u.cursor.left, w = tip.offsetWidth, W = u.over.clientWidth;
    tip.style.left = (x + 14 + w > W ? Math.max(0, x - 14 - w) : x + 14) + 'px';
    tip.style.top = '8px';
  }

  /* keyboard: Left/Right one sample, Shift = 10, Home/End; Escape hides the cursor */
  function keys(u, id) {
    el(id).addEventListener('keydown', function (e) {
      var n = u.data[0].length; if (!n) return;
      var i = u.cursor.idx == null ? n - 1 : u.cursor.idx, step = e.shiftKey ? 10 : 1;
      if (e.key === 'ArrowLeft') i = Math.max(0, i - step); else if (e.key === 'ArrowRight') i = Math.min(n - 1, i + step);
      else if (e.key === 'Home') i = 0; else if (e.key === 'End') i = n - 1;
      else if (e.key === 'Escape') { u.setCursor({ left: -10, top: -10 }); return; } else return;
      e.preventDefault();
      u.setCursor({ left: u.valToPos(u.data[0][i], 'x'), top: u.bbox.height / (window.devicePixelRatio || 1) / 2 });
    });
  }

  /* ---------- states ---------- */
  function msg(text, cls) { var m = el('synohw-graphs-msg'); m.className = cls || 'grey-text'; m.textContent = text; m.hidden = !text; }
  function coverage() {
    var h = S.hist, m = S.meta, txt;
    if (!h.exists) return msg('No history yet: the daemon writes the first sample to /var/run/synology-hardware/history within ' + ((m && m.period) || 30) + ' s of its start. The file is in RAM, so it starts empty at every boot.', 'orange-text');
    if (!h.t.length) return msg('No sample in the last ' + RANGES[S.range] + '. History is in RAM and starts empty at every boot; ' + (m && m.first ? 'the oldest sample is from ' + hhmm(m.first) + '.' : 'the file is empty.'), 'orange-text');
    var span = h.now - h.t[0];
    txt = h.t.length + ' samples, ' + hhmm(h.t[0]) + ' to ' + hhmm(h.t[h.t.length - 1]) + '.';
    if (span < S.range - 300) txt += ' The history covers only the last ' + dur(span) + ' of the ' + RANGES[S.range] + ' selected: it lives in RAM and starts empty at every boot.';
    msg(txt, 'grey-text');
  }
  function aria() {
    var h = S.hist, parts = [];
    if (!h || !h.t.length) return;
    function mm(a) { var v = a.filter(function (x) { return x != null; }); return v.length ? Math.min.apply(null, v) + ' to ' + Math.max.apply(null, v) : 'no reading'; }
    parts.push('CPU ' + mm(h.cpu) + ' degrees C');
    dsKeys().forEach(function (k) { parts.push(diskName(k) + ' ' + mm(h.disks[k])); });
    el('synohw-chart-temp').setAttribute('aria-label', boxName('ds') + ' temperatures over the last ' + RANGES[S.range] + ', ' + parts.join('; ') + '. Exact values: the legend table below, and the Temperatures table on the unit\'s tab.');
    if (el('synohw-chart-dxtemp')) el('synohw-chart-dxtemp').setAttribute('aria-label', boxName('dx') + ' disk temperatures over the last ' + RANGES[S.range] + ', ' + (dxKeys().map(function (k) { return diskName(k) + ' ' + mm(h.disks[k]); }).join('; ') || 'no disk') + '.');
    el('synohw-chart-duty').setAttribute('aria-label', 'Fan duty sent over the last ' + RANGES[S.range] + ': ' + mm(h.duty.filter(function (x) { return x >= 0; })) + ' percent.');
    if (el('synohw-chart-dx')) {
      var lv = {}; h.dx.split('').forEach(function (c) { if (dxVal(c) != null) lv[DXNAME[dxVal(c)]] = 1; });
      el('synohw-chart-dx').setAttribute('aria-label', 'DX1215 fan level over the last ' + RANGES[S.range] + ': ' + (Object.keys(lv).join(', ') || 'not recorded') + (h.dxal.some(function (x) { return x; }) ? '; a fan alarm was set at least once' : '') + '.');
    }
  }

  /* ---------- data flow ---------- */
  function rebuild() {
    ['temp', 'duty', 'dx', 'dxtemp'].forEach(function (c) { if (S.charts[c]) { S.charts[c].destroy(); S.charts[c] = null; } });
    if (!S.hist || !S.hist.t.length) { el('synohw-graphs-plots').hidden = true; coverage(); return; }
    el('synohw-graphs-plots').hidden = false;
    S.charts.temp = makeTemp(); S.charts.duty = makeDuty();
    keys(S.charts.temp, 'synohw-chart-temp'); keys(S.charts.duty, 'synohw-chart-duty');
    if (el('synohw-chart-dx')) {   // 2026.10.25h: only when the range holds at least one recorded DX level
      var dxOn = hasDx(); el('synohw-chart-dx').hidden = !dxOn; el('synohw-chart-dx-h').hidden = !dxOn;
      var dm = el('synohw-chart-dx-msg'); if (dm) { dm.hidden = dxOn; }
      if (dxOn) { S.charts.dx = makeDx(); keys(S.charts.dx, 'synohw-chart-dx'); }
    }
    if (el('synohw-chart-dxtemp')) {   // 2026.10.25j: the expansion unit's disks, when the history holds any
      var dxt = dxKeys().length > 0; el('synohw-chart-dxtemp').hidden = !dxt; el('synohw-chart-dxtemp-h').hidden = !dxt;
      var tm = el('synohw-chart-dxtemp-msg'); if (tm) tm.hidden = dxt;
      if (dxt) { S.charts.dxtemp = makeDxTemp(); keys(S.charts.dxtemp, 'synohw-chart-dxtemp'); }
    }
    buildLegend(); coverage(); aria();
    if (S.sweep) sweepBuild();   // its disk colours follow the history's
  }
  function load() {
    S.loading = true;
    msg('Loading the last ' + RANGES[S.range] + ' of history ...', 'grey-text');
    $.post(URL_, { action: 'history', range: S.range }).done(function (r) {
      if (!r || !r.ok || !r.hist) { S.failAt = Date.now(); msg('History request failed: ' + ((r && r.error) || 'unexpected reply') + '; retrying every 30 s.', 'red-text'); return; }
      S.meta = r.meta; S.hist = r.hist; rebuild();
    }).fail(function (x, t) {
      S.failAt = Date.now();
      msg('History request failed (HTTP ' + x.status + (t ? ', ' + t : '') + '). The tables below still show min/max; retrying every 30 s.', 'red-text');
    }).always(function () { S.loading = false; });
  }
  /* parameters the page's 5 s poll adds, so one request carries the live HTML and the new samples */
  function pollParams() {
    var p = {};
    if (!S.hist && !S.loading && S.failAt && Date.now() - S.failAt >= 30000) load();   // the first load failed: try again
    if (S.hist && !S.loading && visible(el('synohw-graphs'))) { p.range = S.range; p.since = S.hist.t.length ? S.hist.t[S.hist.t.length - 1] : 0; }
    if (el('synohw-sweep-graphs') && visible(el('synohw-sweep-box'))) { p.sweep_since = S.sweep && S.sweep.t.length ? S.sweep.t[S.sweep.t.length - 1] : 0; p.sweep_id = S.sweep ? S.sweep.id : 0; }
    return p;
  }
  function append(d) {
    var h = S.hist; if (!h || d.range !== S.range) return;
    var n0 = h.t.length, keysChanged = false, from = d.now - S.range, i, k, cut = 0, dx0 = hasDx();
    Object.keys(d.disks).forEach(function (k) { if (!h.disks[k]) keysChanged = true; });
    Object.keys(d.cores).forEach(function (k) { if (!h.cores[k]) keysChanged = true; });
    if (keysChanged) { load(); return; }                // a disk appeared: rebuild with its own colour and legend row
    for (i = 0; i < d.t.length; i++) {
      h.t.push(d.t[i]); h.cpu.push(d.cpu[i]); h.duty.push(d.duty[i]); h.want.push(d.want[i]); h.sweep.push(d.sweep[i]); h.dxal.push(d.dxal[i]);
      for (k in h.disks) { h.disks[k].push(d.disks[k] ? d.disks[k][i] : null); h.dst[k] += d.dst[k] ? d.dst[k].charAt(i) : '-'; }
      for (k in h.cores) h.cores[k].push(d.cores[k] ? d.cores[k][i] : null);
    }
    h.mode += d.mode; h.why += d.why; h.dsm += d.dsm; h.dx += d.dx; h.dxw += d.dxw; h.dxy += d.dxy;
    while (cut < h.t.length && h.t[cut] < from) cut++;   // keep exactly the selected range
    if (cut) {
      ['t', 'cpu', 'duty', 'want', 'sweep', 'dxal'].forEach(function (c) { h[c].splice(0, cut); });
      ['mode', 'why', 'dsm', 'dx', 'dxw', 'dxy'].forEach(function (c) { h[c] = h[c].slice(cut); });
      for (k in h.disks) { h.disks[k].splice(0, cut); h.dst[k] = h.dst[k].slice(cut); }
      for (k in h.cores) h.cores[k].splice(0, cut);
    }
    h.now = d.now; h.exists = d.exists;
    if ((!n0 && h.t.length) || hasDx() !== dx0) { rebuild(); return; }   // first rows, or the DX chart appears/disappears
    if (S.charts.temp) { S.charts.temp.setData(tempData()); S.charts.duty.setData(dutyData()); if (S.charts.dx) S.charts.dx.setData(dxData()); if (S.charts.dxtemp) S.charts.dxtemp.setData(dxTempData()); updateValues(S.hoverIdx); coverage(); aria(); }
    else coverage();
  }
  function update(r) {
    if (r.meta) S.meta = r.meta;
    if (r.hist) append(r.hist);
    if (r.sweep) sweepUpdate(r.sweep);
  }

  /* ---------- fan sweep chart (sweep.csv), in the sweep panel ---------- */
  function sweepCols() {
    var w = S.sweep, keys = Object.keys(w.cols).filter(function (k) { return k === 'cpu_max' || k.indexOf('disk:') === 0; });
    return keys;
  }
  function sweepName(k) {
    if (k === 'cpu_max') return 'CPU (hottest sensor)';
    var m = S.sweep.disks && S.sweep.disks[k], id = k.slice(5);
    return m ? m.device + ' · ' + (m.model || id) + ' · ' + m.box_label : id + ' (not present now)';
  }
  /* the same colour as the disk's line in the history graphs (colour follows the disk, matched by device), else by order */
  function sweepSlot(k, fallback) {
    var m = S.sweep.disks && S.sweep.disks[k], dk = diskKeys(), i;
    if (m) for (i = 0; i < dk.length; i++) { var h = diskInfo(dk[i]); if (h && h.device === m.device) return i; }
    return fallback;
  }
  function sweepBuild() {
    ['stemp', 'sduty'].forEach(function (c) { if (S.charts[c]) { S.charts[c].destroy(); S.charts[c] = null; } });
    var w = S.sweep, box = el('synohw-sweep-graphs'), m = el('synohw-sweep-msg');
    if (!box) return;
    if (!w.exists || !w.t.length) {
      box.hidden = true; m.hidden = false;
      m.textContent = w.error ? 'sweep.csv could not be read: ' + w.error + '.' : 'No sweep data: /var/run/synology-hardware/sweep.csv does not exist (no sweep since boot) or has no sample yet.';
      return;
    }
    box.hidden = false; m.hidden = true;
    var ks = sweepCols(), series = [{}], data = [w.t.slice()], di = 0, legend = '';
    ks.forEach(function (k) {
      var col = k === 'cpu_max' ? tok('--synohw-series-1') : diskColour(sweepSlot(k, di++));
      series.push({ label: sweepName(k), stroke: col, width: 2, spanGaps: false, points: { show: false } });
      data.push(w.cols[k].slice());
      legend += '<li>' + swatch({ colour: col, dash: [] }) + esc(sweepName(k)) + '</li>';
    });
    el('synohw-sweep-legend').innerHTML = legend;
    function stepLines(u) {   // a vertical line + label at each step start: "step 2: 15 %"
      var ctx = u.ctx, dpr = window.devicePixelRatio || 1, i;
      ctx.save(); ctx.font = (11 * dpr) + 'px ' + (tok('--synohw-font') || 'sans-serif'); ctx.textBaseline = 'top'; ctx.textAlign = 'left';
      for (i = 0; i < w.t.length; i++) {
        if (i && w.step[i] === w.step[i - 1]) continue;
        var x = Math.round(u.valToPos(w.t[i], 'x', true)) + 0.5;
        ctx.strokeStyle = tok('--synohw-threshold'); ctx.lineWidth = dpr; ctx.beginPath(); ctx.moveTo(x, u.bbox.top); ctx.lineTo(x, u.bbox.top + u.bbox.height); ctx.stroke();
        if (u === S.charts.sduty || !S.charts.sduty) { ctx.fillStyle = tok('--synohw-ink-muted'); ctx.fillText('step ' + w.step[i] + ': ' + w.duty[i] + ' %', x + 3 * dpr, u.bbox.top + 2 * dpr); }
      }
      ctx.restore();
    }
    function sweepTip(u) {
      var i = u.cursor.idx, tip = u.root.querySelector('.synohw-tip');
      if (!tip) { tip = document.createElement('div'); tip.className = 'synohw-tip'; tip.setAttribute('aria-hidden', 'true'); u.over.appendChild(tip); }
      if (i == null || u.cursor.left == null || u.cursor.left < 0) { tip.style.display = 'none'; el('synohw-sweep-at').textContent = ''; return; }
      var o = '<b>' + esc(hhmm(w.t[i], true)) + '</b>, step ' + esc(w.step[i]) + ', duty sent <b>' + esc(w.duty[i]) + ' %</b>', txt = hhmm(w.t[i], true) + ': step ' + w.step[i] + ', duty ' + w.duty[i] + ' %';
      ks.forEach(function (k, j) {   // the duty graph's tip shows the duty only; the text line below always has everything
        if (u !== S.charts.sduty) o += '<br>' + swatch({ colour: series[j + 1].stroke, dash: [] }) + esc(sweepName(k)) + ': <b>' + esc(C(w.cols[k][i])) + '</b>';
        txt += '; ' + sweepName(k) + ' ' + C(w.cols[k][i]);
      });
      tip.innerHTML = o; tip.style.display = 'block';
      var x = u.cursor.left, tw = tip.offsetWidth, W = u.over.clientWidth;
      tip.style.left = (x + 14 + tw > W ? Math.max(0, x - 14 - tw) : x + 14) + 'px'; tip.style.top = '8px';
      el('synohw-sweep-at').textContent = txt;
    }
    var sx = function () { return [w.t[0] - 30, Math.max(w.t[w.t.length - 1] + 30, w.t[0] + 600)]; };
    S.charts.stemp = new window.uPlot({
      ...size('synohw-sweep-temp'), series: series, axes: axes('°C'), legend: { show: false },
      scales: { x: { time: true, range: sx }, y: { range: function (u, a, b) { return [Math.floor(((a == null ? 25 : a) - 3) / 5) * 5, Math.ceil(Math.max((b == null ? 40 : b) + 3, 49) / 5) * 5]; } } },
      cursor: { sync: { key: 'synohw-sweep' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { draw: [stepLines, drawSweepAbort], setCursor: [sweepTip] }
    }, data, el('synohw-sweep-temp'));
    S.charts.sduty = new window.uPlot({
      ...size('synohw-sweep-duty'), axes: axes('fan %', [10, 20, 40, 70, 99]), legend: { show: false },
      series: [{}, { label: 'Duty sent', stroke: tok('--synohw-ink'), width: 2, points: { show: false }, paths: window.uPlot.paths.stepped({ align: 1 }) }],
      scales: { x: { time: true, range: sx }, y: { range: [0, 100] } },
      cursor: { sync: { key: 'synohw-sweep' }, drag: { x: false, y: false }, y: false, points: { size: 8 } },
      hooks: { draw: [stepLines], setCursor: [sweepTip] }
    }, [w.t.slice(), w.duty.slice()], el('synohw-sweep-duty'));
    keys(S.charts.stemp, 'synohw-sweep-temp'); keys(S.charts.sduty, 'synohw-sweep-duty');
    el('synohw-sweep-temp').setAttribute('aria-label', 'Fan sweep temperatures, ' + w.t.length + ' samples from ' + hhmm(w.t[0]) + ' to ' + hhmm(w.t[w.t.length - 1]) + '. Per-step numbers: sweep-summary.txt.');
  }
  /* the sweep's own abort limits (disk 46 C, CPU 65 C: rc.synology-hardware sweep help) as reference lines */
  function drawSweepAbort(u) {
    var ctx = u.ctx, dpr = window.devicePixelRatio || 1, l = u.bbox.left, wd = u.bbox.width;
    [[46, 'sweep aborts at disk 46 °C'], [65, 'sweep aborts at CPU 65 °C']].forEach(function (a) {
      if (a[0] < u.scales.y.min || a[0] > u.scales.y.max) return;
      var y = Math.round(u.valToPos(a[0], 'y', true)) + 0.5;
      ctx.save(); ctx.strokeStyle = tok('--synohw-alert'); ctx.lineWidth = dpr; ctx.setLineDash([5 * dpr, 4 * dpr]);
      ctx.beginPath(); ctx.moveTo(l, y); ctx.lineTo(l + wd, y); ctx.stroke(); ctx.setLineDash([]);
      ctx.fillStyle = tok('--synohw-alert-text'); ctx.font = (11 * dpr) + 'px ' + (tok('--synohw-font') || 'sans-serif');
      ctx.textAlign = 'right'; ctx.textBaseline = 'bottom'; ctx.fillText(a[1], l + wd - 4 * dpr, y - 2 * dpr); ctx.restore();
    });
  }
  function sweepUpdate(d) {
    if (!el('synohw-sweep-graphs')) return;
    if (!S.sweep || d.reset || !d.since || d.id !== S.sweep.id) { S.sweep = d; sweepBuild(); return; }
    if (!d.t.length) return;
    var w = S.sweep;
    d.t.forEach(function (t, i) {
      w.t.push(t); w.step.push(d.step[i]); w.duty.push(d.duty[i]);
      Object.keys(w.cols).forEach(function (k) { w.cols[k].push(d.cols[k] ? d.cols[k][i] : null); });
    });
    if (!S.charts.stemp) { sweepBuild(); return; }
    var data = [w.t.slice()]; sweepCols().forEach(function (k) { data.push(w.cols[k].slice()); });
    S.charts.stemp.setData(data); S.charts.sduty.setData([w.t.slice(), w.duty.slice()]);
  }
  function sweepLoad() {
    $.post(URL_, { action: 'sweep' }).done(function (r) { if (r && r.ok && r.sweep) { S.sweep = r.sweep; sweepBuild(); } })
      .fail(function (x) { var m = el('synohw-sweep-msg'); if (m) { m.hidden = false; m.className = 'red-text'; m.textContent = 'Sweep data request failed (HTTP ' + x.status + ').'; } });
  }

  function init() {
    if (!el('synohw-graphs')) return;
    if (!window.uPlot) { msg('The chart library (vendor/uplot-1.6.32) did not load; the tables below still show every value.', 'red-text'); return; }
    document.documentElement.setAttribute('data-synohw-scheme', scheme());   // 2026.10.25j: the tokens live on :root (sheets/SynologyHWOverview.css)
    try { var r = window.localStorage.getItem('synohw-range'); if (RANGES[r]) S.range = +r; } catch (e) { /* storage blocked: default 24 h */ }
    $('input[name=synohw-range][value=' + S.range + ']').prop('checked', true);
    $('input[name=synohw-range]').on('change', function () {
      S.range = +this.value; try { window.localStorage.setItem('synohw-range', S.range); } catch (e) { /* not kept */ }
      load();
    });
    $('#synohw-cores').on('change', function () {
      S.cores = this.checked;
      $('.synohw-legend-box tr[data-key^="core:"]').prop('hidden', !S.cores);
      if (!S.rows) return;
      S.rows.forEach(function (r) {
        if (!r.core) return;
        $('.synohw-legend-box input[data-key="' + r.key + '"]').prop('checked', S.cores && !S.hidden[r.key]);
        if (S.charts.temp) S.charts.temp.setSeries(r.idx, { show: S.cores && !S.hidden[r.key] });
      });
    });
    $('#synohw-cputhr').on('change', function () { S.cpuThr = this.checked; if (S.charts.temp) S.charts.temp.setData(tempData()); });   // setData re-runs yRangeTemp
    var ro = window.ResizeObserver ? new window.ResizeObserver(function () {
      [['temp', 'synohw-chart-temp'], ['duty', 'synohw-chart-duty'], ['dx', 'synohw-chart-dx'], ['dxtemp', 'synohw-chart-dxtemp'], ['stemp', 'synohw-sweep-temp'], ['sduty', 'synohw-sweep-duty']].forEach(function (c) {
        if (S.charts[c[0]] && el(c[1]) && el(c[1]).clientWidth) S.charts[c[0]].setSize(size(c[1]));   // hidden tab: width 0, keep the size
      });
    }) : null;
    if (ro) { ro.observe(el('synohw-graphs')); if (el('synohw-sweep-graphs')) ro.observe(el('synohw-sweep-box')); }
    load();
    if (el('synohw-sweep-graphs')) sweepLoad();
  }

  window.SynoHWCharts = { init: init, pollParams: pollParams, update: update };
}(window, window.jQuery));
