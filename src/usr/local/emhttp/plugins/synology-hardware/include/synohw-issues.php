<?php
/* synohw-issues.php - Known issues and firmware panel (Hardware tab), 2026.10.26b. Written: 2026-10-26.
 * READ-ONLY, runs no command: sysfs/procfs files plus the daemon's inventory cache ($run/inventory/firmware.txt and smart-<dev>.txt,
 * written by scripts/synohw-inventory; smartctl there runs with -n standby and only for disks Unraid reports awake).
 * Data shipped in the plugin: data/known-hw-issues.json and data/firmware-versions.json (copies of the project's
 * research/2026-10-26-known-hw-issues.json and research/2026-10-26-firmware-versions.json; the .md files beside them hold the quotes).
 * Rules (research/2026-10-26-known-hw-issues.md, research/2026-10-25-avr54-ds2415.md):
 *   AVR54: host bridge 00:00.0 = 8086:1f0c (Atom C2000); PCI revision 0x02 = B0 = affected silicon, 0x03 = C0 = fixed silicon;
 *          no C2000 = not affected. The board fix (a resistor) cannot be seen by software: AVR54_FIXED=yes (owner confirms) shrinks
 *          the warning to "resistor fitted (owner confirmed)".
 *   AVR49: info (USB device not detected at power-on); Intel's workaround is a January 2018 BIOS change, Synology's last DS2415+
 *          BIOS M.211 is dated 2017/05/09. Heuristic: USB enumeration errors in dmesg (cache).
 *   AVR35: info (GbE dead after a start from mechanical off with WOL disabled); igb port count against the model's.
 *   RTC battery: /proc/driver/rtc batt_status ("dead" is meaningful, "okay" proves little: rtc-cmos.c comment).
 *   Firmware: BIOS (BVDT) and microcode against the newest known; MCU, Marvell AHCI and I354 NVM not checkable (reason shown);
 *          DX1215 port multiplier revision display only; disk firmware = smartctl -i "==> WARNING:" lines from the cache. */
require_once __DIR__ . '/synohw-status.php';
require_once __DIR__ . '/synohw-hw.php';

const SYNOHW_AVR54_GUIDE = 'https://tautomer.github.io/posts/fix_synology_ds2415+/';
const SYNOHW_C2000_SPEC = 'https://www.intel.com/content/dam/www/public/us/en/documents/specification-updates/atom-c2000-family-spec-update.pdf';

function synohw_issue_data(): array {
  static $d = null;
  if ($d !== null) return $d;
  $f = fn($n) => json_decode((string)@file_get_contents(__DIR__ . "/../data/$n"), true) ?: [];
  return $d = ['issues' => $f('known-hw-issues.json'), 'fw' => $f('firmware-versions.json')];
}

/* CPU signature as Intel writes it (406D8 = family 6, model 0x4D, stepping 8), from /proc/cpuinfo */
function synohw_cpu_sig(string $cpuinfo): array {
  $g = fn($k) => preg_match('/^' . $k . '\s*:\s*(\d+)\s*$/m', $cpuinfo, $m) ? (int)$m[1] : null;
  $fam = $g('cpu family'); $mod = $g('model'); $st = $g('stepping');
  $name = preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m) ? trim(preg_replace('/\s+/', ' ', $m[1])) : '';
  if ($fam === null || $mod === null || $st === null) return ['sig' => '', 'name' => $name, 'c2000' => false];
  $bf = min($fam, 15); $ef = $fam > 15 ? $fam - 15 : 0;
  $sig = sprintf('%X', ($ef << 20) | (($mod >> 4) << 16) | ($bf << 8) | (($mod & 15) << 4) | $st);
  return ['sig' => $sig, 'name' => $name, 'c2000' => $fam === 6 && $mod === 0x4d];
}

