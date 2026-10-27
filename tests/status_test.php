<?php
/* Offline tests for include/synohw-status.php and include/status.php (build host only). Written: 2026-10-25.
 * usage: php status_test.php <plugin-dir> <fixture-root>   (fixtures built by tests/mkfixture.py)
 * Any PHP notice/warning in the library counts as a failure. */
$plug = $argv[1]; $root = $argv[2];
$fails = 0;
function ok($name, $cond, $detail = '') { global $fails; echo ($cond ? 'PASS ' : 'FAIL ') . $name . (!$cond && $detail !== '' ? "  [$detail]" : '') . "\n"; if (!$cond) $fails++; }
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) { $warnings[] = "$str at " . basename($file) . ":$line"; return true; });
require "$plug/include/synohw-status.php";
function render($sc) {
  global $root;
  $GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-$sc.json"), true);
  $s = synohw_collect(synohw_paths());
  return [$s, synohw_render_all($s)];
}

// normal: running daemon, LOW table, hottest disk 41 C, CPU 46 C
[$s, $h] = render('normal');
ok('status normal: daemon running with pid', strpos($h['daemon'], 'running, pid 4242') !== false, $h['daemon']);
ok('status normal: hottest disk named, standby disks never counted as hot', strpos($h['fan'], 'Hottest disk (disk3)') !== false && $s['disks']['list'][2]['standby'] === true);
ok('status normal: LOW would pick 20 %, HIGH would pick 40 % at disk 41 C', preg_match('#<td><b>20 %</b></td><td><b>40 %</b></td>#', $h['fan']) === 1, $h['fan']);
ok('status normal: alert margins per table (LOW 58 - 41 = 17, HIGH 53 - 41 = 12)', strpos($h['fan'], 'alert 58&nbsp;&deg;C: 17&nbsp;&deg;C away') !== false && strpos($h['fan'], 'alert 53&nbsp;&deg;C: 12&nbsp;&deg;C away') !== false);
ok('status normal: daemon decision and reason shown', strpos($h['fan'], 'because disk and CPU give the same step') !== false);
ok('status normal: flash and empty slots not listed as disks', !array_filter($s['disks']['list'], fn($d) => in_array($d['name'], ['flash', 'disk5'])));
ok('status normal: 24 h history min/max per sensor (parity 35..38, 720 samples)', $s['hist']['sensors']['parity']['min'] === 35 && $s['hist']['sensors']['parity']['max'] === 38 && $s['hist']['samples'] === 720, json_encode($s['hist']['sensors']['parity'] ?? null));
ok('status normal: standby counted as standby, not as a temperature', $s['hist']['sensors']['disk2']['n'] === 0 && $s['hist']['sensors']['disk2']['standby'] === 720);
ok('status normal: CPU per-core labels from hwmon, acpitz ignored', count($s['cpu']['list']) === 4 && $s['cpu']['max'] === 46);
ok('status normal: ports in PCI order with DOM MAC n -> port n, NVM address from bonding', $s['net']['ports'][0]['nic'] === 'eth0' && $s['net']['ports'][3]['dom'] === '00:11:32:aa:bb:04' && $s['net']['ports'][1]['perm'] === '00:a0:c9:00:00:01' && $s['net']['ports'][0]['state'] === 'real');
ok('status normal: udev rule present + identical, cron active, version from the plg', $s['udev']['rom'] && $s['udev']['same'] && $s['cron']['active'] && $s['version'] === '2026.10.25b');
ok('status normal: MCU counts and rate', strpos($h['mcu'], '0x4b') !== false && strpos($h['mcu'], '4.0 per s') !== false, $h['mcu']);
ok('status normal: running config table lists effective keys', strpos($h['cfgdiff'], 'MCU_LOG_SEC') !== false);

// fan sweep panel (2026.10.25d): none / running / aborted (reason is data: escaped)
[$s, $h] = render('normal');
ok('sweep panel: no sweep state -> says none, how to start (CLI only)', strpos($h['sweep'], 'none since the daemon started') !== false && strpos($h['sweep'], 'sweep start') !== false, $h['sweep']);
$run = json_decode(file_get_contents("$root/ov-normal.json"), true)['run'];
$now0 = $s['now'];
file_put_contents("$run/sweep.state", "state=running\nplan=20 15 12\nsteps=3\nstep=2\nduty=15\nstarted=" . ($now0 - 700) . "\nupdated=" . ($now0 - 5) . "\nstep_started=" . ($now0 - 400) . "\nhold=0\nmin_hold=600\nmax_hold=1800\nsettled=-\nsamples=24\ntemps=cpu=41 ST4000-AAA111=31 WDC-BBB222=28\nreason=\ncsv=/var/run/synology-hardware/sweep.csv\nsummary=/var/run/synology-hardware/sweep-summary.txt\nresult_csv=\nresult_txt=\n");
[$s, $h] = render('normal');
ok('sweep panel running: step 2 of 3, duty 15 %, time in step 6 min computed from step_started, temps listed',
   strpos($h['sweep'], '2 of 3') !== false && strpos($h['sweep'], 'duty sent 15 %') !== false && strpos($h['sweep'], '>6 min <') !== false
   && strpos($h['sweep'], 'ST4000-AAA111 31') !== false && strpos($h['sweep'], 'not decided yet') !== false, $h['sweep']);
file_put_contents("$run/sweep.state", "state=aborted\nplan=20 15\nsteps=2\nstep=1\nduty=20\nstarted=$now0\nupdated=$now0\nstep_started=$now0\nhold=61\nmin_hold=600\nmax_hold=1800\nsettled=no\nsamples=3\ntemps=cpu=41\nreason=disk <script>x</script> at 46 C\ncsv=/var/run/synology-hardware/sweep.csv\nsummary=/var/run/synology-hardware/sweep-summary.txt\nresult_csv=/boot/config/plugins/synology-hardware/sweep-20261025-1530.csv\nresult_txt=/boot/config/plugins/synology-hardware/sweep-20261025-1530.txt\n");
[$s, $h] = render('normal');
ok('sweep panel aborted: red ABORTED, reason HTML-escaped, flash paths and browse link',
   strpos($h['sweep'], 'ABORTED') !== false && strpos($h['sweep'], '<script>') === false && strpos($h['sweep'], '&lt;script&gt;') !== false
   && strpos($h['sweep'], 'sweep-20261025-1530.csv') !== false && strpos($h['sweep'], '/Main/Browse?dir=%2Fboot%2Fconfig%2Fplugins%2Fsynology-hardware') !== false, $h['sweep']);
unlink("$run/sweep.state");

[$s, $h] = render('stopped');
ok('status stopped: "stopped on purpose", last keepalive marked as last known', strpos($h['daemon'], 'stopped on purpose') !== false && strpos($h['daemon'], 'last known') !== false, $h['daemon']);
[$s, $h] = render('notty');
ok('status notty: ttyS1 missing explained, no state yet', strpos($h['daemon'], '/dev/ttyS1 is missing') !== false && strpos($h['daemon'], 'no state file yet') !== false, $h['daemon']);
ok('status notty: no history -> says so', strpos($h['temps'], 'No history yet') !== false);
[$s, $h] = render('crashed');
ok('status crashed: pid file without process = died, watchdog mentioned', strpos($h['daemon'], 'it died') !== false && strpos($h['daemon'], 'watchdog cron entry is active') !== false, $h['daemon']);
[$s, $h] = render('hot');
ok('status hot: HIGH alert reached is flagged red, LOW/HIGH both 99 %', strpos($h['fan'], 'reached (2&nbsp;&deg;C over)') !== false && preg_match('#<td><b>99 %</b></td><td><b>99 %</b></td>#', $h['fan']) === 1, $h['fan']);
ok('status hot: alert LED state shown', strpos($h['daemon'], 'ALERT band') !== false);

// measured (DS2415+, 2026-10-25 01:52): array unassigned, 24 DISK_NP slots + flash in disks.ini, sdd 28 / sdb DOM / sdc 31 in devs.ini
// 2026.10.25k: panels after Synology's guides (research/2026-10-25-ds2415-dx1215-panels.md)
preg_match_all('#<g class="synohw-bay [^"]*"[^>]*>(?:<title>[^<]*</title>)?<rect x="([\d.]+)" y="([\d.]+)"#', $h['diagram'], $bm);
$bx = array_map('floatval', $bm[1]); $by = array_map('floatval', $bm[2]);
ok('25k bays: 2 columns x 6 rows per unit, numbered row by row from the top left (1 2 / 3 4 / ... / 11 12), as the QIG p.8 and DX1215 UG p.14 figures',
   count($bx) === 24 && $bx[1] > $bx[0] && $by[1] === $by[0] && $bx[2] === $bx[0] && $by[2] > $by[0] && $bx[11] === $bx[1] && $by[11] === $by[10]
   && count(array_unique(array_slice($bx, 0, 12))) === 2 && count(array_unique(array_slice($by, 0, 12))) === 6, json_encode([$bx, $by]));
