<?php
/* synohw-hw.php - the Hardware section of the synology-hardware settings page (2026.10.25g). Written: 2026-10-25.
 * Which items, why and how they were measured: research/2026-10-25-hw-info-inventory.md (raw outputs in
 * experiments/2026-10-25-hw-inventory/); "#n" below = the row number in that file's table B.
 *
 * READ-ONLY, like synohw-status.php: only sysfs/procfs files and the cache that scripts/synohw-inventory writes to
 * /var/run/synology-hardware/inventory (SMART of awake disks via smartctl -n standby, ethtool), which the daemon refreshes every
 * INVENTORY_PERIOD (600 s). Nothing here runs a command or opens a device, so rendering it can never wake a disk.
 * Cost: a few hundred small sysfs reads; the page renders it once and refreshes it every 60 s (action=hardware), not in the
 * 5 s live poll. Every value is escaped with synohw_h(). Requires synohw-status.php. */

/* lspci -nn names of the devices measured on the DS2415+ (experiments/2026-10-25-hw-inventory/pci-usb.txt); others: ids only */
const SYNOHW_PCI_NAMES = [
  '8086:1f0c' => 'Atom C2000 SoC Transaction Router', '8086:1f10' => 'Atom C2000 PCIe Root Port 1', '8086:1f11' => 'Atom C2000 PCIe Root Port 2',
  '8086:1f12' => 'Atom C2000 PCIe Root Port 3', '8086:1f13' => 'Atom C2000 PCIe Root Port 4', '8086:1f14' => 'Atom C2000 RAS',
  '8086:1f16' => 'Atom C2000 RCEC', '8086:1f15' => 'Atom C2000 SMBus 2.0', '8086:1f41' => 'Intel I354 Ethernet (1 GbE)',
  '8086:1f2c' => 'Atom C2000 USB EHCI (USB 2.0)', '8086:1f22' => 'Atom C2000 AHCI SATA2 (3 Gb/s)', '8086:1f32' => 'Atom C2000 AHCI SATA3 (6 Gb/s)',
  '8086:1f38' => 'Atom C2000 PCU (LPC)', '8086:1f3c' => 'Atom C2000 PCU SMBus', '10b5:8605' => 'PLX PEX 8605 PCIe Gen2 switch',
  '1b4b:9215' => 'Marvell 88SE9215 SATA 6 Gb/s, 4 ports (internal bays)', '1b4b:9235' => 'Marvell 88SE9235 SATA 6 Gb/s, 4 ports (expansion: DX1215)',
  '1b6f:7052' => 'Etron EJ188/EJ198 USB 3.0 xHCI',
];

function synohw_hw_paths(array $p): array {
  return $p + [
    'pcidev' => '/sys/bus/pci/devices', 'atalink' => '/sys/class/ata_link', 'cpusys' => '/sys/devices/system/cpu',
    'cpuinfo' => '/proc/cpuinfo', 'meminfo' => '/proc/meminfo', 'uptime' => '/proc/uptime', 'loadavg' => '/proc/loadavg',
    'osrelease' => '/proc/sys/kernel/osrelease', 'kversion' => '/proc/version', 'cmdline' => '/proc/cmdline',
    'usbdev' => '/sys/bus/usb/devices', 'sysfw' => '/sys/firmware', 'edac' => '/sys/devices/system/edac',
    'powercap' => '/sys/class/powercap', 'watchdog' => '/sys/class/watchdog', 'leds' => '/sys/class/leds', 'gpio' => '/sys/class/gpio',
  ];
}

/* ---------------- collectors ---------------- */

function synohw_lines(string $f): array { return (is_file($f) && is_readable($f)) ? (@file($f, FILE_IGNORE_NEW_LINES) ?: []) : []; }   // missing = empty, no warning
function synohw_int_file(string $f): ?int { $v = synohw_read($f); return preg_match('/^-?\d{1,18}$/', $v) ? (int)$v : null; }

/* AER counters: the TOTAL_ERR_* line of aer_dev_{correctable,nonfatal,fatal} (#19) */
function synohw_aer(string $dir): ?array {
  $out = []; $any = false;
  foreach (['correctable' => 'TOTAL_ERR_COR', 'nonfatal' => 'TOTAL_ERR_NONFATAL', 'fatal' => 'TOTAL_ERR_FATAL'] as $k => $tot) {
    $t = synohw_read("$dir/aer_dev_$k");
    if ($t === '') { $out[$k] = null; continue; }
    $any = true;
    $out[$k] = preg_match('/' . $tot . '\s+(\d+)/', $t, $m) ? (int)$m[1] : null;
  }
  return $any ? $out : null;
}

function synohw_hw_pci(array $p): array {
  $list = [];
  foreach (glob("{$p['pcidev']}/*") ?: [] as $d) {
    $a = basename($d);
    if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $a)) continue;
    $v = strtolower(synohw_read("$d/vendor")); $dv = strtolower(synohw_read("$d/device"));
    $id = (preg_match('/^0x[0-9a-f]{4}$/', $v) && preg_match('/^0x[0-9a-f]{4}$/', $dv)) ? substr($v, 2) . ':' . substr($dv, 2) : '?';
    $drv = is_link("$d/driver") ? basename((string)readlink("$d/driver")) : '';
    $cs = synohw_read("$d/current_link_speed"); $cw = synohw_read("$d/current_link_width");
    $ms = synohw_read("$d/max_link_speed"); $mw = synohw_read("$d/max_link_width");
    // 2026.10.25h: on-chip SoC functions without a PCIe link (the I354 NICs 00:14.x) still have the files, reading
    // "Unknown" / width 0 now and "Unknown" / width 255 max (measured: docs/screenshots/webgui-2026-10-25g-hardware.png). No link.
    $nolink = fn($sp, $w) => $sp === '' || stripos($sp, 'Unknown') === 0 || !ctype_digit($w) || (int)$w === 0 || (int)$w > 32;
    if ($nolink($cs, $cw)) { $cs = ''; $cw = ''; }
    if ($nolink($ms, $mw)) { $ms = ''; $mw = ''; }
    $num = fn($s) => preg_match('/^([\d.]+)\s*GT\/s/', $s, $m) ? (float)$m[1] : null;
    $bridge = strncmp(synohw_read("$d/class"), '0x0604', 6) === 0;   // a port: its link is as wide as the device behind it allows
    $narrow = ($cw !== '' && $mw !== '' && ctype_digit($cw) && ctype_digit($mw) && (int)$cw > 0 && (int)$cw < (int)$mw)
         || ($num($cs) !== null && $num($ms) !== null && $num($cs) < $num($ms));
    $down = $narrow && !$bridge;
    $list[] = ['addr' => $a, 'id' => $id, 'name' => SYNOHW_PCI_NAMES[$id] ?? '', 'driver' => $drv, 'class' => synohw_read("$d/class"),
               'cur' => $cs !== '' ? trim("$cs x$cw") : '', 'max' => $ms !== '' ? trim("$ms x$mw") : '', 'down' => $down,
               'narrow' => $narrow, 'bridge' => $bridge, 'aer' => synohw_aer($d)];
  }
  usort($list, fn($a, $b) => strcmp($a['addr'], $b['addr']));
  return $list;
}

