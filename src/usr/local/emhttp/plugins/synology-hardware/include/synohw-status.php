<?php
/* synohw-status.php - read-only live values for the synology-hardware settings page.
 * Written: 2026-10-25 (2026.10.25b; real disks only + devs.ini + USB exclusion 2026.10.25c; graph data + disk box 2026.10.25f;
 * DX1215 section, fan modes, DX level in the history 2026.10.25h). Used by SynologyHW.page (first paint) and include/status.php
 * (polled every 5 s).
 *
 * READ-ONLY by design: this file only reads files in /var/run, /var/local/emhttp, /sys, /proc,
 * /etc/cron.d and the flash. It runs no command, opens no device, never touches ttyS1, never mounts
 * the DOM and never wakes a disk (disk temperatures come from Unraid's own disks.ini and devs.ini, where a
 * sleeping disk shows temp="*" spundown="1"). Every value is escaped with htmlspecialchars before it is output.
 *
 * Fan tables: copy of synohw_tables() in scripts/synohw-lib.sh, which is a copy of the DS2415+
 * scemd.xml (capture/2026-10-23/bin/usr/syno/etc.defaults/scemd.xml lines 3-15 HIGH, 17-29 LOW).
 * tests/run-tests.sh fails if this copy, the bash copy and the XML ever differ.
 *
 * DX1215 (2026.10.25h): the expansion plugin's module (synology-expansion, formerly synology-dx) exposes /sys/kernel/synology_dx/unit/*. Reading a unit file whose
 * values are older than the module's cache (refresh_s, 30 s) makes the module re-read the DX master's port multiplier (one
 * libata EH round). So while the daemon runs this page reads the daemon's copy (/var/run/synology-hardware/dx, written at every DX
 * evaluation) and touches the unit files only when the daemon is not running (at most one EH round per 30 s, the module's cache).
 */

const SYNOHW_TABLES = [
  'LOW'  => ['xml' => 'DUAL_MODE_LOW',  'lines' => 'scemd.xml:17-29',
             'disk' => [[0, 20], [46, 40], [50, 70], [54, 99]], 'disk_alert' => 58, 'disk_shutdown' => 61,
             'cpu'  => [[0, 20], [65, 70], [85, 99]],            'cpu_alert'  => 85, 'cpu_shutdown'  => 95],
  'HIGH' => ['xml' => 'DUAL_MODE_HIGH', 'lines' => 'scemd.xml:3-15',
             'disk' => [[0, 20], [41, 40], [45, 70], [49, 99]], 'disk_alert' => 53, 'disk_shutdown' => 61,
             'cpu'  => [[0, 20], [65, 70], [85, 99]],            'cpu_alert'  => 85, 'cpu_shutdown'  => 95],
];
const SYNOHW_HISTORY_WINDOW = 86400;   // 24 h
/* DX1215 EBOX table, copy of DX_STEPS in synohw_tables() (scemd.xml:357-370, Synology-DX1215 HIGH_EBOX and LOW_EBOX are identical) */
const SYNOHW_DX_TABLE = ['lines' => 'scemd.xml:357-370', 'steps' => [[0, 'low'], [48, 'middle'], [55, 'full']], 'shutdown' => 61];
const SYNOHW_DX_UNIT_KEYS = ['master_host', 'unique', 'model', 'fan_level', 'fan_code', 'fan_fail', 'fan1_fail', 'fan2_fail', 'power_on', 'psu1', 'psu2',
                             'switch_mode', 'last_error', 'fan_stop_test_s'];

function synohw_paths(array $over = []): array {
  return array_merge([
    'run'         => '/var/run/synology-hardware',
    'cfg'         => '/boot/config/plugins/synology-hardware/synology-hardware.cfg',
    'tty'         => '/dev/ttyS1',
    'disks_ini'   => '/var/local/emhttp/disks.ini',
    'devs_ini'    => '/var/local/emhttp/devs.ini',
    'sysblock'    => '/sys/block',
    'dynamix_cfg' => '/boot/config/plugins/dynamix/dynamix.cfg',
    'hwmon'       => '/sys/class/hwmon',
    'thermal'     => '/sys/class/thermal',
    'sysnet'      => '/sys/class/net',
    'bonding'     => '/proc/net/bonding',
    'proc'        => '/proc',
    'udev_rom'    => '/boot/config/udev/70-synology-hardware-mac.rules',
    'udev_ram'    => '/etc/udev/rules.d/70-synology-hardware-mac.rules',
    'cron_file'   => '/boot/config/plugins/synology-hardware/synology-hardware.cron',
    'crontab'     => '/etc/cron.d/root',
    'plg_link'    => '/var/log/plugins/synology-hardware.plg',
    'plg_flash'   => '/boot/config/plugins/synology-hardware.plg',
    'placeholder' => '00:a0:c9:00:00',
    'dxsys'       => '/sys/kernel/synology_dx',             // 2026.10.25h: the expansion plugin's module (synology-expansion, formerly synology-dx)
    'sysmodule'   => '/sys/module',
    // 2026.10.25i: the expansion plugin is being renamed synology-dx -> synology-expansion; either name is recognised
    'dx_plg_link' => '/var/log/plugins/synology-expansion.plg',
    'dx_plg_flash'=> '/boot/config/plugins/synology-expansion.plg',
    'dx_run'      => '/var/run/synology-expansion',
    'dx_plg_link_old' => '/var/log/plugins/synology-dx.plg',
    'dx_plg_flash_old'=> '/boot/config/plugins/synology-dx.plg',
    'dx_run_old'  => '/var/run/synology-dx',
    'pcidev'      => '/sys/bus/pci/devices',               // 2026.10.25j: main-unit model by PCI topology (synohw_main_model)
    'ataport'     => '/sys/class/ata_port',                // 2026.10.25j: port_no per ata port, for the bay numbers
    'usbdev'      => '/sys/bus/usb/devices',               // 2026.10.25k: what is plugged into the rear USB sockets (synohw_rear_usb)
    'now'         => time(),
  ], $GLOBALS['synohw_path_override'] ?? [], $over);   // override: offline tests and the render harness only
}

function synohw_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* key=value lines (the daemon's own files and the plugin cfg). Values are data, never evaluated. */
function synohw_kv(string $file): ?array {
  if (!is_file($file) || !is_readable($file)) return null;
  $out = [];
  foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)="?([^"]*)"?\s*$/', rtrim($line, "\r"), $m)) $out[$m[1]] = $m[2];
  }
  return $out;
}

function synohw_read(string $f): string { return (is_file($f) && is_readable($f)) ? trim((string)@file_get_contents($f)) : ''; }

/* highest step whose threshold is <= t (no hysteresis): [threshold, duty] */
function synohw_pick(array $steps, int $t): array {
  $r = $steps[0];
  foreach ($steps as $s) if ($t >= $s[0]) $r = $s;
  return $r;
}

function synohw_age(int $now, int $at): string {
  if ($at <= 0) return 'never';
  $d = max(0, $now - $at);
  if ($d < 120) return "$d s ago";
  if ($d < 7200) return intdiv($d, 60) . ' min ago';
  return intdiv($d, 3600) . ' h ' . intdiv($d % 3600, 60) . ' min ago';
}

function synohw_clock(int $at): string { return $at > 0 ? date('H:i:s', $at) : '-'; }

function synohw_daemon(array $p): array {
  $run = $p['run'];
  $pid = (int)synohw_read("$run/pid");
  $running = false;
  if ($pid > 0 && is_dir("{$p['proc']}/$pid")) {
    $cmd = str_replace("\0", ' ', (string)@file_get_contents("{$p['proc']}/$pid/cmdline"));
    $running = strpos($cmd, 'synohw-daemon') !== false;   // a reused pid is not our daemon
  }
  return [
    'pid' => $pid, 'running' => $running,
    'stopped' => is_file("$run/stopped"),
    'tty' => file_exists($p['tty']) && @filetype($p['tty']) === 'char',
    'state' => synohw_kv("$run/state"),
    'effective' => synohw_kv("$run/effective"),
    'mcu' => synohw_kv("$run/mcu"),
    'sweep' => synohw_kv("$run/sweep.state"),
  ];
}

/* Real disks only: same rules, same order and same keys as synohw_disk_list() in scripts/synohw-lib.sh
 * (tests/run-tests.sh runs both on the same fixtures and fails on any difference).
 * disks.ini: skip the flash, status DISK_NP*, device="". devs.ini (unassigned): keyed by device, labelled
 * with id. USB devices (resolved /sys/block/<dev>/device contains /usb, or transport="usb") are skipped.
 * reading: NN | '*' (temp="*" and spundown="1": standby) | '?' (anything else: spun up, no temperature). */
function synohw_is_usb(array $p, string $dev): bool {
  $b = "{$p['sysblock']}/$dev";
  $r = file_exists("$b/device") ? realpath("$b/device") : (file_exists($b) ? realpath($b) : false);
  return is_string($r) && strpos($r, '/usb') !== false;
}

function synohw_disks(array $p): array {
  $list = []; $seen = []; $any = false;
  foreach (['array' => $p['disks_ini'], 'unassigned' => $p['devs_ini']] as $src => $file) {
    if (!is_file($file) || !is_readable($file)) continue;
    $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
    if (!is_array($ini)) continue;
    $any = true;
    foreach ($ini as $sec => $d) {
      if (!is_array($d)) continue;
      $dev = (string)($d['device'] ?? ''); $name = (string)($d['name'] ?? $sec);
      $status = (string)($d['status'] ?? ''); $temp = (string)($d['temp'] ?? '');
      $id = (string)($d['id'] ?? ''); $tr = (string)($d['transport'] ?? '');
      if (strncmp($status, 'DISK_NP', 7) === 0 || $dev === '') continue;            // empty slot, not a disk
      if (!preg_match('/^[a-z0-9]{1,16}$/', $dev) || isset($seen[$dev])) continue;
      if ($src === 'array') {
        if ($name === 'flash') continue;
        $key = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) ? $name : $dev;
      } else $key = $dev;
      if ($tr === 'usb' || synohw_is_usb($p, $dev)) continue;                         // DOM, sticks: not in a bay
      if (preg_match('/^\d{1,3}$/', $temp)) $r = (string)(int)$temp;
      elseif ($temp === '*' && (string)($d['spundown'] ?? '') === '1') $r = '*';
      else $r = '?';
      if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) $id = '-';
      $seen[$dev] = true;
      $list[] = [
        'name' => $key, 'device' => $dev, 'id' => $id, 'src' => $src, 'reading' => $r,
        'temp' => ctype_digit($r) ? (int)$r : null, 'standby' => $r === '*', 'unreadable' => $r === '?',
        'spundown' => (string)($d['spundown'] ?? ''), 'transport' => $tr,
        'slot' => synohw_unraid_slot($src, trim($name, '"'), (string)($d['type'] ?? '')),   // 2026.10.26b: the name Unraid's Main page shows
        'color' => preg_match('/^[a-z]{1,8}-[a-z]{1,8}$/', (string)($d['color'] ?? '')) ? (string)$d['color'] : '',
      ];
    }
  }
  return ['ok' => $any, 'list' => $list];
}

