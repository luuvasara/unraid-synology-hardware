<?php
/* synohw-view.php - the tabbed page's live blocks (2026.10.25j; diagram redrawn 2026.10.25k). Written: 2026-10-25. Updated: 2026-10-25. Required by synohw-status.php.
 * READ-ONLY like synohw-status.php: it only formats what synohw_collect() read, plus a few sysfs files (PCI ids for the
 * main-unit model, /sys/class/ata_port/ataN/port_no for the bay numbers, /sys/bus/usb/devices for the rear USB sockets).
 * No command, no write. Every value is escaped.
 *
 * Blocks: Overview (diagram, ov_ds, ov_dx, health), DS2415+ tab (ds_now, temps_ds, mcu_link), DX1215 tab (dx_driver, dx_fans,
 * temps_dx, dx_power). Colours: dynamix classes and the --synohw-* tokens in sheets/SynologyHWOverview.css only.
 *
 * Models (the user's rule of 2026-10-25: "the animations are only for these models, others get the info with only device
 * image"): SYNOHW_MODELS below. A unit is drawn (bays, fans, ports, animated) only when its model is in the registry;
 * any other unit gets a static generic picture with its name, and the same facts in the cards and tables.
 *   Main unit: this plugin reads no model string (the DOM vender file holds MACs and the serial only, scripts/synohw-macfix;
 *   the Hardware tab's "Model" row was a fixed text until 2026.10.25i). The DS2415+ is recognised by its measured PCI
 *   topology (experiments/2026-10-25-hw-inventory/lspci-link.txt): Atom C2000 (8086:1f0c) + exactly three Marvell 88SE9215
 *   (1b4b:9215) + one 88SE9235 (1b4b:9235). Another C2000 model with the same controllers (the RS2416+ is the likely one,
 *   research/2026-10-23-c2538-model-matrix.md:67, not measured) would be taken for a DS2415+; anything else is "not identified".
 *   Expansion unit: the synology_dx module's unit/model + unit/unique (GPIO page 0 of the master port multiplier,
 *   research/2026-10-25-dx1215-gpio-spec.md); only DX1215 (unique 0x13) is drawn.
 *
 * Bay numbers (2026.10.25k: Synology's own numbering is 2 columns x 6 rows, 1 top left, row by row, research/2026-10-25-ds2415-dx1215-panels.md;
 * which slot a disk is in: CONFIRMED at the box on 2026-10-26 by blink count for both units, experiments/2026-10-26-bay-map/RESULT.md:
 * the Seagate on 03:00.0 port 0 is in DS bay 1, the WD on the EMID 2 port multiplier port 1 in DX bay 8; 2026.10.26a):
 *   DS2415+: DSM's own disk index map from the measured kernel command line SataPortMap=424444 DiskIdxMap=1b1f0004080c
 *   (research/2026-10-24-stage1-universality.md:81, docs/DESIGN-upstream.md:222): controllers in PCI order get 4,2,4,4,4,4 ports
 *   and first disk index 0x1b, 0x1f, 0, 4, 8, 0x0c, so the three 88SE9215 (03:00.0, 05:00.0, 06:00.0) hold disks 1-4, 5-8,
 *   9-12; the C2000 AHCI ports (0x1b, 0x1f) are not bays; the 88SE9235 (0x0c = 13+) is the expansion port. Bay = first index +
 *   port_no (1-based port within the controller, libata /sys/class/ata_port/ataN/port_no) = 4 x controller index + port + 1.
 *   DX1215: Synology's "3 disks per port multiplier" for the DX1215 (synosata.h, research/2026-10-25-dx1215-fans-web.md:33):
 *   bay = 3 x (port_no - 1) + PMP port + 1 on the 88SE9235's four ports; port_no - 1 = the port multiplier's EMID on this unit
 *   (hostN/emid), i.e. bay = 3 x EMID + port + 1.
 * Bay LEDs (2026.10.26a): synohw_bay_leds() reads the driver's LED state (ds/<pci>/leds_enabled + fault_mask + ds/pch_led_enable,
 *   hostN/led_mask + fault) and the daemon's health record ($run/leds); drawn in each tray and listed by synohw_render_leds(). */

const SYNOHW_VIEW_BLOCKS = [
  'diagram' => 'synohw_render_diagram', 'ov_ds' => 'synohw_render_ov_ds', 'ov_dx' => 'synohw_render_ov_dx', 'health' => 'synohw_render_health',
  'ds_now' => 'synohw_render_ds_now', 'temps_ds' => 'synohw_render_temps_ds', 'mcu_link' => 'synohw_render_mcu_link',
  'dx_driver' => 'synohw_render_dx_driver', 'dx_fans' => 'synohw_render_dx_fans', 'temps_dx' => 'synohw_render_temps_dx', 'dx_power' => 'synohw_render_dx_power',
  'leds_ds' => 'synohw_render_leds_ds', 'leds_dx' => 'synohw_render_leds_dx',
  'baymap' => 'synohw_render_baymap', 'issues' => 'synohw_render_issues',   // 2026.10.26b
];

/* the drawn models (2026.10.25k: redrawn from Synology's own guides, research/2026-10-25-ds2415-dx1215-panels.md):
 * bays = 2 columns x 6 rows of horizontal trays, numbered row by row from the top left (DS2415+ QIG p.8, DX1215 UG p.14);
 * front strip STATUS, ALERT, power button, then four LED windows (DS2415+: LAN1-LAN4; DX1215: only the second, LINK);
 * rear = a two-fan module (2 x 120 mm) on the left, the power inlet under it, a port column on the right edge:
 * DS2415+ USB 3.0 x4 (2 x 2), expansion port, console + RESET, LAN 1-4 stacked top to bottom; DX1215 expansion port and the
 * DEFAULT/MANUAL switch (UG p.6-7). No Kensington lock is shown in either guide. */
const SYNOHW_MODELS = [
  'DS2415+' => ['role' => 'main', 'name' => 'DS2415+', 'role_text' => 'main unit', 'bays' => 12, 'cols' => 2, 'fans' => 2, 'lan' => 4, 'usb' => 4,
                'leds' => ['LAN1', 'LAN2', 'LAN3', 'LAN4']],
  'DX1215'  => ['role' => 'expansion', 'name' => 'DX1215', 'role_text' => 'expansion unit', 'unique' => '0x13', 'bays' => 12, 'cols' => 2, 'fans' => 2, 'lan' => 0, 'usb' => 0,
                'leds' => ['', 'LINK', '', '']],
];
const SYNOHW_DS_BAY_START = ['0000:03:00.0' => 0, '0000:05:00.0' => 4, '0000:06:00.0' => 8];   // DiskIdxMap 00 04 08 (see the header)

/* ---------------- models, boxes, bays ---------------- */

function synohw_main_model(array $p): array {
  $ids = [];
  foreach (glob(($p['pcidev'] ?? '/sys/bus/pci/devices') . '/*') ?: [] as $d) {
    $v = strtolower(synohw_read("$d/vendor")); $dv = strtolower(synohw_read("$d/device"));
    if (preg_match('/^0x[0-9a-f]{4}$/', $v) && preg_match('/^0x[0-9a-f]{4}$/', $dv)) $ids[] = substr($v, 2) . ':' . substr($dv, 2);
  }
  $c = array_count_values($ids);
  if (($c['8086:1f0c'] ?? 0) >= 1 && ($c['1b4b:9215'] ?? 0) === 3 && ($c['1b4b:9235'] ?? 0) === 1)
    return ['model' => 'DS2415+', 'drawn' => true, 'how' => 'recognised by its PCI controllers (Atom C2000 + three 88SE9215 + one 88SE9235, as measured on this unit); no model string is read'];
  return ['model' => '', 'drawn' => false, 'how' => $ids ? 'the PCI controllers do not match the DS2415+ (' . ($c['1b4b:9215'] ?? 0) . ' x 88SE9215, ' . ($c['1b4b:9235'] ?? 0) . ' x 88SE9235)' : 'no PCI device list readable'];
}

function synohw_models(array $p, array $dx): array {
  $main = synohw_main_model($p);
  $v = $dx['v']; $lvl = $v['fan_level'] ?? '';
  $master = in_array($lvl, ['full', 'middle', 'low', 'stop'], true);
  $model = preg_match('/^[A-Za-z0-9+ -]{1,24}$/', (string)($v['model'] ?? '')) ? (string)$v['model'] : '';
  $uniq = (string)($v['unique'] ?? '');
  $present = $master || (bool)$dx['disks'];
  $drawn = $master && isset(SYNOHW_MODELS[$model]) && (SYNOHW_MODELS[$model]['unique'] ?? '') === $uniq;
  return ['main' => $main, 'dx' => ['model' => $master ? $model : '', 'unique' => $master ? $uniq : '', 'present' => $present, 'master' => $master, 'drawn' => $drawn,
          'how' => $master ? 'unit/model and unit/unique from the synology_dx module' : ($dx['disks'] ? 'disks behind the expansion port, but the synology_dx module gives no unit (model unknown)' : 'no unit and no disk behind the expansion port')]];
}

/* the name a unit goes by on the page: its model when known, else its role */
function synohw_box_name(?array $s, string $box): string {
  $m = $s['model'] ?? null;
  if ($box === 'ds') return ($m && $m['main']['model'] !== '') ? $m['main']['model'] : 'Main unit';
  if ($box === 'dx') return ($m && $m['dx']['model'] !== '') ? $m['dx']['model'] : 'Expansion unit';
  return $box === 'usb' ? 'USB' : 'unit unknown';
}

/* device -> box (synohw_disk_box) + bay number (see the header; null = not in a bay or not known) */
function synohw_disk_boxes(array $p): array {
  $out = [];
  foreach (synohw_disks($p)['list'] as $d) {
    $b = synohw_disk_box($p, $d['device']);
    $b['bay'] = null; $b['bay_note'] = '';
    $pn = $b['ata'] !== null ? (int)synohw_read("{$p['ataport']}/ata{$b['ata']}/port_no") : 0;
    if ($b['box'] === 'ds') {
      if (!isset(SYNOHW_DS_BAY_START[$b['pci']])) $b['bay_note'] = 'not on a bay controller (' . ($b['pci'] ?: 'controller unknown') . ')';
      elseif ($pn < 1 || $pn > 4) $b['bay_note'] = 'port number unreadable';
      else $b['bay'] = SYNOHW_DS_BAY_START[$b['pci']] + $pn;
    } elseif ($b['box'] === 'dx') {
      $pm = max(0, (int)$b['pmp']);
      if ($pn < 1 || $pn > 4) $b['bay_note'] = 'port number unreadable';
      elseif ($pm > 2) $b['bay_note'] = 'port multiplier port ' . $pm . ' (beyond 3 per port multiplier)';
      else $b['bay'] = 3 * ($pn - 1) + $pm + 1;
    }
    $out[$d['device']] = $b;
  }
  return $out;
}

