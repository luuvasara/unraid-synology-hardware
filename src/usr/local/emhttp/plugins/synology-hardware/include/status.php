<?php
/* status.php - live values for the synology-hardware settings page, polled by SynologyHW.page with $.post.
 * Written: 2026-10-25 (2026.10.25b). READ-ONLY: see synohw-status.php. Graph data 2026.10.25f.
 *
 * Access: POST only. Every POST to the webGui's PHP goes through Unraid's auto_prepend_file
 * webGui/include/local_prepend.php (Unraid 7.3.4, lines 33-51), which ends the request unless the
 * csrf_token posted (or sent as X-CSRF-Token) matches var.ini; the page's jQuery sends it through the
 * $.ajaxPrefilter in DefaultPageLayout/HeadInlineJS.php:539-545. A GET would skip that check, so a GET
 * gets 405 and nothing else.
 *
 * Parameters (2026.10.25f, all optional; anything else in the request is ignored; nothing from the request
 * reaches a path, a command or the HTML):
 *   action     ''        live HTML blocks (as before), plus the graph deltas below when asked for
 *              'history' the graph history for one range, as columns (synohw_history_series) + labels/boxes
 *              'sweep'   the whole sweep.csv as columns (synohw_sweep_series)
 *              'hardware' the Hardware section HTML (include/synohw-hw.php; 2026.10.25g), sysfs + the inventory cache
 *   range      3600 | 21600 | 86400 (SYNOHW_RANGES); anything else: HTTP 400
 *   since      epoch seconds, digits only; rows at or before it are left out (live append). Default 0.
 *   sweep_since, sweep_id   the same for the sweep chart, in the live poll
 *   blocks     (2026.10.25j) comma-separated block names the visible tab shows (keys of synohw_render_all); only those are
 *              rendered. Absent = all blocks, empty = none. An unknown name or more than 24 names: HTTP 400.
 * Replies are bounded: at most SYNOHW_GRAPH_ROWS history rows, SYNOHW_SWEEP_ROWS sweep rows, 32 disks, 16 cores.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/synohw-status.php';

function synohw_param_int(string $k, int $def, ?array $allowed = null): int {
  $v = $_POST[$k] ?? null;
  if ($v === null || $v === '') return $def;
  if (!is_string($v) || !preg_match('/^\d{1,10}$/', $v) || ($allowed !== null && !in_array((int)$v, $allowed, true))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "bad parameter $k"]);
    exit;
  }
  return (int)$v;
}

const SYNOHW_JSON = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
try {
  $p = synohw_paths();
  $action = $_POST['action'] ?? '';
  if ($action === 'history') {
    $range = synohw_param_int('range', 86400, SYNOHW_RANGES);
    echo json_encode(['ok' => true, 'time' => date('H:i:s', $p['now']), 'meta' => synohw_graph_meta($p),
                      'hist' => synohw_history_series($p, $range, synohw_param_int('since', 0))], SYNOHW_JSON);
  } elseif ($action === 'hardware') {   // 2026.10.25g: the Hardware section, refreshed by the page every 60 s (not in the 5 s poll)
    require_once __DIR__ . '/synohw-hw.php';
    echo json_encode(['ok' => true, 'time' => date('H:i:s', $p['now']), 'html' => synohw_render_hw(synohw_hw_collect($p))], SYNOHW_JSON);
  } elseif ($action === 'sweep') {
    echo json_encode(['ok' => true, 'time' => date('H:i:s', $p['now']), 'sweep' => synohw_sweep_series($p, 0)], SYNOHW_JSON);
  } elseif ($action === '') {
    $only = null;
    if (isset($_POST['blocks'])) {
      $b = $_POST['blocks'];
      $known = array_keys(synohw_render_all_names());
      $only = $b === '' ? [] : (is_string($b) && preg_match('/^[a-z_]{1,16}(,[a-z_]{1,16}){0,23}$/', $b) ? explode(',', $b) : null);   // '' = none (graph rows only)
      if ($only === null || array_diff($only, $known)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad parameter blocks']); exit; }
    }
    $s = synohw_collect($p);
    $r = ['ok' => true, 'time' => date('H:i:s', $s['now']), 'html' => synohw_render_all($s, $only)];
    if (isset($_POST['range'])) {
      $r['hist'] = synohw_history_series($p, synohw_param_int('range', 86400, SYNOHW_RANGES), synohw_param_int('since', 0));
      $r['meta'] = synohw_graph_meta($p);
    }
    if (isset($_POST['sweep_since'])) {
      $sw = synohw_sweep_series($p, synohw_param_int('sweep_since', 0));
      $sid = synohw_param_int('sweep_id', 0);
      if ($sw['id'] !== $sid && $sid !== 0) $sw = synohw_sweep_series($p, 0) + ['reset' => true];   // a new sweep: send it whole
      $r['sweep'] = $sw;
    }
    echo json_encode($r, SYNOHW_JSON);
  } else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'status collection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
}