ok('25k front strips: DS2415+ STATUS, ALERT, power button, LAN1-LAN4; DX1215 STATUS, ALERT, POWER, only LINK labelled (3 blank windows)',
   preg_match('#>STATUS<.*>ALERT<.*synohw-powerbtn.*>LAN1<.*>LAN2<.*>LAN3<.*>LAN4<#s', $h['diagram']) === 1 && substr_count($h['diagram'], 'synohw-led-blank') === 3 && strpos($h['diagram'], '>LINK<') !== false
   && strpos($h['diagram'], 'LAN1 LED: link 1G (eth0, verified)') !== false, '');
ok('25k DS2415+ rear: USB 3.0 x4 (4 sockets, 1 in use = the Unraid flash on root-hub port 1, socket not claimed), expansion, console, RESET, LAN 1-4, power port',
   substr_count($h['diagram'], 'synohw-usb-socket') === 4 && strpos($h['diagram'], '>1 in use<') !== false && strpos($h['diagram'], 'Kingston DT Ultimate G3 (Unraid boot flash), root-hub port 1, 480 Mb/s') !== false
   && strpos($h['diagram'], 'Which socket is which root-hub port is not documented') !== false && strpos($h['diagram'], 'Console port (Synology: manufacturing use only)') !== false
   && strpos($h['diagram'], '>RESET<') !== false && preg_match('#>LAN 1</text>.*>LAN 2</text>.*>LAN 3</text>.*>LAN 4</text>#s', $h['diagram']) === 1 && substr_count($h['diagram'], 'Power port (AC in)') === 2, '');
ok('25k/26a DX1215 rear: expansion port + DEFAULT/MANUAL switch: bit 1 = DEFAULT (verified 2026-10-26); LINK green from the link',
   strpos($h['diagram'], 'DEFAULT/MANUAL switch: DEFAULT (verified)') !== false && strpos($h['diagram'], 'DEFAULT = auto power on/off with the DiskStation, MANUAL = no auto power on') !== false
   && strpos($h['diagram'], 'LINK LED: expected green') !== false && strpos($h['diagram'], 'Expansion port (from the DS2415+)') !== false, '');
ok('25k/26b cable: one path from the DS2415+ expansion port (508,120) up the gap (556), along the lane above the DX1215 (y -11), straight down over the DX1215 rear onto its expansion port (1059,92); drawing not mirrored',
   preg_match('#<path d="M508,120 H546 Q556,120 556,110 V-1 Q556,-11 566,-11 H1049 Q1059,-11 1059,-1 V92" class="synohw-cable synohw-cable-up synohw-anim synohw-flow"#', $h['diagram']) === 1
   && strpos($h['diagram'], '<g transform="translate(0,34)">') !== false && strpos($h['diagram'], 'viewBox="0 0 1104 358"') !== false, '');
ok('26b cable label "Expansion cable (InfiniBand, SATA ×4)" and hover title "Synology Cable InfiniBand SATAx4"',
   strpos($h['diagram'], '<title>Synology Cable InfiniBand SATAx4</title>') !== false && strpos($h['diagram'], '>Expansion cable (InfiniBand, SATA ×4): link up, the unit answers<') !== false, '');
$u = synohw_rear_usb(synohw_paths(['usbdev' => '/nonexistent']));
ok('25k USB: no sysfs = no device, no warning', $u['devices'] === [] && $u['controller'] === '', json_encode($u));
[$s, $h] = render('measured');
ok('status measured: only the two unassigned HDDs are disks (no phantom slots, no flash, no USB DOM)', array_column($s['disks']['list'], 'name') === ['sdd', 'sdc'] && array_column($s['disks']['list'], 'src') === ['unassigned', 'unassigned'], json_encode(array_column($s['disks']['list'], 'name')));
ok('status measured: hottest disk sdc 31 C, LOW 20 % / HIGH 20 %, not "all in standby"', strpos($h['fan'], 'Hottest disk (sdc)') !== false && strpos($h['fan'], '31&nbsp;&deg;C') !== false && strpos($h['fan'], 'all in standby') === false && preg_match('#<td><b>20 %</b></td><td><b>20 %</b></td>#', $h['fan']) === 1, $h['fan']);
ok('status measured: temps table rows sdd and sdc marked unassigned, no disk1..22', substr_count($h['temps'], '(unassigned)') === 2 && strpos($h['temps'], '>disk1<') === false && strpos($h['temps'], 'sdb') === false, $h['temps']);
$ov = json_decode(file_get_contents("$root/ov-measured.json"), true);
file_put_contents($ov['devs_ini'], "[\"dev1\"]\ndevice=\"sdd\"\nspundown=\"0\"\ntemp=\"*\"\n[\"dev2\"]\ndevice=\"sdb\"\ntemp=\"*\"\n[\"dev3\"]\ndevice=\"sdc\"\nspundown=\"0\"\ntemp=\"31\"\n");
file_put_contents("{$ov['run']}/history", "{$ov['now']} cpu=44 duty=70 sdd=? sdc=31\n", FILE_APPEND);
[$s, $h] = render('measured');
ok('status measured, sdd spun up with temp "*": no reading, floor 70 % in LOW and HIGH, not standby', $s['disks']['list'][0]['unreadable'] === true && $s['disks']['list'][0]['standby'] === false && strpos($h['fan'], 'no reading from sdd') !== false && preg_match('#<td><b>70 % \(a disk has no reading: floor 70 %, MIDDLE\)</b></td><td><b>70 % \(a disk has no reading#', $h['fan']) === 1, $h['fan']);
ok('status measured: history "?" counted as no reading, not standby', $s['hist']['sensors']['sdd']['unread'] === 1 && $s['hist']['sensors']['sdd']['standby'] === 0 && strpos($h['temps'], '1 no reading') !== false, json_encode($s['hist']['sensors']['sdd'] ?? null));
copy(dirname(__FILE__) . '/fixtures/measured-2026-10-25/devs.ini', $ov['devs_ini']);   // later tests use the measured file as is

// escaping: hostile values in every file the page reads must come out as text
$ov = json_decode(file_get_contents("$root/ov-normal.json"), true);
$evil = '<script>alert(1)</script>"\'&';
file_put_contents($ov['disks_ini'], file_get_contents($ov['disks_ini']) . "[\"x\"]\nname=\"$evil\"\ndevice=\"sd<b>z\"\ntemp=\"40\"\n[\"y\"]\nname=\"$evil\"\ndevice=\"sdz\"\nstatus=\"DISK_OK\"\nspundown=\"0\"\ntransport=\"$evil\"\ntemp=\"40\"\n");
file_put_contents("{$ov['run']}/state", file_get_contents("{$ov['run']}/state") . "mode=<img src=x onerror=alert(1)>\n");
file_put_contents("{$ov['run']}/mcu", "since=1\nlast=<b>\nlast_at=2\ncount_4b=1\n");
file_put_contents("{$ov['run']}/macs", "sn <i>evil</i>\n", FILE_APPEND);
[$s, $h] = render('normal');
$all = implode("\n", $h);
ok('escaping: no raw <script>, <img>, <b>, <i> from data files reaches the output', !preg_match('#<script|<img|<b>z|<i>evil|onerror=alert\(1\)>#', $all) && strpos($all, '&lt;script&gt;') !== false, substr($all, 0, 300));

// endpoint: GET -> 405 and nothing else; POST -> JSON with every block
$ep = "$plug/include/status.php";
$get = shell_exec('php -d display_errors=1 -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="GET"; require "' . $ep . '";') . ' 2>&1');
ok('endpoint: GET answers nothing (405)', $get === null || $get === '', var_export($get, true));
$post = shell_exec('php -d display_errors=1 -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="POST"; $GLOBALS["synohw_path_override"]=json_decode(file_get_contents("' . "$root/ov-hot.json" . '"),true); require "' . $ep . '";') . ' 2>&1');
$j = json_decode((string)$post, true);
ok('endpoint: POST without parameters returns ok JSON with every block: the ten of 25b-i first, then the tab blocks of 25j',
   is_array($j) && $j['ok'] === true && array_slice(array_keys($j['html']), 0, 10) === ['daemon', 'mcu', 'fan', 'temps', 'net', 'sys', 'cfgdiff', 'sweep', 'fans', 'dx']
   && array_slice(array_keys($j['html']), 10) === ['diagram', 'ov_ds', 'ov_dx', 'health', 'ds_now', 'temps_ds', 'mcu_link', 'dx_driver', 'dx_fans', 'temps_dx', 'dx_power', 'leds_ds', 'leds_dx', 'baymap', 'issues'], substr((string)$post, 0, 300));