/* bay LEDs (2026.10.26a), read only: per box and bay ['green' => on|off|unknown, 'orange' => on|off|blocked|unknown], plus the
 * daemon's health verdict per device. blocked = the fault bit is set but PCH GPIO 49 (the DS2415+ orange enable) is not high. */
function synohw_bay_leds(array $p): array {
  $d = $p['dxsys'];
  $o = ['ds' => [], 'dx' => [], 'pch' => synohw_read("$d/ds/pch_led_enable"), 'pch_state' => '', 'health' => [], 'policy' => '', 'at' => null];
  if (preg_match('/^state (.+)$/m', synohw_read("$d/ds/pch"), $m)) $o['pch_state'] = trim($m[1]);
  foreach (SYNOHW_DS_BAY_START as $pci => $b0) {
    $c = "$d/ds/$pci";
    if (!is_dir($c)) continue;
    $en = synohw_read("$c/leds_enabled"); $fm = synohw_read("$c/fault_mask");
    $g = $en === '1' ? 'on' : ($en === '0' ? 'off' : 'unknown');
    for ($i = 0; $i < 4; $i++) {
      $bit = ctype_digit($fm) ? ((int)$fm >> $i) & 1 : null;
      $o['ds'][$b0 + $i + 1] = ['green' => $g, 'orange' => $g !== 'on' ? ($g === 'off' ? 'off' : 'unknown') : ($bit === null ? 'unknown' : ($bit ? ($o['pch'] === '1' ? 'on' : 'blocked') : 'off'))];
    }
  }
  foreach (glob("$d/host*", GLOB_ONLYDIR) ?: [] as $h) {
    $e = synohw_read("$h/emid");
    if (!preg_match('/^[0-3]$/', $e)) continue;
    $mk = synohw_read("$h/led_mask"); $f = synohw_read("$h/fault");
    $g = $mk === '0' ? 'on' : ($mk === '1' ? 'off' : 'unknown');
    for ($i = 0; $i < 3; $i++) {
      $bit = ctype_digit($f) ? ((int)$f >> $i) & 1 : null;
      $o['dx'][3 * (int)$e + $i + 1] = ['green' => $g, 'orange' => $g !== 'on' ? ($g === 'off' ? 'off' : 'unknown') : ($bit === null ? 'unknown' : ($bit ? 'on' : 'off'))];
    }
  }
  ksort($o['ds']); ksort($o['dx']);
  foreach (explode("\n", synohw_read("{$p['run']}/leds")) as $l) {
    if (preg_match('/^#at (\d+)$/', trim($l), $m)) { $o['at'] = (int)$m[1]; continue; }
    if (preg_match('/^#health (\S+)$/', trim($l), $m)) { $o['policy'] = $m[1]; continue; }
    $x = explode("\t", $l);
    if (count($x) >= 5 && preg_match('/^[a-z0-9]{1,16}$/', $x[2])) $o['health'][$x[2]] = ['fault' => $x[3] === '1', 'why' => trim($x[4])];
  }
  return $o;
}
/* one bay's LED in words + the tray LED class */
function synohw_bay_led(?array $s, string $box, int $bay): array {
  $L = $s['bayleds'][$box][$bay] ?? null;
  if (!$L) return ['synohw-led-off', 'LED state unknown (driver not loaded or LEDs not attached)'];
  if ($L['orange'] === 'on') return ['synohw-led-orange', 'LED orange (fault)'];
  if ($L['green'] === 'on') return ['synohw-led-green', 'LED green (presence/activity)' . ($L['orange'] === 'blocked' ? '; fault bit set, but orange cannot light (PCH GPIO 49 not high)' : '')];
  return ['synohw-led-off', $L['green'] === 'off' ? 'LED off (bay LEDs switched off)' : 'LED state unknown'];
}

/* ---------------- icons and badges ---------------- */

/* one icon set: 24 x 24, 2 px round strokes, currentColor. Defined once per page (synohw_page_head), used by name. */
function synohw_icon_sprite(): string {
  $i = [
    'ds' => '<rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M8 7h8M8 11h8M8 15h8"/><circle cx="16" cy="18.5" r=".6"/>',
    'dx' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M7 8v8M11 8v8M15 8v8"/><path d="M19 9.5v5"/>',
    'fan' => '<circle cx="12" cy="12" r="9.5"/><path d="M12 12c0-4 1-6.5 3.5-6.5S18 8 14.5 10.5M12 12c3.5 2 5 4.2 3.7 6.3s-4 1.1-4.5-3.2M12 12c-3.5 2-6.2 2-7.4-.2s1.2-4.2 4.6-2.7"/>',
    'disk' => '<rect x="3.5" y="2.5" width="17" height="19" rx="2"/><circle cx="12" cy="10.5" r="4"/><path d="M12 10.5h.01M7.5 18h3"/>',
    'net' => '<path d="M4 9.5h16v8H4z"/><path d="M8 17.5v-3M11 17.5v-3M14 17.5v-3M9 9.5V6.5h6v3"/>',
    'chip' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><path d="M9 2.5v3.5M15 2.5v3.5M9 18v3.5M15 18v3.5M2.5 9H6M2.5 15H6M18 9h3.5M18 15h3.5"/>',
    'cable' => '<path d="M4 7h4v4H4zM16 13h4v4h-4zM8 9c5 0 3 6 8 6"/>',
  ];
  $o = '<svg class="synohw-sprite" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute"><defs>';
  foreach ($i as $k => $v) $o .= '<symbol id="synohw-i-' . $k . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $v . '</symbol>';
  return $o . '</defs></svg>';
}
function synohw_icon(string $n, string $cls = ''): string {
  return '<svg class="synohw-icon' . ($cls !== '' ? " $cls" : '') . '" aria-hidden="true" focusable="false" width="16" height="16"><use href="#synohw-i-' . synohw_h($n) . '"/></svg>';
}
/* box badge: icon + the unit's name in text (never colour alone); $long adds the role */
function synohw_badge(string $box, ?array $s = null, bool $long = false): string {
  $k = in_array($box, ['ds', 'dx'], true) ? $box : 'unknown';
  $role = ['ds' => 'main unit', 'dx' => 'expansion unit', 'unknown' => ''][$k];
  $name = synohw_box_name($s, $box);
  return '<span class="synohw-badge synohw-badge-' . $k . '">' . ($k !== 'unknown' ? synohw_icon($k) : '') . synohw_h($name)
    . ($long && $role !== '' && strcasecmp($name, $role) !== 0 ? ' <span class="synohw-badge-role">' . $role . '</span>' : '') . '</span>';
}

/* ---------------- expansion driver verdict (cheap: no unit/summary read, no host summaries) ---------------- */

/* [orb colour, headline, reason]; the expansion plugin's own page (sdx_verdict) has the full checklist */
function synohw_dx_verdict(array $s): array {
  $x = $s['dx']; $v = $x['v'];
  if (!$x['loaded']) {
    if (!$x['plugin_installed']) return ['grey', 'Expansion driver not installed', 'Install the expansion plugin (synology-expansion) to see and control the expansion unit. Without it the unit keeps its fan level (low after power-on).'];
    return ['red', 'Driver not loaded', $x['loader'] !== '' ? 'The loader says: ' . $x['loader'] : 'The loader has not run since this boot; see grep ' . $x['plugin_name'] . ' /var/log/syslog'];
  }
  $lvl = $v['fan_level'] ?? '';
  if (!in_array($lvl, ['full', 'middle', 'low', 'stop'], true)) return ['yellow', 'Driver loaded, unit not identified', 'No single port multiplier answered as the unit master (EMID 0). Fan control is disabled.'];
  $a1 = ($v['fan1_fail'] ?? '') === '1'; $a2 = ($v['fan2_fail'] ?? '') === '1';
  if ($a1 || $a2) return ['red', 'Driver loaded: FAN ALARM', 'The unit reports ' . ($a1 && $a2 ? 'both fans' : ($a1 ? 'fan 1' : 'fan 2')) . ' as failed (stopped or blocked).'];
  $le = (string)($v['last_error'] ?? '');
  if ($le !== '' && $le !== '-' && $le !== '?') return ['yellow', 'Driver loaded, with an error', 'Last module error: ' . $le];
  return ['green', 'Driver loaded: ' . synohw_box_name($s, 'dx') . ' found and responding', 'Values from ' . ($x['src'] === 'daemon' ? "the daemon's copy" : 'sysfs') . '.'];
}
function synohw_dx_link(array $s): string {
  $x = $s['dx'];
  return $x['plugin_installed'] && $x['plugin_name'] === 'synology-expansion'
    ? '<a href="/Settings/SynologyExpansion">' . synohw_h('Synology Expansion Unit Driver page') . '</a> (its own checks and raw registers)' : '';
}

/* ---------------- shared facts ---------------- */

