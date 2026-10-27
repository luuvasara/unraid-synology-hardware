<?php
/* Offline tests for the hardware report anonymiser (include/synohw-anon.php) and the report endpoint (include/report.php).
 * Written: 2026-10-26 (2026.10.26b). usage: php report_test.php <plugin-dir> <scratch-dir>
 * Planted values of every kind go through the anonymiser; none may survive (case-insensitive search), the same value must get the
 * same placeholder, and harmless look-alikes (PCI addresses, ids, times, versions) must be kept. */
$plug = $argv[1]; $tmp = $argv[2];
$fails = 0;
function ok($name, $cond, $detail = '') { global $fails; echo ($cond ? 'PASS ' : 'FAIL ') . $name . (!$cond && $detail !== '' ? "  [$detail]" : '') . "\n"; if (!$cond) $fails++; }
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) { $warnings[] = "$str at " . basename($file) . ":$line"; return true; });
require "$plug/include/synohw-status.php";
require_once "$plug/include/synohw-anon.php";

// ---- a fake box: ini files, usb serials, DMI, DOM vender serial, hostname, passwd, by-label ----
$B = "$tmp/box"; @mkdir($B, 0755, true);
$w = function ($f, $c) { if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true); file_put_contents($f, $c); };
$w("$B/disks.ini", "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdd\"\nid=\"WDC_WD1003FZEX-00MK2A0_WD-PLANTDISK01\"\n[\"flash\"]\nname=\"flash\"\ndevice=\"sda\"\nid=\"Kingston_DT_Ultimate_G3_PLANTFLASH0000AA00-0:0\"\n");
$w("$B/devs.ini", "[\"dev1\"]\nname=\"dev1\"\ndevice=\"sdb\"\nid=\"Synology_DiskStation_PLANTDOM0E41626-0:0\"\n[\"dev2\"]\nname=\"dev2\"\ndevice=\"sdc\"\nid=\"ST1000DM003-1CH162_PLANTZ9X9\"\n");
$w("$B/usb/1-1/serial", "PLANTUSBSER9\n"); $w("$B/usb/usb1/serial", "0000:00:16.0\n");
$w("$B/dmi/product_serial", "PLANTDMIPS\n"); $w("$B/dmi/board_serial", "PLANTDMIBS\n"); $w("$B/dmi/product_uuid", "PLANTUUI-D000-1111-2222-333344445555\n");
$w("$B/dx/host21/pmp_serial", "PLANTPMPSER\n");
$w("$B/run/macs", "mac 1 00:11:32:aa:bb:01\nsn PLANTVENDERSN\n");
$w("$B/hostname", "plantnas\n");
$w("$B/passwd", "root:x:0:0::/root:/bin/bash\nnobody:x:65534:65534::/:/bin/false\nplantuser:x:1000:100::/home/plantuser:/bin/false\n");
@mkdir("$B/bylabel", 0755, true); touch("$B/bylabel/PLANTLABEL"); touch("$B/bylabel/my\\x20disk");
$p = ['disks_ini' => "$B/disks.ini", 'devs_ini' => "$B/devs.ini", 'usbdev' => "$B/usb", 'dmi' => "$B/dmi", 'dxsys' => "$B/dx", 'run' => "$B/run",
      'hostname' => "$B/hostname", 'passwd' => "$B/passwd", 'bylabel' => "$B/bylabel"];
$k = synohw_anon_known($p);
ok('known values: disk, flash, DOM, USB, DMI, PMP and vender serials; hostname; uid>=1000 user; labels; DMI UUID',
   !array_diff(['WD-PLANTDISK01', 'PLANTFLASH0000AA00', 'PLANTDOM0E41626', 'PLANTZ9X9', 'PLANTUSBSER9', 'PLANTDMIPS', 'PLANTDMIBS', 'PLANTPMPSER', 'PLANTVENDERSN'], $k['serial'])
   && in_array('plantnas', $k['host'], true) && $k['user'] === ['plantuser'] && in_array('PLANTLABEL', $k['label'], true) && in_array('my disk', $k['label'], true)
   && !in_array('0000:00:16.0', $k['serial'], true) && !in_array('root', $k['user'], true), json_encode($k));

