<?php
/* report.php - "Report unsupported hardware" (Report tab), synology-hardware 2026.10.26b. Written: 2026-10-26.
 * POST only (Unraid's CSRF check, local_prepend.php:33-51; a GET gets 405). action=collect runs scripts/synohw-report (fixed path,
 * no argument, nothing from the request reaches it), adds the plugin's own view (models, bay map, known issues), anonymises the
 * whole text (include/synohw-anon.php) and returns only the anonymised text. Nothing is written to the flash or sent anywhere;
 * the raw text exists only in this request's memory. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/synohw-status.php';
require_once __DIR__ . '/synohw-anon.php';

function synohw_report_plugin_view(array $s): string {
  $o = "\n### plugin view (synology-hardware)\n";
  $o .= 'main unit: ' . ($s['model']['main']['model'] ?: 'not identified') . ' (' . $s['model']['main']['how'] . ")\n";
  $o .= 'expansion unit: ' . ($s['model']['dx']['model'] ?: ($s['model']['dx']['present'] ? 'present, model unknown' : 'none')) . ' unique ' . ($s['model']['dx']['unique'] ?: '-') . "\n";
  foreach (synohw_bay_rows($s) as $r)
    $o .= sprintf("bay %s %s: %s %s | %s | %s | %s\n", $r['short'], $r['bay'] ?? '-', $r['device'], $r['id'], $r['slot'], $r['temp'], preg_replace('/^LED /', '', $r['led'][1]));
  $x = synohw_issues_collect(synohw_paths(), $s['cfg']);
  foreach (array_merge(synohw_issue_rows($x), synohw_fw_rows($x)) as [$k, $lvl, $st, $det])
    $o .= "$lvl | $k | " . html_entity_decode(strip_tags($st), ENT_QUOTES | ENT_HTML5) . "\n";
  return $o;
}

try {
  if (($_POST['action'] ?? '') !== 'collect') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']); exit; }
  $p = synohw_paths();
  $script = dirname(__DIR__) . '/scripts/synohw-report';
  $raw = (string)shell_exec('timeout 90 /bin/bash ' . escapeshellarg($script) . ' 2>&1');
  $s = synohw_collect($p);
  $raw .= synohw_report_plugin_view($s);
  [$text, $n] = synohw_anonymise($raw, synohw_anon_known($p));
  if (function_exists('mb_convert_encoding')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
  echo json_encode(['ok' => true, 'text' => $text, 'replaced' => $n, 'model' => $s['model']['main']['model'] ?: 'unidentified',
                    'version' => $s['version']], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'report failed']);
}