/* firmware/disk facts per disk from the inventory cache: model, firmware, drivedb warning lines; null = not in the cache */
function synohw_disk_fw(string $inv, string $dev): ?array {
  $f = "$inv/smart-$dev.txt";
  if (!preg_match('/^[a-z0-9]{1,16}$/', $dev) || !is_file($f)) return null;
  $t = (string)@file_get_contents($f, false, null, 0, 262144);
  $rc = preg_match('/^# synohw .* at=(\d+) rc=(\d+)/m', $t, $m) ? [(int)$m[1], (int)$m[2]] : [0, -1];
  $model = preg_match('/^(?:Device Model|Model Number|Product):\s*(.+)$/m', $t, $m) ? trim($m[1]) : '';
  $fw = preg_match('/^(?:Firmware Version|Revision):\s*(.+)$/m', $t, $m) ? trim($m[1]) : '';
  $warn = [];
  if (preg_match_all('/^==> WARNING:(.*(?:\n(?!\n)(?!=== )[^\n]*)*)/m', $t, $mm)) foreach ($mm[1] as $w) $warn[] = trim(preg_replace('/\s+/', ' ', $w));
  $standby = $rc[1] === 2 && $model === '';   // smartctl -n standby returns 2 without touching a sleeping disk
  return ['at' => $rc[0], 'rc' => $rc[1], 'model' => $model, 'fw' => $fw, 'warn' => $warn, 'standby' => $standby];
}

function synohw_issues_collect(array $p, array $cfg = []): array {
  $h = synohw_hw_paths($p) + ['rtc' => '/proc/driver/rtc'];
  $D = synohw_issue_data();
  $hb = "{$h['pcidev']}/0000:00:00.0";
  $vid = strtolower(synohw_read("$hb/vendor")) . ':' . strtolower(synohw_read("$hb/device"));
  $rev = strtolower(synohw_read("$hb/revision"));
  $cpu = synohw_cpu_sig(synohw_read($h['cpuinfo']));
  $c2000 = $vid === '0x8086:0x1f0c' || $cpu['c2000'];
  $inv = "{$p['run']}/inventory";
  $fwc = synohw_kv("$inv/firmware.txt") ?? [];
  $usbErr = [];
  foreach (synohw_lines("$inv/firmware.txt") as $l) if (strncmp($l, 'usb_err=', 8) === 0) $usbErr[] = substr($l, 8);
  $rtc = preg_match('/^batt_status\s*:\s*(\S+)/m', synohw_read($h['rtc']), $m) ? $m[1] : '';
  $mc = synohw_read("{$h['cpusys']}/cpu0/microcode/version");
  $main = synohw_main_model($p);
  $model = $main['model'];
  return ['vid' => $vid, 'rev' => $rev, 'c2000' => $c2000, 'cpu' => $cpu, 'fw' => $fwc, 'usb_err' => $usbErr, 'rtc' => $rtc,
          'microcode' => $mc, 'model' => $model, 'fixed' => ($cfg['AVR54_FIXED'] ?? 'no') === 'yes', 'wol' => $cfg['WOL'] ?? 'keep',
          'data' => $D, 'inv' => $inv, 'disks' => synohw_disks($p)['list'], 'pcidev' => $h['pcidev'], 'dxsys' => $p['dxsys']];
}

