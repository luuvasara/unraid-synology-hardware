<?php
require_once __DIR__ . '/synohw-hw.php';   // synohw_lines, synohw_read
/* synohw-anon.php - anonymiser for the hardware report (synology-hardware 2026.10.26b). Written: 2026-10-26.
 * Replaces identifying values with stable placeholders: the same value gets the same placeholder everywhere in one report
 * (<serial-1>, <mac-1>, <wwn-1>, <uuid-1>, <partuuid-1>, <label-1>, <host-1>, <ip-1>, <ipv6-1>, <user-1>).
 * Two passes:
 *   1. known values read from this box (synohw_anon_known): disk serials from Unraid's disk ids (disks.ini, devs.ini: flash, DOM and
 *      every disk), USB serials (/sys/bus/usb/devices/<dev>/serial, root hubs excepted), DMI serials and UUID, any *serial* file of
 *      the synology_dx driver (it exposes none today; the DX1215 "unique" 0x13 is a model code, not a serial), the DOM vender
 *      serial (the daemon's macs file), the hostname, the users of /etc/passwd with uid >= 1000, filesystem labels (by-label);
 *   2. patterns, for anything not known in advance: MAC addresses, WWNs (0x5 + 15 hex, naa./wwn- forms, smartctl "LU WWN"), UUIDs,
 *      MBR PARTUUIDs (8 hex-2 hex), LABEL=/UUID=/PARTUUID= values, IPv4 and IPv6 addresses, and serial-number fields
 *      ("Serial Number:", "SerialNumber:", "serial=", "SN=", "product_serial:" ...).
 * Tests: tests/report_test.php plants values of every kind and checks that none survives. */

function synohw_anon_known(array $p): array {
  $k = ['serial' => [], 'host' => [], 'user' => [], 'label' => [], 'uuid' => []];
  foreach ([$p['disks_ini'], $p['devs_ini']] as $f) {
    $ini = is_readable($f) ? @parse_ini_file($f, true, INI_SCANNER_RAW) : false;
    if (!is_array($ini)) continue;
    foreach ($ini as $d) {
      $id = is_array($d) ? (string)($d['id'] ?? '') : '';
      if ($id === '') continue;
      $id = preg_replace('/-\d+:\d+$/', '', $id);                 // USB ids end in "-0:0" (LUN)
      $i = strrpos($id, '_');
      if ($i !== false && strlen($id) - $i > 4) $k['serial'][] = substr($id, $i + 1);
    }
  }
  foreach (glob(($p['usbdev'] ?? '/sys/bus/usb/devices') . '/*/serial') ?: [] as $f) {
    $v = synohw_read($f);
    if ($v !== '' && !preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $v)) $k['serial'][] = $v;   // a root hub's "serial" is its PCI address
  }
  $dmi = $p['dmi'] ?? '/sys/class/dmi/id';
  foreach (['product_serial', 'board_serial', 'chassis_serial'] as $f) $k['serial'][] = synohw_read("$dmi/$f");
  $k['uuid'][] = synohw_read("$dmi/product_uuid");
  foreach (glob(($p['dxsys'] ?? '/sys/kernel/synology_dx') . '/*/*serial*') ?: [] as $f) $k['serial'][] = synohw_read($f);
  foreach (synohw_lines("{$p['run']}/macs") as $l) if (preg_match('/^sn\s+(\S+)/', $l, $m)) $k['serial'][] = $m[1];
  $k['host'][] = (string)gethostname();
  $k['host'][] = synohw_read($p['hostname'] ?? '/etc/hostname');
  foreach (synohw_lines($p['passwd'] ?? '/etc/passwd') as $l) {
    $x = explode(':', $l);
    if (count($x) >= 3 && ctype_digit($x[2]) && (int)$x[2] >= 1000 && (int)$x[2] < 65534) $k['user'][] = $x[0];
  }
  foreach (glob(($p['bylabel'] ?? '/dev/disk/by-label') . '/*') ?: [] as $f) $k['label'][] = stripcslashes(basename($f));   // udev escapes as \x20
  // serials and UUIDs are matched anywhere (they sit inside ids like WDC_..._<serial>), so very short ones are left to the patterns
  foreach ($k as $c => $l) $k[$c] = array_values(array_unique(array_filter(array_map('trim', $l), fn($v) => strlen($v) >= (in_array($c, ['serial', 'uuid'], true) ? 6 : 2))));
  return $k;
}