/* box of a sysfs device path (same rules as synohw_disk_box: 88SE9235 or a port-multiplier link = DX1215) */
function synohw_path_box(string $r): array {
  $prefix = ''; $ctrl = ''; $id = ''; $dx = false; $pmp = false;
  foreach (explode('/', $r) as $i => $c) {
    $prefix = $i === 0 ? $c : "$prefix/$c";
    if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $c)) {
      $v = strtolower(synohw_read("$prefix/vendor")); $d = strtolower(synohw_read("$prefix/device"));
      $ctrl = $c; $id = (strlen($v) === 6 && strlen($d) === 6) ? substr($v, 2) . ':' . substr($d, 2) : '';
      if ($id === '1b4b:9235') $dx = true;
    } elseif (preg_match('/^link\d+\.\d+$/', $c)) $pmp = true;
  }
  return ['box' => ($dx || $pmp) ? 'DX1215' : ($ctrl !== '' ? 'DS2415+ internal' : 'unknown'), 'ctrl' => $ctrl, 'ctrl_id' => $id, 'pmp' => $pmp];
}

/* SATA links (#21, #22): negotiated speed per link, incl. the port-multiplier fan-out links (linkN.M) */
function synohw_hw_ata(array $p, array $disks): array {
  $byLink = [];
  foreach ($disks as $d) {
    $b = synohw_disk_box($p, $d['device']);
    if ($b['ata'] === null) continue;
    $byLink['link' . $b['ata'] . ($b['pmp'] !== null ? '.' . max(0, $b['pmp']) : '')] = $d;
  }
  $out = [];
  foreach (glob("{$p['atalink']}/link*") ?: [] as $l) {
    $n = basename($l);
    if (!preg_match('/^link(\d{1,3})(\.(\d{1,2}))?$/', $n, $m)) continue;
    if (($m[3] ?? '') === '15') continue;                                  // the PMP's own control port, no device
    $spd = synohw_read("$l/sata_spd");
    $known = $spd !== '' && $spd !== '<unknown>';
    if (!$known && !isset($byLink[$n])) continue;                          // nothing attached: not listed
    $r = realpath($l);
    $box = is_string($r) ? synohw_path_box($r) : ['box' => 'unknown', 'ctrl' => '', 'ctrl_id' => '', 'pmp' => false];
    $lim = synohw_read("$l/sata_spd_limit"); $hw = synohw_read("$l/hw_sata_spd_limit");
    $out[] = ['link' => $n, 'ata' => (int)$m[1], 'pmp' => isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null, 'spd' => $known ? $spd : '',
              'limit' => $lim !== '<unknown>' ? $lim : '', 'hwlimit' => $hw !== '<unknown>' ? $hw : '', 'disk' => $byLink[$n] ?? null] + $box;
  }
  usort($out, fn($a, $b) => [$a['ata'], $a['pmp'] ?? -1] <=> [$b['ata'], $b['pmp'] ?? -1]);
  return $out;
}

/* one smartctl -n standby output from the inventory cache (#24, #25) */
function synohw_smart_parse(string $text): array {
  $s = ['dev' => '', 'key' => '', 'id' => '', 'at' => 0, 'rc' => null, 'standby' => false, 'info' => [], 'health' => '', 'attr' => [], 'phy' => [], 'errlog' => ''];
  $lines = explode("\n", $text);
  if (preg_match('/^# synohw dev=([a-z0-9]{1,16}) key=(\S*) id=(\S*) at=(\d+) rc=(\d+)$/', trim($lines[0] ?? ''), $m)) {
    [, $s['dev'], $s['key'], $s['id'], $at, $rc] = $m; $s['at'] = (int)$at; $s['rc'] = (int)$rc;
  }
  $sec = '';
  foreach ($lines as $l) {
    if (stripos($l, 'Device is in STANDBY mode') !== false || stripos($l, 'Device is in SLEEP mode') !== false) $s['standby'] = true;
    if (preg_match('/^SATA Phy Event Counters/', $l)) { $sec = 'phy'; continue; }
    if (preg_match('/^(Model Family|Device Model|Serial Number|Firmware Version|User Capacity|Rotation Rate|SATA Version is|Form Factor):\s+(.+)$/', $l, $m)) { $s['info'][$m[1]] = trim($m[2]); continue; }
    if (preg_match('/^SMART overall-health self-assessment test result:\s+(\S+)/', $l, $m)) { $s['health'] = $m[1]; continue; }
    if (preg_match('/^No Errors Logged/', $l)) { $s['errlog'] = 'none'; continue; }
    if (preg_match('/^ATA Error Count:\s+(\d+)/', $l, $m)) { $s['errlog'] = $m[1]; continue; }
    if (preg_match('/^\s*(\d{1,3})\s+([A-Za-z0-9_-]+)\s+0x[0-9a-f]{4}\s+(\d{3})\s+(\d{3})\s+(\d{3})\s+\S+\s+\S+\s+\S+\s+(\S.*)$/', $l, $m)) {
      $s['attr'][(int)$m[1]] = ['name' => $m[2], 'value' => (int)$m[3], 'worst' => (int)$m[4], 'thresh' => (int)$m[5], 'raw' => trim($m[6]),
                                'rawn' => preg_match('/^(\d+)/', trim($m[6]), $r) ? (int)$r[1] : null];
      continue;
    }
    if ($sec === 'phy' && preg_match('/^0x([0-9a-f]{4})\s+\d+\s+(\d+)\s+(.+)$/', trim($l), $m)) $s['phy'][hexdec($m[1])] = ['value' => (int)$m[2], 'desc' => trim($m[3])];
  }
  if ($s['rc'] !== null && ($s['rc'] & 2) && !$s['info']) $s['standby'] = true;   // smartctl -n standby: exit 2 = not read
  return $s;
}