function synohw_box_disks(array $s, string $box): array {
  $l = [];
  foreach ($s['disks']['list'] as $d) {
    $b = $s['disk_box'][$d['device']] ?? ['box' => 'unknown', 'bay' => null, 'bay_note' => ''];
    if (($b['box'] === 'dx') === ($box === 'dx')) $l[] = $d + ['bay' => $b['bay'], 'bay_note' => $b['bay_note'], 'box' => $b['box']];
  }
  return $l;
}
function synohw_hottest(array $disks): ?array {
  $h = null; foreach ($disks as $d) if ($d['temp'] !== null && ($h === null || $d['temp'] > $h['temp'])) $h = $d;
  return $h;
}
function synohw_disk_summary(array $disks): string {
  $h = synohw_hottest($disks); $sb = count(array_filter($disks, fn($d) => $d['standby'])); $ur = count(array_filter($disks, fn($d) => $d['unreadable']));
  if (!$disks) return '<span class="grey-text">no disk</span>';
  $o = $h ? '<b>' . synohw_h($h['temp']) . '&nbsp;&deg;C</b> ' . synohw_h($h['name']) . ($h['name'] !== $h['device'] ? ' (' . synohw_h($h['device']) . ')' : '') : 'no reading';
  $o .= ' <span class="grey-text">&middot; ' . synohw_h(count($disks)) . ' disk(s)' . ($sb ? ', ' . synohw_h($sb) . ' in standby' : '') . '</span>';
  if ($ur) $o .= ' <span class="orange-text">&middot; ' . synohw_h($ur) . ' spinning without a reading</span>';
  return $o;
}
function synohw_ds_mode_text(array $st): string {
  return ($st['ds_mode'] ?? 'AUTO') === 'MANUAL' ? 'MANUAL ' . synohw_h($st['ds_duty'] ?? '?') . ' %'
    : 'AUTO, ' . synohw_h($st['mode'] ?? '?') . ' table' . ((int)($st['ds_floor'] ?? 20) < 20 ? ', floor ' . synohw_h($st['ds_floor']) . ' %' : '');
}
function synohw_ds_why(array $s): string {
  $st = $s['daemon']['state'] ?? [];
  if (($s['daemon']['sweep']['state'] ?? '') === 'running') return 'a fan sweep holds the fan (see Fan sweep on the DS2415+ tab)';
  return synohw_h(SYNOHW_DS_WHY[$st['chosen'] ?? ''] ?? 'starting');
}
function synohw_ds_controlled(array $s): bool { return $s['daemon']['running'] && !empty($s['daemon']['state']); }
function synohw_dx_controlled(array $s): bool { return synohw_ds_controlled($s) && (($s['daemon']['state']['dx_state'] ?? '') === 'ok'); }

/* ---------------- Overview ---------------- */

function synohw_render_ov_ds(array $s): string {
  $st = $s['daemon']['state'] ?? []; $r = [];
  if (synohw_ds_controlled($s)) {
    $r['Fan mode'] = synohw_ds_mode_text($st);
    $r['Fan output'] = '<b>' . synohw_h($st['sent'] ?? '?') . ' %</b> sent <span class="grey-text">(V' . synohw_h($st['sent'] ?? '?') . ' at ' . synohw_h(synohw_clock((int)($st['sent_at'] ?? 0))) . ')</span>'
      . ((string)($st['table_duty'] ?? '') !== '' && ($st['table_duty'] ?? '') !== ($st['sent'] ?? '') ? ' <span class="grey-text">table: ' . synohw_h($st['table_duty']) . ' %</span>' : '');
    $r['Why'] = synohw_ds_why($s);
  } else {
    $r['Fan'] = '<span class="orange-text">not controlled: the daemon is not running</span>' . ($s['daemon']['stopped'] ? ' <span class="grey-text">(stopped on purpose: the fan was left at 99 %)</span>' : '');
  }
  $cpu = $s['cpu'];
  $r['CPU'] = $cpu['max'] !== null ? '<b>' . synohw_h($cpu['max']) . '&nbsp;&deg;C</b> <span class="grey-text">hottest of ' . synohw_h(count($cpu['list'])) . ' sensor(s)</span>' : '<span class="orange-text">no reading</span>';
  $mine = synohw_box_disks($s, 'ds');
  $r['Hottest disk here'] = synohw_disk_summary($mine);
  $all = synohw_hottest($s['disks']['list']);
  if ($all && ($s['disk_box'][$all['device']]['box'] ?? '') === 'dx')
    $r['Fan table input'] = 'the hottest disk of both units: ' . synohw_h($all['name']) . ' ' . synohw_h($all['temp']) . '&nbsp;&deg;C ' . synohw_badge('dx', $s);
  $alert = ($st['alert'] ?? '0') === '1';
  $r['Front LEDs'] = !$st ? '<span class="grey-text">unknown (no state file)</span>' : ($alert ? synohw_orb('yellow') . '<span class="orange-text">status orange blinking, alert LED on (ALERT band)</span>' : synohw_orb('green') . 'status green steady, alert LED off');
  $d = $s['daemon'];
  $r['Health'] = ($d['running'] ? synohw_orb('green') . 'daemon' : synohw_orb('red') . 'daemon not running') . ' &nbsp; ' . ($d['tty'] ? synohw_orb('green') . 'ttyS1' : synohw_orb('red') . 'ttyS1 missing')
    . ($st ? ' &nbsp; ' . (((int)$s['now'] - (int)($st['ka_at'] ?? 0)) <= 3 * (int)($st['keepalive_sec'] ?? 10) + 5 && $d['running'] ? synohw_orb('green') : synohw_orb('yellow')) . 'keepalive ' . synohw_h(synohw_age($s['now'], (int)($st['ka_at'] ?? 0))) : '');
  return synohw_kv_table($r, 'synohw-card-kv');
}

function synohw_render_ov_dx(array $s): string {
  $st = $s['daemon']['state'] ?? []; $x = $s['dx']; $v = $x['v']; $r = [];
  [$c, $head, $why] = synohw_dx_verdict($s);
  $r['Driver'] = synohw_orb($c) . '<span class="' . ['green' => '', 'grey' => 'grey-text', 'yellow' => 'orange-text', 'red' => 'red-text'][$c] . '">' . synohw_h($head) . '</span>';
  if (synohw_dx_controlled($s)) {
    $r['Fan mode'] = synohw_h($st['dx_mode'] ?? '?') . (($st['dx_mode'] ?? '') === 'MANUAL' ? ' ' . synohw_h($st['dx_cfg_level'] ?? '') : '');
    $r['Fan level'] = '<b>' . synohw_h($st['dx_level'] ?? '?') . '</b> <span class="grey-text">(wanted ' . synohw_h($st['dx_want'] ?? '?') . '; a 2-bit level, no RPM exists)</span>';
    $r['Why'] = synohw_h(SYNOHW_DX_WHY[$st['dx_why'] ?? ''] ?? 'starting');
  } else {
    $r['Fan'] = '<span class="grey-text">not controlled' . (!$s['daemon']['running'] ? ' (daemon not running)' : (isset($st['dx_why']) ? ': ' . synohw_h(SYNOHW_DX_WHY[$st['dx_why']] ?? $st['dx_why']) : '')) . '; the unit keeps its level</span>'
      . (isset($v['fan_level']) && in_array($v['fan_level'], ['full', 'middle', 'low', 'stop'], true) ? ' &middot; reads <b>' . synohw_h($v['fan_level']) . '</b>' : '');
  }
  $r['Hottest disk here'] = synohw_disk_summary(synohw_box_disks($s, 'dx'));
  if ($v && in_array($v['fan_level'] ?? '', ['full', 'middle', 'low', 'stop'], true)) {
    $al = [];
    foreach (['1' => 'fan1_fail', '2' => 'fan2_fail'] as $n => $k) {
      $a = (string)($v[$k] ?? '?');
      $al[] = $a === '0' ? synohw_orb('green') . "fan $n no alarm" : ($a === '1' ? synohw_orb('red') . "<span class=\"red-text\">fan $n ALARM</span>" : synohw_orb('yellow') . "<span class=\"orange-text\">fan $n unreadable</span>");
    }
    $r['Fan alarms'] = implode(' &nbsp; ', $al);
    $po = (string)($v['power_on'] ?? '?');
    $r['Power'] = $po === '1' ? synohw_orb('green') . 'on' : synohw_orb('yellow') . '<span class="orange-text">power_on ' . synohw_h($po) . '</span>';
  }
  $lk = synohw_dx_link($s);
  if ($lk !== '') $r['Driver details'] = $lk;
  return synohw_kv_table($r, 'synohw-card-kv');
}

function synohw_render_health(array $s): string {
  $d = synohw_daemon_rows($s); $r = ['Daemon' => $d['Daemon'], 'Serial port' => $d['Serial port']];
  [$c, $head, $why] = synohw_dx_verdict($s);
  $r['Expansion driver'] = synohw_orb($c) . synohw_h($head) . ' <span class="grey-text">' . synohw_h($why) . '</span>';
  $m = $s['model'];
  $r['Models'] = synohw_badge('ds', $s) . ' <span class="grey-text">' . synohw_h($m['main']['how']) . '</span><br>' . synohw_badge('dx', $s) . ' <span class="grey-text">' . synohw_h($m['dx']['how']) . '</span>';
  return synohw_kv_table($r + synohw_sys_rows($s));
}

/* ---------------- DS2415+ tab ---------------- */

function synohw_render_ds_now(array $s): string {
  $st = $s['daemon']['state'] ?? [];
  if (!synohw_ds_controlled($s)) return '<p class="grey-text">The daemon is not running: nobody controls this fan (it was left at 99 % if it was stopped on purpose).</p>';
  $disk = (string)($st['disk'] ?? '?');
  return synohw_kv_table([
    'Mode' => synohw_ds_mode_text($st),
    'Input now' => 'hottest disk (both units) ' . synohw_h($disk) . ($disk === 'standby' || $disk === '?' ? '' : '&nbsp;&deg;C') . ', CPU ' . synohw_h($st['cpu'] ?? '?') . '&nbsp;&deg;C',
    'Output' => '<b>' . synohw_h($st['sent'] ?? '?') . ' %</b> sent' . ((string)($st['table_duty'] ?? '') !== '' && ($st['table_duty'] ?? '') !== ($st['sent'] ?? '') ? ' <span class="grey-text">(table: ' . synohw_h($st['table_duty']) . ' %)</span>' : '')
      . ' <span class="grey-text">the fan speed itself cannot be read (no tach)</span>',
    'Why' => synohw_ds_why($s),
  ]);
}
function synohw_render_temps_ds(array $s): string {
  $o = synohw_render_temps($s, 'ds');
  $dx = synohw_box_disks($s, 'dx');
  if ($dx) $o .= '<p class="grey-text whitespace-normal">The fan table above also counts the ' . synohw_h(count($dx)) . ' disk(s) in the expansion unit (hottest disk of both units, as the daemon reads it); they are listed on the ' . synohw_h(synohw_box_name($s, 'dx')) . ' tab.</p>';
  return $o;
}
function synohw_render_mcu_link(array $s): string {
  $r = synohw_daemon_rows($s); unset($r['Daemon']);
  return synohw_kv_table($r);
}

/* ---------------- DX1215 tab ---------------- */