/* rows: [item, level (warn|info|ok|na), state html, detail html]; every value escaped */
function synohw_issue_rows(array $x): array {
  $e = 'synohw_h'; $r = [];
  $link = fn($u, $t) => '<a href="' . $e($u) . '" target="_blank" rel="noopener noreferrer">' . $e($t) . '</a>';
  $spec = $link(SYNOHW_C2000_SPEC, 'Intel Atom C2000 Specification Update 329460');
  $hbTxt = $x['vid'] !== ':' ? 'host bridge 00:00.0 ' . substr($x['vid'], 2, 4) . ':' . substr($x['vid'], 9) . ' rev ' . ($x['rev'] !== '' ? substr($x['rev'], 2) : '?') : 'host bridge 00:00.0 not readable';
  // AVR54
  if (!$x['c2000']) $r[] = ['AVR54: Atom C2000 LPC clock (unit may stop booting for good)', 'na', 'not affected', $e("No Atom C2000 in this unit ($hbTxt).")];
  elseif ($x['rev'] === '0x03') $r[] = ['AVR54: Atom C2000 LPC clock (unit may stop booting for good)', 'ok', 'fixed silicon (C0)', $e("$hbTxt: revision 03 = C0, the stepping Intel fixed. ") . "Source: $spec, Table 8 (PCI Rev ID 2 = B0, 3 = C0)."];
  elseif ($x['rev'] === '0x02') {
    $where = $x['model'] === 'DS2415+' ? 'Instructions for the DS2415+: ' . $link(SYNOHW_AVR54_GUIDE, 'Fixing the Synology DS2415+ (resistor between header pins 1 and 6)') . '.'
      : $e('The resistor header location differs per board; the published DS2415+ instructions (' . SYNOHW_AVR54_GUIDE . ') show the idea, not your board\'s pins.');
    $r[] = $x['fixed']
      ? ['AVR54: Atom C2000 LPC clock (unit may stop booting for good)', 'ok', 'resistor fitted (owner confirmed)', $e("$hbTxt = B0 silicon (affected). The board fix cannot be seen by software; the owner confirmed it on this page (AVR54_FIXED=yes). ") . "Source: $spec."]
      : ['AVR54: Atom C2000 LPC clock (unit may stop booting for good)', 'warn', '<span class="red-text">affected silicon (B0)</span>',
         $e("$hbTxt = B0, the stepping with erratum AVR54: the LPC clock can fail and the unit then never boots again. Software cannot see whether the board fix (a resistor) is fitted. Keep backups and avoid needless power cycles. ") . $where . " Formal source: $spec (AVR54; Table 8 PCI Rev ID 2 = B0). If the resistor is fitted, say so with the setting below."];
  } else $r[] = ['AVR54: Atom C2000 LPC clock (unit may stop booting for good)', 'info', 'stepping unknown', $e("Atom C2000, $hbTxt: not B0 (02) or C0 (03) as Intel lists them.")];
  if ($x['c2000']) {
    // AVR49
    $bios = (string)($x['fw']['bios'] ?? '');
    $n = (int)($x['fw']['usb_enum_errors'] ?? 0);
    $r[] = ['AVR49: USB device may not be detected at power-on', $n > 0 ? 'warn' : 'info', $n > 0 ? '<span class="orange-text">' . $e("$n USB enumeration error(s) this boot") . '</span>' : 'information',
      $e('Unraid boots from USB, so a "boot device not found" at a cold start can be this erratum; a power cycle clears it. Intel\'s workaround is a January 2018 BIOS change; ' . ($bios !== '' ? "this unit runs BIOS $bios" : 'the BIOS version is not read yet') . ', and the newest DS2415+ BIOS (M.211) is dated 2017/05/09. ')
      . ($x['usb_err'] ? $e('dmesg: ' . implode(' | ', $x['usb_err'])) . ' ' : $e(isset($x['fw']['usb_enum_errors']) ? 'No USB enumeration error in dmesg this boot. ' : 'dmesg not checked yet (daemon inventory). ')) . "Source: $spec (AVR49)."];
    // AVR35
    $ig = $x['fw']['igb_ports'] ?? null; $want = $x['model'] === 'DS2415+' ? 4 : null;
    $r[] = ['AVR35: network ports dead after a start from mechanical off', $ig !== null && $want !== null && (int)$ig < $want ? 'warn' : 'info',
      $ig === null ? 'information' : $e("$ig igb port(s)" . ($want !== null ? " of $want" : '')),
      $e('Only after the unit is started from mechanical off (power cord) with Wake-on-LAN disabled: the Gigabit ports may not work; a restart from soft-off clears it. MCU Wake-on-LAN setting here: ' . $x['wol'] . ' (keep = as DSM left it). ') . "Source: $spec (AVR35)."];
  }
  // RTC battery
  $rt = $x['rtc'];
  $r[] = ['RTC / CMOS battery (CR1220)', $rt === 'dead' ? 'warn' : ($rt === '' ? 'na' : 'ok'), $rt === '' ? 'not checkable' : ($rt === 'dead' ? '<span class="red-text">dead</span>' : $e($rt)),
    $rt === '' ? $e('/proc/driver/rtc gives no batt_status.') : $e(($rt === 'dead' ? 'The RTC reports its battery dead: replace the CR1220 (the board must come out); several DS415+/DS1515+/DS1815+ owners report a unit that no longer powers on. '
      : '"okay" proves little: the kernel notes that Intel chipsets may report the battery with a different bit; "dead" is the meaningful value. ')
      . (isset($x['fw']['rtc_clock']) ? 'Boot: ' . $x['fw']['rtc_clock'] . '.' : '')) . ' Sources: research/2026-10-26-known-hw-issues.md (cr1220).'];
  return $r;
}