function synohw_hw_smart(array $p): array {
  $inv = "{$p['run']}/inventory"; $out = [];
  foreach (glob("$inv/smart-*.txt") ?: [] as $f) {
    if (!preg_match('/^smart-([a-z0-9]{1,16})\.txt$/', basename($f), $m)) continue;
    $out[$m[1]] = synohw_smart_parse(implode("\n", synohw_tail_lines($f, 65536)));
  }
  return ['at' => synohw_kv("$inv/at") ?? [], 'disks' => $out];
}

function synohw_hw_cpu(array $p): array {
  $c = $p['cpusys']; $models = []; $mc = '';
  foreach (synohw_lines($p['cpuinfo']) as $l) {
    if (preg_match('/^model name\s*:\s*(.+)$/', $l, $m)) $models[] = preg_replace('/\s+/', ' ', trim($m[1]));
    elseif (preg_match('/^microcode\s*:\s*(\S+)/', $l, $m)) $mc = $m[1];
  }
  $cores = [];
  foreach (glob("$c/cpu[0-9]*") ?: [] as $d) {
    if (!preg_match('/^cpu(\d+)$/', basename($d), $m)) continue;
    $f = "$d/cpufreq"; $idle = [];
    foreach (glob("$d/cpuidle/state*") ?: [] as $st) {
      $idle[] = ['name' => synohw_read("$st/name"), 'usage' => synohw_int_file("$st/usage"), 'time' => synohw_int_file("$st/time"), 'latency' => synohw_int_file("$st/latency")];
    }
    $cores[(int)$m[1]] = ['cur' => synohw_int_file("$f/scaling_cur_freq"), 'min' => synohw_int_file("$f/cpuinfo_min_freq"), 'max' => synohw_int_file("$f/cpuinfo_max_freq"),
                          'gov' => synohw_read("$f/scaling_governor"), 'drv' => synohw_read("$f/scaling_driver"), 'idle' => $idle,
                          'throttle' => is_dir("$d/thermal_throttle")];
  }
  ksort($cores);
  $tis = []; $f0 = "$c/cpu0/cpufreq/stats";
  foreach (preg_split('/\n/', synohw_read("$f0/time_in_state")) as $l) if (preg_match('/^(\d+)\s+(\d+)$/', trim($l), $m)) $tis[(int)$m[1]] = (int)$m[2];
  $thermal = [];
  foreach (glob("{$p['thermal']}/thermal_zone*") ?: [] as $z) {
    $trips = [];
    foreach (glob("$z/trip_point_*_temp") ?: [] as $t) {
      if (!preg_match('/trip_point_(\d+)_temp$/', $t, $m)) continue;
      $trips[(int)$m[1]] = ['type' => synohw_read("$z/trip_point_{$m[1]}_type"), 'temp' => synohw_int_file($t)];
    }
    ksort($trips);
    $thermal[] = ['zone' => basename($z), 'type' => synohw_read("$z/type"), 'temp' => synohw_int_file("$z/temp"), 'policy' => synohw_read("$z/policy"), 'mode' => synohw_read("$z/mode"), 'trips' => $trips];
  }
  return ['model' => $models[0] ?? '', 'count' => count($models), 'microcode' => $mc, 'cores' => $cores, 'tis' => $tis,
          'trans' => synohw_int_file("$f0/total_trans"), 'idle_driver' => synohw_read("$c/cpuidle/current_driver"), 'thermal' => $thermal];
}

function synohw_hw_system(array $p): array {
  $mem = [];
  foreach (synohw_lines($p['meminfo']) as $l) if (preg_match('/^(MemTotal|MemAvailable|MemFree|SwapTotal):\s+(\d+) kB/', $l, $m)) $mem[$m[1]] = (int)$m[2];
  $up = synohw_read($p['uptime']);
  return ['mem' => $mem, 'uptime' => preg_match('/^([\d.]+)/', $up, $m) ? (int)$m[1] : null,
          'load' => implode(' ', array_slice(explode(' ', synohw_read($p['loadavg'])), 0, 3)), 'release' => synohw_read($p['osrelease']),
          'version' => synohw_read($p['kversion']), 'cmdline' => synohw_read($p['cmdline']), 'efi' => is_dir("{$p['sysfw']}/efi")];
}

/* NICs (#28-#30): sysfs link state and counters; Wake-on from the ethtool cache */
function synohw_hw_net(array $p): array {
  $out = []; $inv = "{$p['run']}/inventory";
  foreach (glob("{$p['sysnet']}/*") ?: [] as $n) {
    if (!file_exists("$n/device") || file_exists("$n/wireless")) continue;
    $nic = basename($n);
    if (!preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $nic)) continue;
    $st = [];
    foreach (['rx_errors', 'tx_errors', 'rx_crc_errors', 'rx_missed_errors', 'rx_fifo_errors', 'rx_length_errors', 'collisions', 'rx_dropped', 'tx_dropped'] as $k) $st[$k] = synohw_int_file("$n/statistics/$k");
    $eth = ['at' => 0, 'rc' => null, 'wol' => '', 'wol_sup' => '', 'autoneg' => ''];
    $t = synohw_lines("$inv/ethtool-$nic.txt");
    if ($t && preg_match('/ at=(\d+) rc=(\d+)$/', $t[0], $m)) { $eth['at'] = (int)$m[1]; $eth['rc'] = (int)$m[2]; }
    foreach ($t as $l) {
      if (preg_match('/^\s*Supports Wake-on:\s*(\S+)/', $l, $m)) $eth['wol_sup'] = $m[1];
      elseif (preg_match('/^\s*Wake-on:\s*(\S+)/', $l, $m)) $eth['wol'] = $m[1];
      elseif (preg_match('/^\s*Auto-negotiation:\s*(\S+)/', $l, $m)) $eth['autoneg'] = $m[1];
    }
    $out[] = ['nic' => $nic, 'oper' => synohw_read("$n/operstate"), 'carrier' => synohw_read("$n/carrier"), 'speed' => synohw_int_file("$n/speed"),
              'duplex' => synohw_read("$n/duplex"), 'driver' => is_link("$n/device/driver") ? basename((string)readlink("$n/device/driver")) : '',
              'pci' => basename((string)realpath("$n/device")), 'changes' => synohw_int_file("$n/carrier_changes"), 'stats' => $st, 'eth' => $eth];
  }
  usort($out, fn($a, $b) => strnatcmp($a['nic'], $b['nic']));
  return $out;
}