/* [$text, $counts]; $known as synohw_anon_known() returns it */
function synohw_anonymise(string $t, array $known): array {
  $map = []; $n = [];
  $ph = function (string $cat, string $v) use (&$map, &$n): string {
    $key = $cat . "\0" . strtolower($v);
    if (!isset($map[$key])) { $n[$cat] = ($n[$cat] ?? 0) + 1; $map[$key] = "<$cat-{$n[$cat]}>"; }
    return $map[$key];
  };
  // 1. known values, longest first (a serial that contains another is replaced whole)
  $all = [];
  foreach ($known as $cat => $vals) foreach ($vals as $v) $all[] = [$cat, $v];
  usort($all, fn($a, $b) => strlen($b[1]) <=> strlen($a[1]));
  foreach ($all as [$cat, $v]) {
    $q = preg_quote($v, '/');
    $re = in_array($cat, ['host', 'user', 'label'], true) ? '/(?<![A-Za-z0-9_.-])' . $q . '(?![A-Za-z0-9_-])/' . ($cat === 'host' ? 'i' : '') : '/' . $q . '/i';
    $t = preg_replace_callback($re, fn($m) => $ph($cat, $v), $t);
  }
  // 2. patterns
  $P = [
    // serial-number fields: whatever follows the field name, up to the end of the token
    ['serial', '/((?:Serial[ _]?Number|SerialNumber|Serial No\.?|iSerial(?:\s+\d+)?|product_serial|board_serial|chassis_serial|serial|SN)\s*[:=]\s*)(?!<)([A-Za-z0-9][A-Za-z0-9._\/-]{2,63})/i', 2],
    ['wwn',  '/LU WWN Device Id:\s*([0-9a-f]\s[0-9a-f]{6}\s[0-9a-f]{9})/i', 1],
    ['wwn',  '/(?<![0-9a-z])(?:wwn-|naa\.)?0x5[0-9a-f]{15}(?:[0-9a-f]{16})?(?![0-9a-z])/i', 0],
    ['wwn',  '/(?<![0-9a-z])naa\.[0-9a-f]{16,32}(?![0-9a-z])/i', 0],
    ['mac',  '/(?<![0-9a-f:-])[0-9a-f]{2}([:-])(?:[0-9a-f]{2}\1){4}[0-9a-f]{2}(?![0-9a-f:-])/i', 0],
    ['uuid', '/(?<![0-9a-f-])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9a-f-])/i', 0],
    ['label', '/((?:PART)?LABEL=)("[^"\n]*"|\S+)/', 2],
    ['uuid', '/((?:UUID|UUID_SUB)=)("?)(?!<)([0-9A-Fa-f][0-9A-Fa-f-]{3,63})/', 3],
    ['partuuid', '/(?<![0-9a-z-])[0-9a-f]{8}-[0-9a-f]{2}(?![0-9a-z-])/i', 0],
    ['ipv6', '/(?<![0-9a-z:.])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}(?:\/\d{1,3})?(?![0-9a-z:])/i', 0],
    ['ipv6', '/(?<![0-9a-z:.])(?=[0-9a-f:]*[0-9a-f])(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?:%[A-Za-z0-9]+)?(?:\/\d{1,3})?(?![0-9a-z:])/i', 0],
    ['ip',   '/(?<![0-9.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\/\d{1,2})?(?![0-9]|\.\d)/', 0],
  ];
  foreach ($P as [$cat, $re, $g]) {
    $t = preg_replace_callback($re, function ($m) use ($cat, $g, $ph) {
      if ($g === 0) {
        if ($cat === 'ip' && preg_match('/^(0\.0\.0\.0|127\.0\.0\.1|255\.255\.255\.\d+)(\/\d+)?$/', $m[0])) return $m[0];   // not identifying
        return $ph($cat, $m[0]);
      }
      $v = trim($m[$g], '"');
      if ($v === '' || $v[0] === '<') return $m[0];
      $pre = substr($m[0], 0, strlen($m[0]) - strlen($m[$g]));
      $q = $m[$g][0] === '"' ? '"' : '';
      return $pre . $q . $ph($cat, $v) . $q;
    }, $t);
  }
  return [$t, $n];
}