function synohw_fw_rows(array $x): array {
  $e = 'synohw_h'; $r = []; $F = $x['data']['fw']['components'] ?? [];
  // BIOS
  $bv = $x['fw']['bvdt'] ?? ''; $bios = (string)($x['fw']['bios'] ?? '');
  $latest = $F['bios']['latest'][$x['model']] ?? null;
  if ($bv === 'ok' && $bios !== '') {
    $cur = $e($bios) . (($x['fw']['bios_board'] ?? '') !== '' ? ' <span class="grey-text">(' . $e($x['fw']['bios_board']) . ', ' . $e($x['fw']['bios_extra'] ?? '') . ')</span>' : '');
    $r[] = ['BIOS', $latest ? ($bios === $latest['version'] ? 'ok' : 'warn') : 'info', $cur,
      $latest ? $e($bios === $latest['version'] ? "Newest known for the {$x['model']}: {$latest['version']} ({$latest['date']}, from Synology's last DSM)." : "Newest known for the {$x['model']}: {$latest['version']} ({$latest['date']}); this unit runs $bios.")
              : $e('No reference BIOS known for this model.') ];
  } else $r[] = ['BIOS', 'na', 'not read yet', $e($bv === 'unreadable' ? 'ACPI table UEFI1 is not readable.' : ($bv === 'absent' ? 'No ACPI table UEFI1 (no Insyde BVDT block).' : ($bv === 'nomark' ? 'UEFI1 has no $BVDT$ block.' : 'The daemon\'s inventory reads the Insyde $BVDT$ block of ACPI table UEFI1 (root only) every 10 min; no copy yet.')))];
  // microcode
  $sig = $x['cpu']['sig']; $ml = $F['microcode']['latest'][$sig] ?? null; $mc = $x['microcode'];
  if ($mc === '') $r[] = ['CPU microcode', 'na', 'not checkable', $e('/sys/devices/system/cpu/cpu0/microcode/version not readable.')];
  else $r[] = ['CPU microcode', $ml ? (hexdec($mc) >= hexdec($ml['revision']) ? 'ok' : 'warn') : 'info', $e($mc) . ' <span class="grey-text">(CPUID ' . $e($sig ?: '?') . ')</span>',
    $ml ? $e(hexdec($mc) >= hexdec($ml['revision']) ? "Newest Intel revision for $sig: {$ml['revision']} ({$ml['release']}); loaded." : "Intel's newest for $sig is {$ml['revision']} ({$ml['release']}); this CPU runs $mc: update the OS microcode package.")
        : $e("No reference revision known for CPUID $sig.")];
  // not checkable rows
  $r[] = ['Front-panel MCU', 'na', 'not checkable', $e('The version query (O) gets no reply on Unraid, and DSM ships no MCU image.')];
  $mv = [];
  foreach (glob("{$x['pcidev']}/*") ?: [] as $d) {
    $id = strtolower(synohw_read("$d/vendor")) . ':' . strtolower(synohw_read("$d/device"));
    if (in_array($id, ['0x1b4b:0x9215', '0x1b4b:0x9235'], true)) $mv[] = basename($d) . ' ' . substr($id, 9) . ' rev ' . substr(strtolower(synohw_read("$d/revision")), 2);
  }
  $r[] = ['Marvell SATA controllers', 'na', 'not checkable', $e(($mv ? implode(', ', $mv) . '. ' : '') . 'ahci exposes no firmware version, and no firmware is published; only the PCI revision is shown.')];
  if ($x['c2000']) $r[] = ['Intel I354 network NVM', 'na', 'not checkable', $e('igb reports no firmware version for the I354 (all zeros); its NVM lives in the board flash.')];
  $pm = [];
  foreach (glob("{$x['dxsys']}/host*", GLOB_ONLYDIR) ?: [] as $hd) {
    $g = synohw_read("$hd/gscr_1"); $em = synohw_read("$hd/emid");
    if (preg_match('/^0x[0-9a-f]{1,8}$/i', $g)) $pm[] = 'EMID ' . $em . ': r' . ((hexdec($g) >> 8) & 0xff);
  }
  if ($pm) $r[] = ['DX1215 port multipliers (88SM9705)', 'info', $e(implode(', ', $pm)), $e('Display only: Synology updates expansion-unit firmware for some SAS models only; the DX1215 is SATA.')];
  // disks
  foreach ($x['disks'] as $d) {
    $f = synohw_disk_fw($x['inv'], $d['device']);
    $lab = 'Disk ' . $d['device'] . ' (' . $d['slot'] . ')';
    if ($d['standby'] && (!$f || $f['model'] === '')) { $r[] = [$lab, 'na', 'not checked', $e('In standby: never woken for this. Checked when Unraid reports it awake (smartctl -i -n standby, every 10 min).')]; continue; }
    if (!$f || $f['model'] === '') { $r[] = [$lab, 'na', 'not checked yet', $e('No smartctl -i copy in the inventory yet (the daemon refreshes it every 10 min for awake disks).')]; continue; }
    $r[] = [$lab, $f['warn'] ? 'warn' : 'ok', $e(trim($f['model'] . ' ' . $f['fw'])),
      $f['warn'] ? '<span class="orange-text">' . $e('smartmontools drive database: ' . implode(' | ', $f['warn'])) . '</span>' : $e('No firmware warning in the smartmontools drive database (smartctl -i).')];
  }
  return $r;
}