/* USB (#26, #27): devices with ids, speed, power; the DOM (f400:f400) and the Unraid flash (disks.ini flash device) marked */
function synohw_hw_usb(array $p): array {
  $flashUsb = '';
  $ini = is_readable($p['disks_ini']) ? @parse_ini_file($p['disks_ini'], true, INI_SCANNER_RAW) : false;
  $fdev = '';   // INI_SCANNER_RAW keeps the quotes of ["flash"], so match the name field
  foreach (is_array($ini) ? $ini : [] as $sec => $v) if (is_array($v) && (($v['name'] ?? trim($sec, '"')) === 'flash')) $fdev = (string)($v['device'] ?? '');
  if (preg_match('/^[a-z0-9]{1,16}$/', $fdev)) {
    $r = realpath("{$p['sysblock']}/$fdev/device");
    if (is_string($r) && preg_match_all('#/(\d+-[\d.]+)(?=/)#', $r, $mm)) $flashUsb = end($mm[1]);
  }
  $out = [];
  foreach (glob("{$p['usbdev']}/*") ?: [] as $d) {
    $n = basename($d);
    if (!preg_match('/^(usb\d+|\d+-[\d.]+)$/', $n)) continue;                // devices only, not interfaces (1-1:1.0)
    $vid = synohw_read("$d/idVendor"); $pid = synohw_read("$d/idProduct");
    $id = preg_match('/^[0-9a-f]{4}$/', $vid) && preg_match('/^[0-9a-f]{4}$/', $pid) ? "$vid:$pid" : '?';
    $role = strncmp($n, 'usb', 3) === 0 ? 'root hub (' . synohw_read("$d/serial") . ')' : ($id === 'f400:f400' ? 'Synology DOM (vender file: MACs, serial)' : ($n === $flashUsb ? 'Unraid boot flash' : (synohw_read("$d/bDeviceClass") === '09' ? 'hub' : '')));
    $out[] = ['dev' => $n, 'bus' => (int)synohw_read("$d/busnum"), 'id' => $id, 'mfr' => synohw_read("$d/manufacturer"), 'product' => synohw_read("$d/product"),
              'serial' => strncmp($n, 'usb', 3) === 0 ? '' : synohw_read("$d/serial"), 'speed' => synohw_read("$d/speed"), 'version' => trim(synohw_read("$d/version")),
              'power' => synohw_read("$d/bMaxPower"), 'role' => $role];
  }
  usort($out, fn($a, $b) => ($a['bus'] <=> $b['bus']) ?: (strncmp($b['dev'], 'usb', 3) === 0) <=> (strncmp($a['dev'], 'usb', 3) === 0) ?: strnatcmp($a['dev'], $b['dev']));
  return $out;
}

/* the MCU only ever sends K and 3 (#41, #44): true while the daemon's byte counts hold nothing else */
function synohw_mcu_only_k3(array $p): bool {
  $m = synohw_kv("{$p['run']}/mcu") ?? [];
  foreach ($m as $k => $v) if (preg_match('/^count_([0-9a-f]{2})$/', $k, $mm) && !in_array($mm[1], ['33', '4b'], true) && (int)$v > 0) return false;
  return true;
}

/* what this hardware/kernel does not provide, checked now where a file can tell (section C of the research) */
function synohw_hw_absent(array $p, array $cpu): array {
  $hw = [];
  foreach (glob("{$p['hwmon']}/hwmon*") ?: [] as $d) $hw[] = synohw_read("$d/name");
  $empty = fn($dir) => !is_dir($dir) || !(glob("$dir/*") ?: []);
  $throttle = !array_filter($cpu['cores'], fn($c) => $c['throttle']);   // absent unless a cpuN/thermal_throttle exists
  return [
    ['SMBIOS / DMI (board, BIOS version, DIMMs)', !is_dir("{$p['sysfw']}/dmi"), 'no /sys/firmware/dmi', 'dmidecode: "No SMBIOS nor DMI entry point found" (#11); the Insyde BIOS exposes no SMBIOS tables. ACPI OEM id: INSYDE / MOHOPEAK (#12).'],
    ['ECC / EDAC memory error counters', !is_dir($p['edac']), 'no /sys/devices/system/edac', 'The Avoton RAS device 8086:1f14 has no upstream EDAC driver; pnd2_edac and sb_edac are for other chips (#15). Whether the DIMMs are ECC is unknown.'],
    ['DIMM details (slots, speed, part numbers)', true, 'not readable', 'Needs SMBIOS or SPD over SMBus; the SPD driver (at24/ee1004) is not shipped and probing the bus is off-limits (#14).'],
    ['CPU package temperature', true, 'coretemp has Core 0-3 only', 'No "Package id" sensor on the C2538 (#6).'],
    ['Power draw (RAPL)', $empty($p['powercap']), 'no /sys/class/powercap entries', 'The C2000 has no RAPL MSRs; intel_rapl_msr exists but does not bind (#7).'],
    ['CPU thermal-throttle counters', $throttle, 'no cpu*/thermal_throttle', 'Not exposed for this CPU by this kernel (#5).'],
    ['Board fans, voltages, board temperatures', !array_diff($hw, ['coretemp', 'acpitz', 'synology_dx', '']), 'hwmon: ' . (implode(', ', array_filter($hw)) ?: 'none'), 'No Super-IO chip; the fan is driven by the MCU on ttyS1 and has no tach reading (#34). The hwmon synology_dx (DX1215 fan alarms and level, synology-dx module) has no speed or voltage either.'],
    ['Hardware watchdog', $empty($p['watchdog']), 'no /sys/class/watchdog entries', 'The BIOS has a WDAT table but no wdat_wdt / iTCO_wdt module ships with Unraid (#35). The plugin\'s cron watchdog only restarts its daemon.'],
    ['LEDs / GPIO as kernel devices', $empty($p['leds']) && $empty($p['gpio']), 'no /sys/class/leds, /sys/class/gpio entries', 'Status, power and alert LEDs are MCU commands on ttyS1 (#39); the DX1215 LEDs are PMP GPIO (needs the synology-dx module).'],
    ['Power supply telemetry', true, 'no PMBus seen', 'The DS2415+ PSU has no telemetry on any visible bus; DX1215 PSU status only via PMP GPIO (#40): two raw bits, shown in the DX1215 section with the synology-dx module (polarity unknown).'],
    ['MCU firmware version', synohw_mcu_only_k3($p), 'no byte other than K / 3 since the daemon started', 'The daemon sends O (MICROP_VERSION_REPORT) at start; the MCU has never sent anything but K and 3 (#41). See the MCU byte table.'],
    ['Reason of the last boot', true, 'not recorded', 'No watchdog status bit and no pstore content to read it from (#42).'],
  ];
}