// ---- graphs (2026.10.25f) ----
function ep(array $post, string $sc) {   // the endpoint as Unraid runs it: POST, override = fixture
  global $plug, $root;
  $code = '$_SERVER["REQUEST_METHOD"]="POST"; $_POST=json_decode(' . var_export(json_encode($post), true) . ',true); $GLOBALS["synohw_path_override"]=json_decode(file_get_contents("' . "$root/ov-$sc.json" . '"),true); require "' . "$plug/include/status.php" . '";';
  return json_decode((string)shell_exec('php -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1'), true);
}
$GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-graphs.json"), true);
$p = synohw_paths();
// box by sysfs: parity/disk1/disk2 on the 88SE9215 (internal), disk3/disk4 on the 88SE9235 behind port multipliers (DX1215)
$m = synohw_graph_meta($p);
ok('graphs: box from sysfs: 88SE9215 disks = DS2415+ internal, 88SE9235 + PMP disks = DX1215 with ata port and PMP port',
   $m['disks']['parity']['box'] === 'ds' && $m['disks']['disk2']['box'] === 'ds' && $m['disks']['disk3']['box'] === 'dx' && $m['disks']['disk4']['box'] === 'dx'
   && strpos($m['disks']['disk4']['via'], 'ata20 port multiplier port 2') !== false && strpos($m['disks']['disk4']['via'], '1b4b:9235') !== false
   && strpos($m['disks']['parity']['via'], '1b4b:9215') !== false, json_encode(array_map(fn($d) => $d['box'] . ' ' . $d['via'], $m['disks'])));
ok('graphs: model and serial split from the ini id; core labels from hwmon; running mode LOW',
   $m['disks']['disk3']['model'] === 'WDC WD40EFRX-68N32N0' && $m['disks']['disk3']['serial'] === 'WD-WCC7K0AAA4' && ($m['cores']['coretemp_temp2'] ?? '') === 'Core 0' && $m['mode'] === 'LOW');
// sysfs edge cases: no device link, USB, a PMP port on a non-9235 controller still counts as DX1215
$e = synohw_disk_box($p, 'sdz');
$u = synohw_disk_box(['sysblock' => json_decode(file_get_contents("$root/ov-measured.json"), true)['sysblock']] + $p, 'sdb');
$mm = synohw_disk_box(['sysblock' => json_decode(file_get_contents("$root/ov-measured.json"), true)['sysblock']] + $p, 'sdd');
ok('graphs: box edge cases: no sysfs entry = unknown with the reason, the USB DOM = usb, measured sdd [20:1:0:0] on ata21 = DX1215 PMP port 1',
   $e['box'] === 'unknown' && strpos($e['via'], 'no /sys/block/sdz/device') !== false && $u['box'] === 'usb' && $mm['box'] === 'dx' && $mm['pmp'] === 1 && $mm['ata'] === 21, json_encode([$e, $u, $mm]));
// history as columns: 24 h of the 26 h file, states per disk, the gap, the sweep, why/mode
$h = synohw_history_series($p, 86400);
$n = count($h['t']);
ok('graphs: 24 h range = only samples of the last 24 h (file holds 26 h), all columns the same length',
   $n > 2700 && $n < 2881 && min($h['t']) >= $p['now'] - 86400 && count($h['cpu']) === $n && count($h['duty']) === $n && strlen($h['why']) === $n
   && strlen($h['dst']['disk2']) === $n && count($h['cores']['coretemp_temp2']) === $n, "n=$n");
ok('graphs: standby is null + state s (never a temperature), numbers are ints with state n',
   in_array(null, $h['disks']['disk2'], true) && strpos($h['dst']['disk2'], 's') !== false && is_int($h['disks']['parity'][0]) && $h['dst']['parity'][0] === 'n');
$gap = 0; for ($i = 1; $i < $n; $i++) $gap = max($gap, $h['t'][$i] - $h['t'][$i - 1]);
ok('graphs: the 25 min daemon gap stays a gap (no invented rows)', $gap >= 1500, "max gap $gap");
$sw = array_filter($h['sweep']);
ok('graphs: sweep rows carry their step (1..4) and the duty sent (20/15/12/10), table duty stays the table\'s',
   count($sw) > 100 && array_values(array_unique($sw)) === [1, 2, 3, 4] && in_array(10, array_intersect_key($h['duty'], $sw), true) && !in_array(10, $h['want'], true));
ok('graphs: why/mode encoded one char per row (d/b, L)', strpbrk($h['why'], 'db') !== false && trim($h['mode'], 'L') === '');
$h1 = synohw_history_series($p, 3600);
ok('graphs: 1 h range = 120 samples', count($h1['t']) === 120, (string)count($h1['t']));
$d = synohw_history_series($p, 3600, $h1['t'][117]);
ok('graphs: since = live append: only the rows after it', $d['t'] === array_slice($h1['t'], 118), json_encode($d['t']));
// compatibility: 25b-e lines (no @ tokens) and 25f lines read the same for cpu/duty/disks; the old summary parser ignores new tokens
$GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-normal.json"), true);
$pn = synohw_paths(); $hn = synohw_history_series($pn, 86400);
ok('graphs compat: the normal fixture mixes old and new lines; old ones give why "-", new ones a reason and core temps',
   $hn['why'][0] === '-' && substr($hn['why'], -1) !== '-' && $hn['cores']['coretemp_temp2'][0] === null && end($hn['cores']['coretemp_temp2']) !== null);
$sn = synohw_history($pn);
ok('graphs compat: the min/max summary still sees only cpu + the 5 disks (no core:/@ keys as sensors), 720 samples',
   array_keys($sn['sensors']) == ['cpu', 'parity', 'disk1', 'disk2', 'disk3', 'disk4'] && $sn['samples'] === 720, json_encode(array_keys($sn['sensors'])));
// hostile history lines: odd tokens are skipped, nothing breaks, the series count stays bounded
$hf = "{$pn['run']}/history"; $keep = file_get_contents($hf);
$junk = '';
for ($i = 0; $i < 60; $i++) $junk .= "{$pn['now']} cpu=40 duty=20 d$i=30 core:c$i=1 @why=<b> @mode=x\"y bad=<script>\n";
file_put_contents($hf, $keep . "garbage line\n-5 cpu=1\n" . $junk, LOCK_EX);
$hb = synohw_history_series($pn, 3600);
ok('graphs: malformed lines skipped; at most 32 disk and 16 core series however many keys a file has',
   count((array)$hb['disks']) <= 32 && count((array)$hb['cores']) <= 16 && strpos(json_encode($hb), '<script') === false && strpos(json_encode($hb), '<b>') === false, count((array)$hb['disks']) . '/' . count((array)$hb['cores']));
file_put_contents($hf, $keep);
// sweep.csv as columns
$GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-graphs.json"), true);
$sw = synohw_sweep_series(synohw_paths());
ok('graphs: sweep.csv -> 160 rows, id = first epoch, steps 1..4, cpu_max + 4 cores + 4 disks, disk columns labelled by id -> device + box',
   count($sw['t']) === 160 && $sw['id'] === $sw['t'][0] && array_values(array_unique($sw['step'])) === [1, 2, 3, 4] && count($sw['cols']) === 9
   && $sw['disks']['disk:WDC_WD40EFRX-68N32N0_WD-WCC7K0AAA4']['box'] === 'dx' && $sw['disks']['disk:ST4000VN008-2DR166_ZDH0AAA1']['device'] === 'sdb', json_encode(array_keys($sw['cols'])));
$sw2 = synohw_sweep_series(synohw_paths(), $sw['t'][157]);
ok('graphs: sweep since -> only the newer rows, same id', count($sw2['t']) === 2 && $sw2['id'] === $sw['id']);
ok('graphs: no sweep.csv -> exists false, no rows', synohw_sweep_series(['run' => '/nonexistent'] + synohw_paths())['exists'] === false);
// endpoint
$r = ep(['action' => 'history', 'range' => '21600'], 'graphs');
ok('endpoint history: ok, range 6 h, meta with tables/disks/cores, 720 rows', ($r['ok'] ?? false) === true && $r['hist']['range'] === 21600 && count($r['hist']['t']) === 720
   && isset($r['meta']['tables']['LOW'], $r['meta']['disks']['disk3']) && !isset($r['html']), json_encode(array_keys($r ?? [])));
$r = ep(['range' => '3600', 'since' => (string)$h1['t'][118], 'sweep_since' => '0', 'sweep_id' => '0'], 'graphs');
ok('endpoint poll: live HTML + the 1 new history row + the whole sweep (first time)', isset($r['html']['temps']) && count($r['hist']['t']) === 1 && count($r['sweep']['t']) === 160);
$r = ep(['range' => '3600', 'since' => '0', 'sweep_since' => (string)$sw['t'][159], 'sweep_id' => '12345'], 'graphs');
ok('endpoint poll: a different sweep id -> the whole new sweep with reset', ($r['sweep']['reset'] ?? false) === true && count($r['sweep']['t']) === 160);
$r = ep([], 'graphs');
ok('endpoint poll without graph parameters: unchanged reply (no hist, no sweep)', isset($r['html']) && !isset($r['hist']) && !isset($r['sweep']));
foreach ([['action' => 'history', 'range' => '7200'], ['action' => 'history', 'range' => '3600; rm -rf /'], ['range' => '3600', 'since' => '-1'], ['action' => 'drop'], ['action' => 'history', 'range' => ['3600']]] as $bad) {
  $r = ep($bad, 'graphs');
  ok('endpoint refuses ' . json_encode($bad) . ' with ok=false', is_array($r) && ($r['ok'] ?? null) === false, json_encode($r));
}

