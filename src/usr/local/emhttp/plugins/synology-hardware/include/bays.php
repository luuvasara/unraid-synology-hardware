<?php
/* bays.php - which unit and bay each disk is in, for the Main page badges (SynologyHWBadges.page, js/synohw-badges.js). 2026.10.26b.
 * Written: 2026-10-26. READ-ONLY: disks.ini/devs.ini and sysfs through synohw_collect(); runs no command, takes no parameter.
 * POST only, like status.php: every POST to the webGui passes Unraid's CSRF check (local_prepend.php:33-51); a GET gets 405.
 * Reply: {"ok":true,"bays":{"sdc":{"u":"DS","bay":1,"unit":"DS2415+","slot":"Unassigned (Dev 2)"},...}}; disks in no bay are left out. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/synohw-status.php';
try {
  $s = synohw_collect(synohw_paths());
  $out = [];
  foreach (synohw_bay_rows($s) as $r) {
    if ($r['bay'] === null || !preg_match('/^[a-z0-9]{1,16}$/', $r['device'])) continue;
    $out[$r['device']] = ['u' => $r['short'], 'bay' => (int)$r['bay'], 'unit' => $r['unit'], 'slot' => $r['slot']];
  }
  echo json_encode(['ok' => true, 'bays' => (object)$out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'bay map failed']);
}