function synohw_hw_collect(array $p): array {
  $p = synohw_hw_paths($p);
  $p['inv_period'] = (int)((synohw_kv("{$p['run']}/effective") ?? [])['INVENTORY_PERIOD'] ?? 600);
  $disks = synohw_disks($p);
  $cpu = synohw_hw_cpu($p);
  return ['now' => $p['now'], 'p' => $p, 'disks' => $disks['list'], 'sys' => synohw_hw_system($p), 'cpu' => $cpu, 'pci' => synohw_hw_pci($p),
          'ata' => synohw_hw_ata($p, $disks['list']), 'smart' => synohw_hw_smart($p), 'net' => synohw_hw_net($p), 'usb' => synohw_hw_usb($p),
          'absent' => synohw_hw_absent($p, $cpu), 'macs' => synohw_ports($p), 'mcu' => synohw_kv("{$p['run']}/mcu") ?? [],
          'temps' => synohw_cpu($p), 'cfg' => synohw_kv($p['cfg']) ?? [], 'model' => synohw_models($p, synohw_dx($p))];   // model: 2026.10.25j
}

/* ---------------- rendering ---------------- */

function synohw_ghz(?int $khz): string { return $khz === null ? '-' : number_format($khz / 1e6, 2) . '&nbsp;GHz'; }
function synohw_kb(?int $kb): string { return $kb === null ? '-' : number_format($kb / 1048576, 1) . '&nbsp;GiB'; }
function synohw_n($v): string { return $v === null ? '-' : synohw_h(number_format((int)$v, 0, '.', ' ')); }
function synohw_bad($v, string $cls = 'red-text'): string { return $v === null ? '-' : ((int)$v > 0 ? '<span class="' . $cls . '">' . synohw_n($v) . '</span>' : synohw_n($v)); }
function synohw_tbl(string $cls, array $head, array $rows): string {
  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-hw ' . $cls . '"><thead><tr>';
  foreach ($head as $h) $o .= '<td>' . $h . '</td>';
  $o .= '</tr></thead><tbody>';
  foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td>' . $c . '</td>'; $o .= '</tr>'; }
  return $o . '</tbody></table></div>';
}

function synohw_render_hw_system(array $h): string {
  $s = $h['sys']; $c = $h['cpu']; $m = $s['mem']; $sn = $h['macs']['sn'];
  $dom = array_values(array_filter($h['usb'], fn($u) => $u['id'] === 'f400:f400'))[0] ?? null;
  $rows = [
    ['Model', ($h['model']['main']['model'] !== '' ? 'Synology ' . synohw_h($h['model']['main']['model']) : '<span class="orange-text">not identified</span>') . ' <span class="grey-text">(' . synohw_h($h['model']['main']['how']) . ')</span>'
              . ' &middot; ' . ($sn !== '' ? 'unit serial ' . synohw_h($sn) . ' (DOM vender file)' : 'unit serial: DOM vender file not decoded in this boot')],
    ['Expansion unit', $h['model']['dx']['model'] !== '' ? 'Synology ' . synohw_h($h['model']['dx']['model']) . ' <span class="grey-text">(unique ' . synohw_h($h['model']['dx']['unique']) . '; ' . synohw_h($h['model']['dx']['how']) . ')</span>' : '<span class="grey-text">' . synohw_h($h['model']['dx']['how']) . '</span>'],
    ['CPU', synohw_h($c['model'] ?: '-') . ', ' . synohw_h($c['count']) . ' logical CPUs, microcode ' . synohw_h($c['microcode'] ?: '-')],
    ['Memory', isset($m['MemTotal']) ? synohw_kb($m['MemTotal']) . ' total, ' . synohw_kb($m['MemAvailable'] ?? null) . ' available (/proc/meminfo)' : '-'],
    ['Uptime, load', ($s['uptime'] !== null ? synohw_h(synohw_dur($s['uptime'])) : '-') . ' &middot; load ' . synohw_h($s['load'] ?: '-')],
    ['Kernel', synohw_h($s['release'] ?: '-') . ' <span class="grey-text">' . synohw_h(preg_replace('/^Linux version \S+\s*/', '', $s['version'])) . '</span>'],
    ['Boot', 'command line <code>' . synohw_h($s['cmdline'] ?: '-') . '</code> &middot; ' . ($s['efi'] ? 'UEFI' : 'legacy BIOS boot (no /sys/firmware/efi)')],
    ['BIOS', 'Insyde, ACPI OEM table id MOHOPEAK (the Avoton reference board); no SMBIOS, so no BIOS version or date (measured 2026-10-25, #11-#12)'],
    ['MCU (ttyS1)', synohw_render_mcu_line($h)],
    ['DOM', $dom ? synohw_h(trim($dom['mfr'] . ' ' . $dom['product'])) . ', USB ' . synohw_h($dom['id']) . ', ' . synohw_h($dom['speed']) . '&nbsp;Mb/s, serial ' . synohw_h($dom['serial'] ?: '-') . ' &middot; ' . synohw_h($h['macs']['dom_count']) . ' MACs decoded' : '<span class="grey-text">no USB f400:f400 device found</span>'],
  ];
  $o = '<table class="unraid synohw-kv"><tbody>';
  foreach ($rows as $r) $o .= '<tr><td>' . $r[0] . '</td><td class="whitespace-normal">' . $r[1] . '</td></tr>';
  return $o . '</tbody></table>';
}

