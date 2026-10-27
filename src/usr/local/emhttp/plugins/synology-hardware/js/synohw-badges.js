/* synohw-badges.js - "DS 1" / "DX 8" after each disk's device name on Unraid's Main page (synology-hardware 2026.10.26b).
 * Written: 2026-10-26. Visual only: asks include/bays.php (POST, read-only) which unit and bay each sdX is in, then appends a
 * badge to the device name (the row's first cell) of every table row with a cell ending in "(sdX)" (Unraid's device_desc():
 * "<id> - <size> (sdX)", nchan/device_list); the identification cell itself is clipped with an ellipsis.
 * The Main tables are re-rendered by nchan every few seconds, so a MutationObserver re-adds the badges before the next paint. Fails silently: if the
 * request fails, the reply is odd or no cell matches, nothing is added and nothing is logged. */
(function (window, document, $) {
  'use strict';
  if (!$ || !window.MutationObserver) return;
  var URL_ = '/plugins/synology-hardware/include/bays.php', map = null, busy = false, pending = null;
  var RE = /\(([a-z]{2,4}[a-z0-9]{0,12})\)\s*$/;
  function apply() {
    pending = null;
    if (!map) return;
    try {
      var cells = document.querySelectorAll('table tbody td');
      for (var i = 0; i < cells.length; i++) {
        var td = cells[i], tr = td.parentNode;
        if (!tr || tr.querySelector('.synohw-bay-badge') || td.querySelector('select, input')) continue;
        var m = RE.exec(td.textContent || '');
        if (!m || !Object.prototype.hasOwnProperty.call(map, m[1])) continue;
        var b = map[m[1]];
        if (!b || (b.u !== 'DS' && b.u !== 'DX') || typeof b.bay !== 'number') continue;
        var s = document.createElement('span');
        s.className = 'synohw-bay-badge';
        s.textContent = b.u + ' ' + b.bay;
        s.title = String(b.unit || b.u) + ' bay ' + b.bay + ' (Synology Hardware plugin)';
        // next to the device name ("Disk 1", "Dev 2") in the row's first cell; the identification cell is clipped with an ellipsis
        var host = tr.cells && tr.cells[0] && tr.cells[0] !== td ? tr.cells[0] : td;
        host.appendChild(s);
      }
    } catch (e) { /* the Main page changed shape: add nothing */ }
  }
  function ownBadges(m) {   // a mutation that only added our badges: ignore it, or apply() would trigger itself
    var n = m.addedNodes;
    if (!n || !n.length || m.removedNodes.length) return false;
    for (var i = 0; i < n.length; i++) if (!(n[i].classList && n[i].classList.contains('synohw-bay-badge'))) return false;
    return true;
  }
  function later() { if (!pending) pending = setTimeout(apply, 150); }
  function load() {
    if (busy || document.hidden) return;
    busy = true;
    $.post(URL_).done(function (r) {
      if (r && r.ok === true && r.bays && typeof r.bays === 'object') { map = r.bays; later(); }
    }).always(function () { busy = false; });
  }
  $(function () {
    load();
    setInterval(load, 60000);   // a disk added or moved shows up within a minute
    // Re-add in the observer callback itself, not after a timer: the callback runs before the browser paints, so a row that
    // nchan has just re-rendered never shows without its badge (a 150 ms delay made the badges blink on every refresh).
    try { new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) if (!ownBadges(ms[i])) { apply(); return; }
    }).observe(document.body, { childList: true, subtree: true }); } catch (e) { /* no observer: badges from the first pass only */ }
  });
}(window, document, window.jQuery));