/* 2026.10.26b: the slot name as Unraid's Main page shows it: my_disk() (dynamix/include/Helpers.php:60-63) = ucfirst + a space
 * before the trailing number ("disk1" -> "Disk 1", "parity2" -> "Parity 2", "cache" -> "Cache"); devs.ini = unassigned devices,
 * shown by Unraid as "Dev N" in the Unassigned Devices table. */
function synohw_unraid_slot(string $src, string $name, string $type): string {
  if (!preg_match('/^[A-Za-z0-9_~.-]{1,32}$/', $name)) return $src === 'array' ? 'array slot' : 'Unassigned';
  $fancy = ucfirst(preg_replace('/(\d+)$/', ' $1', $name));
  if ($src !== 'array') return "Unassigned ($fancy)";
  return $type === 'Cache' ? "$fancy (pool)" : $fancy;
}

function synohw_unraid_thresholds(array $p): array {
  $c = @parse_ini_file($p['dynamix_cfg'], true);
  $d = is_array($c) ? ($c['display'] ?? []) : [];
  return ['hot' => (int)($d['hot'] ?? 45), 'max' => (int)($d['max'] ?? 55), 'from_cfg' => isset($d['hot'])];
}

function synohw_cpu(array $p): array {
  $temps = [];
  foreach (glob("{$p['hwmon']}/hwmon*") ?: [] as $dir) {
    $name = synohw_read("$dir/name");
    if (!in_array($name, ['coretemp', 'k10temp', 'cpu_thermal'], true)) continue;
    foreach (glob("$dir/temp*_input") ?: [] as $f) {
      $v = synohw_read($f);
      if (!preg_match('/^-?\d+$/', $v)) continue;
      $label = synohw_read(preg_replace('/_input$/', '_label', $f)) ?: basename($f, '_input');
      $temps[] = ['label' => "$name: $label", 'temp' => intdiv((int)$v, 1000)];
    }
  }
  if (!$temps) foreach (glob("{$p['thermal']}/thermal_zone*") ?: [] as $dir) {
    if (synohw_read("$dir/type") !== 'x86_pkg_temp') continue;
    $v = synohw_read("$dir/temp");
    if (preg_match('/^-?\d+$/', $v)) $temps[] = ['label' => 'x86_pkg_temp', 'temp' => intdiv((int)$v, 1000)];
  }
  $max = $temps ? max(array_column($temps, 'temp')) : null;
  return ['list' => $temps, 'max' => $max];
}

/* history: per sensor min/max/samples over the last 24 h, plus the share of time at each fan duty */
function synohw_history(array $p): array {
  $f = "{$p['run']}/history";
  $out = ['exists' => is_file($f), 'first' => 0, 'samples' => 0, 'sensors' => [], 'duty' => []];
  if (!$out['exists']) return $out;
  $from = $p['now'] - SYNOHW_HISTORY_WINDOW;
  foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $tok = explode(' ', trim($line));
    $ts = (int)array_shift($tok);
    if ($ts < $from || $ts > $p['now'] + 60) continue;
    if (!$out['first']) $out['first'] = $ts;
    $out['samples']++;
    foreach ($tok as $kv) {
      if (!preg_match('/^([A-Za-z0-9_-]{1,32})=(\d{1,3}|\*|\?|-1)$/', $kv, $m)) continue;
      [, $k, $v] = $m;
      if ($k === 'duty') { if (ctype_digit($v)) $out['duty'][$v] = ($out['duty'][$v] ?? 0) + 1; continue; }
      $s = &$out['sensors'][$k];
      $s = $s ?? ['min' => null, 'max' => null, 'n' => 0, 'standby' => 0, 'unread' => 0];
      if (ctype_digit($v)) {
        $v = (int)$v; $s['n']++;
        $s['min'] = $s['min'] === null ? $v : min($s['min'], $v);
        $s['max'] = $s['max'] === null ? $v : max($s['max'], $v);
      } elseif ($v === '*') $s['standby']++;
      elseif ($v === '?') $s['unread']++;
      unset($s);
    }
  }
  ksort($out['duty'], SORT_NUMERIC);
  return $out;
}

/* physical PCI NICs sorted by PCI address; DOM MAC n -> n-th port, exactly as synohw-macfix does */
function synohw_ports(array $p): array {
  $macs = []; $sn = '';
  foreach (@file("{$p['run']}/macs", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^mac (\d) ([0-9a-f:]{17})$/', $l, $m)) $macs[(int)$m[1]] = $m[2];
    elseif (preg_match('/^sn (\S+)$/', $l, $m)) $sn = $m[1];
  }
  $perm = [];   // bond slaves: "Permanent HW addr" = the address in the NIC's NVM
  foreach (glob("{$p['bonding']}/*") ?: [] as $bf) {
    $slave = null;
    foreach (@file($bf, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
      if (preg_match('/^Slave Interface:\s*(\S+)/', $l, $m)) $slave = $m[1];
      elseif ($slave && preg_match('/^Permanent HW addr:\s*([0-9a-f:]{17})/i', $l, $m)) $perm[$slave] = strtolower($m[1]);
    }
  }
  $nics = [];
  foreach (glob("{$p['sysnet']}/*") ?: [] as $n) {
    if (!file_exists("$n/device") || file_exists("$n/wireless")) continue;
    $pci = basename((string)realpath("$n/device"));
    if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $pci)) continue;
    $nics[$pci] = basename($n);
  }
  ksort($nics, SORT_STRING);
  $ports = []; $masters = []; $i = 0;
  foreach ($nics as $pci => $nic) {
    $dir = "{$p['sysnet']}/$nic";
    $cur = strtolower(synohw_read("$dir/address"));
    $dom = $macs[$i] ?? '';
    $master = is_link("$dir/master") ? basename((string)realpath("$dir/master")) : '';
    $m = $master;
    while ($m !== '' && is_link("{$p['sysnet']}/$m/master")) { $masters[$m] = true; $m = basename((string)realpath("{$p['sysnet']}/$m/master")); }
    if ($m !== '') $masters[$m] = true;
    $ports[] = [
      'nic' => $nic, 'pci' => $pci, 'cur' => $cur, 'dom' => $dom, 'master' => $master,
      'perm' => $perm[$nic] ?? '', 'assign' => synohw_read("$dir/addr_assign_type"),
      'oper' => synohw_read("$dir/operstate"),
      'carrier' => synohw_read("$dir/carrier"),   // 2026.10.25j: '1' link, '0' none, '' unreadable (port down)
      'speed' => preg_match('/^\d{1,6}$/', $sp = synohw_read("$dir/speed")) ? (int)$sp : null,   // 2026.10.25j: Mb/s, -1/empty = no link
      'state' => ($dom !== '' && $cur === $dom) ? 'real' : (strpos($cur, $p['placeholder']) === 0 ? 'placeholder' : 'other'),
    ];
    $i++;
  }
  $mlist = [];
  foreach (array_keys($masters) as $m) $mlist[] = ['name' => $m, 'mac' => strtolower(synohw_read("{$p['sysnet']}/$m/address"))];
  return ['ports' => $ports, 'masters' => $mlist, 'dom_count' => count($macs), 'sn' => $sn, 'cache' => is_file("{$p['run']}/macs")];
}