/* MCU heartbeat in one line (#44): rate of each byte value since the daemon started, last byte age */
function synohw_render_mcu_line(array $h): string {
  $m = $h['mcu']; if (!$m) return '<span class="grey-text">no byte counted yet (daemon not running, or just started)</span>';
  $since = (int)($m['since'] ?? 0); $at = (int)($m['last_at'] ?? 0); $span = max(1, $at - $since); $parts = [];
  foreach ($m as $k => $v) if (preg_match('/^count_([0-9a-f]{2})$/', $k, $mm)) {
    $c = hexdec($mm[1]); $parts[] = '0x' . $mm[1] . ($c >= 0x21 && $c <= 0x7e ? " '" . chr($c) . "'" : '') . ' ' . number_format((int)$v / $span, 2) . '/s';
  }
  return synohw_h(implode(', ', $parts)) . ' since ' . synohw_h(synohw_clock($since)) . ', last byte ' . synohw_h(synohw_age($h['now'], $at))
    . ' &middot; firmware version: ' . (synohw_mcu_only_k3($h['p']) ? 'no reply to O (only K and 3 ever seen)' : '<span class="orange-text">other bytes seen, see the MCU byte table</span>');
}

function synohw_render_hw_cpu(array $h): string {
  $c = $h['cpu']; $up = $h['sys']['uptime']; $rows = []; $tl = [];
  foreach ($h['temps']['list'] as $t) $tl[] = $t;
  foreach ($c['cores'] as $i => $k) {
    $rows[] = ['cpu' . synohw_h($i), synohw_ghz($k['cur']), synohw_ghz($k['min']) . ' &ndash; ' . synohw_ghz($k['max']), synohw_h($k['gov'] ?: '-'), synohw_h($k['drv'] ?: '-'),
               isset($tl[$i]) ? synohw_h($tl[$i]['label']) . ': ' . synohw_h($tl[$i]['temp']) . '&nbsp;&deg;C' : '-'];
  }
  $o = synohw_tbl('synohw-hw-cores', ['CPU', 'Frequency now', 'Range', 'Governor', 'Driver', 'Temperature (coretemp; order as listed by hwmon)'], $rows);
  // C-states: share of all CPU time since boot (#4)
  $agg = []; $n = count($c['cores']);
  foreach ($c['cores'] as $k) foreach ($k['idle'] as $s) {
    $a = &$agg[$s['name']]; $a = $a ?? ['usage' => 0, 'time' => 0, 'latency' => $s['latency']];
    $a['usage'] += (int)$s['usage']; $a['time'] += (int)$s['time']; unset($a);
  }
  if ($agg && $up) {
    $rows = []; $tot = $up * 1e6 * max(1, $n);
    foreach ($agg as $name => $a) $rows[] = [synohw_h($name ?: '?'), synohw_h(number_format(100 * $a['time'] / $tot, 1)) . '&nbsp;%', synohw_n($a['usage']), synohw_h($a['latency'] ?? '-') . '&nbsp;&micro;s'];
    $o .= synohw_tbl('synohw-hw-cstates', ['Idle state (' . synohw_h($c['idle_driver'] ?: 'cpuidle') . ')', 'Share of CPU time since boot (all ' . synohw_h($n) . ' CPUs)', 'Entries', 'Exit latency'], $rows);
  }
  if ($c['tis']) {
    $sum = array_sum($c['tis']) ?: 1; $rows = [];
    krsort($c['tis']);
    foreach ($c['tis'] as $f => $t) $rows[] = [synohw_ghz($f), synohw_h(number_format(100 * $t / $sum, 1)) . '&nbsp;%', synohw_h(synohw_dur(intdiv($t, 100)))];
    $o .= '<details class="synohw-more"><summary>Time at each frequency, cpu0 (cpufreq stats; ' . synohw_n($c['trans']) . ' transitions since boot)</summary>'
        . synohw_tbl('synohw-hw-tis', ['Frequency', 'Share of time', 'Time'], $rows) . '</details>';
  }
  $rows = [];
  foreach ($c['thermal'] as $z) {
    $trips = [];
    foreach ($z['trips'] as $t) $trips[] = synohw_h($t['type']) . ' ' . ($t['temp'] !== null ? synohw_h(intdiv($t['temp'], 1000)) . '&nbsp;&deg;C' : '?');
    $rows[] = [synohw_h($z['zone'] . ' (' . $z['type'] . ')'), $z['temp'] !== null ? synohw_h(number_format($z['temp'] / 1000, 1)) . '&nbsp;&deg;C' : '-', implode(', ', $trips) ?: '-', synohw_h(trim($z['policy'] . ', ' . $z['mode'], ', '))];
  }
  if ($rows) $o .= synohw_tbl('synohw-hw-thermal', ['ACPI thermal zone', 'Now', 'Trip points (BIOS)', 'Policy, mode'], $rows)
    . '<p class="grey-text whitespace-normal">The ACPI trip points belong to the BIOS\'s own thermal zone (acpitz), not to the plugin\'s fan table; "active" would switch the ACPI Fan object, which is not the MCU fan (#8, #9).</p>';
  return $o;
}

function synohw_render_hw_pci(array $h): string {
  $rows = []; $aerTot = 0; $down = 0;
  foreach ($h['pci'] as $d) {
    $a = $d['aer'];
    if ($a) $aerTot += (int)$a['correctable'] + (int)$a['nonfatal'] + (int)$a['fatal'];
    if ($d['down']) $down++;
    $rows[] = [synohw_h($d['addr']), synohw_h($d['name'] ?: '(not in the measured list)') . ' <span class="grey-text">[' . synohw_h($d['id']) . ']</span>', synohw_h($d['driver'] ?: '-'),
               $d['cur'] === '' ? '<span class="grey-text">no PCIe link (on-chip)</span>' : ($d['down'] ? '<span class="orange-text">' . synohw_h($d['cur']) . ' (below its maximum)</span>' : synohw_h($d['cur']) . ($d['narrow'] ? ' <span class="grey-text">(port; set by the device behind it)</span>' : '')),
               synohw_h($d['max'] ?: '-'),
               $a ? synohw_bad($a['correctable'], 'orange-text') . ' / ' . synohw_bad($a['nonfatal']) . ' / ' . synohw_bad($a['fatal']) : '<span class="grey-text">no AER</span>'];
  }
  return synohw_tbl('synohw-hw-pci', ['PCI address', 'Device', 'Driver', 'Link now', 'Link max', 'AER errors: correctable / non-fatal / fatal'], $rows)
    . '<p class="grey-text whitespace-normal">' . synohw_h(count($h['pci'])) . ' devices; ' . ($down ? '<span class="orange-text">' . synohw_h($down) . ' link(s) below their maximum</span>' : 'no link below its maximum') . '; '
    . ($aerTot ? '<span class="red-text">' . synohw_h($aerTot) . ' AER errors in total</span>' : 'AER error counters all 0') . '. From /sys/bus/pci/devices (current_/max_link_speed and _width, aer_dev_*; #18, #19). '
    . 'A device whose link runs slower or narrower than it can is orange (downtrained: a slot, riser or signal problem). A port (bridge) that can do x4 but feeds an x1 or x2 device runs at the device\'s width; that is the board\'s wiring, not a fault (measured 2026-10-25: every device link equals its maximum).</p>';
}

