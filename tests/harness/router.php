<?php
/* Local render harness for SynologyHW.page (build host only; NOT the real webGui). Written 2026-10-25 (2026.10.25b), kept in the
 * repo since 2026.10.25f (tests/harness/serve.sh sets it up; earlier it lived only in a session scratchpad).
 * Mimics: page header split, parse_text() (Translations.php:63-66, copied), the real MarkdownExtra
 * library (copied from the 7.3.4 extract), the content wrapper (MainContentTabless.php), the dynamix
 * CSS in DefaultPageLayout.php order, the csrf $.ajaxPrefilter, HelpButton() and the help pinning JS
 * (BodyInlineJS.php:270-290, copied). Data comes from tests/mkfixture.py, never from this machine. */
$H = getenv('HARNESS_WORK'); $theme = getenv('THEME') ?: 'black';   // work dir made by serve.sh: webroot/ + lib/
$GLOBALS['theme'] = $theme;   // Unraid's $theme global (display.theme), read by synohw_page_head()
$GLOBALS['synohw_path_override'] = json_decode(file_get_contents(getenv('OVERRIDE')), true);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/plugins/synology-hardware/include/status.php') {
  if (getenv('FAIL')) { http_response_code(502); echo 'Bad Gateway'; return true; }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? '') !== 'HARNESS') { echo 'csrf'; return true; } // mimic local_prepend
  require "$H/webroot/plugins/synology-hardware/include/status.php"; return true;
}
if ($uri !== '/' && $uri !== '/Settings/SynologyHW') return false;   // static files from webroot
require_once "$H/lib/MarkdownExtra.inc.php";
function mk_option($select, $value, $text, $extra="") {   // Helpers.php:196-198, copied
  return "<option value='$value'".($value == $select ? " selected" : "").(strlen($extra) ? " $extra" : "").">$text</option>";
}
function harness_t($text) {   // Translations.php:49-51 with an empty $language
  $text = trim($text); return preg_replace(['/\*\*(.+?)\*\*/','/\*(.+?)\*/',"/'/"],['<b>$1</b>','<i>$1</i>','&apos;'],$text);
}
function translate($key) { return true; }   // no translation loaded: inline text is shown
function parse_text($text) {   // Translations.php:63-66 with harness_t for _()
  return preg_replace_callback('/_\((.+?)\)_/m',function($m){return harness_t($m[1]);},preg_replace(["/^:(.+_help):$/m","/^:(.+_plug):$/m","/^:end$/m"],["<?translate(\"_$1\");?>","<?if (translate(\"_$1\")):?>","<?endif;?>"],$text));
}
$docroot = "$H/webroot";
/* 2026.10.25j: the page is tabbed. Like DefaultPageLayout.php:52-58 + PageBuilder.php find_pages(): the parent SynologyHW.page
 * (Type="xmenu", no text) and its children Menu="SynologyHW:<n>", sorted by rank; each child rendered as in
 * MainContentTabbed.php (markup and script copied below), its sheet loaded as in includePageStylesheets. */
function harness_page(string $f): array {
  $raw = file_get_contents($f); $parts = explode("\n---\n", $raw, 2);
  preg_match_all('/^(\w+)="(.*)"$/m', $parts[0], $m);
  return array_combine($m[1], $m[2]) + ['name' => basename($f, '.page'), 'text' => $parts[1] ?? '', 'file' => $f];
}
$dir = "$docroot/plugins/synology-hardware";
$parent = harness_page("$dir/SynologyHW.page");
$pages = [];
foreach (glob("$dir/*.page") as $f) { $pg = harness_page($f); if (preg_match('/^SynologyHW:(\d+)$/', $pg['Menu'] ?? '', $mm)) $pages[$mm[1] . $pg['name']] = $pg; }
ksort($pages, SORT_NATURAL);
if (trim($parent['text']) !== '') $pages = ['0' => $parent] + $pages;
$rendered = [];
foreach ($pages as $k => $pg) $rendered[$k] = Markdown(parse_text($pg['text']));
file_put_contents("$H/last-render-" . getenv('SCENARIO') . ".html", implode("\n<!-- tab -->\n", $rendered));
function harness_tab_title($title, $tag) {   // PageBuilder.php tab_title for fa tags
  return "<span class='left inline-flex flex-row items-center gap-1'><i class='fa fa-" . htmlspecialchars($tag) . " title'></i>" . htmlspecialchars($title) . "</span>";
}
?><!DOCTYPE html>
<html lang="en" class="Theme--<?=$theme?>">
<head><meta charset="utf-8"><title>Server/SynologyHW (local harness)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php foreach (['default-fonts','default-cases','font-awesome','context.standalone','jquery.sweetalert','jquery.ui','default-color-palette','default-base','default-dynamix',"themes/$theme"] as $c): ?>
<link type="text/css" rel="stylesheet" href="/webGui/styles/<?=$c?>.css">
<?php endforeach; ?>
<?php foreach ($pages as $pg): if (is_file("$dir/sheets/{$pg['name']}.css")): ?><link type="text/css" rel="stylesheet" href="/plugins/synology-hardware/sheets/<?=$pg['name']?>.css">
<?php endif; endforeach; ?>
<script src="/webGui/javascript/dynamix.js"></script>
<script>
var csrf_token = "HARNESS"; var timers = {};
function done() {}
$.ajaxPrefilter(function(s, orig, xhr){   // HeadInlineJS.php:539-545, copied
  if (s.type.toLowerCase() == "post" && !s.crossDomain) { s.data = s.data || ""; s.data += s.data?"&":""; s.data += "csrf_token="+csrf_token; }
});
function HelpButton() {   // HelpButton.page, copied
  if ($('.nav-item.HelpButton').toggleClass('active').hasClass('active')) { $('.inline_help').show('slow'); } else { $('.inline_help').hide('slow'); }
}
</script>
</head>
<body>
<div id="header" style="padding:8px 16px"><b>LOCAL HARNESS, not the Unraid webGui</b> &middot; Synology Hardware &nbsp; <span class="nav-item HelpButton util"><a href="#" id="harness-help" onclick="HelpButton();return false;">Help</a></span></div>
<div id="displaybox">
    <nav class="tabs" role="tablist" aria-label="Page Tabs">
        <div class="tabs-container">