function synohw_version(array $p, string $a = 'plg_link', string $b = 'plg_flash'): string {
  foreach ([$p[$a], $p[$b]] as $f) {
    if (is_readable($f) && preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', (string)@file_get_contents($f), $m)) return $m[1];
  }
  return 'unknown';
}

function synohw_collect(array $p): array {
  $rom = is_file($p['udev_rom']); $ram = is_file($p['udev_ram']);
  return [
    'now' => $p['now'],
    'daemon' => synohw_daemon($p),
    'cfg' => synohw_kv($p['cfg']) ?? [],
    'disks' => synohw_disks($p),
    'unraid' => synohw_unraid_thresholds($p),
    'cpu' => synohw_cpu($p),
    'hist' => synohw_history($p),
    'net' => synohw_ports($p),
    'udev' => ['rom' => $rom, 'ram' => $ram, 'same' => $rom && $ram && md5_file($p['udev_rom']) === md5_file($p['udev_ram']),
               'lines' => $rom ? count(preg_grep('/^ACTION==/', @file($p['udev_rom']) ?: [])) : 0],
    'cron' => ['file' => is_file($p['cron_file']),
               'active' => strpos(synohw_read($p['crontab']), 'rc.synology-hardware watchdog') !== false],
    'version' => synohw_version($p),
    'dx' => $dx = synohw_dx($p),
    'disk_box' => synohw_disk_boxes($p),               // 2026.10.25j: device -> box, bay (synohw-view.php)
    'bayleds' => synohw_bay_leds($p),                  // 2026.10.26a: driver LED state + the daemon's health record (synohw-view.php)
    'model' => synohw_models($p, $dx),                 // 2026.10.25j: main + expansion model, drawn or not
    'usb' => synohw_rear_usb($p),                      // 2026.10.25k: devices on the xHCI root ports = the rear USB 3.0 sockets
  ];
}

/* DX1215 through the synology-dx module (2026.10.25h). Module/plugin facts are free to read (/sys/module attributes, the plg);
 * the unit values come from the daemon's copy while the daemon runs (see the header). */
function synohw_dx(array $p): array {
  $mod = "{$p['sysmodule']}/synology_dx";
  $o = ['loaded' => is_dir($mod), 'version' => synohw_read("$mod/version"), 'srcversion' => synohw_read("$mod/srcversion"),
        'refresh_s' => synohw_read("$mod/parameters/refresh_s"), 'hosts' => synohw_read("$mod/parameters/hosts"),
        'unit' => is_dir("{$p['dxsys']}/unit"), 'plugin' => 'unknown', 'plugin_name' => 'synology-expansion', 'plugin_installed' => false, 'loader' => '',
        'hwmon' => '', 'v' => [], 'src' => '', 'at' => 0, 'disks' => []];
  foreach (glob("{$p['hwmon']}/hwmon*") ?: [] as $d) if (synohw_read("$d/name") === 'synology_dx') $o['hwmon'] = basename($d);
  foreach (['' => 'synology-expansion', '_old' => 'synology-dx'] as $sfx => $nm) {
    if (!is_file($p["dx_plg_link$sfx"] ?? '') && !is_file($p["dx_plg_flash$sfx"] ?? '')) continue;
    $o['plugin_name'] = $nm; $o['plugin_installed'] = is_file($p["dx_plg_link$sfx"]);
    $o['plugin'] = synohw_version($p, "dx_plg_link$sfx", "dx_plg_flash$sfx"); $o['loader'] = synohw_read("{$p["dx_run$sfx"]}/status");
    break;
  }
  $pid = (int)synohw_read("{$p['run']}/pid");
  $running = $pid > 0 && strpos(str_replace("\0", ' ', synohw_read("{$p['proc']}/$pid/cmdline")), 'synohw-daemon') !== false;
  $snap = synohw_kv("{$p['run']}/dx");
  $period = (int)((synohw_kv("{$p['run']}/effective") ?? [])['DX_PERIOD'] ?? 60);
  if ($running && $snap && $p['now'] - (int)($snap['at'] ?? 0) <= 3 * max(1, $period) + 30) {
    $o['src'] = 'daemon'; $o['at'] = (int)$snap['at'];
    foreach (SYNOHW_DX_UNIT_KEYS as $k) $o['v'][$k] = substr((string)($snap[$k] ?? '?'), 0, 64);
  } elseif ($o['unit']) {
    $o['src'] = 'sysfs'; $o['at'] = $p['now'];
    foreach (SYNOHW_DX_UNIT_KEYS as $k) { $f = "{$p['dxsys']}/unit/$k"; $o['v'][$k] = is_readable($f) ? substr(strtok((string)@file_get_contents($f), "\n") ?: '', 0, 64) : '?'; }
  }
  foreach (synohw_disks($p)['list'] as $d) {
    $b = synohw_disk_box($p, $d['device']);
    if ($b['box'] === 'dx') $o['disks'][] = $d + ['via' => $b['via'], 'pmp' => $b['pmp'], 'ata' => $b['ata']];
  }
  return $o;
}

/* ---------------- rendering: every dynamic value goes through synohw_h() ---------------- */

function synohw_orb(string $colour): string { return "<i class=\"fa fa-circle orb {$colour}-orb middle\"></i> "; }

/* daemon line + the reason it is in this state; colours are dynamix classes only.
 * 2026.10.25j: built as labelled rows, so the Overview health block (Daemon) and the DS2415+ MCU block (the rest) share them. */
function synohw_daemon_rows(array $s): array {
  $d = $s['daemon']; $st = $d['state'] ?? []; $now = $s['now']; $r = [];
  if ($d['running']) {
    $upd = (int)($st['updated'] ?? 0); $ka = (int)($st['keepalive_sec'] ?? 10);
    $stale = $upd > 0 && $now - $upd > 3 * $ka + 5;
    $r['Daemon'] = ($stale ? synohw_orb('yellow') : synohw_orb('green')) . 'running, pid ' . synohw_h($d['pid'])
      . ', started ' . synohw_h(synohw_clock((int)($st['started'] ?? 0)))
      . ($stale ? ' <span class="orange-text">state file not updated for ' . synohw_h($now - $upd) . ' s (expected every ' . synohw_h($ka) . ' s): the main loop may be stuck</span>' : '');
  } elseif (!$d['tty']) {
    $r['Daemon'] = synohw_orb('red') . '<span class="red-text">not running: /dev/ttyS1 is missing.</span> The MCU sits on a 16550 UART at I/O 0x2F8 (measured under DSM); without that device node nothing can be sent. Not a DS2415+, or the kernel did not register the port. The watchdog does not retry while ttyS1 is missing.';
  } elseif ($d['stopped']) {
    $r['Daemon'] = synohw_orb('yellow') . '<span class="orange-text">stopped on purpose</span> (rc.synology-hardware stop or shutdown). The last commands were V99 and t: fan at 99 %, fan checker disarmed. The watchdog leaves a deliberate stop alone; Apply starts it again.';
  } else {
    $r['Daemon'] = synohw_orb('red') . '<span class="red-text">not running and not stopped on purpose (it died).</span> ' . ($s['cron']['active'] ? 'The watchdog cron entry is active and restarts it within 5 minutes.' : 'The watchdog cron entry is not active, so nothing restarts it. See syslog: grep synology-hardware /var/log/syslog');
  }
  $r['Serial port'] = $d['tty'] ? synohw_orb('green') . '/dev/ttyS1 present (character device)' : synohw_orb('red') . '<span class="red-text">/dev/ttyS1 missing</span>';
  if ($st) {
    $kaAt = (int)($st['ka_at'] ?? 0);
    $r['Keepalive u'] = 'last sent ' . synohw_h(synohw_clock($kaAt)) . ' (' . synohw_h(synohw_age($now, $kaAt)) . '), '
      . synohw_h($st['ka_count'] ?? 0) . ' sent since start, ' . synohw_h($st['ka_fail'] ?? 0) . ' write failures, interval ' . synohw_h($st['keepalive_sec'] ?? '?') . ' s'
      . (!$d['running'] ? ' <span class="grey-text">(last known; daemon not running)</span>' : '');
    $alert = ($st['alert'] ?? '0') === '1';
    $r['Status LED'] = $alert ? '<span class="orange-text">ALERT band: orange blinking (;) and alert LED on (LA1)</span>' : 'green steady (8), alert LED off (LA3 when an alert clears)';
  } else {
    $r['Daemon state'] = '<span class="grey-text">no state file yet (/var/run/synology-hardware/state is written after the first evaluation, within ' . synohw_h($s['daemon']['effective']['CPU_PERIOD'] ?? 30) . ' s of start)</span>';
  }
  return $r;
}
/* rows => key/value table (labels are fixed text, values already escaped) */
function synohw_kv_table(array $rows, string $cls = ''): string {
  $o = '<table class="unraid synohw-kv' . ($cls !== '' ? ' ' . $cls : '') . '"><tbody>';
  foreach ($rows as $k => $v) $o .= '<tr><td>' . $k . '</td><td class="whitespace-normal">' . $v . '</td></tr>';
  return $o . '</tbody></table>';
}
function synohw_render_daemon(array $s): string { return synohw_kv_table(synohw_daemon_rows($s)); }

function synohw_render_mcu(array $s): string {
  $m = $s['daemon']['mcu']; $now = $s['now'];
  if (!$m) return '<p class="grey-text">No MCU byte counted yet (/var/run/synology-hardware/mcu appears with the first byte the MCU sends; on this unit it streams "K3" about 4 times a second from power-on).</p>';
  $since = (int)($m['since'] ?? 0); $lastAt = (int)($m['last_at'] ?? 0); $last = (string)($m['last'] ?? '');
  if (!preg_match('/^[0-9a-f]{2}$/', $last)) $last = '??';
  $o = '<table class="unraid synohw-kv"><tbody>';
  $chr = function ($hex) { if (!preg_match('/^[0-9a-f]{2}$/', $hex)) return 'unknown'; $c = hexdec($hex); return ($c >= 0x21 && $c <= 0x7e) ? "'" . chr($c) . "'" : 'non-printable'; };
  $silent = $s['daemon']['running'] && $lastAt > 0 && $now - $lastAt > 30;
  $o .= '<tr><td>Last byte</td><td class="whitespace-normal">0x' . synohw_h($last) . ' (' . synohw_h($chr($last)) . '), '
     . synohw_h(synohw_age($now, $lastAt)) . ($silent ? ' <span class="orange-text">the MCU has been silent for more than 30 s; it normally streams K3 nonstop</span>' : '') . '</td></tr>';
  $span = max(1, $lastAt - $since);
  foreach ($m as $k => $v) {
    if (!preg_match('/^count_([0-9a-f]{2})$/', $k, $mm)) continue;
    $o .= '<tr><td>0x' . synohw_h($mm[1]) . ' ' . synohw_h($chr($mm[1])) . '</td><td>' . synohw_h($v) . ' since ' . synohw_h(synohw_clock($since))
       . ' (' . synohw_h(number_format((int)$v / $span, 1)) . ' per s)</td></tr>';
  }
  return $o . '</tbody></table>';
}

/* the block next to the Fan table setting: temperatures now, what LOW and HIGH would pick, margins */
function synohw_render_fan(array $s): string {
  $st = $s['daemon']['state'] ?? []; $now = $s['now'];
  $disks = $s['disks']['list']; $cpu = $s['cpu']['max'];
  $hot = null; $hotName = '';
  foreach ($disks as $d) if ($d['temp'] !== null && ($hot === null || $d['temp'] > $hot)) { $hot = $d['temp']; $hotName = $d['name']; }
  $allStandby = $disks && !array_filter($disks, fn($d) => !$d['standby']);
  $unread = array_values(array_filter($disks, fn($d) => $d['unreadable']));   // spun up, temp="*": floor
  $running = $st['mode'] ?? '';
  $saved = $s['cfg']['FAN_MODE'] ?? 'LOW';
  $C = fn($t) => $t === null ? '-' : synohw_h($t) . '&nbsp;&deg;C';

  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-fan"><thead><tr><td>Input</td><td>Now</td>';
  foreach (SYNOHW_TABLES as $mode => $t) {
    $tag = [];
    if ($mode === $running && $s['daemon']['running']) $tag[] = 'running';
    if ($mode === $saved) $tag[] = 'saved';
    $o .= '<td>' . synohw_h($mode) . ' (' . synohw_h($t['xml']) . ')' . ($tag ? ' <span class="green-text">' . synohw_h(implode(', ', $tag)) . '</span>' : '') . '</td>';
  }
  $o .= '</tr></thead><tbody>';

  $duty = [];
  // disk row
  $dlabel = 'Hottest disk' . ($hotName !== '' ? ' (' . synohw_h($hotName) . ')' : '');
  if ($hotName !== '') foreach ($disks as $d) if ($d['name'] === $hotName) $dlabel .= ' ' . synohw_badge(($s['disk_box'][$d['device']]['box'] ?? 'unknown'), $s);   // 25j: which unit
  $o .= '<tr><td>' . $dlabel . '</td><td>' . ($hot !== null ? $C($hot) : ($allStandby ? 'all in standby' : ($s['disks']['ok'] ? ($disks ? 'no reading' : 'no disk found') : 'disks.ini and devs.ini unreadable')))
     . ($unread ? '<br><span class="orange-text">no reading from ' . synohw_h(implode(', ', array_column($unread, 'name'))) . ' (spun up, temp="*")</span>' : '') . '</td>';
  foreach (SYNOHW_TABLES as $mode => $t) {
    if ($hot === null) {
      if ($allStandby) { $duty[$mode][] = $t['disk'][0][1]; $o .= '<td class="whitespace-normal">standby counts as cool: step 0&nbsp;&deg;C &rarr; ' . synohw_h($t['disk'][0][1]) . ' %</td>'; }
      else { $duty[$mode][] = null; $o .= '<td class="whitespace-normal">no reading &rarr; floor 70 %</td>'; }
      continue;
    }
    [$thr, $du] = synohw_pick($t['disk'], $hot); $duty[$mode][] = $du;
    $next = null; foreach ($t['disk'] as $x) if ($x[0] > $hot) { $next = $x; break; }
    $o .= '<td class="whitespace-normal">step ' . synohw_h($thr) . '&nbsp;&deg;C &rarr; <b>' . synohw_h($du) . ' %</b>'
       . ($next ? '; next step ' . synohw_h($next[0]) . '&nbsp;&deg;C (' . synohw_h($next[1]) . ' %) in ' . synohw_h($next[0] - $hot) . '&nbsp;&deg;C' : '')
       . '<br>alert ' . synohw_h($t['disk_alert']) . '&nbsp;&deg;C: ' . synohw_margin($t['disk_alert'] - $hot)
       . ' &middot; shutdown band ' . synohw_h($t['disk_shutdown']) . '&nbsp;&deg;C: ' . synohw_margin($t['disk_shutdown'] - $hot)
       . ($unread ? '<br>a disk without reading &rarr; floor 70 %' : '') . '</td>';
  }
  $o .= '</tr>';
  // cpu row
  $o .= '<tr><td>CPU (hottest coretemp sensor)</td><td>' . ($cpu !== null ? $C($cpu) : 'no reading') . '</td>';
  foreach (SYNOHW_TABLES as $mode => $t) {
    if ($cpu === null) { $duty[$mode][] = null; $o .= '<td>no reading &rarr; floor 70 %</td>'; continue; }
    [$thr, $du] = synohw_pick($t['cpu'], $cpu); $duty[$mode][] = $du;
    $next = null; foreach ($t['cpu'] as $x) if ($x[0] > $cpu) { $next = $x; break; }
    $o .= '<td class="whitespace-normal">step ' . synohw_h($thr) . '&nbsp;&deg;C &rarr; <b>' . synohw_h($du) . ' %</b>'
       . ($next ? '; next step ' . synohw_h($next[0]) . '&nbsp;&deg;C (' . synohw_h($next[1]) . ' %) in ' . synohw_h($next[0] - $cpu) . '&nbsp;&deg;C' : '')
       . '<br>alert ' . synohw_h($t['cpu_alert']) . '&nbsp;&deg;C: ' . synohw_margin($t['cpu_alert'] - $cpu)
       . ' &middot; shutdown band ' . synohw_h($t['cpu_shutdown']) . '&nbsp;&deg;C: ' . synohw_margin($t['cpu_shutdown'] - $cpu) . '</td>';
  }
  $o .= '</tr>';
  // result row
  $o .= '<tr><td>Fan duty (higher of the two)</td><td>';
  if ($s['daemon']['running'] && $st) {
    $o .= 'sent V' . synohw_h($st['sent'] ?? '?') . ' at ' . synohw_h(synohw_clock((int)($st['sent_at'] ?? 0)));
  } else $o .= '<span class="grey-text">daemon not running</span>';
  $o .= '</td>';
  foreach (SYNOHW_TABLES as $mode => $t) {
    $v = $duty[$mode];   // [disk, cpu]; null = that source has no reading
    if ($v[0] === null && $v[1] === null) $r = '99 % (no temperature at all: FULL)';
    elseif ($v[0] === null || $v[1] === null) $r = max(70, (int)max($v)) . ' % (one source unreadable: floor 70 %, MIDDLE)';
    elseif ($unread) $r = max(70, (int)max($v)) . ' % (a disk has no reading: floor 70 %, MIDDLE)';
    else $r = max($v) . ' %';
    $o .= '<td><b>' . synohw_h($r) . '</b></td>';
  }
  $o .= '</tr></tbody></table></div>';

  // the running daemon's own decision (includes the 2 C hysteresis, which the table above leaves out)
  if ($st) {
    $why = ['disk' => 'the disk step is higher', 'cpu' => 'the CPU step is higher', 'both' => 'disk and CPU give the same step',
            'floor' => ((int)($st['disk_unread'] ?? 0) > 0 ? ((int)$st['disk_unread']) . ' spun-up disk(s) gave no temperature' : 'one temperature source is unreadable') . ', so the 70 % floor applies', 'no-data' => 'no temperature could be read at all, so 99 %',
            'manual' => 'DS_MODE is MANUAL (the set duty)', 'alert' => 'DS_MODE is MANUAL but a temperature is in the ALERT band, so 99 %'][$st['chosen'] ?? ''] ?? 'starting';
    $man = ($st['ds_mode'] ?? 'AUTO') === 'MANUAL';
    $o .= '<p class="whitespace-normal">Daemon decision (' . synohw_h($st['mode'] ?? '?') . ' table, at ' . synohw_h(synohw_clock((int)($st['updated'] ?? 0))) . '): disk '
       . synohw_h($st['disk'] ?? '?') . (($st['disk'] ?? '') === 'standby' ? '' : '&nbsp;&deg;C') . ' &rarr; step ' . synohw_h(($st['disk_step'] ?? '') !== '' ? $st['disk_step'] . "\u{00A0}°C" : '-') . ' = ' . synohw_h(($st['disk_duty'] ?? '') !== '' ? $st['disk_duty'] . ' %' : '-')
       . '; CPU ' . synohw_h($st['cpu'] ?? '?') . '&nbsp;&deg;C &rarr; step ' . synohw_h(($st['cpu_step'] ?? '') !== '' ? $st['cpu_step'] . "\u{00A0}°C" : '-') . ' = ' . synohw_h(($st['cpu_duty'] ?? '') !== '' ? $st['cpu_duty'] . ' %' : '-')
       . ($man ? '; table result ' . synohw_h($st['table_duty'] ?? '?') . ' %, but <b>MANUAL ' . synohw_h($st['ds_duty'] ?? '?') . ' %</b> is set' : (((int)($st['ds_floor'] ?? 20)) < 20 ? '; lowest row lowered to DS_FLOOR ' . synohw_h($st['ds_floor']) . ' %' : ''))
       . '; result <b>' . synohw_h($st['duty'] ?? '?') . ' %</b> because ' . synohw_h($why) . '.'
       . ' The daemon reads the CPU every ' . synohw_h($s['daemon']['effective']['CPU_PERIOD'] ?? 30) . ' s and disks.ini every ' . synohw_h($s['daemon']['effective']['DISK_PERIOD'] ?? 60)
       . ' s, and steps down only when a temperature is 2&nbsp;&deg;C below the step it is on, so it can sit one step above the table above for a while.</p>';
  }
  if ($running !== '' && $running !== $saved) {
    $o .= '<p class="orange-text">The saved setting is ' . synohw_h($saved) . ' but the daemon still runs with ' . synohw_h($running) . ': press Apply to restart it with the saved value.</p>';
  }
  $u = $s['unraid'];
  $o .= '<p class="grey-text whitespace-normal">For comparison, Unraid itself warns at ' . synohw_h($u['hot']) . '&nbsp;&deg;C and calls a disk critical at ' . synohw_h($u['max'])
     . '&nbsp;&deg;C (' . ($u['from_cfg'] ? 'your Settings &gt; Disk Settings' : 'Unraid defaults, default.cfg:26-27') . '). Those only send notifications; they do not change the fan.</p>';
  return $o;
}

function synohw_margin(int $d): string {
  if ($d > 0) return synohw_h($d) . '&nbsp;&deg;C away';
  return '<span class="red-text">reached (' . synohw_h(-$d) . '&nbsp;&deg;C over)</span>';
}

/* every sensor: now + 24 h min/max from the daemon's RAM ring file */
/* $box (2026.10.25j): null = everything (as before), 'ds' = CPU + every disk not in the expansion unit, 'dx' = the expansion unit's disks
 * only, with their bay and the margins to the DX1215 table's rows instead of the DS2415+ alert */
function synohw_render_temps(array $s, ?string $box = null): string {
  $h = $s['hist']; $now = $s['now'];
  $win = $h['first'] ? $now - $h['first'] : 0;
  $dxT = SYNOHW_DX_TABLE['steps'];
  $last = $box === 'dx' ? 'To DX1215 rows: middle ' . $dxT[1][0] . ' / full ' . $dxT[2][0] . ' / shutdown ' . SYNOHW_DX_TABLE['shutdown'] . '&nbsp;&deg;C' : 'To alert: LOW / HIGH';
  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-temps synohw-sensors"><thead><tr><td>Sensor</td><td>Device</td><td>Now</td><td>Min (window)</td><td>Max (window)</td><td>Samples</td><td>' . $last . '</td></tr></thead><tbody>';
  $hs = $h['sensors'];
  $fmt = fn($v) => $v === null ? '-' : synohw_h($v) . '&nbsp;&deg;C';
  if ($box !== 'dx') {
  $cpu = $s['cpu']['max'];
  $c = $hs['cpu'] ?? ['min' => null, 'max' => null, 'n' => 0, 'standby' => 0];
  $o .= '<tr><td>CPU (max of ' . synohw_h(count($s['cpu']['list'])) . ')</td><td>coretemp</td><td>' . $fmt($cpu) . '</td><td>' . $fmt($c['min']) . '</td><td>' . $fmt($c['max']) . '</td><td>' . synohw_h($c['n']) . '</td>'
     . '<td>' . ($cpu === null ? '-' : synohw_margin(SYNOHW_TABLES['LOW']['cpu_alert'] - $cpu) . ' / ' . synohw_margin(SYNOHW_TABLES['HIGH']['cpu_alert'] - $cpu)) . '</td></tr>';
  foreach ($s['cpu']['list'] as $t) {
    $o .= '<tr><td class="grey-text">&nbsp;&nbsp;' . synohw_h($t['label']) . '</td><td></td><td>' . $fmt($t['temp']) . '</td><td></td><td></td><td></td><td></td></tr>';
  }
  }
  if (!$s['disks']['ok']) $o .= '<tr><td colspan="7" class="red-text">/var/local/emhttp/disks.ini and devs.ini are missing or unreadable (emhttpd writes them); the daemon then uses its smartctl -n standby fallback.</td></tr>';
  $shown = 0;
  foreach ($s['disks']['list'] as $d) {
    $bx = $s['disk_box'][$d['device']] ?? ['box' => 'unknown', 'bay' => null, 'bay_note' => ''];
    if ($box !== null && (($bx['box'] === 'dx') !== ($box === 'dx'))) continue;
    $shown++;
    $x = ($hs[$d['name']] ?? []) + ['min' => null, 'max' => null, 'n' => 0, 'standby' => 0, 'unread' => 0];
    $now_t = $d['temp'] !== null ? $fmt($d['temp']) : ($d['standby'] ? '<span class="grey-text">standby, not woken</span>' : '<span class="orange-text">no reading (spun up, temp="*"): fan floor 70 %</span>');
    $o .= '<tr><td>' . synohw_h($d['name']) . ($d['src'] === 'unassigned' ? ' <span class="grey-text">(unassigned)</span>' : '') . '</td><td>' . synohw_h($d['device'])
       . ($d['id'] !== '-' ? ' ' . synohw_h($d['id']) : '') . ($d['transport'] !== '' ? ' (' . synohw_h($d['transport']) . ')' : '')
       . ($box !== null ? '<br><span class="grey-text">' . ($bx['bay'] !== null ? 'bay ' . synohw_h($bx['bay']) : synohw_h($bx['bay_note'] ?: 'no bay number')) . '</span>' : '') . '</td><td>' . $now_t . '</td>'
       . '<td>' . $fmt($x['min']) . '</td><td>' . $fmt($x['max']) . '</td><td>' . synohw_h($x['n']) . ($x['standby'] ? ' + ' . synohw_h($x['standby']) . ' standby' : '') . ($x['unread'] ? ' + ' . synohw_h($x['unread']) . ' no reading' : '') . '</td>'
       . '<td>' . ($d['temp'] === null ? '-' : ($box === 'dx' ? synohw_margin($dxT[1][0] - $d['temp']) . ' / ' . synohw_margin($dxT[2][0] - $d['temp']) . ' / ' . synohw_margin(SYNOHW_DX_TABLE['shutdown'] - $d['temp'])
          : synohw_margin(SYNOHW_TABLES['LOW']['disk_alert'] - $d['temp']) . ' / ' . synohw_margin(SYNOHW_TABLES['HIGH']['disk_alert'] - $d['temp']))) . '</td></tr>';
  }
  if ($box !== null && !$shown && $s['disks']['ok']) $o .= '<tr><td colspan="7" class="grey-text">' . ($box === 'dx' ? 'No disk found in the expansion unit (decided from sysfs: behind the 88SE9235 or a port multiplier).' : 'No disk found in the main unit.') . '</td></tr>';
  $o .= '</tbody></table></div>';
  if ($box === 'dx') return $o;   // the window / duty sentence belongs to the DS2415+ fan
  if (!$h['exists']) {
    $o .= '<p class="grey-text whitespace-normal">No history yet. The daemon appends one sample per evaluation (every 30 s) to /var/run/synology-hardware/history, in RAM only: it starts empty at every boot and is never written to the flash.</p>';
  } else {
    $shares = [];
    $tot = array_sum($h['duty']) ?: 1;
    foreach ($h['duty'] as $du => $n) $shares[] = synohw_h($du) . ' %: ' . synohw_h(round(100 * $n / $tot)) . ' % of the time';
    $o .= '<p class="grey-text whitespace-normal">Window: the last ' . synohw_h(synohw_dur($win)) . ' (' . synohw_h($h['samples']) . ' samples; at most 24 h, RAM only, starts empty at every boot).'
       . ($shares ? ' Fan duty sent in that window: ' . implode(', ', $shares) . '.' : '') . '</p>';
  }
  return $o;
}

function synohw_dur(int $s): string {
  if ($s < 120) return "$s s";
  if ($s < 7200) return intdiv($s, 60) . ' min';
  return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
}

function synohw_render_net(array $s): string {
  $n = $s['net'];
  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-net"><thead><tr><td>Port</td><td>PCI address</td><td>MAC now</td><td>DOM MAC (vender slot)</td><td>NVM address</td><td>Assigned by</td><td>Master</td><td>Link</td></tr></thead><tbody>';
  $assign = ['0' => 'NVM (permanent)', '1' => 'random', '2' => 'stolen from a slave', '3' => 'set by software'];
  foreach ($n['ports'] as $i => $p) {
    $state = $p['state'] === 'real' ? synohw_orb('green') . 'real' : ($p['state'] === 'placeholder' ? synohw_orb('yellow') . '<span class="orange-text">placeholder</span>' : synohw_orb('grey') . 'other');
    $o .= '<tr><td>' . synohw_h($p['nic']) . '</td><td>' . synohw_h($p['pci']) . '</td><td>' . synohw_h($p['cur']) . ' ' . $state . '</td>'
       . '<td>' . ($p['dom'] !== '' ? synohw_h($p['dom']) . ' (' . synohw_h($i) . ')' : '-') . '</td>'
       . '<td>' . ($p['perm'] !== '' ? synohw_h($p['perm']) : '<span class="grey-text">only shown for bond slaves</span>') . '</td>'
       . '<td>' . synohw_h($assign[$p['assign']] ?? ($p['assign'] === '' ? '-' : $p['assign'])) . '</td>'
       . '<td>' . synohw_h($p['master'] ?: '-') . '</td><td>' . synohw_h($p['oper'] ?: '-') . '</td></tr>';
  }
  foreach ($n['masters'] as $m) $o .= '<tr><td>' . synohw_h($m['name']) . '</td><td class="grey-text">virtual</td><td>' . synohw_h($m['mac']) . '</td><td colspan="5" class="grey-text">takes the MAC of its first slave (rc.inet1 bond_up enslaves eth0 first)</td></tr>';
  if (!$n['ports']) $o .= '<tr><td colspan="8">No PCI network ports found in /sys/class/net.</td></tr>';
  $o .= '</tbody></table></div>';
  if (!$n['cache']) $o .= '<p class="orange-text whitespace-normal">The DOM MACs are not decoded in this boot yet (/var/run/synology-hardware/macs is missing). This page never mounts the DOM; the plugin install and Apply do (read-only).</p>';
  return $o;
}

function synohw_sys_rows(array $s): array {
  $u = $s['udev']; $c = $s['cron']; $mf = $s['cfg']['MACFIX'] ?? 'yes'; $r = [];
  $r['Plugin version'] = synohw_h($s['version']) . ($s['net']['sn'] !== '' ? ' &middot; unit serial ' . synohw_h($s['net']['sn']) . ' (from the DOM vender file)' : '');
  $rule = $u['rom'] ? synohw_orb('green') . 'present on the flash (' . synohw_h($u['lines']) . ' rule lines)' : ($mf === 'yes' ? synohw_orb('red') . '<span class="red-text">absent although MACFIX=yes</span>' : synohw_orb('grey') . 'absent (MACFIX=no)');
  $rule .= ' &middot; running copy in /etc/udev/rules.d: ' . ($u['ram'] ? ($u['same'] ? 'identical' : '<span class="orange-text">differs (press Apply)</span>') : 'absent');
  $r['udev rule'] = $rule;
  $r['Watchdog cron'] = ($c['file'] ? 'synology-hardware.cron on the flash' : '<span class="red-text">synology-hardware.cron missing</span>')
     . ' &middot; ' . ($c['active'] ? synohw_orb('green') . 'active in /etc/cron.d/root' : synohw_orb('red') . '<span class="red-text">not in /etc/cron.d/root</span> (update_cron has not run since install)');
  return $r;
}
function synohw_render_sys(array $s): string {
  $o = '<table class="unraid synohw-kv"><tbody>';
  foreach (synohw_sys_rows($s) as $k => $v) $o .= '<tr><td>' . $k . '</td>' . ($k === 'Plugin version' ? '<td>' : '<td class="whitespace-normal">') . $v . '</td></tr>';
  return $o . '</tbody></table>';
}

/* the settings the running daemon actually uses vs the saved cfg */
function synohw_render_cfgdiff(array $s): string {
  $eff = $s['daemon']['effective'] ?? []; $cfg = $s['cfg'];
  if (!$eff) return '<p class="grey-text">The daemon has not written /var/run/synology-hardware/effective yet (it does at start).</p>';
  $o = '<table class="unraid synohw-kv"><thead><tr><td>Key</td><td>Daemon uses</td><td>Saved in synology-hardware.cfg</td></tr></thead><tbody>';
  foreach ($eff as $k => $v) {
    $sv = $cfg[$k] ?? null;
    $diff = $sv !== null && $sv !== $v;
    $o .= '<tr><td>' . synohw_h($k) . '</td><td>' . synohw_h($v) . '</td><td>' . ($sv === null ? '<span class="grey-text">not set (default)</span>' : synohw_h($sv))
       . ($diff ? ' <span class="orange-text">differs: not applied yet, or refused by the parser (see syslog "cfg: ignoring")</span>' : '') . '</td></tr>';
  }
  return $o . '</tbody></table>';
}

/* fan sweep (read-only): state of /var/run/synology-hardware/sweep.state, written by the daemon. Start/stop is CLI only. */
function synohw_render_sweep(array $s): string {
  $w = $s['daemon']['sweep'] ?? null; $now = $s['now'];
  $o = '<table class="unraid synohw-kv"><tbody>';
  $row = function ($k, $v) use (&$o) { $o .= '<tr><td>' . $k . '</td><td class="whitespace-normal">' . $v . '</td></tr>'; };
  if (!$w) {
    $row('Fan sweep', '<span class="grey-text">none since the daemon started.</span> Start one on the NAS: <code>/etc/rc.d/rc.synology-hardware sweep start</code> (read the help for what it does and its safety stops).');
    return $o . '</tbody></table>';
  }
  $st = $w['state'] ?? '?';
  $orb = ['running' => 'blue', 'done' => 'green', 'aborted' => 'red', 'stopped' => 'yellow'][$st] ?? 'grey';
  $label = ['running' => 'running', 'done' => 'done (plan complete)', 'aborted' => 'ABORTED', 'stopped' => 'stopped by the operator'][$st] ?? $st;
  $row('Fan sweep', synohw_orb($orb) . ($st === 'aborted' ? '<span class="red-text">' . synohw_h($label) . '</span>' : synohw_h($label))
    . ' <span class="grey-text">(started ' . synohw_h(synohw_clock((int)($w['started'] ?? 0))) . ')</span>');
  $step = (int)($w['step'] ?? 0); $steps = (int)($w['steps'] ?? 0);
  $hold = $st === 'running' ? max(0, $now - (int)($w['step_started'] ?? $now)) : (int)($w['hold'] ?? 0);
  $row('Step / duty', ($step > 0 ? synohw_h("$step of $steps") . ', fan duty sent ' . synohw_h($w['duty'] ?? '?') . ' %' : '-')
    . ' <span class="grey-text">(plan: ' . synohw_h($w['plan'] ?? '') . ' %)</span>');
  $row('Time in step', synohw_h(synohw_dur($hold)) . ' <span class="grey-text">(minimum ' . synohw_h($w['min_hold'] ?? '?') . ' s, maximum ' . synohw_h($w['max_hold'] ?? '?') . ' s'
    . ($st === 'running' ? '; settled so far: ' . synohw_h(($w['settled'] ?? '-') === '-' ? 'not decided yet' : $w['settled']) : '') . ')</span>');
  $row('Last temperatures', synohw_h(($w['temps'] ?? '') !== '' ? str_replace('=', ' ', $w['temps']) : '-') . ' <span class="grey-text">(&deg;C; ' . synohw_h($w['samples'] ?? 0) . ' samples, last update ' . synohw_h(synohw_age($now, (int)($w['updated'] ?? 0))) . ')</span>');
  if (($w['reason'] ?? '') !== '') $row('Reason', '<span class="' . ($st === 'aborted' ? 'red-text' : 'grey-text') . '">' . synohw_h($w['reason']) . '</span>');
  $files = '<code>' . synohw_h($w['csv'] ?? '') . '</code> and <code>' . synohw_h($w['summary'] ?? '') . '</code> (RAM, until reboot)';
  if (($w['result_csv'] ?? '') !== '') {
    $files .= '<br>Flash copy: <code>' . synohw_h($w['result_csv']) . '</code> and <code>' . synohw_h($w['result_txt'] ?? '') . '</code>'
           .  ' <a href="/Main/Browse?dir=' . rawurlencode(dirname($w['result_csv'])) . '">browse the folder</a>';
  }
  $row('Results', $files);
  return $o . '</tbody></table>';
}

/* ---------------- graphs (2026.10.25f): compact JSON for js/synohw-charts.js, read-only, bounded ---------------- */

const SYNOHW_RANGES = [3600, 21600, 86400];   // the page's 1 h / 6 h / 24 h selector; anything else is refused
const SYNOHW_GRAPH_ROWS = 3200;               // history keeps 3000 lines (HIST_MAX); never more rows than this
const SYNOHW_GRAPH_BYTES = 2097152;           // read at most the last 2 MiB of a file (history is ~150-400 KB)
const SYNOHW_GRAPH_DISKS = 32;                // series caps: a malformed file cannot make the reply unbounded
const SYNOHW_GRAPH_CORES = 16;
const SYNOHW_SWEEP_ROWS = 6000;               // 24 steps x 1800 s / 30 s = 1440 rows for the longest plan
/* @dxwhy -> one char per row (js/synohw-charts.js DXWHY has the texts) */
const SYNOHW_DX_WHY_CODE = ['auto' => 'a', 'standby' => 's', 'nodisk' => 'd', 'noread' => 'r', 'shutdown' => 'x', 'alarm' => 'A', 'manual' => 'm', 'linked' => 'k', 'nomod' => 'n', 'nomaster' => 'o'];

/* last $max bytes of a file as lines (the first, possibly cut, line is dropped when the file was longer) */
function synohw_tail_lines(string $f, int $max): array {
  if (!is_file($f) || !is_readable($f)) return [];
  $size = (int)@filesize($f);
  $h = @fopen($f, 'rb');
  if (!$h) return [];
  $cut = $size > $max;
  if ($cut) fseek($h, $size - $max);
  $data = (string)stream_get_contents($h, $max);
  fclose($h);
  $lines = explode("\n", $data);
  if ($cut) array_shift($lines);
  return array_values(array_filter($lines, fn($l) => $l !== ''));
}

/* Which box a disk sits in, decided from sysfs (never from the sdX name, which changes between boots).
 * /sys/block/<dev>/device resolves to .../<pci>/.../ataN/hostM/targetM:C:0/M:C:0:0 for SATA.
 *   DX1215: the controller is the Marvell 88SE9235 (1b4b:9235; the DS2415+'s eSATA/expansion controller, measured
 *           2026-10-25: research/2026-10-25-dx1215-kernel-design.md "DX ports on this boot: ata19-22"), or the ata
 *           port has port-multiplier fan-out links (ataN/linkN.0 exists: libata creates them for a PMP only; the DX1215's
 *           PMPs are Marvell 88SM9705, 1b4b:9705); C = the PMP port.
 *   DS2415+ internal: any other SATA controller (the 88SE9215s and the C2000 AHCI, lspci-link.txt of 2026-10-25).
 *   usb: a USB device (the DOM, a stick). Anything else: unknown, with the reason. */
function synohw_disk_box(array $p, string $dev): array {
  $out = ['box' => 'unknown', 'label' => 'box unknown', 'via' => '', 'ata' => null, 'pmp' => null, 'pci' => '', 'pci_id' => ''];
  if (!preg_match('/^[a-z0-9]{1,16}$/', $dev)) { $out['via'] = 'bad device name'; return $out; }
  $b = "{$p['sysblock']}/$dev/device";
  $r = file_exists($b) ? realpath($b) : false;
  if (!is_string($r)) { $out['via'] = "no /sys/block/$dev/device"; return $out; }
  if (strpos($r, '/usb') !== false) return ['box' => 'usb', 'label' => 'USB'] + $out;
  $prefix = ''; $dx = '';
  foreach (explode('/', $r) as $i => $c) {
    $prefix = $i === 0 ? $c : "$prefix/$c";
    if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $c)) {
      $v = strtolower(synohw_read("$prefix/vendor")); $d = strtolower(synohw_read("$prefix/device"));
      $out['pci'] = $c;
      $out['pci_id'] = preg_match('/^0x[0-9a-f]{4}$/', $v) && preg_match('/^0x[0-9a-f]{4}$/', $d) ? substr($v, 2) . ':' . substr($d, 2) : '';
      if ($out['pci_id'] === '1b4b:9235') $dx = "controller 88SE9235 (1b4b:9235) at $c";
    } elseif (preg_match('/^ata(\d{1,3})$/', $c, $m)) {
      $out['ata'] = (int)$m[1];
      if (is_dir("$prefix/link{$m[1]}.0")) $out['pmp'] = -1;   // port multiplier on this port; port number from the target below
    } elseif ($out['pmp'] === -1 && preg_match('/^target\d+:(\d+):\d+$/', $c, $m)) {
      $out['pmp'] = (int)$m[1];
    }
  }
  if ($out['ata'] === null) { $out['via'] = 'not on a SATA (ata) port'; return $out; }
  $where = "ata{$out['ata']}" . ($out['pmp'] !== null ? ' port multiplier port ' . max(0, $out['pmp']) : '');
  if ($dx !== '' || $out['pmp'] !== null) {
    $out['box'] = 'dx'; $out['label'] = 'DX1215';
    $out['via'] = $where . ($dx !== '' ? ", $dx" : '') . ($out['pmp'] !== null ? ', behind a port multiplier (the DX1215 uses 88SM9705, 1b4b:9705)' : '');
  } else {
    $out['box'] = 'ds'; $out['label'] = 'DS2415+ internal';
    $out['via'] = $where . ($out['pci'] !== '' ? ", controller {$out['pci']}" . ($out['pci_id'] !== '' ? " ({$out['pci_id']})" : '') : '');
  }
  return $out;
}