function synohw_render_hw_ata(array $h): string {
  $rows = [];
  foreach ($h['ata'] as $l) {
    $d = $l['disk']; $sm = $d ? ($h['smart']['disks'][$d['device']] ?? null) : null;
    $cap = $sm['info']['SATA Version is'] ?? '';
    $slow = $l['spd'] !== '' && (float)$l['spd'] < 6.0;
    $rows[] = [synohw_h($l['link']) . ($l['pmp'] !== null ? ' <span class="grey-text">(port multiplier port ' . synohw_h($l['pmp']) . ')</span>' : ''),
               synohw_badge(['DX1215' => 'dx', 'DS2415+ internal' => 'ds'][$l['box']] ?? 'unknown', ['model' => $h['model']]) . ' <span class="grey-text">' . synohw_h(trim($l['ctrl'] . ' ' . ($l['ctrl_id'] ? '[' . $l['ctrl_id'] . ']' : ''))) . '</span>',
               $l['spd'] === '' ? '<span class="grey-text">no link</span>' : ($slow ? '<span class="orange-text">' . synohw_h($l['spd']) . '</span>' : synohw_h($l['spd'])),
               synohw_h($l['limit'] ?: ($l['hwlimit'] ?: 'none')),
               $d ? synohw_h($d['name'] . ' (' . $d['device'] . ')') . ($d['id'] !== '-' ? ' <span class="grey-text">' . synohw_h($d['id']) . '</span>' : '')
                  : ($l['pmp'] === null && $l['box'] === 'DX1215' ? '<span class="grey-text">the controller\'s link to the port multiplier (the disks are on its linkN.M ports)</span>' : '<span class="grey-text">no disk in disks.ini / devs.ini on this link</span>'),
               $cap !== '' ? synohw_h($cap) : '<span class="grey-text">from SMART, when awake</span>'];
  }
  if (!$rows) return '<p class="grey-text">No SATA link with a device (/sys/class/ata_link).</p>';
  return synohw_tbl('synohw-hw-ata', ['SATA link', 'Box, controller', 'Negotiated', 'Speed limit', 'Disk', 'Disk supports'], $rows)
    . '<p class="grey-text whitespace-normal">Only links with a device are listed (/sys/class/ata_link/link*/sata_spd; #22). linkN.M = port M behind the port multiplier on ataN (the DX1215, #21). Below 6.0 Gb/s is orange: a cable, a backplane or the disk itself may be limiting it.</p>';
}

function synohw_render_hw_smart(array $h): string {
  $sm = $h['smart']; $now = $h['now']; $rows = [];
  $attr = fn($s, $id) => $s['attr'][$id] ?? null;
  foreach ($h['disks'] as $d) {
    $s = $sm['disks'][$d['device']] ?? null;
    $box = synohw_disk_box($h['p'], $d['device']);
    $who = synohw_h($d['name']) . ' <span class="grey-text">' . synohw_h($d['device']) . '</span> ' . synohw_badge($box['box'], ['model' => $h['model']]);
    if ($d['standby']) { $rows[] = [$who, '<span class="grey-text">standby: not read (never woken)</span>', '', '', '', '', '', '', '']; continue; }
    if (!$s) { $rows[] = [$who, '<span class="grey-text">not read yet (the cache is refreshed every ' . synohw_h($h['p']['inv_period'] ?? 600) . ' s while the daemon runs)</span>', '', '', '', '', '', '', '']; continue; }
    if ($s['standby']) { $rows[] = [$who, '<span class="grey-text">went to standby before the read (smartctl -n standby skipped it), ' . synohw_h(synohw_age($now, $s['at'])) . '</span>', '', '', '', '', '', '', '']; continue; }
    $poh = $attr($s, 9); $lcc = $attr($s, 193);
    $model = trim(($s['info']['Device Model'] ?? '') . ' ' . (isset($s['info']['Serial Number']) ? '(' . $s['info']['Serial Number'] . ')' : ''));
    $health = $s['health'] === 'PASSED' ? synohw_orb('green') . 'PASSED' : ($s['health'] !== '' ? synohw_orb('red') . '<span class="red-text">' . synohw_h($s['health']) . '</span>' : '<span class="orange-text">no health line (rc ' . synohw_h($s['rc']) . ')</span>');
    $phy = $s['phy'];
    $pv = fn($id) => isset($phy[$id]) ? $phy[$id]['value'] : null;
    $rerr = null; foreach ([2, 3, 4, 5, 6, 7] as $id) if (isset($phy[$id])) $rerr = (int)$rerr + $phy[$id]['value'];
    $rows[] = [$who . '<br><span class="grey-text">' . synohw_h($model) . '</span>',
               $health . ' &middot; error log: ' . ($s['errlog'] === 'none' ? 'no errors' : ($s['errlog'] !== '' ? '<span class="red-text">' . synohw_h($s['errlog']) . ' errors</span>' : '-')) . '<br><span class="grey-text">read ' . synohw_h(synohw_age($now, $s['at'])) . '</span>',
               $poh ? synohw_n($poh['rawn']) . '&nbsp;h <span class="grey-text">(' . synohw_h(number_format(($poh['rawn'] ?? 0) / 8766, 1)) . '&nbsp;y)</span>' : '-',
               synohw_bad($attr($s, 5)['rawn'] ?? null) . ' / ' . synohw_bad($attr($s, 197)['rawn'] ?? null) . ' / ' . synohw_bad($attr($s, 198)['rawn'] ?? null),
               synohw_bad($attr($s, 199)['rawn'] ?? null, 'orange-text'),
               synohw_bad($attr($s, 187)['rawn'] ?? null),
               $lcc ? synohw_n($lcc['rawn']) . '<br><span class="' . ($lcc['value'] <= 25 ? 'orange-text' : 'grey-text') . '">normalized ' . synohw_h($lcc['value']) . ' (threshold ' . synohw_h($lcc['thresh']) . ')</span>' : '-',
               synohw_bad($pv(1), 'orange-text') . ' / ' . synohw_bad($rerr, 'orange-text'),
               synohw_n($pv(9)) . ' / ' . synohw_n($pv(10))];
  }
  if (!$rows) return '<p class="grey-text">No real disk found.</p>';
  $at = (int)($sm['at']['at'] ?? 0);
  return synohw_tbl('synohw-hw-smart', ['Disk', 'Health', 'Power-on', 'Reallocated / pending / offline uncorrectable', 'UDMA CRC errors', 'Reported uncorrectable', 'Load cycles (head parking)', 'SATA phy: ICRC / R_ERR', 'SATA phy: PhyRdy&rarr;NRdy / COMRESET'], $rows)
    . '<p class="grey-text whitespace-normal">Cache: ' . ($at ? 'last refresh ' . synohw_h(synohw_age($now, $at)) . ' (' . synohw_h($sm['at']['smart'] ?? '?') . ' disk(s) read, ' . synohw_h($sm['at']['skipped_standby'] ?? '?') . ' in standby skipped)' : '<span class="orange-text">not written yet</span> (scripts/synohw-inventory runs when the daemon starts and every INVENTORY_PERIOD, or now with <code>rc.synology-hardware inventory</code>)')
    . '. Only disks that Unraid reports awake are read, with <code>smartctl -n standby</code>, which skips a disk that went to sleep in between: this page never wakes a disk (#24). Load cycles: the normalized value falls as the heads park; at 25 or below it is orange (the vendor threshold is the "worn out" point). PhyRdy&rarr;NRdy and COMRESET count link resets since the disk\'s power-on (#25); ICRC and R_ERR are transfer errors (a cable or backplane problem when they rise).</p>';
}

