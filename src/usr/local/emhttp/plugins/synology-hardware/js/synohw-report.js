/* synohw-report.js - Report tab (synology-hardware 2026.10.26b). Written: 2026-10-26.
 * Collect: one POST to include/report.php (Unraid's $.ajaxPrefilter adds the csrf_token). Copy: Clipboard API, else select + execCommand.
 * Download: a Blob, saved by the browser. Open GitHub issue: a new tab on the issue form with title and body (form field id "body") in
 * the URL; a report too long for a URL opens the form with a note and downloads the file to attach. Nothing is sent from the NAS. */
(function (window, document, $) {
  'use strict';
  var URL_ = '/plugins/synology-hardware/include/report.php';
  var ISSUE = 'https://github.com/luuvasara/unraid-synology-hardware/issues/new';
  var MAX_URL = 7000;   // GitHub answers 414 above its server limit; stay well below ~8 KB
  var rep = null;
  function $id(i) { return document.getElementById(i); }
  function say(cls, t) { var e = $id('synohw-report-state'); if (e) { e.className = cls; e.textContent = t; } }
  function enable(on) { ['copy', 'download', 'issue'].forEach(function (k) { var b = $id('synohw-report-' + k); if (b) b.disabled = !on; }); }
  function fname() { return 'synology-hardware-report-' + new Date().toISOString().slice(0, 10) + '.txt'; }
  function download() {
    if (!rep) return;
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([rep.text], { type: 'text/plain;charset=utf-8' }));
    a.download = fname(); document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    say('green-text', 'Downloaded ' + a.download + '.');
  }
  function copy() {
    if (!rep) return;
    var ta = $id('synohw-report-text');
    var done = function () { say('green-text', 'Report copied to the clipboard (' + rep.text.length + ' characters).'); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(rep.text).then(done, fallback); else fallback();
    function fallback() { ta.focus(); ta.select(); try { if (document.execCommand('copy')) { done(); return; } } catch (e) { /* below */ } say('orange-text', 'Copy is blocked by the browser here: the text is selected, press Ctrl+C.'); }
  }
  function issue() {
    if (!rep) return;
    var title = 'Hardware report: ' + rep.model + ' (plugin ' + rep.version + ')';
    var body = rep.text;   // the form's "body" field renders as a code block itself (render: text)
    var u = ISSUE + '?template=hardware-report.yml&title=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(body);
    if (u.length > MAX_URL) {
      var note = 'The report (' + rep.text.length + ' characters) is too long for a link. It was downloaded as ' + fname() + ': please attach that file here (drag it into this box).';
      u = ISSUE + '?template=hardware-report.yml&title=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(note);
      download();
      say('orange-text', 'Too long for a link: the form opens with a note, and the report was downloaded to attach.');
    }
    var w = window.open(u, '_blank');
    if (w) { try { w.opener = null; } catch (e) { /* cross-origin already */ } }
    else say('orange-text', 'The browser blocked the new tab. Allow pop-ups for this page, or copy the report and open ' + ISSUE + ' yourself.');
  }
  function collect() {
    var b = $id('synohw-report-collect'); b.disabled = true; enable(false);
    say('grey-text', 'Collecting (a few seconds)...');
    $.post(URL_, { action: 'collect' }).done(function (r) {
      if (r && r.ok && typeof r.text === 'string') {
        rep = { text: r.text, model: String(r.model || 'unidentified'), version: String(r.version || '?') };
        $id('synohw-report-text').value = r.text;
        var n = 0, k; for (k in (r.replaced || {})) n += r.replaced[k];
        say('green-text', 'Collected ' + r.text.length + ' characters; ' + n + ' value(s) replaced by placeholders. Read it before sending.');
        enable(true);
      } else say('red-text', 'Collecting failed: ' + String((r && r.error) || 'unexpected reply') + '.');
    }).fail(function (x) { say('red-text', 'Collecting failed (HTTP ' + x.status + ').'); }).always(function () { b.disabled = false; });
  }
  $(function () {
    if (!$id('synohw-report-collect')) return;
    $('#synohw-report-collect').on('click', collect);
    $('#synohw-report-copy').on('click', copy);
    $('#synohw-report-download').on('click', download);
    $('#synohw-report-issue').on('click', issue);
  });
}(window, document, window.jQuery));