$planted = [
  // known values (pass 1)
  'WD-PLANTDISK01', 'PLANTFLASH0000AA00', 'PLANTDOM0E41626', 'PLANTZ9X9', 'PLANTUSBSER9', 'PLANTDMIPS', 'PLANTDMIBS', 'PLANTPMPSER', 'PLANTVENDERSN',
  'plantnas', 'plantuser', 'PLANTLABEL', 'my disk', 'PLANTUUI-D000-1111-2222-333344445555',
  // pattern values (pass 2), unknown in advance
  '00:11:32:aa:bb:01', '00-11-32-AA-BB-02', '0a:1b:2c:3d:4e:5f', '0x50014ee2b5c1a3b4', 'wwn-0x5000c500a1b2c3d4', 'naa.5000c500a1b2c3d5', '5 0014ee 2b5c1a3b9',
  '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b', 'A1B2C3D4-01', '1234-ABCD', 'PATTLABEL1', 'Spaced Label',
  '198.51.100.143', '10.0.0.7', '198.51.100.0/24', 'fe80::211:32ff:feaa:bb01', '2001:db8:85a3::8a2e:370:7334', '2001:0db8:0000:0000:0000:ff00:0042:8329',
  'PATTSERIAL77', 'PATTSER88', 'XYZPATT99', 'PATTSN4242', 'PATTDMI5',
];
$raw = <<<TXT
### DMI
product_serial: PLANTDMIPS
board_serial: PLANTDMIBS
product_uuid: PLANTUUI-D000-1111-2222-333344445555
### disks
disk1 WDC_WD1003FZEX-00MK2A0_WD-PLANTDISK01 (sdd) and wd-plantdisk01 lower case
flash Kingston_DT_Ultimate_G3_PLANTFLASH0000AA00-0:0
dev2 ST1000DM003-1CH162_PLANTZ9X9 (sdc)
DOM Synology_DiskStation_PLANTDOM0E41626-0:0, vender SN=PLANTVENDERSN,CHK=12
usb 1-1: SerialNumber: PLANTUSBSER9
usb 2-3: SerialNumber: PATTSERIAL77
Serial Number:    PATTSER88
serial=XYZPATT99 SN=PATTSN4242 chassis_serial: PATTDMI5
host21 pmp_serial: PLANTPMPSER
LU WWN Device Id: 5 0014ee 2b5c1a3b9
by-id wwn-0x5000c500a1b2c3d4 and 0x50014ee2b5c1a3b4, naa.5000c500a1b2c3d5
### net
eth0: 00:11:32:aa:bb:01 perm 00:11:32:aa:bb:01, eth1 00-11-32-AA-BB-02, other 0A:1B:2C:3D:4E:5F
inet 198.51.100.143/24 gw 10.0.0.7 net 198.51.100.0/24, loopback 127.0.0.1, any 0.0.0.0
inet6 fe80::211:32ff:feaa:bb01/64 and 2001:db8:85a3::8a2e:370:7334 and 2001:0db8:0000:0000:0000:ff00:0042:8329
### fs
/dev/sdd1: UUID="6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b" PARTUUID="A1B2C3D4-01" LABEL="PATTLABEL1"
/dev/sda1: UUID="1234-ABCD" LABEL="Spaced Label" (by-label PLANTLABEL, my disk)
### host
hostname plantnas.local (PLANTNAS), user plantuser owns /home/plantuser
### harmless, must stay
00:1f.2 SATA controller [0106]: Intel Corporation Atom processor C2000 AHCI SATA3 Controller [8086:1f22] (rev 02)
-[0000:00]-+-00.0  Intel Corporation Atom processor C2000 SoC Transaction Router [8086:1f0c]
ata21.01: ATA-9: WDC WD1003FZEX-00MK2A0, 01.01A01, max UDMA/133 at 12:25:00, kernel 6.18.58, gscr_1: 0x0000a00e
unraid: 7.3.4 BIOS M.211 MohonPeak 05.04.12, model DS2415+, ttyS1 port:000002F8 irq:3, root user
TXT;
foreach ($planted as $v) if (stripos($raw, $v) === false) { ok("fixture contains planted $v", false); }
[$out, $n] = synohw_anonymise($raw, $k);
$surv = array_values(array_filter($planted, fn($v) => stripos($out, $v) !== false));
ok('no planted serial, MAC, WWN, UUID, PARTUUID, label, hostname, IPv4, IPv6 or user name survives (' . count($planted) . ' planted values)', !$surv, implode(', ', $surv) . "\n" . $out);
// placeholders stable
preg_match_all('/eth0: (<mac-\d+>) perm (<mac-\d+>)/', $out, $m);
ok('the same value gets the same placeholder (MAC twice; serial in two cases)', ($m[1][0] ?? 'a') === ($m[2][0] ?? 'b') && preg_match('/(<serial-\d+>) \(sdd\) and (<serial-\d+>) lower/', $out, $m2) && $m2[1] === $m2[2], $out);
ok('different values get different placeholders (three MACs -> <mac-1>, <mac-2>, <mac-3>)', strpos($out, '<mac-1>') !== false && strpos($out, '<mac-2>') !== false && strpos($out, '<mac-3>') !== false, $out);
ok('counts per category returned', ($n['mac'] ?? 0) === 3 && ($n['host'] ?? 0) >= 1 && ($n['user'] ?? 0) === 1 && ($n['ip'] ?? 0) === 3 && ($n['ipv6'] ?? 0) === 3, json_encode($n));
foreach (['00:1f.2', '[8086:1f22]', '(rev 02)', '-[0000:00]-+-00.0', '[8086:1f0c]', 'ata21.01', '01.01A01', '12:25:00', '6.18.58', '0x0000a00e', '7.3.4', 'M.211', '05.04.12', 'DS2415+', 'port:000002F8', '127.0.0.1', '0.0.0.0', 'root user', 'WDC_WD1003FZEX-00MK2A0_'] as $keep)
  if (strpos($out, $keep) === false) ok("harmless text kept: $keep", false, $out);