// ---- Hardware section (2026.10.25g): sysfs + the inventory cache, read-only ----
require_once "$plug/include/synohw-hw.php";
function hw($sc) { global $root; $GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-$sc.json"), true); $c = synohw_hw_collect(synohw_paths()); return [$c, synohw_render_hw($c)]; }
[$c, $hh] = hw('graphs');
$pci = []; foreach ($c['pci'] as $d) $pci[$d['addr']] = $d;
ok('hw pci: 25 devices of the measured lspci, names from the built-in map, AER counters for the 17 PCIe functions, none downtrained',
   count($c['pci']) === 25 && count(array_filter($c['pci'], fn($d) => $d['aer'] !== null)) === 17 && !array_filter($c['pci'], fn($d) => $d['down'])
   && ($pci['0000:07:00.0']['id'] ?? '') === '1b4b:9235', json_encode(array_column($c['pci'], 'id')));
ok('hw pci: a x4 root port feeding a x2 device is "narrow" (wiring), not downtrained', $pci['0000:00:01.0']['narrow'] === true && $pci['0000:00:01.0']['down'] === false);
$links = []; foreach ($c['ata'] as $l) $links[$l['link']] = $l;
ok('hw ata: internal links on the 88SE9215, DX1215 PMP host links + PMP ports 19.0 / 20.2 on the 88SE9235, disks attached, link15 control ports skipped',
   $links['link1']['box'] === 'DS2415+ internal' && $links['link19']['box'] === 'DX1215' && $links['link19.0']['pmp'] === 0 && $links['link20.2']['pmp'] === 2
   && $links['link19.0']['disk']['device'] === 'sde' && $links['link20.2']['disk']['device'] === 'sdf' && !preg_grep('/\.15$/', array_keys($links)), implode(' ', array_keys($links)));
ok('hw ata: a 3.0 Gbps link is shown and flagged', $links['link2']['spd'] === '3.0 Gbps' && substr_count($hh, '<span class="orange-text">3.0 Gbps</span>') === 1);
$sm = $c['smart']['disks'];
ok('hw smart: cache parsed (health, power-on hours, load cycle raw + normalized), serials from the fake fixtures',
   $sm['sdb']['health'] === 'PASSED' && $sm['sdb']['attr']['9']['rawn'] === 23459 && $sm['sdc']['attr']['9']['rawn'] === 36199
   && $sm['sdc']['attr']['193']['value'] === 15 && $sm['sdc']['attr']['193']['rawn'] === 170472 && $sm['sdb']['info']['Serial Number'] === 'WD-WCC0000FAKE1');
ok('hw smart: SATA phy event counters (COMRESET 67 on the Seagate)', ($sm['sdc']['phy']['10']['value'] ?? null) === 67);
ok('hw smart: the standby disk has no cache file and is shown as not read (never woken); inventory summary in the text',
   !isset($sm['sdd']) && strpos($hh, 'standby: not read (never woken)') !== false && strpos($hh, 'standby skipped') !== false);
ok('hw smart: load cycle normalized 15 is flagged (vendor threshold 25 or below)', strpos($hh, '170 472<br><span class="orange-text">normalized 15 (threshold 0)</span>') !== false && strpos($hh, '6 016<br><span class="orange-text">') === false);
$roles = array_column($c['usb'], 'role', 'dev');
ok('hw usb: DOM f400:f400 and the Unraid boot flash (by disks.ini flash) recognised, root hubs with their PCI address',
   strpos($roles['1-1.1'] ?? '', 'Synology DOM') === 0 && ($roles['2-1'] ?? '') === 'Unraid boot flash' && ($roles['usb2'] ?? '') === 'root hub (0000:04:00.0)', json_encode($roles));
$n0 = $c['net'][0];
ok('hw net: link/speed/duplex/driver from sysfs, WoL from the ethtool cache, no-carrier port has speed -1',
   $n0['nic'] === 'eth0' && $n0['speed'] === 1000 && $n0['duplex'] === 'full' && $n0['driver'] === 'igb' && $n0['eth']['wol'] === 'g' && $c['net'][1]['speed'] === -1);
ok('hw cpu: model, 4 cores, governor, C-states with residency', $c['cpu']['model'] === 'Intel(R) Atom(TM) CPU C2538 @ 2.40GHz' && count($c['cpu']['cores']) === 4
   && $c['cpu']['cores'][0]['gov'] === 'ondemand' && array_column($c['cpu']['cores'][0]['idle'], 'name') === ['POLL', 'C1', 'C6']);
ok('hw system: memory, uptime, kernel; MCU row with rates and the firmware version status',
   $c['sys']['mem']['MemTotal'] === 16754840 && $c['sys']['uptime'] === 57906 && $c['sys']['release'] === '6.18.58-Unraid'
   && strpos($hh, 'MCU (ttyS1)') !== false && strpos($hh, 'no reply to O') !== false);
$ab = array_column($c['absent'], 3, 0);
ok('hw absent: 12 rows each with a #n source; DMI, EDAC, RAPL, watchdog, MCU version among them',
   count($c['absent']) === 12 && !array_filter($c['absent'], fn($r) => !preg_match('/\(#\d+\)/', $r[3]))
   && isset($ab['SMBIOS / DMI (board, BIOS version, DIMMs)'], $ab['ECC / EDAC memory error counters'], $ab['Power draw (RAPL)'], $ab['Hardware watchdog'], $ab['MCU firmware version']));
ok('hw headings: all 8 sections', substr_count($hh, '<h3 class="synohw-hw-title">') === 8);
// a value from a cache file is data: escaped
$inv = json_decode(file_get_contents("$root/ov-graphs.json"), true)['run'] . '/inventory';
$orig = file_get_contents("$inv/smart-sdb.txt");
file_put_contents("$inv/smart-sdb.txt", str_replace('WDC WD1003FZEX-00MK2A0', 'WD <img src=x onerror=alert(1)>', $orig));
[$c2, $hh2] = hw('graphs');
ok('hw: SMART text from the cache is HTML-escaped', strpos($hh2, '<img') === false && strpos($hh2, '&lt;img') !== false);
file_put_contents("$inv/smart-sdb.txt", $orig);
[$c3, $hh3] = hw('notty');
ok('hw notty (no inventory yet): says how it gets filled, still renders every section', strpos($hh3, 'INVENTORY_PERIOD') !== false && substr_count($hh3, '<h3 class="synohw-hw-title">') === 8);
ok('hw notty: no cpu directories still means "no thermal_throttle" (absent), nothing flagged as found', strpos($hh3, 'found now') === false);
$ovg = json_decode(file_get_contents("$root/ov-graphs.json"), true);
$GLOBALS["synohw_path_override"] = $ovg; $wd = synohw_hw_paths(synohw_paths())["watchdog"];
@mkdir("$wd/watchdog0", 0777, true);
[$c5, $hh5] = hw('graphs');
ok('hw absent: a watchdog device appearing is flagged orange as "found now", not silently listed as absent',
   preg_match('#<span class="orange-text">found now: the check &quot;no /sys/class/watchdog entries&quot; no longer holds#', $hh5) === 1, $wd);
rmdir("$wd/watchdog0");
[$c4, $hh4] = hw('measured');
ok('hw measured fixture: renders, PCI + SATA tables present', count($c4['pci']) > 0 && strpos($hh4, 'SATA links') !== false);
$r = ep(['action' => 'hardware'], 'graphs');
ok('endpoint hardware: ok + the Hardware HTML, nothing else', ($r['ok'] ?? false) === true && strpos($r['html'] ?? '', 'PCIe devices') !== false && !isset($r['hist']), json_encode(array_keys($r ?? [])));

// ---- DX1215 + fan modes (2026.10.25h) ----
[$s, $h] = render('normal');
ok('dx normal: module 0.4.0 + plugin version + hwmon, unit DX1215 unique 0x13 master host18, values from the daemon copy',
   strpos($h['dx'], 'synology_dx 0.4.0 loaded') !== false && strpos($h['dx'], 'plugin synology-expansion 2026.10.25b') !== false && strpos($h['dx'], 'hwmon hwmon2') !== false
   && strpos($h['dx'], 'DX1215 <span class="grey-text">(unique 0x13') !== false && strpos($h['dx'], 'host18') !== false && strpos($h['dx'], "the daemon's copy from") !== false && $s['dx']['src'] === 'daemon', $h['dx']);
ok('dx normal: level low with code, both alarms 0, power on, PSU and switch raw with "polarity unknown" / "meaning unknown"',
   strpos($h['dx'], '<b>low</b> (CPLD code 2') !== false && substr_count($h['dx'], 'no alarm (0)') === 2 && strpos($h['dx'], 'on (power_on 1)') !== false
   && strpos($h['dx'], 'raw bits 0 / 0') !== false && strpos($h['dx'], 'Polarity unknown') !== false && strpos($h['dx'], 'DEFAULT (verified)') !== false && strpos($h['dx'], 'the switch on DEFAULT reads 1') !== false, $h['dx']);
ok('dx normal: the DX1215 disks by sysfs box (disk3 sde PMP port 0 on ata19 41 C, disk4 standby), never the internal ones',
   strpos($h['dx'], 'disk3 (sde, PMP port 0 on ata19): 41&nbsp;&deg;C') !== false && strpos($h['dx'], 'disk4 (sdf, PMP port 2 on ata20): standby') !== false && strpos($h['dx'], 'parity (') === false, $h['dx']);
ok('dx normal: the decision line (mode AUTO, input disk3 41 C, wanted/reads low, reason)', strpos($h['dx'], 'mode <b>AUTO</b>') !== false && strpos($h['dx'], 'wanted <b>low</b>, reads <b>low</b>') !== false, $h['dx']);
ok('fans normal: one row per box with mode, input, output, reason', strpos($h['fans'], '<td>DS2415+ fan</td><td>AUTO, ') !== false && strpos($h['fans'], '<td>DX1215 fans</td><td>AUTO</td>') !== false
   && strpos($h['fans'], '<b>20 %</b> sent') !== false && strpos($h['fans'], '<b>low</b>') !== false, $h['fans']);
[$s, $h] = render('dxalarm');
ok('dx alarm: fan 1 ALARM red, level full, decision says alarm, fans row flags the alarm', strpos($h['dx'], 'ALARM (1): fan 1') !== false && strpos($h['dx'], '<b>full</b> (CPLD code 0') !== false
   && strpos($h['dx'], 'a fan alarm bit is set') !== false && strpos($h['fans'], '<span class="red-text">fan alarm</span>') !== false, $h['dx']);
[$s, $h] = render('nodx');
ok('dx nodx: plugin installed under its old name synology-dx, module not loaded: says so with the loader status, nothing written', strpos($h['dx'], 'expansion module (synology_dx) not installed or not loaded') !== false && strpos($h['dx'], 'The synology-dx plugin is installed') !== false
   && strpos($h['dx'], 'no synology_dx build for kernel 6.18.99-Unraid') !== false && strpos($h['fans'], 'not controlled') !== false && strpos($h['dx'], 'nothing written') !== false, $h['dx']);
[$s, $h] = render('notty');
ok('dx notty: no plugin at all -> "Install the expansion plugin"', strpos($h['dx'], 'Install the expansion plugin') !== false && !$s['dx']['loaded'], $h['dx']);
[$s, $h] = render('stopped');
ok('dx stopped daemon, module loaded: values read from sysfs directly, said so; fans block says nobody controls them',
   $s['dx']['src'] === 'sysfs' && strpos($h['dx'], 'sysfs directly (the daemon is not running)') !== false && strpos($h['fans'], 'nobody controls the fans') !== false, $h['dx']);
$ov = json_decode(file_get_contents("$root/ov-normal.json"), true);
$keep = file_get_contents("{$ov['run']}/dx");
file_put_contents("{$ov['run']}/dx", $keep . "model=<script>alert(1)</script>\nlast_error=<img src=x onerror=alert(2)>\n");
[$s, $h] = render('normal');
ok('dx escaping: model / last_error from the daemon copy come out as text', strpos($h['dx'], '<script') === false && strpos($h['dx'], '<img') === false && strpos($h['dx'], '&lt;script&gt;') !== false, $h['dx']);
file_put_contents("{$ov['run']}/dx", $keep);
file_put_contents("{$ov['run']}/dx", str_replace('at=' . ($ov['now'] - 20), 'at=' . ($ov['now'] - 3600), $keep));
[$s, $h] = render('normal');
ok('dx: a stale daemon copy (1 h old) is not shown as current: falls back to sysfs', $s['dx']['src'] === 'sysfs', $s['dx']['src']);
file_put_contents("{$ov['run']}/dx", $keep);
// manual DS mode in the decision text
$stf = "{$ov['run']}/state"; $sk = file_get_contents($stf);
file_put_contents($stf, str_replace(["ds_mode=AUTO", "chosen=both"], ["ds_mode=MANUAL", "chosen=manual"], $sk) . "ds_duty=30\n");
[$s, $h] = render('normal');
ok('fans/fan block in DS MANUAL: mode "MANUAL 30 %", reason, "MANUAL 30 % is set"', strpos($h['fans'], 'MANUAL 30 %') !== false && strpos($h['fans'], 'MANUAL: the set duty') !== false && strpos($h['fan'], '<b>MANUAL 30 %</b> is set') !== false, $h['fans']);
file_put_contents($stf, $sk);
// history: the 25h tokens
$GLOBALS['synohw_path_override'] = json_decode(file_get_contents("$root/ov-graphs.json"), true);
$hg = synohw_history_series(synohw_paths(), 86400);
$n = count($hg['t']);
ok('graphs 25h: dx/dxw/dxy/dsm one char per row, dxal one int per row; low/middle/full recorded, an alarm period (A, dxal 1), rows before 25h "-"',
   strlen($hg['dx']) === $n && strlen($hg['dxw']) === $n && strlen($hg['dxy']) === $n && strlen($hg['dsm']) === $n && count($hg['dxal']) === $n
   && strpbrk($hg['dx'], 'l') !== false && strpbrk($hg['dx'], 'f') !== false && strpos($hg['dxy'], 'A') !== false && in_array(1, $hg['dxal'], true)
   && $hg['dx'][0] === '-' && $hg['dxal'][0] === null && substr($hg['dsm'], -1) === 'A', substr($hg['dx'], -40) . ' ' . substr($hg['dxy'], -40));
$sn = synohw_history(synohw_paths());
ok('graphs 25h: the min/max summary still sees no @ key as a sensor', !array_filter(array_keys($sn['sensors']), fn($k) => $k[0] === '@' || strpos($k, 'dx') === 0), json_encode(array_keys($sn['sensors'])));
// PCIe: on-chip I354 with "Unknown" link files
[$c, $hh] = hw('graphs');
$pci = []; foreach ($c['pci'] as $d) $pci[$d['addr']] = $d;
ok('hw pci 25h: I354 0000:00:14.x with "Unknown" x0 / "Unknown" x255 -> no link (on-chip), never "Unknown x0"',
   $pci['0000:00:14.0']['cur'] === '' && $pci['0000:00:14.3']['max'] === '' && !$pci['0000:00:14.0']['narrow'] && strpos($hh, 'Unknown x') === false
   && preg_match('#0000:00:14\.0</td><td>Intel I354[^<]*<span class="grey-text">\[8086:1f41\]</span></td><td>igb</td><td><span class="grey-text">no PCIe link \(on-chip\)</span></td><td>-</td>#', $hh) === 1, json_encode($pci['0000:00:14.0']));
ok('hw absent 25h: the synology_dx hwmon does not flip "Board fans" to "found now"', strpos($hh, 'found now') === false);
$r = ep([], 'dxalarm');
ok('endpoint dxalarm: live reply carries the fans and dx blocks', isset($r['html']['dx'], $r['html']['fans']) && strpos($r['html']['dx'], 'ALARM') !== false);
file_put_contents("$root/php-dx.json", json_encode(SYNOHW_DX_TABLE));

// ---- 2026.10.25j: tabbed page blocks ----
// the tests above appended hostile values to the normal fixture: rebuild it clean
shell_exec('rm -rf ' . escapeshellarg("$root/normal") . ' && python3 -I ' . escapeshellarg(__DIR__ . '/mkfixture.py') . ' ' . escapeshellarg("$root/normal") . ' normal > ' . escapeshellarg("$root/ov-normal.json"));
$r = ep(['blocks' => 'ov_ds,diagram'], 'normal');
ok('endpoint 25j: blocks=ov_ds,diagram renders exactly those two', ($r['ok'] ?? false) && array_keys($r['html']) === ['diagram', 'ov_ds'], json_encode(array_keys($r['html'] ?? [])));
$r = ep(['blocks' => ''], 'normal');
ok('endpoint 25j: blocks= (empty) renders no block (graph rows only)', ($r['ok'] ?? false) && $r['html'] === [], json_encode($r));
foreach (['ov_ds,nosuch', 'ov_ds;x', str_repeat('a,', 30) . 'a', '../x'] as $bad) { $r = ep(['blocks' => $bad], 'normal'); ok("endpoint 25j: bad blocks '" . substr($bad, 0, 12) . "' refused", ($r['ok'] ?? null) === false && ($r['error'] ?? '') === 'bad parameter blocks', json_encode($r)); }
[$s, $h] = render('normal');
ok('25j models: DS2415+ by PCI topology, DX1215 by module model + unique 0x13, both drawn', $s['model']['main']['model'] === 'DS2415+' && $s['model']['main']['drawn'] && $s['model']['dx']['model'] === 'DX1215' && $s['model']['dx']['drawn'], json_encode($s['model']));
ok('25j bays (normal): internal disks on 05:00.0 port 1-3 = bays 5-7 (DiskIdxMap 04), DX disk3 ata19 PMP 0 = bay 1, disk4 ata20 PMP 2 = bay 6',
   $s['disk_box']['sdb']['bay'] === 5 && $s['disk_box']['sdd']['bay'] === 7 && $s['disk_box']['sde']['bay'] === 1 && $s['disk_box']['sdf']['bay'] === 6, json_encode(array_map(fn($b) => [$b['box'], $b['bay']], $s['disk_box'])));
ok('25j diagram (normal): both units drawn with 24 bays, 3 + 2 populated and focusable, fans spinning at the commanded level (20 % -> 2.40 s, DX low -> 2.00 s), cable animated, "not RPM" and "not verified" said',
   substr_count($h['diagram'], 'class="synohw-bay ') === 24 && substr_count($h['diagram'], 'tabindex="0" role="img"') === 5 && substr_count($h['diagram'], '--synohw-spin:2.40s') === 2 && substr_count($h['diagram'], '--synohw-spin:2.00s') === 2
   && preg_match('/class="synohw-cable synohw-cable-up synohw-anim synohw-flow"/', $h['diagram']) === 1 && strpos($h['diagram'], 'not a measured RPM') !== false && strpos($h['diagram'], 'Bays: confirmed at the box by blink count') !== false && strpos($h['diagram'], 'inferred') !== false
   && preg_match('/role="group" aria-label="Hardware diagram, front and rear of each unit\. [^"]*DS2415\+: 3 disk\(s\), fans 20 % commanded/', $h['diagram']) === 1, substr($h['diagram'], 0, 400));
ok('25j diagram: bay text carries the values (bay 5 parity, 38 C, band label), never colour alone', preg_match('/aria-label="DS2415\+ bay 5: WDC_WD40EFRX-68N32N0_[A-Za-z0-9-]+ · sdb · Parity · 38 °C \(LOW table: lowest row[^"]*\) · LED state unknown[^"]*"/', $h['diagram']) === 1, '');
ok('25j diagram: link by carrier: eth0 up at 1G with an animated wire, eth1-3 no carrier = off; LAN role eth0 in bond0', substr_count($h['diagram'], 'synohw-rj45 synohw-rj45-up') === 1 && substr_count($h['diagram'], 'synohw-wire synohw-anim synohw-flow') === 1 && strpos($h['diagram'], 'LAN 1 1G · LAN 2 off · LAN 3 off · LAN 4 off') !== false && strpos($h['diagram'], 'LAN 2-4 = eth1-3 inferred') !== false && strpos($h['diagram'], 'LAN: eth0 in bond0') !== false, '');
ok('25j cards: DS card says the fan table input is a DX1215 disk (hottest of both units) with its badge; DX card verdict green', strpos($h['ov_ds'], 'the hottest disk of both units: disk3 41') !== false && strpos($h['ov_ds'], 'synohw-badge-dx') !== false
   && strpos($h['ov_dx'], 'Driver loaded: DX1215 found and responding') !== false, $h['ov_ds']);
ok('25j per-unit temperature tables: DS has CPU + parity/disk1/disk2 and no DX disk; DX has disk3/disk4 with bays and margins to the DX rows (48 - 41 = 7)',
   strpos($h['temps_ds'], 'CPU (max of 4)') !== false && strpos($h['temps_ds'], '<td>disk3') === false && strpos($h['temps_ds'], '<td>parity') !== false
   && strpos($h['temps_dx'], '<td>disk3') !== false && strpos($h['temps_dx'], 'CPU (max') === false && strpos($h['temps_dx'], 'bay 1</span>') !== false && strpos($h['temps_dx'], '7&nbsp;&deg;C away') !== false, $h['temps_dx']);
ok('25j DX tab blocks hold every row of the old DX table (driver + fans + power + disks)', count(array_filter(['Module', 'Unit', 'Values from', 'Last module error'], fn($k) => strpos($h['dx_driver'], "<td>$k</td>") !== false)) === 4
   && count(array_filter(['Fan control', 'Fan level now', 'Fan 1 alarm', 'Fan 2 alarm', 'Disks in the DX1215'], fn($k) => strpos($h['dx_fans'], "<td>$k</td>") !== false)) === 5
   && count(array_filter(['Power', 'PSU 1 / PSU 2', 'Switch mode'], fn($k) => strpos($h['dx_power'], "<td>$k</td>") !== false)) === 3, '');
ok('25j health block: Daemon, Serial port, Expansion driver, Models, then the sys rows', preg_match('#<td>Daemon</td>.*<td>Serial port</td>.*<td>Expansion driver</td>.*<td>Models</td>.*<td>Plugin version</td>.*<td>udev rule</td>.*<td>Watchdog cron</td>#s', $h['health']) === 1, '');
[$s, $h] = render('measured');
ok('25j bays (measured topology): sdc ata7 on 03:00.0 port_no 1 = DS bay 1; sdd ata21 PMP port 1 = DX bay 3*(3-1)+1+1 = 8', $s['disk_box']['sdc']['bay'] === 1 && $s['disk_box']['sdd']['bay'] === 8 && $s['disk_box']['sdd']['box'] === 'dx', json_encode($s['disk_box']));
[$s, $h] = render('dxalarm');
ok('25j diagram (dxalarm): DX fan 1 drawn stopped and red with "ALARM" (no spin class), fan 2 still turning at full (0.60 s)', preg_match('#<g class="synohw-fan synohw-fan-alarm"[^>]*><circle r="27" class="synohw-fan-ring"/><g class="synohw-fan-rotor">#', $h['diagram']) === 1
   && strpos($h['diagram'], 'fan 1: ALARM') !== false && substr_count($h['diagram'], '--synohw-spin:0.60s') === 1 && strpos($h['ov_dx'], 'fan 1 ALARM') !== false, '');
[$s, $h] = render('unknown');
ok('25j unknown models: main unit not identified, DX517 (0x15) not drawn: plain pictures with the name, no bay, no fan, no animation; cards still full',
   !$s['model']['main']['drawn'] && $s['model']['dx']['model'] === 'DX517' && !$s['model']['dx']['drawn'] && strpos($h['diagram'], 'Main unit: model not identified (not yet drawn)') !== false
   && strpos($h['diagram'], 'Synology DX517 (unique 0x15) (not yet drawn)') !== false && strpos($h['diagram'], 'synohw-bay') === false && strpos($h['diagram'], 'synohw-anim') === false
   && strpos($h['ov_ds'], 'Fan output') !== false && strpos($h['ov_dx'], 'Fan level') !== false && strpos($h['ov_dx'], 'DX517') !== false, substr($h['diagram'], 0, 300));
[$s, $h] = render('nodx');
ok('25j diagram (nodx): DX disks visible but no module: plain "Expansion unit: model unknown" picture, cable static', strpos($h['diagram'], 'Expansion unit: model unknown (not yet drawn)') !== false && strpos($h['diagram'], 'synohw-cable-up"') !== false && strpos($h['ov_dx'], 'Driver not loaded') !== false, '');
[$s, $h] = render('stopped');
ok('25j diagram (stopped daemon): DS2415+ fans drawn idle with "not controlled"; the DX1215 fans keep the level the unit reads (low, 2.00 s: the CPLD holds it)',
   substr_count($h['diagram'], 'synohw-fan synohw-fan-idle') === 2 && substr_count($h['diagram'], '>not controlled<') === 2 && substr_count($h['diagram'], '--synohw-spin:2.00s') === 2 && strpos($h['ov_ds'], 'not controlled') !== false, '');

// 2026.10.26a bay LEDs: the normal fixture plus driver LED files (copied dxsys + run) and the daemon's health record
$ov = json_decode(file_get_contents("$root/ov-normal.json"), true);
$L = "$root/leds-normal"; shell_exec('rm -rf ' . escapeshellarg($L) . '; mkdir -p ' . escapeshellarg($L) . ' && cp -a ' . escapeshellarg($ov['dxsys']) . ' ' . escapeshellarg("$L/dx") . ' && cp -a ' . escapeshellarg($ov['run']) . ' ' . escapeshellarg("$L/run"));
$pf = function ($f, $c) { if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true); file_put_contents($f, $c); };
foreach (['0000:03:00.0', '0000:05:00.0', '0000:06:00.0'] as $pci) { $pf("$L/dx/ds/$pci/leds_enabled", "1\n"); $pf("$L/dx/ds/$pci/fault_mask", $pci === '0000:05:00.0' ? "1\n" : "0\n"); }
$pf("$L/dx/ds/pch_led_enable", "1\n"); $pf("$L/dx/ds/pch", "state ok\ngpiobase 0x500\n");
foreach ([18, 19, 20, 21] as $i => $hn) { $pf("$L/dx/host$hn/emid", "$i\n"); $pf("$L/dx/host$hn/ata_port", 'ata' . ($hn + 1) . "\n"); }
foreach (glob("$L/dx/host*", GLOB_ONLYDIR) as $hd) { $pf("$hd/led_mask", "0\n"); $pf("$hd/fault", basename($hd) === 'host19' ? "4\n" : "0\n"); }
$pf("$L/run/leds", "#at " . time() . "\n#health yes\nds\t5\tsdb\t1\tSMART 5 Reallocated_Sector_Ct FAILING_NOW\ndx\t1\tsde\t0\thealthy: SMART attributes ok\n");
$GLOBALS['synohw_path_override'] = array_merge($ov, ['dxsys' => "$L/dx", 'run' => "$L/run"]);
$s = synohw_collect(synohw_paths()); $h = synohw_render_all($s);
$hosts = array_map('basename', glob("$L/dx/host*", GLOB_ONLYDIR));
ok('26a bay LEDs: 12 DS bays, orange on DS bay 5 only (05:00.0 fault_mask bit 0), green elsewhere', count($s['bayleds']['ds']) === 12 && $s['bayleds']['ds'][5]['orange'] === 'on' && $s['bayleds']['ds'][1]['orange'] === 'off' && $s['bayleds']['ds'][1]['green'] === 'on', json_encode($s['bayleds']['ds']));
ok('26a bay LEDs: DX bays from hostN/emid (' . implode(',', $hosts) . '), orange where hostN/fault has the bit', count($s['bayleds']['dx']) === 3 * count(array_filter($hosts, fn($x) => preg_match('/^[0-3]$/', trim((string)@file_get_contents("$L/dx/$x/emid"))))), json_encode($s['bayleds']['dx']));
ok('26a diagram: DS bay 5 tray LED orange with the health reason in its label; legend has the three LED keys',
   preg_match('/aria-label="DS2415\+ bay 5: [^"]* · sdb · Parity · [^"]*LED orange \(fault\): SMART 5 Reallocated_Sector_Ct FAILING_NOW"/', $h['diagram']) === 1 && substr_count($h['diagram'], 'class="synohw-led-orange"') >= 2 && strpos($h['diagram'], 'tray LED orange: fault (disk health)') !== false, '');