function synohw_render_hw_net(array $h): string {
  $rows = []; $mcuWol = $h['cfg']['WOL'] ?? 'keep';
  foreach ($h['net'] as $n) {
    $st = $n['stats']; $err = 0; foreach ($st as $v) $err += (int)$v;
    $up = $n['carrier'] === '1';
    $e = $n['eth'];
    $rows[] = [synohw_h($n['nic']) . ' <span class="grey-text">' . synohw_h($n['pci']) . '</span>',
               $up ? synohw_orb('green') . synohw_h($n['speed'] !== null && $n['speed'] > 0 ? $n['speed'] . ' Mb/s' : '?') . ', ' . synohw_h($n['duplex'] ?: '?') . ' duplex' : synohw_orb('grey') . 'no link',
               synohw_h($n['driver'] ?: '-'), synohw_n($n['changes']),
               $err ? '<span class="orange-text">' . synohw_h(implode(', ', array_map(fn($k, $v) => "$k $v", array_keys(array_filter($st)), array_filter($st)))) . '</span>' : 'all 0',
               $e['at'] ? synohw_h($e['wol'] ?: '?') . ' <span class="grey-text">(supports ' . synohw_h($e['wol_sup'] ?: '?') . ')</span>' : '<span class="grey-text">not read yet</span>'];
  }
  if (!$rows) return '<p class="grey-text">No physical network port found.</p>';
  return synohw_tbl('synohw-hw-net', ['Port', 'Link', 'Driver', 'Link changes', 'Error counters (rx/tx errors, CRC, missed, FIFO, length, collisions, drops)', 'Wake-on-LAN in the NIC'], $rows)
    . '<p class="grey-text whitespace-normal">Link and counters from /sys/class/net/&lt;port&gt; (#28, #29); Wake-on from <code>ethtool</code>, cached with the SMART data (#30): g = magic packet. The MCU has its own wake-on-LAN switch, the WOL setting (now ' . synohw_h($mcuWol) . '); whether both are needed is not measured.</p>';
}

function synohw_render_hw_usb(array $h): string {
  $rows = [];
  foreach ($h['usb'] as $u) {
    $rows[] = [synohw_h($u['dev']), synohw_h(trim($u['mfr'] . ' ' . $u['product'])) . ' <span class="grey-text">[' . synohw_h($u['id']) . ']</span>', synohw_h($u['role'] ?: '-'),
               synohw_h($u['speed'] ?: '?') . '&nbsp;Mb/s', synohw_h($u['version'] ?: '-'), synohw_h($u['power'] ?: '-'), synohw_h($u['serial'] ?: '-')];
  }
  if (!$rows) return '<p class="grey-text">No USB device found.</p>';
  return synohw_tbl('synohw-hw-usb', ['USB device', 'Product', 'Role', 'Speed', 'USB version (descriptor)', 'Max power', 'Serial'], $rows)
    . '<p class="grey-text whitespace-normal">From /sys/bus/usb/devices (#26, #27). 480 Mb/s = USB 2.0, 5000 Mb/s = USB 3.0. The Etron xHCI has a USB 2.0 bus and a USB 3.0 bus (two root hubs with the same PCI address); a device on the 2.0 bus runs at 480 Mb/s even when it could do more.</p>';
}

function synohw_render_hw_absent(array $h): string {
  $rows = [];
  foreach ($h['absent'] as [$what, $ok, $check, $why]) {
    $rows[] = [synohw_h($what), $ok ? synohw_h($check) : '<span class="orange-text">found now: the check &quot;' . synohw_h($check) . '&quot; no longer holds (the research of 2026-10-25 said it did; worth a look)</span>', synohw_h($why)];
  }
  return synohw_tbl('synohw-hw-absent', ['Not available', 'Checked now', 'Why, and the source'], $rows);
}

function synohw_render_hw(array $h): string {
  $sec = fn($t, $body) => '<h3 class="synohw-hw-title">' . $t . '</h3>' . $body;
  return $sec('System', synohw_render_hw_system($h)) . $sec('CPU: frequency, idle states, ACPI thermal zone', synohw_render_hw_cpu($h))
    . $sec('PCIe devices: link now vs maximum, AER error counters', synohw_render_hw_pci($h))
    . $sec('SATA links (main unit vs expansion unit)', synohw_render_hw_ata($h))
    . $sec('Disks: SMART summary (awake disks only)', synohw_render_hw_smart($h))
    . $sec('Network ports', synohw_render_hw_net($h)) . $sec('USB', synohw_render_hw_usb($h))
    . $sec('Not available on this hardware', synohw_render_hw_absent($h));
}