/* model / serial from the ini id ("WDC_WD1003FZEX-00MK2A0_WD-WCC4E0000001": the serial follows the last '_') */
function synohw_id_parts(string $id): array {
  if ($id === '' || $id === '-') return ['model' => '', 'serial' => ''];
  $i = strrpos($id, '_');
  if ($i === false) return ['model' => $id, 'serial' => ''];
  return ['model' => str_replace('_', ' ', substr($id, 0, $i)), 'serial' => substr($id, $i + 1)];
}

/* per disk key (as in the history: disks.ini name or devs.ini device): label, model, serial, box. Current disks only;
 * history is RAM and starts at boot, so a key that is no longer present is shown by its key alone. */
function synohw_disk_meta(array $p, array $disks): array {
  $m = [];
  foreach ($disks['list'] as $d) {
    $box = synohw_disk_box($p, $d['device']);
    $m[$d['name']] = synohw_id_parts($d['id']) + ['device' => $d['device'], 'id' => $d['id'], 'src' => $d['src'],
                     'box' => $box['box'], 'box_label' => $box['label'], 'via' => $box['via']];
  }
  return $m;
}

/* CPU sensor key as the daemon writes it (synohw_cpu_cores: "<hwmon name>_<tempN>") -> its hwmon label */
function synohw_core_labels(array $p): array {
  $l = [];
  foreach (glob("{$p['hwmon']}/hwmon*") ?: [] as $dir) {
    $name = synohw_read("$dir/name");
    if (!in_array($name, ['coretemp', 'k10temp', 'cpu_thermal'], true)) continue;
    foreach (glob("$dir/temp*_input") ?: [] as $f) {
      $t = basename($f, '_input');
      $l["{$name}_$t"] = synohw_read("$dir/{$t}_label") ?: $t;
    }
  }
  return $l;
}