ok('harmless look-alikes kept (PCI addresses and ids, revisions, ata ports, firmware revisions, times, versions, register values, loopback)', true);
// mutation check: with the anonymiser bypassed, the same assertion must fail (the check can fail)
$surv0 = array_filter($planted, fn($v) => stripos($raw, $v) !== false);
ok('control: the raw text fails the survival check (' . count($surv0) . ' of ' . count($planted) . ' present)', count($surv0) === count($planted));
// placeholders are not re-anonymised, idempotent on a second pass
[$out2] = synohw_anonymise($out, $k);
ok('a second pass changes nothing (placeholders are left alone)', $out2 === $out, substr($out2, 0, 400));

// ---- report.php: GET 405; unknown action 400 ----
$ep = "$plug/include/report.php";
$g = shell_exec('php -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="GET"; require "' . $ep . '";') . ' 2>&1');
$b = json_decode((string)shell_exec('php -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="POST"; $_POST=["action"=>"x"]; require "' . $ep . '";') . ' 2>&1'), true);
ok('report.php: GET answers nothing (405); an unknown action is refused', ($g === null || $g === '') && is_array($b) && $b['ok'] === false, var_export([$g, $b], true));

// ---- the real collector on this machine (build host): its own hostname, MACs and IPs must not survive ----
$fix = getenv('SYNOHW_UEFI1_FIXTURE');
$raw = (string)shell_exec('SYNOHW_UEFI1=' . escapeshellarg((string)$fix) . ' timeout 120 bash ' . escapeshellarg("$plug/scripts/synohw-report") . ' 2>&1');
$k2 = synohw_anon_known(['disks_ini' => '/nonexistent', 'devs_ini' => '/nonexistent', 'run' => "$tmp/none"]);
[$o2] = synohw_anonymise($raw . "\nhostname " . gethostname() . "\n", $k2);
$mine = [gethostname()];
foreach (glob('/sys/class/net/*/address') as $f) { $a = trim(file_get_contents($f)); if ($a !== '00:00:00:00:00:00' && $a !== '') $mine[] = $a; }
foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $ip) if ($ip !== '') $mine[] = $ip;
$left = array_filter($mine, fn($v) => stripos($o2, $v) !== false);
ok('collector on this machine: sections present, BIOS from the BVDT fixture, own hostname/MACs/IPs (' . count($mine) . ') gone after anonymising',
   strpos($raw, '### lspci -nn') !== false && strpos($raw, '### dmesg (relevant lines)') !== false && strpos($raw, 'M.211 MohonPeak 05.04.12') !== false && !$left, implode(', ', $left));
ok('collector: no write to a tty or disk command in the script (no smartctl/hdparm/echo > /dev)', !preg_match('#\b(smartctl|hdparm|sg_)\b|>\s*/dev/(tty|sd)#', preg_replace('/^\s*#.*$/m', '', file_get_contents("$plug/scripts/synohw-report"))));

ok('no PHP notice or warning', !$warnings, implode('; ', array_slice($warnings, 0, 5)));
exit($fails ? 1 : 0);
