/* synohw-page.js - live updates for the tabbed synology-hardware page (2026.10.25j). Written: 2026-10-25.
 * Every 5 s one POST to include/status.php (Unraid's $.ajaxPrefilter adds the csrf_token) asks only for the live blocks of the
 * visible tab ([data-synohw-block] elements that are rendered: a hidden tab's panel is display:none, MainContentTabbed.php),
 * plus the graph rows when the History tab is visible (SynoHWCharts.pollParams). The next poll starts only after the previous
 * one ended. A tab click polls at once. A block is not replaced while the keyboard focus is inside it (the diagram's bays),
 * nor when its HTML did not change. The Hardware section refreshes every 60 s while visible (action=hardware). */
(function (window, $) {
  'use strict';
  var URL_ = '/plugins/synology-hardware/include/status.php', EVERY = 5000, HW_EVERY = 60000;
  var boot, last, timer = null, busy = false, seen = {}, hwAt = Date.now(), hwLast;

  function visible(e) { return !!(e && e.offsetParent !== null); }
  function text(html) { return $('<div>').html(html || '').text(); }
  function say(cls, t) { $('.synohw-live-state').attr('class', 'synohw-live-state ' + cls).text(t); }
  /* light or dark tokens, from the colour the page really paints (any Unraid theme) */
  function scheme() {
    var m = (window.getComputedStyle(document.body).backgroundColor || '').match(/\d+(\.\d+)?/g);
    if (!m || m.length < 3) return;
    document.documentElement.setAttribute('data-synohw-scheme', (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255 > 0.5 ? 'light' : 'dark');
  }
  function blocks() {
    var k = {};
    $('[data-synohw-block]').each(function () { if (visible(this)) k[this.getAttribute('data-synohw-block')] = 1; });
    return Object.keys(k);
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(poll, EVERY); }
  function poll() {
    timer = null;
    if (document.hidden) { schedule(); return; }
    var b = blocks(), p = window.SynoHWCharts ? window.SynoHWCharts.pollParams() : {};
    if (!b.length && p.range === undefined && p.sweep_since === undefined) { schedule(); return; }   // nothing live on this tab
    p.blocks = b.join(',');
    busy = true;
    $.post(URL_, p).done(function (r) {
      if (r && r.ok && r.html) {
        $.each(r.html, function (k, v) {
          if (seen[k] === v) return;
          var $b = $('[data-synohw-block="' + k + '"]');
          if ($b.find(document.activeElement).length) return;   // keep the keyboard focus; next poll
          seen[k] = v; $b.html(v);
        });
        if (window.SynoHWCharts) window.SynoHWCharts.update(r);
        last = r.time;
        say('green-text', 'Live: updated ' + r.time + ', every 5 s');
      } else {
        say('red-text', 'Live update failed: ' + text((r && r.error) || 'unexpected reply') + '. Values shown are from ' + last + '.');
      }
    }).fail(function (x, t) {
      say('red-text', 'Live update failed (HTTP ' + x.status + (t ? ', ' + t : '') + '). Values shown are from ' + last + '; retrying every 5 s.');
    }).always(function () { busy = false; schedule(); });
  }
  function pollNow() { if (busy) return; clearTimeout(timer); poll(); }

  function hwPoll(force) {
    var box = document.getElementById('synohw-hw'), $st = $('#synohw-hw-state');
    if (!box || !visible(box) || document.hidden || (!force && Date.now() - hwAt < HW_EVERY)) return;
    hwAt = Date.now();
    $.post(URL_, { action: 'hardware' }).done(function (r) {
      if (r && r.ok && r.html) { $(box).html(r.html); hwLast = r.time; $st.attr('class', 'grey-text').text('Updated ' + r.time + ', every 60 s while this tab is open; SMART and Wake-on from the daemon\'s cache (every 10 min).'); }
      else $st.attr('class', 'red-text').text('Hardware update failed: ' + text((r && r.error) || 'unexpected reply') + '. Shown: ' + hwLast + '.');
    }).fail(function (x) { $st.attr('class', 'red-text').text('Hardware update failed (HTTP ' + x.status + '). Shown: ' + hwLast + '; retrying every 60 s.'); });
  }

  $(function () {
    boot = document.getElementById('synohw-boot'); if (!boot) return;
    last = hwLast = boot.getAttribute('data-time');
    scheme();
    $('[data-synohw-block]').each(function () { seen[this.getAttribute('data-synohw-block')] = this.innerHTML; });
    say('grey-text', 'Live: values from page load (' + last + '); first update in 5 s');
    $('#synohw-hw-state').text('Values from page load (' + last + '); updated every 60 s while this tab is open.');
    if (window.SynoHWCharts) window.SynoHWCharts.init();
    schedule();
    setInterval(function () { hwPoll(false); }, EVERY);
    // Unraid's tab buttons (MainContentTabbed.php): the newly shown tab gets its values at once
    $(document).on('click', '.tabs [role="tab"]', function () { setTimeout(function () { pollNow(); hwPoll(false); }, 30); });
    // "more" links in the Overview cards: open that tab the way a click on it does
    $(document).on('click', '[data-synohw-goto]', function (e) {
      var t = $('.tabs [role="tab"]').filter(function () { return $(this).text().trim() === e.currentTarget.getAttribute('data-synohw-goto'); });
      if (t.length) { e.preventDefault(); t.first().trigger('click'); window.scrollTo(0, 0); }
    });
  });
}(window, window.jQuery));