/* History as columns. rows with since < ts and now - range <= ts <= now + 60. Values: int or null; per disk a state
 * string, one char per row: n = number, s = standby (not woken), u = spun up without a reading, - = not in that row.
 * why (one char per row): d disk, c cpu, b both, f floor, n no-data, - unknown (lines written before 2026.10.25f). */
function synohw_history_series(array $p, int $range, int $since = 0): array {
  $now = $p['now']; $f = "{$p['run']}/history";
  $from = max($now - $range, $since + 1);
  $rows = [];
  foreach (synohw_tail_lines($f, SYNOHW_GRAPH_BYTES) as $line) {
    $tok = explode(' ', trim($line));
    $ts = array_shift($tok);
    if (!ctype_digit((string)$ts)) continue;
    $ts = (int)$ts;
    if ($ts < $from || $ts > $now + 60) continue;
    $r = ['t' => $ts, 'cpu' => null, 'duty' => null, 'want' => null, 'mode' => '-', 'why' => '-', 'sweep' => 0, 'd' => [], 'c' => [],
          'dsm' => '-', 'dx' => '-', 'dxw' => '-', 'dxy' => '-', 'dxal' => null];
    foreach ($tok as $kv) {
      if (preg_match('/^([A-Za-z0-9_-]{1,32})=(\d{1,3}|\*|\?|-1)$/', $kv, $m)) {
        if ($m[1] === 'cpu') $r['cpu'] = ctype_digit($m[2]) ? (int)$m[2] : null;
        elseif ($m[1] === 'duty') $r['duty'] = ctype_digit($m[2]) ? (int)$m[2] : null;
        else $r['d'][$m[1]] = $m[2];
      } elseif (preg_match('/^core:([A-Za-z0-9_-]{1,40})=(-?\d{1,3}|\?)$/', $kv, $m)) {
        $r['c'][$m[1]] = $m[2] === '?' ? null : (int)$m[2];
      } elseif (preg_match('/^@(mode|why|want|sweep)=([A-Za-z-]{1,8}|\d{1,3})$/', $kv, $m)) {
        if ($m[1] === 'mode') $r['mode'] = ['LOW' => 'L', 'HIGH' => 'H'][$m[2]] ?? '-';
        elseif ($m[1] === 'why') $r['why'] = ['disk' => 'd', 'cpu' => 'c', 'both' => 'b', 'floor' => 'f', 'no-data' => 'n', 'manual' => 'm', 'alert' => 'a'][$m[2]] ?? '-';
        elseif ($m[1] === 'want') $r['want'] = ctype_digit($m[2]) ? (int)$m[2] : null;
        else $r['sweep'] = ctype_digit($m[2]) ? (int)$m[2] : 0;
      } elseif (preg_match('/^@(dsm|dx|dxwant|dxwhy|dxal)=([a-z]{1,10}|[A-Z]{1,8}|\d|-)$/', $kv, $m)) {   // 2026.10.25h
        $lv = ['full' => 'f', 'middle' => 'm', 'low' => 'l', 'stop' => 's', 'none' => 'n'];
        if ($m[1] === 'dsm') $r['dsm'] = ['AUTO' => 'A', 'MANUAL' => 'M'][$m[2]] ?? '-';
        elseif ($m[1] === 'dx') $r['dx'] = $lv[$m[2]] ?? '-';
        elseif ($m[1] === 'dxwant') $r['dxw'] = $lv[$m[2]] ?? '-';
        elseif ($m[1] === 'dxwhy') $r['dxy'] = SYNOHW_DX_WHY_CODE[$m[2]] ?? '-';
        else $r['dxal'] = ctype_digit($m[2]) ? min(3, (int)$m[2]) : null;
      }
    }
    $rows[] = $r;
  }
  if (count($rows) > SYNOHW_GRAPH_ROWS) $rows = array_slice($rows, -SYNOHW_GRAPH_ROWS);
  $dk = []; $ck = [];
  foreach ($rows as $r) {
    foreach ($r['d'] as $k => $_) if (!isset($dk[$k]) && count($dk) < SYNOHW_GRAPH_DISKS) $dk[$k] = true;
    foreach ($r['c'] as $k => $_) if (!isset($ck[$k]) && count($ck) < SYNOHW_GRAPH_CORES) $ck[$k] = true;
  }
  $out = ['t' => [], 'cpu' => [], 'duty' => [], 'want' => [], 'mode' => '', 'why' => '', 'sweep' => [], 'disks' => [], 'dst' => [], 'cores' => [],
          'dsm' => '', 'dx' => '', 'dxw' => '', 'dxy' => '', 'dxal' => []];
  foreach (array_keys($dk) as $k) { $out['disks'][$k] = []; $out['dst'][$k] = ''; }
  foreach (array_keys($ck) as $k) $out['cores'][$k] = [];
  foreach ($rows as $r) {
    $out['t'][] = $r['t']; $out['cpu'][] = $r['cpu']; $out['duty'][] = $r['duty']; $out['want'][] = $r['want'];
    $out['mode'] .= $r['mode']; $out['why'] .= $r['why']; $out['sweep'][] = $r['sweep'];
    $out['dsm'] .= $r['dsm']; $out['dx'] .= $r['dx']; $out['dxw'] .= $r['dxw']; $out['dxy'] .= $r['dxy']; $out['dxal'][] = $r['dxal'];
    foreach (array_keys($dk) as $k) {
      $v = $r['d'][$k] ?? null;
      $out['disks'][$k][] = ($v !== null && ctype_digit($v)) ? (int)$v : null;
      $out['dst'][$k] .= $v === null ? '-' : (ctype_digit($v) ? 'n' : ($v === '*' ? 's' : 'u'));
    }
    foreach (array_keys($ck) as $k) $out['cores'][$k][] = $r['c'][$k] ?? null;
  }
  if (!$out['disks']) { $out['disks'] = new stdClass(); $out['dst'] = new stdClass(); }   // JSON {} not []
  if (!$out['cores']) $out['cores'] = new stdClass();
  return ['exists' => is_file($f), 'range' => $range, 'since' => $since, 'now' => $now] + $out;
}