ok('26a DS tab LED table: bay 5 parity ON (fault) with the reason, PCH GPIO 49 high, link to the expansion settings',
   strpos($h['leds_ds'], '<td>5</td><td>parity (sdb)</td>') !== false && strpos($h['leds_ds'], 'ON (fault)') !== false && strpos($h['leds_ds'], 'SMART 5 Reallocated_Sector_Ct FAILING_NOW') !== false
   && strpos($h['leds_ds'], 'Orange enable (PCH GPIO 49):') !== false && strpos($h['leds_ds'], 'href="/Utilities/SynologyExpansion"') !== false, $h['leds_ds']);
ok('26a DX tab LED table: 12 rows, orange on DX bay 6 only (host19 = EMID 1, fault bit 2), disk3 (sde) in bay 1 healthy', substr_count($h['leds_dx'], '<tr><td>') === 13 && $s['bayleds']['dx'][6]['orange'] === 'on'
   && count(array_filter($s['bayleds']['dx'], fn($x) => $x['orange'] === 'on')) === 1 && strpos($h['leds_dx'], '<td>1</td><td>disk3 (sde)</td>') !== false && strpos($h['leds_dx'], 'healthy: SMART attributes ok') !== false, $h['leds_dx']);
[$s, $h] = render('normal');
ok('26a without driver LED files: the LED tables say so instead of a table', strpos($h['leds_ds'] . $h['leds_dx'], '<table') === false, $h['leds_ds']);