function synohw_pick_rows(array $rows, array $keys): array { $o = []; foreach ($keys as $k) if (isset($rows[$k])) $o[$k] = $rows[$k]; return $o; }
function synohw_render_dx_driver(array $s): string {
  [$c, $head, $why] = synohw_dx_verdict($s);
  $lk = synohw_dx_link($s);
  return '<p class="synohw-verdict">' . synohw_orb($c) . '<b>' . synohw_h($head) . '.</b> ' . synohw_h($why) . ($lk !== '' ? ' Full driver status: ' . $lk . '.' : '') . '</p>'
    . synohw_kv_table(synohw_pick_rows(synohw_dx_rows($s), ['Module', 'Unit', 'Values from', 'Last module error']));
}
function synohw_render_dx_fans(array $s): string {
  $rows = synohw_pick_rows(synohw_dx_rows($s), ['Fan control', 'Fan level now', 'Fan 1 alarm', 'Fan 2 alarm', 'Disks in the DX1215']);
  return $rows ? synohw_kv_table($rows) : '<p class="grey-text">No fan values: the synology_dx module gives no unit.</p>';
}
function synohw_render_temps_dx(array $s): string { return synohw_render_temps($s, 'dx'); }
function synohw_render_dx_power(array $s): string {
  $rows = synohw_pick_rows(synohw_dx_rows($s), ['Power', 'PSU 1 / PSU 2', 'Switch mode']);
  return $rows ? synohw_kv_table($rows) : '<p class="grey-text">No power values: the synology_dx module gives no unit.</p>';
}

/* DSM's DX1215 table as shown on the DX1215 tab (static: SYNOHW_DX_TABLE, tested against scemd.xml) */
function synohw_dx_table_html(): string {
  $t = SYNOHW_DX_TABLE; $rows = [];
  foreach ($t['steps'] as $i => [$thr, $lvl]) $rows[] = '<tr><td>' . ($i === 0 ? 'below ' . synohw_h($t['steps'][1][0]) . '&nbsp;&deg;C' : 'from ' . synohw_h($thr) . '&nbsp;&deg;C') . '</td><td><b>' . synohw_h($lvl) . '</b></td><td class="whitespace-normal">' . ($i === 0 ? 'also when every disk of the unit is asleep (hibernation_speed LOW)' : 'steps down only 2&nbsp;&deg;C below the row (threshold 2)') . '</td></tr>';
  $rows[] = '<tr><td>from ' . synohw_h($t['shutdown']) . '&nbsp;&deg;C</td><td><b>full</b></td><td class="whitespace-normal">the SHUTDOWN row; the plugin raises to full in every mode (see the DX_MODE help)</td></tr>';
  return '<div class="TableContainer--no-min-width"><table class="unraid synohw-kv synohw-dxtable"><thead><tr><td>Hottest disk in the unit</td><td>Level</td><td>Note</td></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
}

/* ---------------- the diagram (Overview) ---------------- */

/* temperature band of a disk against the table that drives its unit's fan */
function synohw_band(array $d, string $box, array $s): array {
  if ($d['standby']) return ['standby', 'standby (asleep, not woken)'];
  if ($d['temp'] === null) return ['noread', 'spinning without a reading'];
  $t = $d['temp'];
  if ($box === 'dx') {
    $st = SYNOHW_DX_TABLE['steps'];
    if ($t >= SYNOHW_DX_TABLE['shutdown']) return ['alert', 'at the DX1215 SHUTDOWN row (' . SYNOHW_DX_TABLE['shutdown'] . ' °C)'];
    if ($t >= $st[2][0]) return ['hot', 'DX1215 table: full (from ' . $st[2][0] . ' °C)'];
    if ($t >= $st[1][0]) return ['warm', 'DX1215 table: middle (from ' . $st[1][0] . ' °C)'];
    return ['cool', 'DX1215 table: low (below ' . $st[1][0] . ' °C)'];
  }
  $mode = $s['daemon']['state']['mode'] ?? ($s['cfg']['FAN_MODE'] ?? 'LOW');
  if (!isset(SYNOHW_TABLES[$mode])) $mode = 'LOW';
  $tb = SYNOHW_TABLES[$mode];
  if ($t >= $tb['disk_alert']) return ['alert', "$mode table: ALERT (from {$tb['disk_alert']} °C)"];
  if ($t >= $tb['disk'][3][0]) return ['hot', "$mode table: fan 99 % (from {$tb['disk'][3][0]} °C)"];
  if ($t >= $tb['disk'][1][0]) return ['warm', "$mode table: fan raised (from {$tb['disk'][1][0]} °C)"];
  return ['cool', "$mode table: lowest row (below {$tb['disk'][1][0]} °C)"];
}

function synohw_svg_text(float $x, float $y, string $t, string $cls = '', string $anchor = 'start'): string {
  return '<text x="' . $x . '" y="' . $y . '" class="' . $cls . '" text-anchor="' . $anchor . '">' . synohw_h($t) . '</text>';
}

/* ---------------- rear USB sockets (2026.10.25k) ----------------
 * The DS2415+ has four rear USB 3.0 sockets (QIG p.4) and one xHCI controller, the Etron EJ188 at 04:00.0, whose two root
 * hubs (USB 2.0 bus + USB 3.0 bus) have 4 ports each (xhci_hcd/4p, experiments/2026-10-25-hw-inventory/pci-usb.txt). A
 * device on root-hub port N of either bus is in one of those sockets. WHICH socket is port N is not documented, so the
 * diagram counts the sockets in use and names the devices, but draws no device in a particular socket. The DOM sits on the
 * EHCI controller (00:16.0, inside the case), not on a rear socket, and is not counted. */
function synohw_rear_usb(array $p): array {
  $dir = $p['usbdev'] ?? '/sys/bus/usb/devices'; $pci = $p['pcidev'] ?? '/sys/bus/pci/devices';
  $buses = [];
  foreach (glob("$dir/usb*") ?: [] as $h) {
    if (!preg_match('/^usb(\d{1,3})$/', basename($h), $m)) continue;
    $ser = synohw_read("$h/serial");   // a root hub's serial = its controller's PCI address
    if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $ser)) continue;
    if (strncmp(strtolower(synohw_read("$pci/$ser/class")), '0x0c0330', 8) !== 0) continue;   // xHCI only
    $buses[(int)$m[1]] = $ser;
  }
  $flash = '';
  $ini = is_readable($p['disks_ini'] ?? '') ? @parse_ini_file($p['disks_ini'], true, INI_SCANNER_RAW) : false;
  foreach (is_array($ini) ? $ini : [] as $sec => $v) if (is_array($v) && (($v['name'] ?? trim($sec, '"')) === 'flash')) $flash = (string)($v['device'] ?? '');
  $flashUsb = '';
  if (preg_match('/^[a-z0-9]{1,16}$/', $flash)) { $r = realpath(($p['sysblock'] ?? '/sys/block') . "/$flash/device"); if (is_string($r) && preg_match_all('#/(\d+-[\d.]+)(?=/)#', $r, $mm)) $flashUsb = end($mm[1]); }
  $dev = [];
  foreach (glob("$dir/*-*") ?: [] as $d) {
    $n = basename($d);
    if (!preg_match('/^(\d{1,3})-(\d{1,2})$/', $n, $m) || !isset($buses[(int)$m[1]])) continue;   // what is plugged into a root port
    $name = trim(synohw_read("$d/manufacturer") . ' ' . synohw_read("$d/product"));
    if ($name === '') { $vid = synohw_read("$d/idVendor"); $pid = synohw_read("$d/idProduct"); $name = preg_match('/^[0-9a-f]{4}$/', $vid . '') && preg_match('/^[0-9a-f]{4}$/', $pid) ? "USB device $vid:$pid" : 'USB device'; }
    if (strlen($name) > 48) $name = substr($name, 0, 48);
    $sp = synohw_read("$d/speed");
    $dev[] = ['name' => $name, 'port' => (int)$m[2], 'bus' => (int)$m[1], 'speed' => ctype_digit($sp) ? (int)$sp : null, 'flash' => $n === $flashUsb];
  }
  usort($dev, fn($a, $b) => ($a['port'] <=> $b['port']) ?: ($a['bus'] <=> $b['bus']));
  return ['controller' => $buses ? implode(', ', array_unique($buses)) : '', 'devices' => $dev];
}
function synohw_usb_text(array $d): string {
  return $d['name'] . ($d['flash'] ? ' (Unraid boot flash)' : '') . ', root-hub port ' . $d['port'] . ($d['speed'] ? ', ' . ($d['speed'] >= 5000 ? '5 Gb/s' : $d['speed'] . ' Mb/s') : '');
}

/* ---------------- the diagram (Overview), 2026.10.25k ----------------
 * Each drawn unit = its front panel and its rear panel (seen from behind), side by side, as in Synology's "at a Glance"
 * figures. Panel 256 x 216 (the chassis is 300 x 270 mm). The rear port column is drawn wider than on the unit, so the labels
 * fit next to the ports: schematic, not to scale. Positions: research/2026-10-25-ds2415-dx1215-panels.md. */
const SYNOHW_PW = 256, SYNOHW_PH = 216, SYNOHW_PY = 36;

/* one fan: ring + rotor at radius $r; $period = seconds per turn (commanded level), null = not turning; $alarm = stopped + red */
function synohw_svg_fan(float $cx, float $cy, float $r, ?float $period, bool $alarm, string $label, string $sub): string {
  $cls = $alarm ? 'synohw-fan synohw-fan-alarm' : ($period === null ? 'synohw-fan synohw-fan-idle' : 'synohw-fan');
  $blade = 'M0,-3 C6,-20 19,-15 13,-3 Z'; $k = number_format($r / 27, 3, '.', '');
  $spin = $period !== null && !$alarm;
  $rot = '<g class="synohw-fan-rotor' . ($spin ? ' synohw-anim synohw-spin' : '') . '"' . ($spin ? ' style="--synohw-spin:' . number_format($period, 2, '.', '') . 's"' : '') . '>'
    . '<circle r="24" class="synohw-hit"/><path d="' . $blade . '"/><path d="' . $blade . '" transform="rotate(120)"/><path d="' . $blade . '" transform="rotate(240)"/><circle r="4.5" class="synohw-fan-hub"/></g>';
  return '<g class="' . $cls . '" transform="translate(' . $cx . ',' . $cy . ') scale(' . $k . ')"><circle r="27" class="synohw-fan-ring"/>' . $rot . '</g>'
    . synohw_svg_text($cx, $cy + $r + 14, $label, $alarm ? 'synohw-svg-alert synohw-svg-small' : 'synohw-svg-small', 'middle')
    . ($sub !== '' ? synohw_svg_text($cx, $cy + $r + 26, $sub, 'synohw-svg-muted synohw-svg-small', 'middle') : '');
}
function synohw_svg_led(float $cx, float $cy, string $cls, string $title, float $r = 3.5): string {
  return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" class="' . $cls . '"><title>' . synohw_h($title) . '</title></circle>';
}
/* LAN LED colour as Synology's LED table: green = 1 Gb/s, orange = 100 Mb/s, off = 10 Mb/s or no network (QIG p.16) */
function synohw_lan_led(?array $p, bool $up): string {
  if (!$up) return 'synohw-led-off';
  $sp = $p['speed'] ?? null;
  return $sp === null || $sp >= 1000 ? 'synohw-led-green' : ($sp >= 100 ? 'synohw-led-orange' : 'synohw-led-off');
}
function synohw_port_up(?array $p): bool { return $p && ($p['carrier'] !== '' ? $p['carrier'] === '1' : $p['oper'] === 'up'); }   // carrier when readable (link), else operstate
function synohw_speed_txt(?array $p): string { return $p && $p['speed'] !== null && $p['speed'] > 0 ? ($p['speed'] >= 1000 ? ($p['speed'] / 1000) . 'G' : $p['speed'] . 'M') : ''; }