/* what the charts need besides the samples: labels, boxes, the running table and its thresholds */
function synohw_graph_meta(array $p): array {
  $disks = synohw_disks($p);
  $st = synohw_kv("{$p['run']}/state") ?? [];
  $mode = in_array($st['mode'] ?? '', ['LOW', 'HIGH'], true) ? $st['mode'] : ((synohw_kv($p['cfg']) ?? [])['FAN_MODE'] ?? 'LOW');
  if (!isset(SYNOHW_TABLES[$mode])) $mode = 'LOW';
  $first = 0;
  foreach (synohw_tail_lines("{$p['run']}/history", SYNOHW_GRAPH_BYTES) as $l) {   // oldest sample still in the file
    if (preg_match('/^(\d+) /', $l, $m)) { $first = (int)$m[1]; break; }
  }
  return ['disks' => synohw_disk_meta($p, $disks) ?: new stdClass(), 'cores' => synohw_core_labels($p) ?: new stdClass(),
          'mode' => $mode, 'tables' => SYNOHW_TABLES, 'first' => $first, 'period' => (int)((synohw_kv("{$p['run']}/effective") ?? [])['CPU_PERIOD'] ?? 30),
          // 2026.10.25j: the units' names for the legends and the DX1215 table for the expansion unit's chart
          'box_names' => ['ds' => synohw_box_name($mm = ['model' => synohw_models($p, synohw_dx($p))], 'ds'), 'dx' => synohw_box_name($mm, 'dx')], 'dx_table' => SYNOHW_DX_TABLE];
}