// ---- 2026.10.26b: Unraid slot names, device ids, Bay map, empty-bay LEDs, Main page badge endpoint, Known issues + firmware ----
ok('26b slot names as Unraid shows them (my_disk): Parity, Parity 2, Disk 1, Disk 12, Cache (pool), Fast 2 (pool), Unassigned (Dev 3)',
   synohw_unraid_slot('array', 'parity', 'Parity') === 'Parity' && synohw_unraid_slot('array', 'parity2', 'Parity') === 'Parity 2' && synohw_unraid_slot('array', 'disk1', 'Data') === 'Disk 1'
   && synohw_unraid_slot('array', 'disk12', 'Data') === 'Disk 12' && synohw_unraid_slot('array', 'cache', 'Cache') === 'Cache (pool)' && synohw_unraid_slot('array', 'fast2', 'Cache') === 'Fast 2 (pool)'
   && synohw_unraid_slot('unassigned', 'dev3', '') === 'Unassigned (Dev 3)' && synohw_unraid_slot('array', '<b>', 'Data') === 'array slot');
ok('26b device id as Unraid prints it (my_id): a trailing _3<wwn> is dropped, other ids kept',
   synohw_unraid_id('ST4000VN008-2DR166_ZDH12345_35000c500a1b2c3d4') === 'ST4000VN008-2DR166_ZDH12345' && synohw_unraid_id('WDC_WD1003FZEX-00MK2A0_WD-WCC4E0000001') === 'WDC_WD1003FZEX-00MK2A0_WD-WCC4E0000001' && synohw_unraid_id('') === '-');