/* front panel: top strip (STATUS, ALERT, power button, four LED windows) + 2 x 6 trays numbered row by row */
function synohw_svg_front(float $x, float $y, array $md, string $box, array $disks, array $s, array $led): array {
  $W = SYNOHW_PW; $H = SYNOHW_PH;
  $o = '<rect x="' . $x . '" y="' . $y . '" width="' . $W . '" height="' . $H . '" rx="12" class="synohw-unit"/>';
  // strip: STATUS, ALERT, power button, LED windows (DS2415+ LAN1-4, DX1215 LINK in the second)
  $o .= synohw_svg_led($x + 30, $y + 13, $led['status'][0], $led['status'][1]) . synohw_svg_text($x + 30, $y + 27, 'STATUS', 'synohw-svg-tiny', 'middle')
     . synohw_svg_led($x + 66, $y + 13, $led['alert'][0], $led['alert'][1]) . synohw_svg_text($x + 66, $y + 27, 'ALERT', 'synohw-svg-tiny', 'middle')
     . '<g class="synohw-powerbtn"><title>' . synohw_h($led['power'][1]) . '</title><rect x="' . ($x + 92) . '" y="' . ($y + 7) . '" width="30" height="15" rx="3" class="synohw-port"/>'
     . '<path d="M' . ($x + 104.2) . ',' . ($y + 11.2) . ' a4.6,4.6 0 1,0 5.6,0 M' . ($x + 107) . ',' . ($y + 9.6) . ' v4.6" class="' . $led['power'][0] . '"/></g>';
  foreach ($md['leds'] as $i => $name) {
    $lx = $x + 142 + $i * 26;
    if ($name === '') { $o .= '<rect x="' . ($lx - 2.5) . '" y="' . ($y + 10.5) . '" width="5" height="5" class="synohw-led-blank" aria-hidden="true"/>'; continue; }
    $l = $led['win'][$i] ?? ['synohw-led-off', $name];
    $o .= synohw_svg_led($lx, $y + 13, $l[0], $l[1]) . synohw_svg_text($lx, $y + 27, $name, 'synohw-svg-tiny', 'middle');
  }
  // trays
  $byBay = []; $loose = [];
  foreach ($disks as $d) { if ($d['bay'] !== null && $d['bay'] >= 1 && $d['bay'] <= $md['bays'] && !isset($byBay[$d['bay']])) $byBay[$d['bay']] = $d; else $loose[] = $d; }
  $TW = 110; $TH = 24; $GX = 12; $GY = 3; $name = synohw_box_name($s, $box);
  for ($b = 1; $b <= $md['bays']; $b++) {
    $c = ($b - 1) % $md['cols']; $r = intdiv($b - 1, $md['cols']);
    $bx = $x + 12 + $c * ($TW + $GX); $by = $y + 36 + $r * ($TH + $GY);
    $d = $byBay[$b] ?? null;
    $lock = '<circle cx="' . ($bx + $TW - 9) . '" cy="' . ($by + $TH / 2) . '" r="3.6" class="synohw-lock"/>';
    if (!$d) {
      $o .= '<g class="synohw-bay synohw-bay-empty" aria-hidden="true"><rect x="' . $bx . '" y="' . $by . '" width="' . $TW . '" height="' . $TH . '" rx="3"/>'
        . synohw_svg_text($bx + 16, $by + 16, (string)$b, 'synohw-svg-muted synohw-svg-small') . synohw_svg_text($bx + 40, $by + 16, 'empty', 'synohw-svg-muted synohw-svg-small') . $lock . '</g>';
      continue;
    }
    [$band, $btxt] = synohw_band($d, $box, $s);
    $idp = synohw_id_parts($d['id']);
    $t = $d['temp'] !== null ? $d['temp'] . ' °C' : ($d['standby'] ? 'standby' : 'no reading');
    [$lc, $lt] = synohw_bay_led($s, $box, $b);
    $hl = $s['bayleds']['health'][$d['device']] ?? null;
    // 2026.10.26b: the same facts as the Bay map table: Unraid's device id and slot, the sdX name, temperature, LED
    $desc = "$name bay $b: " . synohw_unraid_id($d['id']) . ' · ' . $d['device'] . ' · ' . $d['slot'] . ' · ' . ($d['temp'] !== null ? "$t ($btxt)" : $btxt) . " · $lt" . ($hl && $hl['fault'] ? ': ' . $hl['why'] : '');
    $o .= '<g class="synohw-bay synohw-band-' . $band . '" tabindex="0" role="img" aria-label="' . synohw_h($desc) . '"><title>' . synohw_h($desc) . '</title>'
      . '<rect x="' . $bx . '" y="' . $by . '" width="' . $TW . '" height="' . $TH . '" rx="3" class="synohw-bay-body"/>'
      . '<rect x="' . ($bx + 14) . '" y="' . ($by + $TH - 5) . '" width="' . ($TW - 30) . '" height="3" rx="1.5" class="synohw-bay-bar"/>'
      . '<circle cx="' . ($bx + 7) . '" cy="' . ($by + $TH / 2) . '" r="2.6" class="' . $lc . '"/>'
      . synohw_svg_text($bx + 16, $by + 16, (string)$b, 'synohw-svg-muted synohw-svg-small')
      . synohw_svg_text($bx + 33, $by + 16.5, $d['temp'] !== null ? $d['temp'] . '°' : ($d['standby'] ? 'stby' : '?'), 'synohw-svg-temp')
      . synohw_svg_text($bx + 62, $by + 16, $d['device'], 'synohw-svg-muted synohw-svg-small') . $lock . '</g>';
  }
  $o .= synohw_svg_text($x + $W - 14, $y + $H - 8, $md['name'], 'synohw-svg-tiny synohw-svg-muted', 'end');
  return [$o, $loose];
}

/* rear panel common part: the two-fan module, the power inlet under it, a recess top middle; returns the svg */
function synohw_svg_rear_body(float $x, float $y, array $fans): string {
  $o = '<rect x="' . $x . '" y="' . $y . '" width="' . SYNOHW_PW . '" height="' . SYNOHW_PH . '" rx="12" class="synohw-unit"/>'
     . '<rect x="' . ($x + 96) . '" y="' . ($y + 14) . '" width="54" height="30" rx="2" class="synohw-recess" aria-hidden="true"/>'
     . '<rect x="' . ($x + 8) . '" y="' . ($y + 62) . '" width="142" height="124" rx="5" class="synohw-fanplate"/>';
  foreach ($fans as $i => $f) $o .= synohw_svg_fan($x + 44 + $i * 70, $y + 106, 29, $f[0], $f[1], $f[2], $f[3]);
  $o .= '<g class="synohw-inlet"><title>Power port (AC in)</title><rect x="' . ($x + 110) . '" y="' . ($y + 191) . '" width="24" height="17" rx="2" class="synohw-port"/>'
     . '<rect x="' . ($x + 115) . '" y="' . ($y + 197) . '" width="2.5" height="6" class="synohw-port-notch"/><rect x="' . ($x + 120.8) . '" y="' . ($y + 195) . '" width="2.5" height="6" class="synohw-port-notch"/><rect x="' . ($x + 126.5) . '" y="' . ($y + 197) . '" width="2.5" height="6" class="synohw-port-notch"/></g>'
     . synohw_svg_text($x + 104, $y + 203, 'power', 'synohw-svg-tiny synohw-svg-muted', 'end');
  return $o;
}

function synohw_svg_generic(float $x, float $y, float $w, float $h, string $title, string $sub, bool $dashed = false): string {
  $o = '<g class="synohw-unit-generic' . ($dashed ? ' synohw-unit-absent' : '') . '"><rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="10" class="synohw-unit"/>';
  if (!$dashed) for ($i = 0; $i < 4; $i++) $o .= '<rect x="' . ($x + 30) . '" y="' . ($y + 60 + $i * 30) . '" width="' . ($w - 60) . '" height="18" rx="3" class="synohw-slot"/>';
  return $o . synohw_svg_text($x + $w / 2, $y + 32, $title, 'synohw-svg-title', 'middle') . synohw_svg_text($x + $w / 2, $y + $h - 18, $sub, 'synohw-svg-muted', 'middle') . '</g>';
}
function synohw_svg_caption(float $x, float $y, array $lines): string {
  $o = ''; foreach ($lines as $i => [$t, $cls]) $o .= synohw_svg_text($x, $y + $i * 13, $t, 'synohw-svg-small ' . $cls); return $o;
}