/* sweep.csv as columns (epoch,step,duty,cpu_max,core:...,disk:<id>...,mcu_33,mcu_4b,mcu_other). id = the first epoch,
 * so the page can tell a new sweep from more rows of the same one. '?' -> null. */
function synohw_sweep_series(array $p, int $since = 0): array {
  $f = "{$p['run']}/sweep.csv";
  $out = ['exists' => is_file($f), 'id' => 0, 'since' => $since, 't' => [], 'step' => [], 'duty' => [], 'cols' => [], 'mcu_other' => [], 'disks' => new stdClass()];
  $lines = synohw_tail_lines($f, SYNOHW_GRAPH_BYTES);
  if (!$lines) return $out;
  $hdr = explode(',', array_shift($lines));
  if (array_slice($hdr, 0, 4) !== ['epoch', 'step', 'duty', 'cpu_max']) { $out['error'] = 'unexpected sweep.csv header'; return $out; }
  $idx = [];
  foreach ($hdr as $i => $h) {
    if ($i >= 3 && preg_match('/^(cpu_max|core:[A-Za-z0-9_-]{1,40}|disk:[A-Za-z0-9._-]{1,64})$/', $h) && count($idx) < SYNOHW_GRAPH_DISKS + SYNOHW_GRAPH_CORES + 1) $idx[$h] = $i;
  }
  $oth = array_search('mcu_other', $hdr, true);
  $cols = array_fill_keys(array_keys($idx), []);
  $rows = 0;
  foreach ($lines as $l) {
    $c = explode(',', $l);
    if (count($c) !== count($hdr) || !ctype_digit($c[0])) continue;
    if (!$out['id']) $out['id'] = (int)$c[0];
    if ((int)$c[0] <= $since) continue;
    if (++$rows > SYNOHW_SWEEP_ROWS) break;
    $out['t'][] = (int)$c[0]; $out['step'][] = (int)$c[1]; $out['duty'][] = ctype_digit($c[2]) ? (int)$c[2] : null;
    foreach ($idx as $h => $i) $cols[$h][] = preg_match('/^-?\d{1,3}$/', $c[$i]) ? (int)$c[$i] : null;
    $out['mcu_other'][] = $oth !== false && ctype_digit($c[$oth]) ? (int)$c[$oth] : null;
  }
  $out['cols'] = $cols ?: new stdClass();
  // disk:<id> -> label and box from the current disk list (matched by id)
  $meta = [];
  foreach (synohw_disk_meta($p, synohw_disks($p)) as $m) if ($m['id'] !== '-') $meta[$m['id']] = $m;
  $dm = [];
  foreach (array_keys($idx) as $h) if (strncmp($h, 'disk:', 5) === 0) $dm[$h] = $meta[substr($h, 5)] ?? null;
  if ($dm) $out['disks'] = $dm;
  return $out;
}

/* ---------------- DX1215 and "what each box is doing" (2026.10.25h) ---------------- */

const SYNOHW_DS_WHY = ['disk' => 'the disk row is higher', 'cpu' => 'the CPU row is higher', 'both' => 'disk and CPU give the same row',
  'floor' => 'a temperature source is unreadable: the 70 % floor (UNREADABLE_FLOOR)', 'no-data' => 'no temperature at all: NO_DATA_DUTY',
  'manual' => 'MANUAL: the set duty (DS_DUTY)', 'alert' => 'MANUAL raised to 99 %: a temperature is in the ALERT band'];
const SYNOHW_DX_WHY = ['auto' => "DSM's DX1215 table on the hottest DX1215 disk", 'standby' => 'every DX1215 disk is asleep: low (scemd hibernation_speed LOW)',
  'nodisk' => 'no disk found in the DX1215 (box from sysfs): at least middle, the input is missing',
  'noread' => 'a DX1215 disk spins without a reading: at least middle', 'shutdown' => 'a DX1215 disk at the 61 °C SHUTDOWN row: full',
  'alarm' => "a fan alarm bit is set: full (as DSM's FanFailSpeedChange)", 'manual' => 'MANUAL: the set level (DX_LEVEL)',
  'linked' => 'LINKED: follows the DS2415+ duty (<40 % low, 40-69 % middle, >=70 % full)',
  'nomod' => 'the synology_dx module is not loaded: nothing written', 'nomaster' => 'the module found no DX1215 master: nothing written'];
const SYNOHW_DX_CODES = ['0' => 'full', '1' => 'middle', '2' => 'low', '3' => 'STOP'];

/* 2026.10.25j: the rows of the old single DX1215 table, by label, so the DX1215 tab can show them in its sections
 * (driver, fan control, power) while synohw_render_dx() still gives the whole table */
function synohw_render_dx(array $s): string { return synohw_kv_table(synohw_dx_rows($s)); }
function synohw_dx_rows(array $s): array {
  $x = $s['dx']; $v = $x['v']; $st = $s['daemon']['state'] ?? []; $now = $s['now'];
  $R = [];
  $row = function ($k, $val) use (&$R) { $R[$k] = $val; };
  // module / plugin
  $plg = $x['plugin_installed'] ? 'plugin ' . synohw_h($x['plugin_name']) . ' ' . synohw_h($x['plugin']) : 'expansion plugin (synology-expansion) <b>not installed</b>';
  if ($x['loaded']) {
    $row('Module', synohw_orb('green') . 'synology_dx ' . synohw_h($x['version'] ?: '?') . ' loaded'
      . ($x['srcversion'] !== '' ? ' <span class="grey-text">(srcversion ' . synohw_h($x['srcversion']) . ')</span>' : '')
      . ' &middot; ' . $plg . ($x['hwmon'] !== '' ? ' &middot; hwmon ' . synohw_h($x['hwmon']) . ' (name synology_dx: fan1_alarm, fan2_alarm, pwm1 255/128/64 = full/middle/low)' : '')
      . ($x['refresh_s'] !== '' ? '<br><span class="grey-text">module cache ' . synohw_h($x['refresh_s']) . ' s (a read older than that re-reads the port multiplier: one libata EH round on the master port)' . ($x['hosts'] !== '' ? '; SCSI hosts ' . synohw_h($x['hosts']) : '') . '</span>' : ''));
  } else {
    $why = $x['plugin_installed'] ? 'The ' . synohw_h($x['plugin_name']) . ' plugin is installed but its module is not loaded'
      . ($x['loader'] !== '' ? '; its loader says: <code>' . synohw_h($x['loader']) . '</code>' : '') . '. See <code>grep ' . synohw_h($x['plugin_name']) . ' /var/log/syslog</code> and its page under Utilities.'
      : 'Install the expansion plugin (synology-expansion, formerly synology-dx; it ships the synology_dx kernel module for this Unraid kernel) to see and control the DX1215 fans.';
    $row('Module', synohw_orb('grey') . '<span class="orange-text">expansion module (synology_dx) not installed or not loaded.</span> ' . $why
      . ' Without it this plugin cannot read or change anything in the DX1215: the unit keeps whatever level it has (its CPLD starts at LOW at power-on, measured 2026-10-25, phase 2a), and the daemon writes nothing.');
  }
  if ($x['v']) {
    $lvl = $v['fan_level'] ?? '?';
    $ok = in_array($lvl, ['full', 'middle', 'low', 'stop'], true);
    $src = $x['src'] === 'daemon' ? 'the daemon\'s copy from ' . synohw_h(synohw_clock($x['at'])) . ' (' . synohw_h(synohw_age($now, $x['at'])) . ')'
         : 'sysfs directly (the daemon is not running)';
    if (!$ok) {
      $row('Unit', '<span class="orange-text">no DX1215 master: fan_level reads "' . synohw_h($lvl) . '"</span> (the module found no port multiplier with EMID 0 and unique 0x13, or more than one; <code>/sys/kernel/synology_dx/unit/summary</code> lists each host)');
    } else {
      $row('Unit', synohw_h($v['model'] ?? '?') . ' <span class="grey-text">(unique ' . synohw_h($v['unique'] ?? '?') . ' from GPIO page 0 of the master port multiplier ' . synohw_h($v['master_host'] ?? '?')
        . ', the EMID-0 88SM9705; the fan, power and PSU pins are read there; research/2026-10-25-dx1215-gpio-spec.md, experiments/2026-10-25-synology-dx-phase2a)</span>');
      $code = (string)($v['fan_code'] ?? '?');
      $stop = (int)($v['fan_stop_test_s'] ?? 0);
      $row('Fan level now', '<b>' . synohw_h($lvl) . '</b> (CPLD code ' . synohw_h($code) . ': 0 full, 1 middle, 2 low, 3 stop; page 4 bits 1:0)'
        . ($lvl === 'stop' ? ' <span class="red-text">STOP: both fans stopped' . ($stop > 0 ? ' by a fan_stop_test_s test, ' . synohw_h($stop) . ' s left' : '') . '</span>' : '')
        . '<br><span class="grey-text">No speed (RPM) exists: the CPLD has a 2-bit level and two alarm wires only. low is the power-on default and the lowest level that runs; nothing lies between low and stop (phase 2b).</span>');
      foreach (['1' => 'fan1_fail', '2' => 'fan2_fail'] as $n => $k) {
        $a = (string)($v[$k] ?? '?');
        $row("Fan $n alarm", $a === '0' ? synohw_orb('green') . 'no alarm (0)' : ($a === '1' ? synohw_orb('red') . '<span class="red-text">ALARM (1): fan ' . $n . ' reports a failure (or is stopped)</span>' : synohw_orb('yellow') . '<span class="orange-text">unreadable (' . synohw_h($a) . ')</span>')
          . " <span class=\"grey-text\">(page 2 bit " . ((int)$n - 1) . '; a 30 s STOP test raised both bits within 5 s, phase 2b; DSM itself checks only bit 0)</span>');
      }
      $po = (string)($v['power_on'] ?? '?');
      $row('Power', ($po === '1' ? synohw_orb('green') . 'on (power_on 1)' : synohw_orb('yellow') . '<span class="orange-text">power_on ' . synohw_h($po) . '</span>')
        . ' <span class="grey-text">(page 2 bit 5, DSM IsPowerOn; Synology\'s GPL code treats the bit clear as "PSU off")</span>');
      $row('PSU 1 / PSU 2', 'raw bits ' . synohw_h($v['psu1'] ?? '?') . ' / ' . synohw_h($v['psu2'] ?? '?')
        . ' <span class="grey-text">(page 2 bits 6 and 7, DSM RedundantPowerStatGet). <b>Polarity unknown</b>: the DX1215 has one PSU and both bits read 0 on this unit, so which value means "OK" is not known.</span>');
      $row('Switch mode', synohw_h(synohw_switch_text((string)($v['switch_mode'] ?? '?')))
        . ' <span class="grey-text">(page 3 bit 0 = ' . synohw_h($v['switch_mode'] ?? '?') . ', DSM SwitchModeGet = the DEFAULT/MANUAL switch on the back. Verified at the box on 2026-10-26: the switch on DEFAULT reads 1. MANUAL = 0 follows, not toggled while measured.)</span>');
      $le = (string)($v['last_error'] ?? '');
      $row('Last module error', $le === '' || $le === '-' ? 'none' : '<span class="orange-text">' . synohw_h($le) . '</span>');
    }
    $row('Values from', $src);
  }
  // DX disks
  $dl = [];
  foreach ($x['disks'] as $d) {
    $t = $d['temp'] !== null ? synohw_h($d['temp']) . '&nbsp;&deg;C' : ($d['standby'] ? 'standby' : '<span class="orange-text">no reading</span>');
    $dl[] = synohw_h($d['name']) . ' (' . synohw_h($d['device']) . ($d['pmp'] !== null ? ', PMP port ' . synohw_h(max(0, $d['pmp'])) : '') . ($d['ata'] !== null ? ' on ata' . synohw_h($d['ata']) : '') . '): ' . $t;
  }
  $row('Disks in the DX1215', $dl ? implode(' &middot; ', $dl) : '<span class="grey-text">none found (decided from sysfs: behind the 88SE9235 or a port multiplier)</span>');
  // the daemon's decision
  if ($st && isset($st['dx_state'])) {
    $row('Fan control', synohw_dx_decision($st, $now));
  } elseif ($s['daemon']['running']) {
    $row('Fan control', '<span class="grey-text">the running daemon is older than 2026.10.25h (no DX fields in its state): press Apply</span>');
  }
  return $R;
}