[$s, $h] = render('normal');
$rows = synohw_bay_rows($s);
ok('26b bay rows: DS bays 5, 6, 7 then DX bays 1, 6, each with sdX and Unraid slot', array_map(fn($r) => $r['short'] . $r['bay'] . ':' . $r['device'] . ':' . $r['slot'], $rows) === ['DS5:sdb:Parity', 'DS6:sdc:Disk 1', 'DS7:sdd:Disk 2', 'DX1:sde:Disk 3', 'DX6:sdf:Disk 4'], json_encode(array_map(fn($r) => [$r['short'], $r['bay'], $r['device'], $r['slot']], $rows)));
ok('26b Bay map table: one row per disk, temperature from Unraid (38 °C, standby), LED column, empty bays counted not listed',
   substr_count($h['baymap'], '<tr><td>') === 6 && strpos($h['baymap'], '<td>sdb</td><td>Parity</td><td>38 °C</td>') !== false && strpos($h['baymap'], '<td>sdd</td><td>Disk 2</td><td>standby</td>') !== false
   && strpos($h['baymap'], 'DS2415+: 3 of 12 bays populated; DX1215: 2 of 12') !== false, $h['baymap']);
[$s, $h] = render('measured');
ok('26b Bay map (measured unit): sdc DS bay 1 and sdd DX bay 8, both "Unassigned (Dev N)" as in devs.ini',
   preg_match('#<b>1</b></td><td class="whitespace-normal">[^<]*</td><td>sdc</td><td>Unassigned \(Dev 3\)</td>#', $h['baymap']) === 1 && preg_match('#<b>8</b></td><td class="whitespace-normal">[^<]*</td><td>sdd</td><td>Unassigned \(Dev 1\)</td>#', $h['baymap']) === 1, $h['baymap']);
$GLOBALS['synohw_path_override'] = array_merge(json_decode(file_get_contents("$root/ov-normal.json"), true), ['dxsys' => "$L/dx", 'run' => "$L/run"]);
$s = synohw_collect(synohw_paths()); $h = synohw_render_all($s);
ok('26b LED tables: an empty bay reads "dark (no disk)" for green and orange, never "on" (DS 9 empty bays, DX 10)',
   substr_count($h['leds_ds'], 'dark (no disk)') === 18 && substr_count($h['leds_dx'], 'dark (no disk)') === 20 && !preg_match('#empty</span></td><td><svg[^>]*><circle[^>]*synohw-led-green#', $h['leds_ds'] . $h['leds_dx']), $h['leds_ds']);
$bp = "$plug/include/bays.php";
$bget = shell_exec('php -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="GET"; require "' . $bp . '";') . ' 2>&1');
$bpost = json_decode((string)shell_exec('php -d display_errors=1 -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="POST"; $GLOBALS["synohw_path_override"]=json_decode(file_get_contents("' . "$root/ov-normal.json" . '"),true); require "' . $bp . '";') . ' 2>&1'), true);
ok('26b bays.php: GET answers nothing (405); POST maps each sdX in a bay to unit + bay (sdb DS 5, sdf DX 6), nothing else',
   ($bget === null || $bget === '') && is_array($bpost) && $bpost['ok'] === true && $bpost['bays']['sdb'] === ['u' => 'DS', 'bay' => 5, 'unit' => 'DS2415+', 'slot' => 'Parity'] && $bpost['bays']['sdf']['u'] === 'DX' && $bpost['bays']['sdf']['bay'] === 6 && count($bpost['bays']) === 5, json_encode($bpost));