function synohw_render_diagram(array $s): string {
  $m = $s['model']; $st = $s['daemon']['state'] ?? []; $x = $s['dx']; $v = $x['v'];
  $dsCtl = synohw_ds_controlled($s); $dxCtl = synohw_dx_controlled($s);
  $sum = []; $loose = []; $svg = '';
  $PW = SYNOHW_PW; $PH = SYNOHW_PH; $Y = SYNOHW_PY;
  $BX = [8, 568]; $BW = 2 * $PW + 12;            // unit blocks: front at BX, rear at BX + PW + 12
  // 2026.10.26b: the expansion cable takes the short path: up the gap at CX, along a lane above the DX1215 (LANE, inside the
  // drawing shifted down by TOP), straight down over the DX1215 rear panel into its expansion port. The LAN wires use the bottom (CY).
  $TOP = 34; $LANE = -11;
  $VW = 1104; $VH = 324 + $TOP; $CX = 556; $CY = 330; $CR = 1100;
  $dsPort = null; $dxPort = null;                // [x, y] of each drawn expansion port (the cable's ends)
  // ---- main unit
  $dsDisks = synohw_box_disks($s, 'ds');
  if ($m['main']['drawn']) {
    $md = SYNOHW_MODELS[$m['main']['model']]; $fx = $BX[0]; $rx = $fx + $PW + 12;
    $alert = ($st['alert'] ?? '0') === '1';
    $ports = $s['net']['ports'];
    $led = ['status' => [!$st ? 'synohw-led-off' : ($alert ? 'synohw-led-orange synohw-anim synohw-blink' : 'synohw-led-green'), 'STATUS LED: ' . (!$st ? 'unknown (no daemon state)' : ($alert ? 'orange, blinking (set by this plugin: ALERT band)' : 'green (set by this plugin)'))],
            'alert' => [$alert ? 'synohw-led-red' : 'synohw-led-off', 'ALERT LED: ' . ($alert ? 'on (set by this plugin: ALERT band)' : 'off')],
            'power' => ['synohw-led-blue-stroke', 'Power button: lit blue (the unit is running)'], 'win' => []];
    for ($i = 0; $i < 4; $i++) { $p = $ports[$i] ?? null; $up = synohw_port_up($p);
      $led['win'][$i] = [synohw_lan_led($p, $up), 'LAN' . ($i + 1) . ' LED: ' . ($up ? 'link' . (($sp = synohw_speed_txt($p)) !== '' ? " $sp" : '') : 'no link') . ($p ? ' (' . $p['nic'] . ', ' . synohw_lan_map($i, $p) . ')' : '')]; }
    $svg .= '<g class="synohw-unit-ds">' . synohw_svg_text($fx, 18, $md['name'], 'synohw-svg-title') . synohw_svg_text($fx + 74, 18, 'main unit', 'synohw-svg-muted')
      . synohw_svg_text($fx + 4, 31, 'front', 'synohw-svg-muted synohw-svg-small') . synohw_svg_text($rx + 4, 31, 'rear (seen from behind)', 'synohw-svg-muted synohw-svg-small');
    [$f, $l] = synohw_svg_front($fx, $Y, $md, 'ds', $dsDisks, $s, $led); $svg .= $f; $loose = array_merge($loose, $l);
    // rear: fans at the commanded duty (20 % -> 2.4 s per turn, 99 % -> 0.48 s); one duty drives both fans
    $duty = $dsCtl && ctype_digit((string)($st['sent'] ?? '')) ? (int)$st['sent'] : null;
    $fans = []; for ($i = 0; $i < $md['fans']; $i++) $fans[] = [$duty ? max(0.3, 48 / $duty) : null, false, 'fan ' . ($i + 1), $duty ? "$duty %" : 'not controlled'];
    $svg .= synohw_svg_rear_body($rx, $Y, $fans);
    $c0 = $rx + 202;                              // label column (text ends here), ports to its right
    // USB 3.0 x4 (2 x 2)
    $usb = $s['usb'] ?? ['devices' => [], 'controller' => '']; $nu = count($usb['devices']);
    $svg .= '<g class="synohw-usb"><title>' . synohw_h('USB 3.0 x4 (rear): ' . ($nu ? $nu . ' device(s): ' . implode('; ', array_map('synohw_usb_text', $usb['devices'])) . '. Which socket is which root-hub port is not documented.' : 'nothing plugged in')) . '</title>';
    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$cx, $cy]) $svg .= '<rect x="' . ($rx + 214 + $cx * 12) . '" y="' . ($Y + 38 + $cy * 18) . '" width="7" height="13" rx="1" class="synohw-port synohw-usb-socket"/>';
    $svg .= synohw_svg_text($c0, $Y + 50, 'USB 3.0', 'synohw-svg-tiny', 'end') . synohw_svg_text($c0, $Y + 62, $nu ? "$nu in use" : 'none in use', 'synohw-svg-tiny synohw-svg-muted', 'end') . '</g>';
    // expansion port
    $svg .= '<g><title>Expansion port (to the DX1215 expansion unit)</title><rect x="' . ($rx + 214) . '" y="' . ($Y + 76) . '" width="18" height="28" rx="4" class="synohw-port"/>'
      . '<rect x="' . ($rx + 220) . '" y="' . ($Y + 81) . '" width="6" height="18" rx="1" class="synohw-port-notch"/></g>' . synohw_svg_text($c0, $Y + 98, 'expansion', 'synohw-svg-tiny', 'end');
    $dsPort = [$rx + 232, $Y + 84];
    // console (manufacturing use only) + RESET pinhole
    $svg .= '<g><title>Console port (Synology: manufacturing use only)</title><rect x="' . ($rx + 212) . '" y="' . ($Y + 111) . '" width="10" height="17" rx="2.5" class="synohw-console"/></g>'
      . '<g><title>RESET button (pinhole)</title><circle cx="' . ($rx + 233) . '" cy="' . ($Y + 120) . '" r="2.2" class="synohw-pin"/></g>'
      . synohw_svg_text($c0, $Y + 117, 'console', 'synohw-svg-tiny synohw-svg-muted', 'end') . synohw_svg_text($c0, $Y + 128, 'RESET', 'synohw-svg-tiny', 'end');
    // LAN 1-4, stacked top to bottom; the Linux NIC in PCI order is in each title and the caption (LAN 1 = eth0 verified, 2-4 inferred)
    $lanUp = []; $lanTxt = [];
    for ($i = 0; $i < $md['lan']; $i++) {
      $p = $ports[$i] ?? null; $py = $Y + 138 + $i * 17; $px = $rx + 214;
      $up = synohw_port_up($p); $spd = synohw_speed_txt($p);
      $svg .= '<g class="synohw-rj45' . ($up ? ' synohw-rj45-up' : '') . '"><title>' . synohw_h('LAN ' . ($i + 1) . ' = ' . ($p ? $p['nic'] : '?') . ' (' . synohw_lan_map($i, $p) . '): ' . ($up ? 'link up' . ($spd !== '' ? ", {$p['speed']} Mb/s" : '') : 'no link') . ($p && $p['master'] !== '' ? ', in ' . $p['master'] : '')) . '</title>'
        . '<rect x="' . $px . '" y="' . $py . '" width="24" height="15" rx="1.5" class="synohw-port"/><rect x="' . ($px + 13) . '" y="' . ($py + 4) . '" width="8" height="7" class="synohw-port-notch"/>'
        . '<circle cx="' . ($px + 5) . '" cy="' . ($py + 4) . '" r="2" class="' . synohw_lan_led($p, $up) . '"/><circle cx="' . ($px + 5) . '" cy="' . ($py + 11) . '" r="2" class="' . ($up ? 'synohw-led-green' : 'synohw-led-off') . '"/>'
        . synohw_svg_text($c0, $py + 11, 'LAN ' . ($i + 1), 'synohw-svg-tiny', 'end') . '</g>';
      if ($up) { $lanUp[] = $py + 7.5; $svg .= '<path d="M' . ($px + 24) . ',' . ($py + 7.5) . ' H' . ($CX - 14) . '" class="synohw-wire synohw-anim synohw-flow" style="--synohw-spin:1.4s"/>'; }
      $lanTxt[] = 'LAN ' . ($i + 1) . ' ' . ($up ? ($spd ?: 'up') : 'off');
      $sum[] = ($p ? $p['nic'] : "port $i") . ' ' . ($up ? 'up' . ($spd !== '' ? " $spd" : '') : 'no link');
    }
    $role = '';
    foreach ($ports as $p) if (synohw_port_up($p) && $p['master'] !== '') { $mm = array_column($s['net']['masters'], 'name'); $role = $p['nic'] . ' in ' . $p['master'] . (count($mm) > 1 ? ' (' . implode(', ', $mm) . ')' : ''); break; }
    if ($lanUp) $svg .= '<path d="M' . ($CX - 14) . ',' . min($lanUp) . ' V' . ($CY - 26) . '" class="synohw-wire"/>';
    $svg .= synohw_svg_text($CX - 18, $CY - 14, $lanUp ? 'LAN' . ($role !== '' ? ': ' . $role : '') : 'LAN: no port has a link', 'synohw-svg-label', 'end');
    // captions: what each panel shows, and what is not known
    $svg .= synohw_svg_caption($fx + 4, $Y + $PH + 15, [['STATUS ' . (!$st ? 'unknown' : ($alert ? 'orange, blinking' : 'green')) . ' · ALERT ' . ($alert ? 'on' : 'off') . ' · power on', ''],
                                                    ['bays numbered as Synology does: 1 top left, row by row', 'synohw-svg-muted']])
      . synohw_svg_caption($rx + 4, $Y + $PH + 15, [[$duty ? "fans $duty % commanded (speed not measured)" : 'fans not controlled', ''],
                                                    [implode(' · ', $lanTxt), ''],
                                                    ['LAN 1 = eth0 verified · LAN 2-4 = eth1-3 inferred (PCI order)', 'synohw-svg-muted']]);
    $svg .= '</g>';
    $sum[] = $md['name'] . ': ' . count($dsDisks) . ' disk(s)' . ($duty ? ", fans $duty % commanded" : ', fans not controlled') . ', USB ' . ($nu ? "$nu in use" : 'none in use');
  } else {
    $nm = $m['main']['model'] !== '' ? 'Synology ' . $m['main']['model'] : 'Main unit: model not identified';
    $svg .= synohw_svg_generic($BX[0], $Y, $BW, $PH, $nm . ' (not yet drawn)', 'details in the cards and tables below');
    $sum[] = "$nm, not drawn: " . count($dsDisks) . ' disk(s)';
  }
  // ---- expansion unit
  $dxDisks = synohw_box_disks($s, 'dx'); $dm = $m['dx'];
  $link = $dm['master'] || $dxDisks;
  if ($dm['drawn']) {
    $md = SYNOHW_MODELS[$dm['model']]; $fx = $BX[1]; $rx = $fx + $PW + 12;
    $lvl = (string)($v['fan_level'] ?? ''); $po = (string)($v['power_on'] ?? '?');
    $a1 = ((string)($v['fan1_fail'] ?? '')) === '1'; $a2 = ((string)($v['fan2_fail'] ?? '')) === '1';
    $led = ['status' => ['synohw-led-off', 'STATUS LED: not read (this plugin does not read or set the DX1215 STATUS LED)'],
            'alert' => [$a1 || $a2 ? 'synohw-led-red synohw-anim synohw-blink' : 'synohw-led-off', 'ALERT LED: ' . ($a1 || $a2 ? 'expected red, blinking: a fan alarm bit is set' : 'expected off: no fan alarm bit') . ' (the LED itself is not read)'],
            'power' => [$po === '1' ? 'synohw-led-blue-stroke' : 'synohw-led-off-stroke', 'POWER button: ' . ($po === '1' ? 'lit blue (power_on bit 1)' : "power_on bit $po")],
            'win' => [1 => [$link ? 'synohw-led-green' : 'synohw-led-off', 'LINK LED: ' . ($link ? 'expected green: the unit answers over the cable' : 'expected off: no link seen') . ' (the LED itself is not read)']]];
    $svg .= '<g class="synohw-unit-dx">' . synohw_svg_text($fx, 18, $md['name'], 'synohw-svg-title') . synohw_svg_text($fx + 66, 18, 'expansion unit', 'synohw-svg-muted')
      . synohw_svg_text($fx + 4, 31, 'front', 'synohw-svg-muted synohw-svg-small') . synohw_svg_text($rx + 4, 31, 'rear (seen from behind)', 'synohw-svg-muted synohw-svg-small');
    [$f, $l] = synohw_svg_front($fx, $Y, $md, 'dx', $dxDisks, $s, $led); $svg .= $f; $loose = array_merge($loose, $l);
    $per = ['low' => 2.0, 'middle' => 1.2, 'full' => 0.6][$lvl] ?? null;
    $fans = [];
    for ($i = 0; $i < $md['fans']; $i++) { $al = $i === 0 ? $a1 : $a2;
      $fans[] = [$lvl === 'stop' ? null : $per, $al, 'fan ' . ($i + 1) . ($al ? ': ALARM' : ''), $al ? 'stopped or blocked' : ($lvl === 'stop' ? 'STOP' : ($lvl ?: '?'))]; }
    $svg .= synohw_svg_rear_body($rx, $Y, $fans);
    $c0 = $rx + 202;
    $svg .= '<g><title>Expansion port (from the DS2415+)</title><rect x="' . ($rx + 214) . '" y="' . ($Y + 56) . '" width="18" height="28" rx="4" class="synohw-port"/>'
      . '<rect x="' . ($rx + 220) . '" y="' . ($Y + 61) . '" width="6" height="18" rx="1" class="synohw-port-notch"/></g>' . synohw_svg_text($c0, $Y + 78, 'expansion', 'synohw-svg-tiny', 'end');
    $dxPort = [$rx + 223, $Y + 56];   // top centre of the DX1215 expansion port (the cable comes down onto it)
    $sm = (string)($v['switch_mode'] ?? '?'); $smt = synohw_switch_text($sm);
    $svg .= '<g class="synohw-switch"><title>' . synohw_h('DEFAULT/MANUAL switch (Synology: DEFAULT = auto power on/off with the DiskStation, MANUAL = no auto power on): ' . $smt . ' (page 3 bit 0 = ' . $sm . '; bit 1 = DEFAULT, verified at the box on 2026-10-26 with the switch on DEFAULT).') . '</title>'
      . '<rect x="' . ($rx + 217) . '" y="' . ($Y + 94) . '" width="12" height="22" rx="2" class="synohw-port"/><rect x="' . ($rx + 219) . '" y="' . ($Y + 100) . '" width="8" height="10" rx="1" class="synohw-switch-knob"/></g>'
      . synohw_svg_text($c0, $Y + 102, 'DEFAULT /', 'synohw-svg-tiny', 'end') . synohw_svg_text($c0, $Y + 113, 'MANUAL', 'synohw-svg-tiny', 'end');
    $svg .= synohw_svg_caption($fx + 4, $Y + $PH + 15, [['POWER ' . ($po === '1' ? 'on' : "bit $po") . ' · LINK ' . ($link ? 'green' : 'off') . ' · ALERT ' . ($a1 || $a2 ? 'red' : 'off') . ' (from the unit\'s bits)', ''],
                                                    ['STATUS LED not read · bays as Synology numbers them', 'synohw-svg-muted']])
      . synohw_svg_caption($rx + 4, $Y + $PH + 15, [[($dxCtl ? 'fan mode ' . ($st['dx_mode'] ?? '?') . ', level ' : 'fans not controlled, level ') . ($lvl ?: '?') . ' (commanded)', $a1 || $a2 ? 'synohw-svg-alert' : ''],
                                                    ['DEFAULT/MANUAL switch: ' . $smt, 'synohw-svg-muted'],
                                                    ['fan 1 / fan 2 left or right: not verified', 'synohw-svg-muted']]);
    $svg .= '</g>';
    $sum[] = $md['name'] . ': ' . count($dxDisks) . ' disk(s), fans ' . ($lvl ?: 'unknown') . ' commanded' . ($a1 || $a2 ? ', FAN ALARM' : '');
  } elseif ($dm['present']) {
    $nm = $dm['model'] !== '' ? 'Synology ' . $dm['model'] . ' (unique ' . $dm['unique'] . ')' : 'Expansion unit: model unknown';
    $svg .= synohw_svg_generic($BX[1], $Y, $BW, $PH, $nm . ' (not yet drawn)', 'details on the expansion tab');
    $sum[] = "$nm, not drawn: " . count($dxDisks) . ' disk(s)';
  } else {
    $svg .= synohw_svg_generic($BX[1], $Y, $BW, $PH, 'No expansion unit seen', $x['loaded'] ? 'the driver finds no unit and no disk' : 'driver not loaded, and no disk behind the expansion port', true);
    $sum[] = 'no expansion unit seen';
  }
  // ---- the expansion cable: Synology's "Cable InfiniBand SATAx4" (DX1215 User's Guide accessory list). DS expansion port -> the gap ->
  // the lane above the DX1215 -> straight down over the DX1215 rear drawing into its expansion port (2026.10.26b; the drawing is
  // not mirrored). Animated only between two drawn units with a link.
  $anim = $link && $m['main']['drawn'] && $dm['drawn'];
  $a = $dsPort ?? [$BX[0] + $BW, $Y + $PH / 2];
  $ctxt = 'Expansion cable (InfiniBand, SATA ×4): ' . ($link ? ($dm['master'] ? 'link up, the unit answers' : 'link up, its disks are visible') : 'no link seen');
  if ($dxPort) {
    $d = 'M' . $a[0] . ',' . $a[1] . ' H' . ($CX - 10) . ' Q' . $CX . ',' . $a[1] . ' ' . $CX . ',' . ($a[1] - 10) . ' V' . ($LANE + 10) . ' Q' . $CX . ',' . $LANE . ' ' . ($CX + 10) . ',' . $LANE
       . ' H' . ($dxPort[0] - 10) . ' Q' . $dxPort[0] . ',' . $LANE . ' ' . $dxPort[0] . ',' . ($LANE + 10) . ' V' . $dxPort[1];
    $lx = ($CX + $dxPort[0]) / 2; $ly = $LANE - 8;
  } else { $by = $Y + $PH / 2; $d = 'M' . $a[0] . ',' . $a[1] . ' H' . $CX . ' V' . $by . ' H' . $BX[1]; $lx = ($CX + $CR) / 2; $ly = $Y + $PH + 23; }   // to the side of a picture that is not drawn
  $svg .= '<g class="synohw-cable-g"><title>Synology Cable InfiniBand SATAx4</title><path d="' . $d . '"'
    . ' class="synohw-cable' . ($link ? ' synohw-cable-up' : ' synohw-cable-down') . ($anim ? ' synohw-anim synohw-flow' : '') . '" style="--synohw-spin:1.2s"/>'
    . synohw_svg_text($lx, $ly, $ctxt, $link ? 'synohw-svg-label' : 'synohw-svg-alert', 'middle') . '</g>';
  $sum[] = 'expansion cable ' . ($link ? 'link up' : 'no link seen');
  $o = '<div class="synohw-diagram-wrap"><svg class="synohw-diagram" viewBox="0 0 ' . $VW . ' ' . $VH . '" role="group" aria-label="' . synohw_h('Hardware diagram, front and rear of each unit. ' . implode('; ', $sum) . '. Each disk bay can be focused for its values; the tables on this page hold the same facts.') . '">'
    . '<title>Hardware diagram</title><g transform="translate(0,' . $TOP . ')">' . $svg . '</g></svg></div>';
  // legend + caveats (text, not colour alone)
  $o .= '<ul class="synohw-diagram-legend">';
  foreach (['cool' => 'cool: the fan table\'s lowest row', 'warm' => 'warm: the fan is raised', 'hot' => 'hot: fan at maximum', 'alert' => 'alert / shutdown band', 'standby' => 'standby (never woken)', 'noread' => 'spinning, no reading'] as $k => $t)
    $o .= '<li><svg width="22" height="10" aria-hidden="true" focusable="false"><rect width="22" height="10" rx="2" class="synohw-key synohw-band-' . $k . '"/></svg>' . synohw_h($t) . '</li>';
  foreach (['synohw-led-green' => 'tray LED green: presence/activity', 'synohw-led-orange' => 'tray LED orange: fault (disk health)', 'synohw-led-off' => 'tray LED off'] as $k => $t)
    $o .= '<li><svg width="12" height="12" aria-hidden="true" focusable="false"><circle cx="6" cy="6" r="4" class="' . $k . '"/></svg>' . synohw_h($t) . '</li>';
  $o .= '</ul><p class="grey-text whitespace-normal synohw-diagram-note">' . ($m['main']['drawn'] || $dm['drawn'] ? 'Panels drawn after Synology\'s installation guides (DS2415+ Quick Installation Guide, DX1215 User\'s Guide): 12 trays in 2 columns of 6, numbered 1 (top left) to 12 (bottom right) row by row; rear port column and LED windows in their real order. Schematic, not to scale. ' : 'Only the DS2415+ and the DX1215 are drawn; other models get a plain picture. ')
     . 'Fan speed shown = the <b>commanded</b> level (DS2415+ duty sent, DX1215 low / middle / full), not a measured RPM: neither unit reports one. A red, stopped fan = an alarm bit. '
     . (!$m['main']['drawn'] && !$dm['drawn'] ? '' : 'Bays: confirmed at the box by blink count (2026-10-26): main unit bay = 4 x controller (88SE9215 03:00.0, 05:00.0, 06:00.0) + port + 1, DX1215 bay = 3 x EMID + port multiplier port + 1. '
     . 'Tray LEDs: what the driver has set (green = the controllers\' presence/activity, orange = fault from disk health), not a reading of the LED itself. '
     . 'LAN 1 = eth0 verified (the only cable, in LAN 1, is the only link, on eth0); LAN 2-4 = eth1-3 inferred from PCI order. USB: the devices on the rear sockets are counted and named, but which socket each is in is not known.')
     . ($loose ? ' <span class="orange-text">Not drawn in a bay: ' . implode(', ', array_map(fn($d) => synohw_h($d['name'] . ' (' . $d['device'] . ', ' . ($d['bay_note'] ?: 'bay ' . $d['bay'] . ' twice') . ')'), $loose)) . '.</span>' : '') . '</p>';
  return $o;
}