/* one sentence: mode, input, row, wanted, why, last write (from the daemon's state file) */
function synohw_dx_decision(array $st, int $now): string {
  $mode = $st['dx_mode'] ?? '?'; $why = $st['dx_why'] ?? '';
  if (in_array($st['dx_state'] ?? '', ['nomod', 'nomaster', 'init'], true)) return synohw_orb('grey') . 'mode ' . synohw_h($mode) . ': ' . synohw_h(SYNOHW_DX_WHY[$why] ?? 'starting');
  $disk = (string)($st['dx_disk'] ?? '');
  $in = $disk === '' ? 'no DX1215 disk reading' : ($disk === 'standby' ? 'all DX1215 disks asleep' : 'hottest DX1215 disk ' . synohw_h($st['dx_hot'] ?? '?') . ' ' . synohw_h($disk) . '&nbsp;&deg;C');
  $o = 'mode <b>' . synohw_h($mode) . '</b>' . ($mode === 'MANUAL' ? ' (DX_LEVEL ' . synohw_h($st['dx_cfg_level'] ?? '?') . ')' : '') . '; input: ' . $in;
  if ((int)($st['dx_unread'] ?? 0) > 0) $o .= ', ' . synohw_h($st['dx_unread']) . ' DX disk(s) without a reading';
  if ($mode === 'AUTO' && ($st['dx_step'] ?? '') !== '') $o .= '; table row from ' . synohw_h($st['dx_step']) . '&nbsp;&deg;C (' . synohw_h($st['dx_base'] ?? '?') . ')';
  if ($mode === 'LINKED') $o .= '; DS2415+ duty ' . synohw_h($st['dx_input'] ?? '?') . ' % &rarr; ' . synohw_h($st['dx_base'] ?? '?');
  $o .= '; wanted <b>' . synohw_h($st['dx_want'] ?? '?') . '</b>, reads <b>' . synohw_h($st['dx_level'] ?? '?') . '</b> because ' . synohw_h(SYNOHW_DX_WHY[$why] ?? $why) . '.';
  if ($why === 'alarm' || $why === 'noread' || $why === 'shutdown' || $why === 'nodisk') $o = '<span class="orange-text">' . $o . '</span>';
  $o .= ' <span class="grey-text">Evaluated ' . synohw_h(synohw_age($now, (int)($st['dx_at'] ?? 0))) . ' (every ' . synohw_h($st['dx_period'] ?? 60) . ' s); '
     . synohw_h($st['dx_writes'] ?? 0) . ' write(s) since start, last ' . synohw_h(synohw_clock((int)($st['dx_wrote_at'] ?? 0))) . '; written only when the wanted level differs from the level read, and again every FAN_REASSERT s.</span>';
  if ((int)($st['dx_write_fail'] ?? 0) > 0) $o .= ' <span class="red-text">' . synohw_h($st['dx_write_fail']) . ' write failure(s)' . (($st['dx_err'] ?? '') !== '' ? ': ' . synohw_h($st['dx_err']) : '') . '</span>';
  return $o;
}

/* both boxes in one table: mode, input, output, why */
function synohw_render_fans(array $s): string {
  $st = $s['daemon']['state'] ?? []; $now = $s['now'];
  if (!$s['daemon']['running'] || !$st) return '<p class="grey-text">The daemon is not running: nobody controls the fans (the DS2415+ fan was left at 99 % if it was stopped on purpose; the DX1215 keeps its level).</p>';
  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-fans"><thead><tr><td>Box</td><td>Mode</td><td>Input now</td><td>Output</td><td>Why</td></tr></thead><tbody>';
  $dsm = $st['ds_mode'] ?? 'AUTO';
  $mode = $dsm === 'MANUAL' ? 'MANUAL ' . synohw_h($st['ds_duty'] ?? '?') . ' %' : 'AUTO, ' . synohw_h($st['mode'] ?? '?') . ' table' . ((int)($st['ds_floor'] ?? 20) < 20 ? ', floor ' . synohw_h($st['ds_floor']) . ' %' : '');
  $disk = (string)($st['disk'] ?? '?');
  $in = 'hottest disk ' . synohw_h($disk) . ($disk === 'standby' || $disk === '?' ? '' : '&nbsp;&deg;C') . ', CPU ' . synohw_h($st['cpu'] ?? '?') . '&nbsp;&deg;C';
  $ch = $st['chosen'] ?? '';
  $sw = ($s['daemon']['sweep']['state'] ?? '') === 'running';
  $o .= '<tr><td>DS2415+ fan</td><td>' . $mode . '</td><td>' . $in . '</td><td><b>' . synohw_h($st['sent'] ?? '?') . ' %</b> sent' . ((string)($st['table_duty'] ?? '') !== '' && ($st['table_duty'] ?? '') !== ($st['sent'] ?? '') ? ' <span class="grey-text">(table: ' . synohw_h($st['table_duty']) . ' %)</span>' : '') . '</td><td class="whitespace-normal">'
     . ($sw ? 'a fan sweep holds the fan (see Fan sweep)' : synohw_h(SYNOHW_DS_WHY[$ch] ?? 'starting')) . '</td></tr>';
  if (isset($st['dx_state'])) {
    $x = $st['dx_state'];
    $dmode = $st['dx_mode'] ?? '?';
    $dx_in = ($st['dx_disk'] ?? '') === '' ? 'no DX1215 disk reading' : (($st['dx_disk'] ?? '') === 'standby' ? 'all DX1215 disks asleep' : 'hottest DX1215 disk ' . synohw_h($st['dx_disk']) . '&nbsp;&deg;C');
    if ($dmode === 'LINKED') $dx_in .= ', DS2415+ duty ' . synohw_h($st['dx_input'] ?? '?') . ' %';
    $al = ($st['dx_alarm1'] ?? '') === '1' || ($st['dx_alarm2'] ?? '') === '1';
    $out = $x === 'ok' ? '<b>' . synohw_h($st['dx_level'] ?? '?') . '</b>' . ($al ? ' <span class="red-text">fan alarm</span>' : '') : '<span class="grey-text">not controlled</span>';
    $o .= '<tr><td>DX1215 fans</td><td>' . synohw_h($dmode) . ($dmode === 'MANUAL' ? ' ' . synohw_h($st['dx_cfg_level'] ?? '') : '') . '</td><td>' . $dx_in . '</td><td>' . $out . '</td><td class="whitespace-normal">' . synohw_h(SYNOHW_DX_WHY[$st['dx_why'] ?? ''] ?? 'starting') . '</td></tr>';
  }
  return $o . '</tbody></table></div>';
}

/* every live block by name. $only (2026.10.25j): the blocks the visible tab shows; null = all. The first ten are the blocks
 * of 2026.10.25b-i (kept: tests and older pages use them), the rest are the tabbed page's (synohw-view.php). */
function synohw_render_all(array $s, ?array $only = null): array {
  $out = [];
  foreach (synohw_render_all_names() as $k => $fn) if ($only === null || in_array($k, $only, true)) $out[$k] = $fn($s);
  return $out;
}
function synohw_render_all_names(): array {
  return [
    'daemon' => 'synohw_render_daemon', 'mcu' => 'synohw_render_mcu', 'fan' => 'synohw_render_fan',
    'temps' => 'synohw_render_temps', 'net' => 'synohw_render_net', 'sys' => 'synohw_render_sys',
    'cfgdiff' => 'synohw_render_cfgdiff', 'sweep' => 'synohw_render_sweep',
    'fans' => 'synohw_render_fans', 'dx' => 'synohw_render_dx',
  ] + SYNOHW_VIEW_BLOCKS;
}

require_once __DIR__ . '/synohw-view.php';