function synohw_render_issues(array $s): string {
  $x = synohw_issues_collect(synohw_paths(), $s['cfg'] ?? []);
  $orb = ['warn' => 'red', 'info' => 'yellow', 'ok' => 'green', 'na' => 'grey'];
  $word = ['warn' => 'warning', 'info' => 'information', 'ok' => 'ok', 'na' => 'not checkable'];
  $tbl = function (string $head, array $rows) use ($orb, $word) {
    $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-issues"><thead><tr><td>' . $head . '</td><td>State</td><td>Details and sources</td></tr></thead><tbody>';
    foreach ($rows as [$k, $lvl, $st, $det]) $o .= '<tr><td>' . synohw_h($k) . '</td><td>' . synohw_orb($orb[$lvl]) . '<span class="synohw-sr">' . $word[$lvl] . ': </span>' . $st . '</td><td class="whitespace-normal">' . $det . '</td></tr>';
    return $o . '</tbody></table></div>';
  };
  return '<h3 class="synohw-sub">Known issues</h3>' . $tbl('Issue', synohw_issue_rows($x))
    . '<h3 class="synohw-sub">Firmware</h3>' . $tbl('Component', synohw_fw_rows($x))
    . '<p class="grey-text whitespace-normal">Read-only: sysfs and procfs, plus the daemon\'s inventory cache (BIOS string, dmesg lines, smartctl -i of awake disks; every 10 min). No disk is woken. Data shipped with the plugin: data/known-hw-issues.json and data/firmware-versions.json (2026-10-26).</p>';
}