<?php $i = 0; foreach ($pages as $pg): $tabId = 'tab' . ($i + 1); ?>
            <button role="tab" id="<?=$tabId?>" aria-controls="<?=$tabId?>-panel" tabindex="<?=$i === 0 ? '0' : '-1'?>" aria-selected="<?=$i === 0 ? 'true' : 'false'?>"><?=harness_tab_title($pg['Title'], $pg['Tag'] ?? 'th')?></button>
<?php $i++; endforeach; ?>
        </div>
    </nav>
<?php $i = 0; foreach ($pages as $k => $pg): $tabId = 'tab' . ($i + 1); ?>
    <section id="<?=$tabId?>-panel" role="tabpanel" aria-labelledby="<?=$tabId?>" class="tab-content content" tabindex="0">
<?php eval('?>' . $rendered[$k]); ?>
    </section>
<?php $i++; endforeach; ?>
</div>
<script>
/* MainContentTabbed.php:113-185 (Unraid 7.3.4), copied */
const tabs = document.querySelectorAll('.tabs [role="tab"]');
const panels = document.querySelectorAll('[role="tabpanel"]');
panels.forEach(panel => panel.style.display = 'none');
let cookieName = 'one';
let activeIdx = 0;
const cookieVal = $.cookie(cookieName);
if (cookieVal) { const idx = Array.from(tabs).findIndex(tab => tab.id === cookieVal); if (idx !== -1) activeIdx = idx; }
else { $.removeCookie('one'); $.removeCookie('tab'); }
tabs.forEach((tab, i) => {
    if (i === activeIdx) { tab.setAttribute('aria-selected', 'true'); tab.setAttribute('tabindex', '0'); panels[i].style.display = 'block'; }
    else { tab.setAttribute('aria-selected', 'false'); tab.setAttribute('tabindex', '-1'); panels[i].style.display = 'none'; }
});
tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => {
        tabs.forEach((t, j) => { t.setAttribute('aria-selected', j === i ? 'true' : 'false'); t.setAttribute('tabindex', j === i ? '0' : '-1'); panels[j].style.display = j === i ? 'block' : 'none'; });
        $.cookie(cookieName, tab.id);
        tab.focus();
    });
    tab.addEventListener('keydown', e => {
        let idx = Array.prototype.indexOf.call(tabs, document.activeElement);
        if (e.key === 'ArrowRight') tabs[(idx+1)%tabs.length].focus(); else if (e.key === 'ArrowLeft') tabs[(idx-1+tabs.length)%tabs.length].focus();
    });
});
</script>
<script>
$(function(){   // BodyInlineJS.php:270-290, copied
    $('blockquote.inline_help').each(function(i) {
      $(this).attr('id','helpinfo'+i);
      var pin = $(this).prev();
      if (!pin.prop('nodeName')) pin = $(this).parent().prev();
      while (pin.prop('nodeName') && pin.prop('nodeName').search(/(table|dl)/i)==-1) pin = pin.prev();
      pin.find('tr:first,dt:last').each(function() {
        var node = $(this);
        var name = node.prop('nodeName').toLowerCase();
        if (name=='dt') {
          while (!node.html() || node.html().search(/(<input|<select|nbsp;)/i)>=0 || name!='dt') {
            if (name=='dt' && node.is(':first-of-type')) break;
            node = node.prev();
            name = node.prop('nodeName').toLowerCase();
          }
          node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        } else {
          if (node.html() && (name!='tr' || node.children('td:first').html())) node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        }
      });
    });
  $('form').append($('<input>').attr({type:'hidden', name:'csrf_token', value:csrf_token}));
});
</script>
</body></html>
