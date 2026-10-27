<?php
/* synohw-page.php - shared by the tab pages of the synology-hardware settings page (2026.10.25j). Written: 2026-10-25.
 * Unraid 7.3.4 renders a Type="xmenu" Tabs="true" parent (SynologyHW.page, header only, like dynamix PowerMode.page) as one
 * tab per child page (Menu="SynologyHW:<n>"), all in the same HTML page: MainContentTabbed.php evaluates every child in order,
 * each through evalContent.php. So the status is collected once (synohw_page(), cached in a static) and the scripts and the icon
 * sprite are printed once (synohw_page_head()), by whichever tab comes first. READ-ONLY: nothing here writes anything. */
require_once __DIR__ . '/synohw-status.php';
require_once __DIR__ . '/synohw-hw.php';

const SYNOHW_CFG_DEFAULTS = ["FAN_MODE" => "LOW", "KEEPALIVE_SEC" => "10", "BEEP_ON_START" => "no", "AC_RECOVERY" => "keep",
  "WOL" => "keep", "SHUTDOWN_ACTION" => "no", "MACFIX" => "yes", "DEBUG" => "no",
  "DS_MODE" => "AUTO", "DS_FLOOR" => "20", "DS_DUTY" => "40", "DX_MODE" => "AUTO", "DX_LEVEL" => "low", "LED_HEALTH" => "yes",
  "MAIN_BADGES" => "yes", "AVR54_FIXED" => "no"];   // 2026.10.26b: page-only settings

function synohw_page(): array {
  static $d = null;
  if ($d !== null) return $d;
  $s = synohw_collect(synohw_paths());
  return $d = ['s' => $s, 'live' => synohw_render_all($s), 'cfg' => array_merge(SYNOHW_CFG_DEFAULTS, $s['cfg'])];
}

/* scripts, the icon sprite, the boot time for the poller; once per page */
function synohw_page_head(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $s = synohw_page()['s']; $v = synohw_h($s['version']);
  $light = in_array($GLOBALS['theme'] ?? '', ['white', 'azure'], true);   // Unraid's display theme; the script re-checks the painted colour
  echo ($light ? '<script>document.documentElement.setAttribute("data-synohw-scheme","light");</script>' : ''),
    '<link type="text/css" rel="stylesheet" href="/plugins/synology-hardware/vendor/uplot-1.6.32/uPlot.min.css">',
    '<script src="/plugins/synology-hardware/vendor/uplot-1.6.32/uPlot.iife.min.js"></script>',
    '<script src="/plugins/synology-hardware/js/synohw-charts.js?v=', $v, '"></script>',
    '<script src="/plugins/synology-hardware/js/synohw-page.js?v=', $v, '"></script>',
    '<div id="synohw-boot" hidden data-time="', synohw_h(date('H:i:s', $s['now'])), '"></div>', synohw_icon_sprite();
}

/* a live block: refreshed by js/synohw-page.js while it is visible */
function synohw_live(string $k, string $cls = ''): string {
  return '<div id="synohw-' . synohw_h($k) . '" data-synohw-block="' . synohw_h($k) . '"' . ($cls !== '' ? ' class="' . synohw_h($cls) . '"' : '') . '>' . (synohw_page()['live'][$k] ?? '') . '</div>';
}