/* 2026.10.26b: the device id as Unraid's Main page prints it: my_id() (dynamix/include/Helpers.php:131-136) drops a trailing
 * "_3<wwn>" (18 characters) unless display wwn is set; we follow the default. */
function synohw_unraid_id(string $id): string {
  if ($id === '' || $id === '-') return '-';
  $w = substr($id, -18);
  return (strlen($id) <= 18 || substr($w, 0, 2) !== '_3' || preg_match('/.[_-]/', $w)) ? $id : substr($id, 0, -18);
}

/* 2026.10.26b: one row per disk in a bay (both units, unit order then bay), plus disks that are in no bay; used by the Bay map table,
 * the tray titles, the Main page badges (include/bays.php) and the hardware report. Read-only, from what synohw_collect() read. */
function synohw_bay_rows(array $s): array {
  $rows = [];
  foreach (['ds' => 'DS', 'dx' => 'DX'] as $box => $short) {
    foreach (synohw_box_disks($s, $box) as $d) {
      if ($box === 'ds' && $d['box'] !== 'ds') continue;   // usb/unknown are not in a bay
      $hl = $s['bayleds']['health'][$d['device']] ?? null;
      $led = $d['bay'] !== null ? synohw_bay_led($s, $box, (int)$d['bay']) : ['synohw-led-off', 'not in a bay'];
      $rows[] = ['box' => $box, 'unit' => synohw_box_name($s, $box), 'short' => $short, 'bay' => $d['bay'], 'note' => $d['bay_note'],
                 'id' => synohw_unraid_id($d['id']), 'device' => $d['device'], 'slot' => $d['slot'], 'name' => $d['name'],
                 'temp' => $d['temp'] !== null ? $d['temp'] . ' °C' : ($d['standby'] ? 'standby' : 'no reading'),
                 'led' => $led, 'fault' => $hl && $hl['fault'] ? $hl['why'] : ''];
    }
  }
  usort($rows, fn($a, $b) => [$a['box'] === 'dx', $a['bay'] === null, $a['bay'] ?? 99, $a['device']] <=> [$b['box'] === 'dx', $b['bay'] === null, $b['bay'] ?? 99, $b['device']]);
  return $rows;
}
function synohw_render_baymap(array $s): string {
  $rows = synohw_bay_rows($s);
  if (!$rows) return '<p class="grey-text whitespace-normal">No disk is in a bay of either unit (Unraid lists none in disks.ini or devs.ini that sits on a bay controller).</p>';
  $o = '<div class="TableContainer--no-min-width"><table class="unraid synohw-baymap"><thead><tr><td>Bay</td><td>Device (as Unraid shows it)</td><td>sdX</td><td>Unraid slot</td><td>Temperature</td><td>LED</td></tr></thead><tbody>';
  foreach ($rows as $r) {
    [$lc, $lt] = $r['led'];
    $o .= '<tr><td>' . synohw_badge($r['box'], $s) . ' ' . ($r['bay'] !== null ? '<b>' . synohw_h($r['bay']) . '</b>' : '<span class="orange-text">' . synohw_h($r['note'] ?: 'no bay') . '</span>') . '</td>'
       . '<td class="whitespace-normal">' . synohw_h($r['id']) . '</td><td>' . synohw_h($r['device']) . '</td><td>' . synohw_h($r['slot']) . '</td>'
       . '<td>' . synohw_h($r['temp']) . '</td><td class="whitespace-normal"><svg width="12" height="12" aria-hidden="true" focusable="false"><circle cx="6" cy="6" r="4" class="' . synohw_h($lc) . '"/></svg> '
       . synohw_h(preg_replace('/^LED /', '', $lt)) . ($r['fault'] !== '' ? ': <span class="orange-text">' . synohw_h($r['fault']) . '</span>' : '') . '</td></tr>';
  }
  $n = ['ds' => 0, 'dx' => 0]; foreach ($rows as $r) if ($r['bay'] !== null) $n[$r['box']]++;
  return $o . '</tbody></table></div><p class="grey-text whitespace-normal">' . synohw_h(synohw_box_name($s, 'ds') . ": {$n['ds']} of 12 bays populated; " . synohw_box_name($s, 'dx') . ": {$n['dx']} of 12. Empty bays are not listed.")
    . ' Temperatures from Unraid\'s cache (disks.ini, devs.ini): a disk in standby is never woken.</p>';
}