// Known issues + firmware: a fake host bridge, cpuinfo, rtc, microcode and inventory cache on top of the normal fixture
$I = "$root/issues"; shell_exec('rm -rf ' . escapeshellarg($I));
$ovn = json_decode(file_get_contents("$root/ov-normal.json"), true);
$mkI = function (string $rev, string $dev = '0x1f0c', string $model = '77', string $batt = 'okay', string $mc = '0x12d') use ($I, $pf, $ovn) {
  shell_exec('rm -rf ' . escapeshellarg($I) . '; mkdir -p ' . escapeshellarg("$I/run") . ' && cp -a ' . escapeshellarg($ovn['run']) . '/. ' . escapeshellarg("$I/run") . ' && cp -rL ' . escapeshellarg($ovn['pcidev']) . ' ' . escapeshellarg("$I/pci"));   // the DS2415+ PCI topology of the fixture
  if ($dev !== '0x1f0c') foreach (glob("$I/pci/*/device") as $f) if (trim(file_get_contents($f)) === '0x1f0c') file_put_contents($f, "$dev\n");   // not a C2000 at all
  $pf("$I/pci/0000:00:00.0/vendor", "0x8086\n"); $pf("$I/pci/0000:00:00.0/device", "$dev\n"); $pf("$I/pci/0000:00:00.0/revision", "$rev\n");
  $pf("$I/cpuinfo", "processor\t: 0\nvendor_id\t: GenuineIntel\ncpu family\t: 6\nmodel\t\t: $model\nmodel name\t: Intel(R) Atom(TM) CPU  C2538  @ 2.40GHz\nstepping\t: 8\nmicrocode\t: $mc\n");
  $pf("$I/cpu/cpu0/microcode/version", "$mc\n"); $pf("$I/rtc", "rtc_time\t: 10:00:00\nbatt_status\t: $batt\n");
  $pf("$I/run/inventory/firmware.txt", "bvdt=ok\nbios=M.211\nbios_board=MohonPeak\nbios_extra=05.04.12\nusb_enum_errors=0\nigb_ports=4\nat=1\n");
  $pf("$I/run/inventory/smart-sdb.txt", "# synohw dev=sdb key=parity id=x at=1 rc=0\nDevice Model:     ST1000DM003-9YN162\nFirmware Version: CC46\n==> WARNING: A firmware update for this drive is available,\nsee the following Seagate web pages:\nhttp://knowledge.seagate.com/articles/en_US/FAQ/223651en\n\n");
  $pf("$I/run/inventory/smart-sdc.txt", "# synohw dev=sdc key=disk1 id=x at=1 rc=0\nDevice Model:     WDC WD1003FZEX-00MK2A0\nFirmware Version: 01.01A01\n");
  return array_merge($ovn, ['pcidev' => "$I/pci", 'cpuinfo' => "$I/cpuinfo", 'cpusys' => "$I/cpu", 'rtc' => "$I/rtc", 'run' => "$I/run"]);
};
$iss = function (array $ov, array $cfg = []) { $GLOBALS['synohw_path_override'] = $ov; $x = synohw_issues_collect(synohw_paths(), $cfg); return [synohw_issue_rows($x), synohw_fw_rows($x), $x]; };
$find = fn($rows, $k) => array_values(array_filter($rows, fn($r) => strpos($r[0], $k) === 0))[0] ?? null;
[$ir, $fr, $x] = $iss($mkI('0x02'));
$a = $find($ir, 'AVR54');
ok('26b AVR54: C2000 host bridge rev 02 = B0 -> warning "affected silicon (B0)", DS2415+ instructions link and Intel spec update 329460 link',
   $x['c2000'] && $x['cpu']['sig'] === '406D8' && $a[1] === 'warn' && strpos($a[2], 'affected silicon (B0)') !== false && strpos($a[3], 'href="https://tautomer.github.io/posts/fix_synology_ds2415+/"') !== false
   && strpos($a[3], 'atom-c2000-family-spec-update.pdf') !== false && strpos($a[3], '329460') !== false, json_encode($a));
[$ir] = $iss($mkI('0x02'), ['AVR54_FIXED' => 'yes']); $a = $find($ir, 'AVR54');
ok('26b AVR54: owner confirmed the resistor (AVR54_FIXED=yes) -> "resistor fitted (owner confirmed)", no warning', $a[1] === 'ok' && $a[2] === 'resistor fitted (owner confirmed)', json_encode($a));
[$ir] = $iss($mkI('0x03')); $a = $find($ir, 'AVR54');
ok('26b AVR54: rev 03 = C0 -> "fixed silicon (C0)"', $a[1] === 'ok' && strpos($a[2], 'fixed silicon') === 0, json_encode($a));
[$ir, $fr2] = $iss($mkI('0x02', '0x5904', '142')); $a = $find($ir, 'AVR54');
ok('26b AVR54: no Atom C2000 -> "not affected", and no AVR49/AVR35/I354 rows', $a[1] === 'na' && $a[2] === 'not affected' && !$find($ir, 'AVR49') && !$find($ir, 'AVR35') && !$find($fr2, 'Intel I354'), json_encode($ir));
[$ir, $fr] = $iss($mkI('0x02'));
$a49 = $find($ir, 'AVR49'); $a35 = $find($ir, 'AVR35'); $rt = $find($ir, 'RTC');
ok('26b AVR49 info with the BIOS date argument; AVR35 info naming WOL + mechanical off and 4 of 4 igb; RTC okay = ok with the caveat',
   $a49[1] === 'info' && strpos($a49[3], 'January 2018') !== false && strpos($a49[3], 'BIOS M.211') !== false && $a35[1] === 'info' && strpos($a35[3], 'Wake-on-LAN disabled') !== false && strpos($a35[2], '4 igb port(s) of 4') !== false
   && $rt[1] === 'ok' && strpos($rt[3], 'proves little') !== false, json_encode([$a49, $a35, $rt]));
[$ir] = $iss($mkI('0x02', '0x1f0c', '77', 'dead')); $rt = $find($ir, 'RTC');
ok('26b RTC batt_status dead -> warning, replace the CR1220', $rt[1] === 'warn' && strpos($rt[3], 'CR1220') !== false, json_encode($rt));
[$ir, $fr] = $iss($mkI('0x02'));
$b = $find($fr, 'BIOS'); $m = $find($fr, 'CPU microcode'); $mcu = $find($fr, 'Front-panel MCU'); $d1 = $find($fr, 'Disk sdb'); $d2 = $find($fr, 'Disk sdc'); $d3 = $find($fr, 'Disk sdd');
ok('26b firmware: BIOS M.211 = newest known (ok), microcode 0x12d = newest for 406D8 (ok), MCU not checkable with the reason',
   $b[1] === 'ok' && strpos($b[2], 'M.211') === 0 && $m[1] === 'ok' && strpos($m[3], '0x12d') !== false && $mcu[1] === 'na' && strpos($mcu[3], 'no reply') !== false, json_encode([$b, $m, $mcu]));
ok('26b firmware: disk with a smartctl "==> WARNING:" -> warning with the text; clean disk ok; standby disk "not checked" (never woken)',
   $d1[1] === 'warn' && strpos($d1[3], 'A firmware update for this drive is available') !== false && strpos($d1[3], 'seagate') !== false && $d2[1] === 'ok' && $d3[1] === 'na' && $d3[2] === 'not checked' && strpos($d3[3], 'never woken') !== false, json_encode([$d1, $d2, $d3]));
[$ir, $fr] = $iss($mkI('0x02', '0x1f0c', '77', 'okay', '0x121')); $m = $find($fr, 'CPU microcode');
ok('26b firmware: microcode 0x121 (BIOS-only) < 0x12d -> warning', $m[1] === 'warn' && strpos($m[3], '0x12d') !== false, json_encode($m));
$GLOBALS['synohw_path_override'] = $mkI('0x02'); $hi = synohw_render_issues(['cfg' => []]);
ok('26b issues block renders both tables with state words for screen readers (not colour alone)', substr_count($hi, '<table class="unraid synohw-issues">') === 2 && strpos($hi, '<span class="synohw-sr">warning: </span>') !== false, substr($hi, 0, 300));
$GLOBALS['synohw_path_override'] = $mkI('0x02'); $fw = file_get_contents("$I/run/inventory/firmware.txt");
file_put_contents("$I/run/inventory/firmware.txt", "bvdt=ok\nbios=<script>x</script>\nusb_err=<img src=x>\nusb_enum_errors=1\n");
$hi = synohw_render_issues(['cfg' => []]);
ok('26b issues: cache values are escaped', strpos($hi, '<script>x') === false && strpos($hi, '<img src=x>') === false && strpos($hi, '&lt;img src=x&gt;') !== false, '');

// tables: dump for the shell-side comparison with scemd.xml and synohw-lib.sh
file_put_contents("$root/php-tables.json", json_encode(SYNOHW_TABLES));
ok('no PHP notice or warning in the status library', !$warnings, implode('; ', array_slice($warnings, 0, 5)));
exit($fails ? 1 : 0);