/* LAN n -> NIC: LAN 1 = eth0 verified at the box on 2026-10-26 (cable in LAN 1, carrier only on eth0); the rest by PCI order */
function synohw_lan_map(int $i, ?array $p): string {
  return $i === 0 && $p && $p['nic'] === 'eth0' ? 'verified' : 'inferred from PCI order';
}
/* DX1215 rear switch: page 3 bit 0; 1 = DEFAULT verified (switch on DEFAULT read 1 on 2026-10-26), 0 = the other position */
function synohw_switch_text(string $sm): string {
  return $sm === '1' ? 'DEFAULT (verified)' : ($sm === '0' ? 'MANUAL (bit 0; bit 1 = DEFAULT is verified)' : 'unknown (bit ' . $sm . ')');
}

/* bay LED tables for the DS2415+ and DX1215 tabs (2026.10.26a) */
function synohw_render_leds_ds(array $s): string { return synohw_render_leds($s, 'ds'); }
function synohw_render_leds_dx(array $s): string { return synohw_render_leds($s, 'dx'); }
function synohw_render_leds(array $s, string $box): string {
  $L = $s['bayleds'] ?? ['ds' => [], 'dx' => [], 'health' => [], 'at' => null, 'policy' => '', 'pch' => '', 'pch_state' => ''];
  $name = synohw_box_name($s, $box); $bays = $L[$box];
  $byBay = [];
  foreach (synohw_box_disks($s, $box) as $d) if ($d['bay'] !== null) $byBay[$d['bay']] = $d;
  $o = '';
  if (!$bays) {
    return '<p class="grey-text whitespace-normal">' . ($s['dx']['loaded'] ?? false ? 'The synology_dx driver is loaded but shows no LED state for the ' . synohw_h($name) . ($box === 'ds' ? ' (no 88SE9215 attached: it was loaded without ds_leds=1)' : ' (no port multiplier identified)') : 'The synology_dx driver (Synology Expansion Unit Driver plugin) is not loaded: the bay LEDs are not driven.') . '</p>';
  }
  $age = $L['at'] !== null ? max(0, $s['now'] - $L['at']) : null;
  $o .= '<p class="whitespace-normal">' . ($L['at'] === null ? '<span class="orange-text">No health record yet: the daemon is not running, so orange is not set from disk health.</span>'
        : ($L['policy'] === 'yes' ? 'Orange is set from disk health by this plugin\'s daemon (last evaluated ' . synohw_h($age) . ' s ago).' : 'Orange from disk health is <b>off</b> (LED_HEALTH=no): the daemon sets no fault bit.'))
     . ($box === 'ds' ? ' Orange enable (PCH GPIO 49): ' . ($L['pch'] === '1' ? synohw_orb('green') . 'high' : synohw_orb('yellow') . '<span class="orange-text">not high' . ($L['pch_state'] !== '' ? ' (' . synohw_h($L['pch_state']) . ')' : '') . '</span>') . '.' : '')
     . ' Switch the bay LEDs on or off: <a href="/Utilities/SynologyExpansion">Utilities, Synology Expansion Unit Driver</a>, Bay LED settings.</p>';
  $word = ['on' => '', 'off' => 'off', 'blocked' => 'set, cannot light', 'unknown' => 'unknown'];
  $o .= '<div class="TableContainer--no-min-width"><table class="unraid synohw-leds"><thead><tr><td>Bay</td><td>Disk</td><td>Green</td><td>Orange</td><td>Health</td></tr></thead><tbody>';
  foreach ($bays as $b => $x) {
    $d = $byBay[$b] ?? null; $hl = $d ? ($L['health'][$d['device']] ?? null) : null;
    if (!$d) {   // 2026.10.26b: no disk = the controller has nothing to show: the bay is dark, whatever the LED enable says
      $o .= '<tr><td>' . synohw_h($b) . '</td><td><span class="grey-text">empty</span></td><td><svg width="12" height="12" aria-hidden="true" focusable="false"><circle cx="6" cy="6" r="4" class="synohw-led-off"/></svg> dark (no disk)</td><td>'
         . ($x['orange'] === 'on' ? '<span class="orange-text">ON (fault bit set on an empty bay)</span>' : '<span class="grey-text">dark (no disk)</span>') . '</td><td><span class="grey-text">-</span></td></tr>';
      continue;
    }
    $g = $x['green'] === 'on' ? '<svg width="12" height="12" aria-hidden="true" focusable="false"><circle cx="6" cy="6" r="4" class="synohw-led-green"/></svg> on' : synohw_h($word[$x['green']]);
    $or = $x['orange'] === 'on' ? '<svg width="12" height="12" aria-hidden="true" focusable="false"><circle cx="6" cy="6" r="4" class="synohw-led-orange"/></svg> <span class="orange-text">ON (fault)</span>'
        : ($x['orange'] === 'blocked' ? '<span class="orange-text">fault bit set, cannot light (PCH GPIO 49 not high)</span>' : synohw_h($word[$x['orange']]));
    $o .= '<tr><td>' . synohw_h($b) . '</td><td>' . ($d ? synohw_h($d['name']) . ($d['name'] !== $d['device'] ? ' (' . synohw_h($d['device']) . ')' : '') : '<span class="grey-text">empty</span>') . '</td><td>' . $g . '</td><td>' . $or . '</td>'
       . '<td class="whitespace-normal">' . ($hl ? ($hl['fault'] ? '<span class="orange-text">' . synohw_h($hl['why']) . '</span>' : synohw_h($hl['why'])) : '<span class="grey-text">' . ($d ? 'no record' : '-') . '</span>') . '</td></tr>';
  }
  return $o . '</tbody></table></div>';
}
require_once __DIR__ . '/synohw-issues.php';   // 2026.10.26b: Known issues and firmware
