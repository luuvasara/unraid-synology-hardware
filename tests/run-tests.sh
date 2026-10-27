#!/bin/bash
# Offline test suite for synology-hardware (runs on the build host, touches no NAS and no real tty). Written: 2026-10-24.
set -u
T=$(cd "$(dirname "$0")" && pwd); S=$T/../src/usr/local/emhttp/plugins/synology-hardware/scripts
fails=0
ok()   { echo "PASS $1"; }
bad()  { echo "FAIL $1 ${2:-}"; fails=$((fails+1)); }
SCRATCH=$(mktemp -d); trap 'rm -rf "$SCRATCH"' EXIT
SRC=$T/../src; PLG=$T/../synology-hardware.plg
# stub `logger` first in PATH: every syslog line lands in $SYNOHW_TEST_SYSLOG as "<tag>: <message>"
mkdir -p "$SCRATCH/bin"; cat >"$SCRATCH/bin/logger" <<'STUB'
#!/bin/bash
f=${SYNOHW_TEST_SYSLOG:-/dev/null}; tag=; msg=
while [ $# -gt 0 ]; do case $1 in -t) tag=$2; shift 2 ;; --) shift; msg="$*"; break ;; *) msg="$*"; break ;; esac; done
if [ -n "$msg" ]; then echo "$tag: $msg" >>"$f"; else while IFS= read -r l; do echo "$tag: $l" >>"$f"; done; fi
STUB
chmod +x "$SCRATCH/bin/logger"; export PATH="$SCRATCH/bin:$PATH"
export SYNOHW_TEST_SYSLOG=$SCRATCH/syslog SYNOHW_RUN=$SCRATCH/run

# --- allow-list ---
( . "$S/synohw-lib.sh"
  e=0
  for c in u t 4 5 6 7 8 9 : ';' 2 O q p l k s r LA1 LA3 LE2 W20 V20 V99 V10; do synohw_safe "$c" || { echo "should allow '$c'"; e=1; }; done
  for c in 1 C 3 V V5 V05 V00 V100 W21 W10 LA4 LA LAA X '' 'u4' 'ut' M P Q = $'\n' 'V9a' '5 7'; do synohw_safe "$c" && { echo "should REFUSE '$c'"; e=1; }; done
  exit $e ) && ok "allow-list accepts measured commands, refuses 1/C/3/V<10/odd input" || bad allow-list

# --- exact bytes via a plain file as fake tty ---
( . "$S/synohw-lib.sh"; SYNOHW_TEST_SYSLOG=$SCRATCH/l; SYNOHW_GAP=0
  exec 3>"$SCRATCH/wire"; synohw_send u; synohw_send V50; synohw_send LA1; synohw_send 1; synohw_send C; synohw_send V05; exec 3>&-
  [ "$(cat "$SCRATCH/wire")" = "uV50LA1" ] ) && ok "synohw_send writes exactly 'uV50LA1'; '1','C','V05' never written" || bad send-bytes
grep -c '^synology-hardware: REFUSED' "$SCRATCH/l" | grep -qx 3 && ok "3 refusals logged to syslog via 'logger -t synology-hardware'" || bad refusal-log

# --- cfg parser: values from the webGui are data, never code ---
cat >"$SCRATCH/cfg1" <<'CFG'
# old comment line (2026.10.24a/b seeded one)
FAN_MODE="HIGH"
KEEPALIVE_SEC=7
DEBUG="yes"
MACFIX="$(touch PWNED)"
WOL=`touch PWNED2`
EVIL_KEY="x"
TICK="0.2"
CFG
sed -i 's/\\r$/\r/' "$SCRATCH/cfg1"
( cd "$SCRATCH"; . "$S/synohw-lib.sh"; SYNOHW_TEST_SYSLOG=$SCRATCH/l2 SYNOHW_CFG=$SCRATCH/cfg1 synohw_load_cfg
  [ "$FAN_MODE" = HIGH ] && [ "$KEEPALIVE_SEC" = 7 ] && [ "$DEBUG" = yes ] && [ "$MACFIX" = yes ] && [ "$WOL" = keep ] && [ "$TICK" = 0.2 ] \
    && [ -z "${EVIL_KEY:-}" ] && [ ! -e PWNED ] && [ ! -e PWNED2 ] ) \
  && grep -q "ignoring MACFIX=" "$SCRATCH/l2" && grep -q "ignoring EVIL_KEY=" "$SCRATCH/l2" \
  && ok "cfg: quoted/CRLF values parsed, \$(..)/backtick values and unknown keys ignored + logged, nothing executed" || bad cfg-parser

# --- cfg (2026.10.25h): fan mode keys ---
( . "$S/synohw-lib.sh"; e=0
  for kv in DS_MODE=AUTO DS_MODE=MANUAL DS_FLOOR=10 DS_FLOOR=15 DS_FLOOR=20 DS_DUTY=10 DS_DUTY=99 DX_MODE=AUTO DX_MODE=MANUAL DX_MODE=LINKED \
            DX_LEVEL=full DX_LEVEL=middle DX_LEVEL=low DX_PERIOD=1 DX_PERIOD=60 DX_PERIOD=3600; do
    synohw_cfg_ok "${kv%%=*}" "${kv#*=}" || { echo "should accept $kv"; e=1; }; done
  for kv in DS_MODE=auto DS_MODE=OFF DS_FLOOR=9 DS_FLOOR=21 DS_FLOOR=05 DS_FLOOR= DS_DUTY=9 DS_DUTY=100 DS_DUTY=05 DS_DUTY=x DX_MODE=STOP DX_MODE=linked \
            DX_LEVEL=stop DX_LEVEL=STOP DX_LEVEL=Full DX_LEVEL='low;stop' DX_LEVEL= DX_PERIOD=0 DX_PERIOD=3601 DX_PERIOD=1.5; do
    synohw_cfg_ok "${kv%%=*}" "${kv#*=}" && { echo "should refuse $kv"; e=1; }; done
  exit $e ) && ok "cfg 25h: DS_MODE/DS_FLOOR 10..20/DS_DUTY 10..99/DX_MODE/DX_LEVEL (never stop)/DX_PERIOD accepted and refused exactly" || bad "cfg 25h keys"
( . "$S/synohw-lib.sh"; e=0
  for p in 0:low 39:low 40:middle 69:middle 70:full 99:full x:middle -1:middle; do r=$(synohw_dx_linked "${p%%:*}"); [ "$r" = "${p#*:}" ] || { echo "linked ${p%%:*} -> $r"; e=1; }; done
  [ "$(synohw_dx_max low middle) $(synohw_dx_max full low) $(synohw_dx_max stop low)" = "middle full low" ] || { echo dx_max; e=1; }
  D=$SCRATCH/dxset; mkdir -p "$D/unit"; SYNOHW_DXSYS=$D SYNOHW_TEST_SYSLOG=$D/log
  ! synohw_dx_set low && [ ! -e "$D/unit/fan_level" ] && grep -q 'missing or not writable' "$D/log" || { echo "created fan_level"; e=1; }
  echo low >"$D/unit/fan_level"
  synohw_dx_set stop && { echo "stop accepted"; e=1; }; synohw_dx_set STOP && e=1; synohw_dx_set 'low stop' && e=1; synohw_dx_set '' && e=1
  [ "$(cat "$D/unit/fan_level")" = low ] || { echo "file changed"; e=1; }
  synohw_dx_set middle && [ "$(cat "$D/unit/fan_level")" = middle ] || { echo "middle not written"; e=1; }
  [ "$(grep -c 'REFUSED DX1215' "$D/log")" = 4 ] || { echo "refusals not logged"; e=1; }
  exit $e ) && ok "DX lib: LINKED map <40 low / 40-69 middle / >=70 full; dx_set never creates fan_level, refuses stop/STOP/odd input (logged), writes middle" || bad "DX lib"

# --- fan logic ---
( . "$S/synohw-lib.sh"; synohw_defaults; synohw_tables; e=0
  chk() { [ "$1" = "$2" ] || { echo "got $1 want $2 ($3)"; e=1; }; }
  l=$(synohw_step_level 40 0 "${DISK_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${DISK_STEPS[@]}")" 20 disk40
  l=$(synohw_step_level 46 0 "${DISK_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${DISK_STEPS[@]}")" 40 disk46
  l=$(synohw_step_level 54 1 "${DISK_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${DISK_STEPS[@]}")" 99 disk54
  l=$(synohw_step_level 53 3 "${DISK_STEPS[@]}"); chk "$l" 3 hyst53-stays
  l=$(synohw_step_level 51 3 "${DISK_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${DISK_STEPS[@]}")" 70 hyst51
  l=$(synohw_step_level 64 0 "${CPU_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${CPU_STEPS[@]}")" 20 cpu64
  l=$(synohw_step_level 65 0 "${CPU_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${CPU_STEPS[@]}")" 70 cpu65
  FAN_MODE=HIGH; synohw_tables; l=$(synohw_step_level 49 0 "${DISK_STEPS[@]}"); chk "$(synohw_level_duty "$l" "${DISK_STEPS[@]}")" 99 high49
  exit $e ) && ok "fan steps follow the scemd.xml LOW/HIGH tables incl. 2 C hysteresis" || bad fan-tables

# --- vender decoder vs real fixture ---
out=$("$S/synohw-vender" decode "$T/fixtures/vender") ; rc=$?
exp=$'mac 0 00:11:32:00:00:09\nmac 1 00:11:32:00:00:0a\nmac 2 00:11:32:00:00:0b\nmac 3 00:11:32:00:00:0c\nsn 0000ABC000000'
[ $rc = 0 ] && [ "$out" = "$exp" ] && ok "vender fixture decodes to MACs ..09-..0c + SN" || bad vender "rc=$rc out=$out"
head -c 20 "$T/fixtures/vender" >"$SCRATCH/short"; "$S/synohw-vender" decode "$SCRATCH/short" >/dev/null 2>&1 && bad vender-short || ok "truncated vender is rejected"
printf '\x01\x11\x32\x00\x00\x09\x20' >"$SCRATCH/mc"; head -c 60 /dev/zero >>"$SCRATCH/mc"; "$S/synohw-vender" decode "$SCRATCH/mc" 2>/dev/null && bad vender-mcast || ok "multicast MAC is rejected"
sha_before=$(sha256sum "$T/fixtures/vender"); "$S/synohw-vender" decode "$T/fixtures/vender" >/dev/null; [ "$sha_before" = "$(sha256sum "$T/fixtures/vender")" ] && ok "decode leaves fixture unchanged" || bad ro

# --- macfix: fake /sys/class/net. PCI order is deliberately not name order:
#     eth1@14.0 eth0@14.1 eth3@14.2 eth2@14.3 (+ a USB NIC and bond0/br0, which must be ignored)
#     -> MAC index 0..3 = ..09 ..0a ..0b ..0c by PCI address
mk_net() { # $1 dir, $2 layout (bond|plain|down), $3 mac-mode (placeholder|real)
  local d=$1 n pci mac i=0
  rm -rf "$d"; mkdir -p "$d/net" "$d/pci" "$d/usb/1-1:1.0"
  for pair in "eth1 0000:00:14.0 00 09" "eth0 0000:00:14.1 01 0a" "eth3 0000:00:14.2 02 0b" "eth2 0000:00:14.3 03 0c"; do
    set -- $pair; mkdir -p "$d/net/$1" "$d/pci/$2"
    [ "$3" ] && { [ "${MACMODE:-placeholder}" = real ] && mac=00:11:32:00:00:$4 || mac=00:a0:c9:00:00:$3; }
    echo "$mac" >"$d/net/$1/address"; ln -s "../../pci/$2" "$d/net/$1/device"
    [ "${LAYOUT:-bond}" = down ] && echo 0x1002 >"$d/net/$1/flags" || echo 0x1003 >"$d/net/$1/flags"
  done
  mkdir -p "$d/net/eth9"; echo 00:a0:c9:00:00:09 >"$d/net/eth9/address"; ln -s "../../usb/1-1:1.0" "$d/net/eth9/device"
  if [ "${LAYOUT:-bond}" = bond ]; then
    mkdir -p "$d/net/bond0/bonding" "$d/net/br0/bridge"; echo 00:a0:c9:00:00:00 >"$d/net/bond0/address"; echo 00:a0:c9:00:00:00 >"$d/net/br0/address"
    for n in eth0 eth1 eth2 eth3; do ln -s ../bond0 "$d/net/$n/master"; done; ln -s ../br0 "$d/net/bond0/master"
  fi
  printf '#!/bin/bash\necho "ip $*" >>%s/calls\n' "$d" >"$d/ip"; printf '#!/bin/bash\necho "inet1 $*" >>%s/calls\n' "$d" >"$d/inet1"; chmod +x "$d/ip" "$d/inet1"
  : >"$d/calls"; : >"$d/syslog"
}
mf() { # $1 dir, rest = args ; MACFIX via $MF_CFG
  local d=$1; shift
  SYNOHW_TEST_SYSLOG=$d/syslog SYNOHW_SYSNET=$d/net SYNOHW_IP=$d/ip SYNOHW_INET1=$d/inet1 SYNOHW_VENDER_FILE=$T/fixtures/vender \
  SYNOHW_UDEV_ROM=$d/boot/udev/70-synology-hardware-mac.rules SYNOHW_UDEV_RAM=$d/etc/rules.d/70-synology-hardware-mac.rules \
  SYNOHW_UDEV_LEGACY="$d/boot/udev/70-synology-hw-mac.rules $d/etc/rules.d/70-synology-hw-mac.rules" SYNOHW_RUN=$d/run SYNOHW_DETACH=no SYNOHW_BUSY_PIDFILES="${MF_BUSY:-$d/none.pid}" SYNOHW_CFG=${MF_CFG:-/nonexistent} "$S/synohw-macfix" "$@"
}
D=$SCRATCH/mf1; mk_net "$D"
mf "$D" rules; rc=$?
EXP_RULES='# synology-hardware: real Synology MACs for the DS2415+ ports (generated by synohw-macfix, do not edit).
# Source: DOM '"'"'vender'"'"' file, MAC n -> n-th PCI network function. Remove: set MACFIX=no or remove the plugin.
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.0", ATTR{address}=="00:11:32:00:00:09", NAME!="?*", NAME="$kernel"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.0", ATTR{address}=="00:a0:c9:00:00:*", RUN+="/sbin/ip link set dev $name address 00:11:32:00:00:09"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.1", ATTR{address}=="00:11:32:00:00:0a", NAME!="?*", NAME="$kernel"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.1", ATTR{address}=="00:a0:c9:00:00:*", RUN+="/sbin/ip link set dev $name address 00:11:32:00:00:0a"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.2", ATTR{address}=="00:11:32:00:00:0b", NAME!="?*", NAME="$kernel"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.2", ATTR{address}=="00:a0:c9:00:00:*", RUN+="/sbin/ip link set dev $name address 00:11:32:00:00:0b"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.3", ATTR{address}=="00:11:32:00:00:0c", NAME!="?*", NAME="$kernel"
ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.3", ATTR{address}=="00:a0:c9:00:00:*", RUN+="/sbin/ip link set dev $name address 00:11:32:00:00:0c"'
[ $rc = 0 ] && [ "$(cat "$D/boot/udev/70-synology-hardware-mac.rules")" = "$EXP_RULES" ] && cmp -s "$D/boot/udev/70-synology-hardware-mac.rules" "$D/etc/rules.d/70-synology-hardware-mac.rules" \
  && ok "udev rule: 4 ports keyed on PCI address in PCI order (not name order), USB NIC ignored, flash + /etc copy" || { diff <(echo "$EXP_RULES") "$D/boot/udev/70-synology-hardware-mac.rules"; bad udev-rules; }
[ ! -s "$D/calls" ] && ok "macfix rules: no ip/rc.inet1 calls" || bad rules-no-calls
grep -c 'wrote' "$D/syslog" | grep -qx 2 && ok "rule write logged to syslog (flash + /etc)" || bad rules-log
ino=$(stat -c %i "$D/boot/udev/70-synology-hardware-mac.rules"); : >"$D/syslog"
mf "$D" rules && [ "$(stat -c %i "$D/boot/udev/70-synology-hardware-mac.rules")" = "$ino" ] && ! grep -q wrote "$D/syslog" \
  && ok "udev rule: unchanged content -> file not rewritten (same inode, no log)" || bad rules-idem
echo "# hand edit" >>"$D/boot/udev/70-synology-hardware-mac.rules"
mf "$D" rules && [ "$(cat "$D/boot/udev/70-synology-hardware-mac.rules")" = "$EXP_RULES" ] && ok "udev rule: differing content -> rewritten" || bad rules-rewrite
echo 'MACFIX="no"' >"$SCRATCH/cfg-nomac"
MF_CFG=$SCRATCH/cfg-nomac mf "$D" rules && [ ! -e "$D/boot/udev/70-synology-hardware-mac.rules" ] && [ ! -e "$D/etc/rules.d/70-synology-hardware-mac.rules" ] \
  && ok "MACFIX=no removes both rule files" || bad rules-macfix-no
# file name must sort after Unraid's naming rule and before the rule generator (eudev sorts all rules files by name)
[ "$(printf '%s\n' 75-persistent-net-generator.rules 70-synology-hardware-mac.rules 70-persistent-net.rules 50-udev-default.rules | LC_ALL=C sort | tr '\n' ' ')" \
  = "50-udev-default.rules 70-persistent-net.rules 70-synology-hardware-mac.rules 75-persistent-net-generator.rules " ] \
  && ok "rule file sorts after 70-persistent-net.rules, before 75-persistent-net-generator.rules" || bad rules-order

# 2026.10.25i: the old plugin's rule files (70-synology-hw-mac.rules) are removed by macfix, logged
D=$SCRATCH/mf1; mkdir -p "$D/boot/udev" "$D/etc/rules.d"; echo old >"$D/boot/udev/70-synology-hw-mac.rules"; echo old >"$D/etc/rules.d/70-synology-hw-mac.rules"; : >"$D/syslog"
mf "$D" rules && [ ! -e "$D/boot/udev/70-synology-hw-mac.rules" ] && [ ! -e "$D/etc/rules.d/70-synology-hw-mac.rules" ] && [ "$(cat "$D/boot/udev/70-synology-hardware-mac.rules")" = "$EXP_RULES" ] \
  && [ "$(grep -c "removed the old plugin's rule" "$D/syslog")" = 2 ] && ok "macfix 25i: old synology-hw rule files removed (logged), new rule in place" || bad "macfix legacy rules"

# live apply: bond0 in br0, all placeholders -> exactly br0_stop, 4 x ip link set dev ethN, br0_start; never bond0/br0
D=$SCRATCH/mf2; mk_net "$D"
mf "$D" run; rc=$?
EXP_CALLS='inet1 br0_stop
ip link set dev eth1 address 00:11:32:00:00:09
ip link set dev eth0 address 00:11:32:00:00:0a
ip link set dev eth3 address 00:11:32:00:00:0b
ip link set dev eth2 address 00:11:32:00:00:0c
inet1 br0_start'
[ $rc = 0 ] && [ "$(cat "$D/calls")" = "$EXP_CALLS" ] && [ -e "$D/boot/udev/70-synology-hardware-mac.rules" ] \
  && ok "first install (run): rule written, then br0_stop / ip per ethN in PCI mapping / br0_start, nothing else" || { cat "$D/calls"; bad apply-bond; }
grep -qE 'ip .*(bond0|br0)' "$D/calls" && bad apply-touched-bond || ok "live apply never sets bond0/br0 directly"
D=$SCRATCH/mf3; MACMODE=real mk_net "$D"; mf "$D" run && [ ! -s "$D/calls" ] && grep -q "already real" "$D/syslog" && ok "run is a no-op when the MACs are already real" || { cat "$D/calls"; bad apply-idem; }
D=$SCRATCH/mf4; mk_net "$D"; echo $$ >"$D/dockerd.pid"
MF_BUSY="$D/dockerd.pid" mf "$D" apply && [ ! -s "$D/calls" ] && grep -q "not applied live" "$D/syslog" && ok "live apply skipped (logged) while Docker/libvirt runs" || { cat "$D/calls"; bad apply-busy; }
D=$SCRATCH/mf5; LAYOUT=down mk_net "$D"; mf "$D" apply && ! grep -q inet1 "$D/calls" && [ "$(grep -c 'ip link set dev' "$D/calls")" = 4 ] \
  && ok "ports that are down and unenslaved: plain ip link set, no rc.inet1" || { cat "$D/calls"; bad apply-down; }
D=$SCRATCH/mf2; mf "$D" status | grep -qx 'port eth1 0000:00:14.0 00:a0:c9:00:00:00 00:11:32:00:00:09 placeholder' && ok "status lists port, PCI, current and DOM MAC" || bad mf-status

# --- cron (update_cron convention: /boot/config/plugins/<plugin>/*.cron, collected only for registered plugins) ---
D=$SCRATCH/cron; mkdir -p "$D"; printf '#!/bin/bash\necho update_cron >>%s/calls\n' "$D" >"$D/uc"; chmod +x "$D/uc"; touch "$D/synology-hardware.plg"
cr() { SYNOHW_TEST_SYSLOG=$D/syslog SYNOHW_CRON_FILE=$D/flash/synology-hardware.cron SYNOHW_PLUGIN_LINK=${CR_LINK:-$D/synology-hardware.plg} SYNOHW_UPDATE_CRON=$D/uc SYNOHW_CRON_WAIT=1 SYNOHW_DETACH=no "$S/synohw-cron" "$@"; }
cr install; rc=$?
EXP_CRON='# synology-hardware watchdog: restart the MCU daemon if it died (a deliberate stop is left alone)
*/5 * * * * /etc/rc.d/rc.synology-hardware watchdog >/dev/null 2>&1'
[ $rc = 0 ] && [ "$(cat "$D/flash/synology-hardware.cron")" = "$EXP_CRON" ] && [ "$(cat "$D/calls")" = update_cron ] \
  && ok "cron: synology-hardware.cron written (5-min watchdog), update_cron run once the plugin is registered" || bad cron-install
ino=$(stat -c %i "$D/flash/synology-hardware.cron"); : >"$D/calls"; : >"$D/syslog"
CR_LINK=$D/absent cr install && [ "$(stat -c %i "$D/flash/synology-hardware.cron")" = "$ino" ] && [ ! -s "$D/calls" ] && grep -q "not registered" "$D/syslog" \
  && ok "cron: unchanged file not rewritten; no update_cron (logged) while the plugin is not registered" || bad cron-idem
cr remove && [ ! -e "$D/flash/synology-hardware.cron" ] && ok "cron remove deletes the file" || bad cron-remove

# --- post-install never fails (no ttyS1 here), logs through logger ---
D=$SCRATCH/pi; mk_net "$D"; mkdir -p "$D/cfg"
SYNOHW_TEST_SYSLOG=$D/syslog SYNOHW_TTY=$D/nottyS1 SYNOHW_CFGDIR=$D/cfg SYNOHW_CFG=$D/cfg/synology-hardware.cfg SYNOHW_DIR=$S \
  SYNOHW_RC_PATH=$SRC/etc/rc.d/rc.synology-hardware SYNOHW_K_PATH=/nonexistent SYNOHW_OLD_CFGDIR=/nonexistent SYNOHW_OLD_RC=/nonexistent SYNOHW_SYSNET=$D/net SYNOHW_IP=$D/ip SYNOHW_INET1=$D/inet1 \
  SYNOHW_VENDER_FILE=$T/fixtures/vender SYNOHW_UDEV_ROM=$D/udev/70-synology-hardware-mac.rules SYNOHW_UDEV_RAM=$D/rules.d/70-synology-hardware-mac.rules \
  SYNOHW_RUN=$D/run SYNOHW_DETACH=no SYNOHW_BUSY_PIDFILES=$D/none SYNOHW_CRON_FILE=$D/cfg/synology-hardware.cron SYNOHW_PLUGIN_LINK=$D/nolink \
  SYNOHW_UPDATE_CRON=/bin/false SYNOHW_CRON_WAIT=1 "$S/synohw-postinstall" >"$D/out" 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'synology-hardware: .*nottyS1 missing' "$D/syslog" && grep -q 'post-install: daemon not running' "$D/syslog" \
  && [ -e "$D/udev/70-synology-hardware-mac.rules" ] && [ -e "$D/cfg/synology-hardware.cron" ] && cmp -s "$D/cfg/synology-hardware.cfg" "$S/../default/synology-hardware.cfg" \
  && ok "post-install with ttyS1 missing: exit 0, failure in syslog, cfg seeded, udev rule + cron still installed" || { cat "$D/out" "$D/syslog"; bad postinstall; }
printf '# old seeded comment\nFAN_MODE=HIGH\n' >"$D/cfg/synology-hardware.cfg"
SYNOHW_TEST_SYSLOG=$D/syslog SYNOHW_TTY=$D/nottyS1 SYNOHW_CFGDIR=$D/cfg SYNOHW_DIR=$S SYNOHW_OLD_CFGDIR=/nonexistent SYNOHW_OLD_RC=/nonexistent SYNOHW_K_PATH=/nonexistent SYNOHW_RC_PATH=/bin/false SYNOHW_CRON_FILE=/proc/nonexistent/c SYNOHW_PLUGIN_LINK=$D/nolink \
  SYNOHW_UPDATE_CRON=/bin/false SYNOHW_CRON_WAIT=1 SYNOHW_DETACH=no "$S/synohw-postinstall" >/dev/null 2>&1; rc=$?
[ $rc = 0 ] && [ "$(cat "$D/cfg/synology-hardware.cfg")" = FAN_MODE=HIGH ] && grep -q 'macfix returned 1' "$D/syslog" && grep -q 'cron install returned 1' "$D/syslog" \
  && ok "post-install: rc script and cron install failing -> still exit 0; old '#' cfg line stripped, user value kept" || bad postinstall-fail
# 2026.10.25i: one-time migration from synology-hw: cfg + sweep results copied when missing (never overwritten), old daemon stopped
M=$SCRATCH/mig; rm -rf "$M"; mkdir -p "$M/old" "$M/new"; printf 'FAN_MODE="HIGH"\nDX_MODE="MANUAL"\n' >"$M/old/synology-hw.cfg"
echo a >"$M/old/sweep-20261025-1530.csv"; echo b >"$M/old/sweep-20261025-1530.txt"; echo keep >"$M/new/sweep-20261025-1600.csv"; echo old >"$M/old/sweep-20261025-1600.csv"
printf '#!/bin/bash\necho "oldrc $*" >>%s/calls\n[ "$1" = status ] && exit 0\nexit 0\n' "$M" >"$M/oldrc"; chmod +x "$M/oldrc"
mig() { SYNOHW_TEST_SYSLOG=$M/syslog SYNOHW_TTY=$M/nottyS1 SYNOHW_CFGDIR=$M/new SYNOHW_OLD_CFGDIR=$M/old SYNOHW_OLD_RC=$M/oldrc SYNOHW_DIR=$S SYNOHW_RC_PATH=/bin/true \
  SYNOHW_CRON_FILE=$M/new/synology-hardware.cron SYNOHW_PLUGIN_LINK=$M/nolink SYNOHW_UPDATE_CRON=/bin/false SYNOHW_CRON_WAIT=1 SYNOHW_DETACH=no "$S/synohw-postinstall" >/dev/null 2>&1; }
mig; rc=$?
[ $rc = 0 ] && cmp -s "$M/old/synology-hw.cfg" "$M/new/synology-hardware.cfg" && [ "$(cat "$M/new/sweep-20261025-1530.csv")" = a ] && [ -e "$M/new/sweep-20261025-1530.txt" ] \
  && [ "$(cat "$M/new/sweep-20261025-1600.csv")" = keep ] && [ -e "$M/old/synology-hw.cfg" ] && grep -q 'oldrc stop' "$M/calls" && grep -q 'migrated the settings' "$M/syslog" && grep -q 'stopped the old synology-hw daemon' "$M/syslog" \
  && ok "post-install 25i: synology-hw cfg + sweep results copied (old files kept, existing new file not overwritten), old daemon stopped, logged" || { cat "$M/syslog"; bad "migration"; }
echo 'FAN_MODE="LOW"' >"$M/new/synology-hardware.cfg"; mig
[ "$(cat "$M/new/synology-hardware.cfg")" = 'FAN_MODE="LOW"' ] && ok "post-install 25i: an existing synology-hardware.cfg is never replaced by the old one" || bad "migration overwrote the new cfg"
# the .plg post-install INLINE itself: run with entities expanded on the build host (script absent) -> must still exit 0
python3 -I - "$PLG" >"$SCRATCH/inline.sh" <<'PY'
import sys, xml.dom.minidom as m
d = m.parse(sys.argv[1])
blocks = [f for f in d.getElementsByTagName("FILE") if f.getAttribute("Run") == "/bin/bash" and not f.getAttribute("Method")]
print(blocks[1].getElementsByTagName("INLINE")[0].firstChild.data)
PY
grep -q synohw-postinstall "$SCRATCH/inline.sh" && SYNOHW_TEST_SYSLOG=$SCRATCH/inline.log bash "$SCRATCH/inline.sh" >/dev/null 2>&1 \
  && grep -q 'post-install returned 127 (ignored)' "$SCRATCH/inline.log" && ok ".plg post-install INLINE exits 0 even when its script fails (logged)" || bad plg-inline
python3 -I - "$PLG" <<'PY' && ok ".plg: well-formed, <MD5> on the txz FILE, launch=, removepkg by exact name, remove drops rule/cron/run dir" || bad plg-structure
import sys, re, xml.dom.minidom as m
raw = open(sys.argv[1]).read(); d = m.parse(sys.argv[1])
ver = re.search(r'ENTITY version\s+"([^"]+)"', raw).group(1)
pkgfile = [f for f in d.getElementsByTagName("FILE") if f.getAttribute("Name").endswith(".txz")][0]
md5 = pkgfile.getElementsByTagName("MD5")[0].firstChild.data
assert re.fullmatch(r"[0-9a-f]{32}", md5), md5
assert d.documentElement.getAttribute("launch") == "Settings/SynologyHW"
rem = [f for f in d.getElementsByTagName("FILE") if f.getAttribute("Method") == "remove"][0].getElementsByTagName("INLINE")[0].firstChild.data
for want in ("removepkg synology-hardware-%s-x86_64-1\n" % ver, "/boot/config/udev/70-synology-hardware-mac.rules", "synology-hardware.cron", "rm -rf /var/run/synology-hardware"):
    assert want in rem, want
assert "*" not in rem.split("removepkg")[1].split("\n")[0]
assert "###" + ver in raw, "no CHANGES entry for " + ver
PY
# 2026.10.25i: Plugins-list entry, the Unraid way (ShowPlugins.php: plugins/<name>/README.md is the description; icon= from images/)
python3 -I - "$PLG" "$SRC/usr/local/emhttp/plugins/synology-hardware" <<'PY' && ok ".plg 25i: name synology-hardware, author not 'local', icon file present, README.md title + one sentence" || bad plg-listing
import sys, re, os, xml.dom.minidom as m
raw = open(sys.argv[1]).read(); d = m.parse(sys.argv[1]).documentElement; P = sys.argv[2]
assert re.search(r'ENTITY name\s+"synology-hardware"', raw)
a = d.getAttribute("author"); assert a and "local" not in a.lower(), a
assert os.path.isfile(os.path.join(P, "images", d.getAttribute("icon"))), d.getAttribute("icon")
r = open(os.path.join(P, "README.md")).read().strip().splitlines()
assert r[0] == "**Synology Hardware**", r[0]
assert len([l for l in r[1:] if l.strip()]) == 1, r
PY

# --- settings page: live status library + endpoint (fixtures with fake MACs/serial, tests/mkfixture.py) ---
P=$SRC/usr/local/emhttp/plugins/synology-hardware; FX=$SCRATCH/fx; mkdir -p "$FX"
for sc in normal stopped notty crashed hot measured graphs nodx dxalarm unknown; do python3 -I "$T/mkfixture.py" "$FX/$sc" "$sc" >"$FX/ov-$sc.json" || bad "fixture $sc"; done
php -d error_reporting=-1 "$T/status_test.php" "$P" "$FX" && ok "status page tests" || bad "status page tests"
# 2026.10.26b: hardware report anonymiser (planted values), report endpoint, the collector on this machine
mkdir -p "$SCRATCH/rep"; SYNOHW_UEFI1_FIXTURE=$T/fixtures/uefi1-ds2415p.bin php -d error_reporting=-1 "$T/report_test.php" "$P" "$SCRATCH/rep" && ok "report tests" || bad "report tests"
for f in "$P"/SynologyHW*.page "$P"/include/*.php; do php -l "$f" >/dev/null 2>&1 || bad "php -l $f"; done
ok "php -l: the 7 page files, status.php, synohw-status.php, synohw-hw.php, synohw-view.php, synohw-page.php"
# graphs (2026.10.25f): the page script parses; the vendored uPlot is the exact npm 1.6.32 build with its MIT licence; the page loads
# nothing from the internet (no http(s):// script/link src)
if command -v node >/dev/null; then node --check "$P/js/synohw-charts.js" && node --check "$P/js/synohw-page.js" && ok "node --check js/synohw-charts.js js/synohw-page.js" || bad "node --check page scripts"
else echo "SKIP node --check (node not installed)"; fi
U=$P/vendor/uplot-1.6.32
{ head -c 60 "$U/uPlot.iife.min.js" | grep -q 'uPlot (v1.6.32)' && grep -q 'The MIT License' "$U/LICENSE" && [ -s "$U/uPlot.min.css" ] \
  && [ "$(sha256sum <"$U/uPlot.iife.min.js" | cut -d' ' -f1)" = "$(sed -n 's/^sha256 uPlot.iife.min.js //p' "$U/SOURCE")" ]; } \
  && ok "vendored uPlot 1.6.32: banner, MIT LICENSE, css, sha256 = vendor/uplot-1.6.32/SOURCE" || bad "vendored uPlot"
grep -nE '(src|href)="(https?:)?//' "$P"/SynologyHW*.page "$P/include/synohw-page.php" "$P/include/synohw-view.php" && bad "page loads something from the internet" || ok "page: no remote script/stylesheet (works offline)"
# scemd.xml is Synology's file (private development tree only). Without it the numbers come from
# tests/fixtures/fan-tables.json and dx-table.json; with it, those fixtures must equal what scemd.xml says.
SCEMD=$T/../../capture/2026-10-23/bin/usr/syno/etc.defaults/scemd.xml
if [ -r "$SCEMD" ]; then SCEMD_SRC=scemd.xml; else SCEMD_SRC="fixtures (scemd.xml not in this tree)"; echo "NOTE scemd.xml absent: fan/DX tables checked against tests/fixtures/{fan-tables,dx-table}.json"; fi
# fan tables: scemd.xml (Synology's file from this unit) == bash synohw_tables == the page's PHP copy
( . "$S/synohw-lib.sh"; for m in LOW HIGH; do FAN_MODE=$m; synohw_tables; echo "$m ${DISK_STEPS[*]} $DISK_ALERT $DISK_SHUTDOWN ${CPU_STEPS[*]} $CPU_ALERT $CPU_SHUTDOWN"; done ) >"$SCRATCH/bash-tables"
python3 -I - "$T/../../capture/2026-10-23/bin/usr/syno/etc.defaults/scemd.xml" "$SCRATCH/bash-tables" "$FX/php-tables.json" "$T/fixtures/fan-tables.json" <<'PY2' && ok "fan tables: $SCEMD_SRC == synohw-lib.sh == synohw-status.php (LOW and HIGH)" || bad fan-table-drift
import json, os, re, sys, xml.etree.ElementTree as ET
ref = json.load(open(sys.argv[4]))
if not os.path.exists(sys.argv[1]):
    want = ref   # public tree: Synology's scemd.xml is not published; the extracted numbers are the fixture
else:
  raw = open(sys.argv[1]).read()
  root = ET.fromstring("<r>" + re.sub(r"<\?xml[^>]*\?>", "", raw) + "</r>")
  want = {}
  for fc in root.iter("fan_config"):
      t = fc.get("type")
      if fc.get("hw_version") or t not in ("DUAL_MODE_LOW", "DUAL_MODE_HIGH"): continue
      m = "LOW" if t.endswith("LOW") else "HIGH"; r = {"disk": [], "cpu": []}
      for e in fc:
          kind = "disk" if e.tag == "disk_temperature" else "cpu"; thr = int(e.text); act = e.get("action")
          duty = int(e.get("fan_speed").split("%")[0])
          # every row is a step (a row repeating the previous duty adds nothing); ALERT/SHUTDOWN rows also set thresholds
          if not r[kind] or r[kind][-1][1] != duty: r[kind].append([thr, duty])
          if act == "ALERT_LED": r[kind + "_alert"] = thr
          elif act == "SHUTDOWN": r[kind + "_shutdown"] = thr
      want[m] = r
  assert want == ref, ("tests/fixtures/fan-tables.json differs from scemd.xml", want, ref)
for line in open(sys.argv[2]):
    f = line.split(); m = f[0]; w = want[m]
    exp = " ".join(f"{a}:{b}" for a, b in w["disk"]) + f" {w['disk_alert']} {w['disk_shutdown']} " + " ".join(f"{a}:{b}" for a, b in w["cpu"]) + f" {w['cpu_alert']} {w['cpu_shutdown']}"
    assert " ".join(f[1:]) == exp, (m, f[1:], exp)
php = json.load(open(sys.argv[3]))
for m, w in want.items():
    for k in ("disk", "cpu", "disk_alert", "disk_shutdown", "cpu_alert", "cpu_shutdown"):
        assert php[m][k] == w[k], (m, k, php[m][k], w[k])
PY2
# DX1215 table (2026.10.25h): scemd.xml:357-370 (Synology-DX1215, HIGH_EBOX == LOW_EBOX) == bash DX_STEPS == the page's SYNOHW_DX_TABLE
( . "$S/synohw-lib.sh"; synohw_defaults; synohw_tables; echo "${DX_STEPS[*]} $DX_SHUTDOWN" ) >"$SCRATCH/bash-dx"
python3 -I - "$T/../../capture/2026-10-23/bin/usr/syno/etc.defaults/scemd.xml" "$SCRATCH/bash-dx" "$FX/php-dx.json" "$T/fixtures/dx-table.json" <<'PYDX' && ok "DX1215 table: $SCEMD_SRC (both EBOX types, lines 357-370) == synohw-lib.sh == synohw-status.php" || bad dx-table-drift
import json, os, re, sys
ref = json.load(open(sys.argv[4]))
raw = open(sys.argv[1]).read().splitlines() if os.path.exists(sys.argv[1]) else []
rows = {"DUAL_MODE_HIGH_EBOX": (ref["steps"], ref["shutdown"]), "DUAL_MODE_LOW_EBOX": (ref["steps"], ref["shutdown"])} if not raw else {}
for i, l in enumerate(raw):
    m = re.search(r'hw_version="Synology-DX1215" period="300" threshold="2" type="(DUAL_MODE_\w+_EBOX)"', l)
    if not m: continue
    assert 357 <= i + 1 <= 370, ("DX1215 rows moved", i + 1)
    r = []; sd = None
    for x in raw[i + 1:]:
        if "</fan_config>" in x: break
        f = re.search(r'fan_speed="(\w+)"\s+action="(\w+)">(\d+)<', x)
        sp, act, thr = f.group(1).lower(), f.group(2), int(f.group(3))
        if act == "SHUTDOWN": sd = thr
        elif not r or r[-1][1] != sp: r.append([thr, sp])
    rows[m.group(1)] = (r, sd)
assert set(rows) == {"DUAL_MODE_HIGH_EBOX", "DUAL_MODE_LOW_EBOX"} and rows["DUAL_MODE_HIGH_EBOX"] == rows["DUAL_MODE_LOW_EBOX"], rows
steps, sd = rows["DUAL_MODE_LOW_EBOX"]
assert [list(x) for x in steps] == ref["steps"] and sd == ref["shutdown"], ("tests/fixtures/dx-table.json differs from scemd.xml", steps, sd, ref)
assert open(sys.argv[2]).read().split() == [f"{a}:{b}" for a, b in steps] + [str(sd)], open(sys.argv[2]).read()
php = json.load(open(sys.argv[3]))
assert php["steps"] == steps and php["shutdown"] == sd, php
PYDX
# read-only: the status library and endpoint run no command and write nothing
grep -nE '\b(exec|shell_exec|system|passthru|popen|proc_open|file_put_contents|fwrite|unlink|mkdir|rename|touch|chmod)\s*\(|`' "$P/include/synohw-status.php" "$P/include/status.php" "$P/include/synohw-hw.php" "$P/include/synohw-view.php" "$P/include/synohw-page.php" "$P/include/synohw-issues.php" "$P/include/synohw-anon.php" "$P/include/bays.php" \
  && bad "status library must be read-only" || ok "status library + endpoint: no command execution, no file writes"
# page: every form field has a native help block (:..._plug: ... :end), no markdown tables inside help (unreadable in dark themes)
# 2026.10.25j: over all tab pages (SynologyHW*.page)
fields=$(cat "$P"/SynologyHW*.page | grep -oE 'name="[A-Z][A-Z0-9_]+"' | sed 's/name="//; s/"//' | sort -u)
missing=; for f in $fields; do cat "$P"/SynologyHW*.page | grep -q "($f):" || missing+=" $f"; done
plugs=$(cat "$P"/SynologyHW*.page | grep -c '^:synohw_[a-z_]*_plug:$'); ends=$(cat "$P"/SynologyHW*.page | grep -c '^:end$')
[ -z "$missing" ] && [ "$plugs" = "$ends" ] && [ "$plugs" -ge "$(wc -w <<<"$fields")" ] && ! cat "$P"/SynologyHW*.page | grep -q '^> |' \
  && ok "page: all $(wc -w <<<"$fields") cfg fields labelled with their key, $plugs help blocks (each closed), no tables inside help" || bad "page help blocks (missing:$missing plugs=$plugs ends=$ends)"
# the sheet: the existing blocks are layout only (colours from the dynamix theme); since 2026.10.25f the graph section at the end
# defines colour TOKENS (--synohw-*), and every colour property in it references a var(), never a raw value
python3 -I - "$P/sheets/SynologyHWOverview.css" <<'PYCSS' && ok "page sheet: layout only before the graph tokens; graph colours only as --synohw-* tokens, used via var()" || bad "page sheet colours"
import re, sys
raw = open(sys.argv[1]).read()
head, sep, tail = raw.partition("---- history graphs (2026.10.25f) ----")
assert sep, "graph token marker missing"
head = re.sub(r"/\*.*?\*/", "", head, flags=re.S); tail = re.sub(r"/\*.*?\*/", "", tail.split("*/", 1)[1], flags=re.S)
assert not re.search(r"#[0-9a-f]{3,8}\b|rgba?\(|hsl\(|color\s*:", head, re.I), "colour before the graph section"
for decl in re.findall(r"([-\w]+)\s*:\s*([^;{}]+);", tail):
    prop, val = decl
    if re.search(r"#[0-9a-f]{3,8}\b|rgba?\(|hsl\(", val, re.I):
        assert prop.startswith("--synohw-"), ("raw colour outside a token", prop, val)
    if prop in ("color", "background", "background-color", "border", "border-color", "outline", "outline-color", "fill", "stroke"):
        assert "var(--" in val or val.strip() in ("0", "none", "transparent"), ("colour property without a token", prop, val)
PYCSS
# 2026.10.25j: tabbed page, the Unraid way (Unraid 7.3.4 DefaultPageLayout.php:52-58, MainContentTabbed.php, dynamix PowerMode.page):
# a header-only Type="xmenu" Tabs="true" parent in Utilities, children Menu="SynologyHW:<rank>"; the sheet is named after a child
# (a parent without text is not in $pages, so its sheet would never load); each settings form posts the same cfg through update.php
# with the same #command and its own Apply/Done; every cfg key is on exactly one form; the canonical reduced-motion block is in the sheet.
python3 -I - "$P" <<'PYTABS' && ok "page 25j/26b: header-only xmenu parent + 7 tabs (ranks 1-5, 5a Report, 6), sheet named after the first tab, 2 settings forms -> update.php with #file/#command + Apply/Done (14 daemon keys), 2 page-only forms without #command (MAIN_BADGES, AVR54_FIXED), each key on one form, reduced-motion block" || bad "tabbed page structure"
import sys, os, re, glob
P = sys.argv[1]
def hdr(f):
    raw = open(f).read(); h, _, body = raw.partition("\n---\n")
    return dict(re.findall(r'^(\w+)="(.*)"$', h, re.M)), body, raw
ph, pbody, praw = hdr(os.path.join(P, "SynologyHW.page"))
assert ph == {"Menu": "Utilities", "Type": "xmenu", "Title": "Synology Hardware", "Icon": "synology-hardware.png", "Tag": "server", "Tabs": "true"}, ph
assert "---" not in praw and pbody == "", "parent must be header only"
kids = {}
for f in glob.glob(os.path.join(P, "SynologyHW?*.page")):
    h, body, _ = hdr(f)
    if h.get("Menu") == "Buttons": continue   # 2026.10.26b: SynologyHWBadges.page, the Main page badge hook (no tab)
    m = re.fullmatch(r"SynologyHW:(\d+[a-z]?)", h.get("Menu", ""))
    assert m and h.get("Title") and h.get("Tag"), (f, h)
    assert "include/synohw-page.php" in body and "synohw_page_head();" in body, f
    kids[m.group(1)] = (os.path.basename(f), h["Title"], body)
order = ["1", "2", "3", "4", "5", "5a", "6"]
assert sorted(kids) == sorted(order), sorted(kids)
assert [kids[i][1] for i in order] == ["Overview", "DS2415+", "DX1215", "History", "Hardware", "Report", "About"], [kids[i][1] for i in order]
b = open(os.path.join(P, "SynologyHWBadges.page")).read()
assert b.startswith('Menu="Buttons"\nLink="nav-user"\n---\n') and "$task ?? '') === 'Main'" in b and "MAIN_BADGES" in b, "badge hook"
kids = {i + 1: kids[k] for i, k in enumerate(order)}
assert os.path.isfile(os.path.join(P, "sheets", kids[1][0][:-5] + ".css")) and not os.path.exists(os.path.join(P, "sheets", "SynologyHW.css"))
keys = []
forms = 0
pageonly = []
for i in range(1, 8):
    for fm in re.findall(r'<form markdown="1".*?</form>', kids[i][2], re.S):
        if 'name="#command"' not in fm:   # 2026.10.26b: display-only settings, saved without restarting the daemon
            assert 'action="/update.php"' in fm and 'name="#file" value="<?=$plugin?>/<?=$plugin?>.cfg"' in fm and '<input type="submit" value="_(Apply)_" disabled>' in fm, kids[i][0]
            pageonly += re.findall(r'<select name="([A-Z0-9_]+)"', fm); continue
        forms += 1
        assert 'action="/update.php"' in fm and 'name="#file" value="<?=$plugin?>/<?=$plugin?>.cfg"' in fm and 'name="#command" value="/plugins/<?=$plugin?>/scripts/synohw-apply"' in fm, kids[i][0]
        assert '<input type="submit" value="_(Apply)_" disabled>' in fm and 'onclick="done()"' in fm, kids[i][0]
        keys += re.findall(r'<(?:select|input type="number") name="([A-Z_]+)"', fm)
assert forms == 2, forms
want = ["FAN_MODE", "DS_MODE", "DS_FLOOR", "DS_DUTY", "KEEPALIVE_SEC", "BEEP_ON_START", "AC_RECOVERY", "WOL", "SHUTDOWN_ACTION", "DEBUG", "MACFIX", "DX_MODE", "DX_LEVEL", "LED_HEALTH"]
assert sorted(keys) == sorted(want) and len(keys) == len(set(keys)), keys
assert sorted(pageonly) == ["AVR54_FIXED", "MAIN_BADGES"], pageonly
css = open(os.path.join(P, "sheets", kids[1][0][:-5] + ".css")).read()
assert "@media (prefers-reduced-motion: reduce)" in css and "animation-duration: 0.01ms !important;" in css and "animation-iteration-count: infinite !important" in css
PYTABS
# --- disks (2026.10.25c): real disks only, disks.ini + devs.ini, USB excluded, '*' + spundown=0 = no reading ---
# Fixture trees: $FX/<scenario> from mkfixture.py (incl. "measured" = the DS2415+ files of 2026-10-25 01:52) and a
# hand-made "edge" tree. Each check runs against a lib + a status library given as arguments, so the mutation
# section below can prove that the same checks FAIL on broken copies.
E=$SCRATCH/edge; mkdir -p "$E/var/local/emhttp"
mksb() { mkdir -p "$E/sys/devices/$2" "$E/sys/block/$1"; ln -sfn "../../devices/$2" "$E/sys/block/$1/device"; }
mksb sda pci0000:00/0000:00:14.0/usb1/1-1/1-1:1.0/host9/target9:0:0/9:0:0:0
mksb sdf pci0000:00/0000:00:14.0/usb1/1-2/1-2:1.0/host10/target10:0:0/10:0:0:0
for x in b c d e g h; do mksb sd$x pci0000:00/0000:00:13.0/ata1/host0/target0:0:0/0:0:0:$x; done
printf '%s\n' '["flash"]' 'name="flash"' 'device="sda"' 'status="DISK_OK"' 'temp="*"' \
  '["disk1"]' 'name="disk1"' 'device="sdb"' 'status="DISK_OK"' 'spundown="0"' 'temp="38"' 'id="WDC_WD40EFRX-68N32N0_WD-WCC7K0000001"' \
  '["parity"]' 'name="parity"' 'device="sdc"' 'status="DISK_OK"' 'spundown="1"' 'temp="*"' \
  '["disk2"]' 'name="disk2"' 'device=""' 'status="DISK_NP"' 'temp="*"' \
  '["disk3"]' 'name="disk3"' 'device="sdz"' 'status="DISK_NP_MISSING"' 'temp="*"' \
  '["x"]' 'name="a b;rm"' 'device="sdd"' 'status="DISK_OK"' 'spundown="0"' 'temp="40"' \
  '["cache"]' 'name="cache"' 'device="sde"' 'status="DISK_OK"' 'spundown="0"' 'temp="NaN"' \
  '["evil"]' 'name="evil"' 'device="sd<b>z"' 'status="DISK_OK"' 'temp="55"' >"$E/var/local/emhttp/disks.ini"
printf '%s\n' '["dev1"]' 'device="sdf"' 'spundown="0"' 'temp="44"' \
  '["dev2"]' 'device="sdg"' 'spundown="0"' 'temp="*"' 'id="ST4000VN008-2DR166_ZDH00000"' \
  '["dev3"]' 'device="sdh"' 'spundown="0"' 'temp="33"' 'transport="usb"' \
  '["dev4"]' 'device="sdb"' 'spundown="0"' 'temp="60"' >"$E/var/local/emhttp/devs.ini"
# expected list "<key> <reading> <device> <id> <src>": sdd keyed by device (bad name), sde NaN -> ?, sdf USB by sysfs,
# sdh USB by transport, dev4 duplicate of sdb, sd<b>z invalid device, flash/disk2/disk3 skipped
EXP_EDGE='disk1 38 sdb WDC_WD40EFRX-68N32N0_WD-WCC7K0000001 array
parity * sdc - array
sdd 40 sdd - array
cache ? sde - array
sdg ? sdg ST4000VN008-2DR166_ZDH00000 unassigned'
EXP_MEAS='sdd 28 sdd - unassigned
sdc 31 sdc - unassigned'
bash_list() { SYNOHW_DISKS_INI=$1/var/local/emhttp/disks.ini SYNOHW_DEVS_INI=$1/var/local/emhttp/devs.ini SYNOHW_SYSBLOCK=$1/sys/block \
  bash -c '. "$1"; synohw_disk_'"${3:-list}" _ "$2"; }
php_list() { php -r '$GLOBALS["synohw_path_override"]=["disks_ini"=>$argv[1]."/var/local/emhttp/disks.ini","devs_ini"=>$argv[1]."/var/local/emhttp/devs.ini","sysblock"=>$argv[1]."/sys/block"];
  require $argv[2]; foreach (synohw_disks(synohw_paths())["list"] as $d) echo "{$d["name"]} {$d["reading"]} {$d["device"]} {$d["id"]} {$d["src"]}\n";' "$1" "$2"; }
disk_checks() {  # disk_checks <lib.sh> <synohw-status.php> [quiet]: 0 = all pass
  local lib=$1 php=$2 q=${3:-} e=0 r d
  say() { [ -n "$q" ] || echo "$@"; }
  r=$(bash_list "$FX/measured" "$lib"); [ "$r" = "$EXP_MEAS" ] && say "PASS measured box: devs.ini HDDs sdd 28 / sdc 31 are the only disks; 24 DISK_NP slots, flash sda and USB DOM sdb skipped" || { say "FAIL measured list [$r]"; e=1; }
  r=$(bash_list "$FX/measured" "$lib" temp); [ "$r" = "31 0" ] && say "PASS measured box: fan input 31 C, 0 unreadable (was: 'standby' -> 20 % whatever the HDD temperature)" || { say "FAIL measured temp [$r]"; e=1; }
  r=$(bash_list "$FX/measured" "$lib" temps); [ "$r" = $'sdd 28\nsdc 31' ] && say "PASS measured box: history pairs 'sdd 28 / sdc 31' only (no phantom disk1..22 standby)" || { say "FAIL measured temps [$r]"; e=1; }
  r=$(bash_list "$E" "$lib"); [ "$r" = "$EXP_EDGE" ] && say "PASS edge list: NP/NP_MISSING/empty device/flash/USB (sysfs + transport)/duplicate/invalid device skipped; bad name -> device key; NaN and '*'+spundown=0 -> ?" || { say "FAIL edge list [$r]"; e=1; }
  r=$(bash_list "$E" "$lib" temp); [ "$r" = "40 2" ] && say "PASS edge: fan input 40 C with 2 unreadable disks (-> floor)" || { say "FAIL edge temp [$r]"; e=1; }
  for d in "$FX/measured" "$E" "$FX/normal" "$FX/hot"; do
    r=$(php_list "$d" "$php" 2>&1); [ "$r" = "$(bash_list "$d" "$lib")" ] || { say "FAIL page/daemon disk list differ on ${d##*/}: php [$r]"; e=1; }
  done
  [ $e = 0 ] && say "PASS page synohw_disks() == daemon synohw_disk_list() on measured, edge, normal, hot fixtures"
  return $e
}
disk_checks "$S/synohw-lib.sh" "$P/include/synohw-status.php" && ok "disk source checks" || bad "disk source checks"
# box by sysfs (2026.10.25h): the daemon's synohw_disk_box == the page's synohw_disk_box on every fixture disk
box_bash() { SYNOHW_SYSBLOCK=$1/sys/block bash -c '. "$1"; for d in $(ls "$SYNOHW_SYSBLOCK"); do echo "$d $(synohw_disk_box "$d")"; done; echo "sdz $(synohw_disk_box sdz)"' _ "$2"; }
box_php() { php -r '$GLOBALS["synohw_path_override"]=["sysblock"=>$argv[1]."/sys/block"]; require $argv[2]; $p=synohw_paths();
  foreach (array_merge(array_map("basename", glob($p["sysblock"]."/*")), ["sdz"]) as $d) echo "$d " . synohw_disk_box($p, $d)["box"] . "\n";' "$1" "$2"; }
r1=$(box_bash "$FX/graphs" "$S/synohw-lib.sh"); r2=$(box_php "$FX/graphs" "$P/include/synohw-status.php"); r3=$(box_bash "$FX/measured" "$S/synohw-lib.sh"); r4=$(box_php "$FX/measured" "$P/include/synohw-status.php")
[ "$r1" = "$r2" ] && [ "$r3" = "$r4" ] && [ "$(tr '\n' ' ' <<<"$r1")" = "sda usb sdb ds sdc ds sdd ds sde dx sdf dx sdz unknown " ] && [ "$(tr '\n' ' ' <<<"$r3")" = "sda usb sdb usb sdc ds sdd dx sdz unknown " ] \
  && ok "disk box: daemon (bash) == page (PHP) on graphs + measured fixtures: 88SE9215 = ds, 88SE9235/PMP = dx, USB, missing = unknown" || bad "disk box drift [$r1 | $r2 | $r3 | $r4]"
r=$(SYNOHW_DISKS_INI=$FX/graphs/var/local/emhttp/disks.ini SYNOHW_DEVS_INI=/nonexistent SYNOHW_SYSBLOCK=$FX/graphs/sys/block bash -c ". '$S/synohw-lib.sh'; synohw_dx_temp")
[ "$r" = "41 0 2 disk3" ] && ok "DX input: graphs fixture -> hottest DX1215 disk disk3 41 C, 2 DX disks (disk4 asleep), internal disks ignored" || bad "dx temp [$r]"
# all-standby (spundown=1) still counts as cool; all unreadable -> rc 1 (floor), never "standby"
printf '["disk1"]\nname="disk1"\ndevice="sdb"\nstatus="DISK_OK"\nspundown="1"\ntemp="*"\n' >"$SCRATCH/sb.ini"
printf '["disk1"]\nname="disk1"\ndevice="sdb"\nstatus="DISK_OK"\nspundown="0"\ntemp="*"\n' >"$SCRATCH/ur.ini"
r1=$(SYNOHW_DISKS_INI=$SCRATCH/sb.ini SYNOHW_DEVS_INI=/nonexistent SYNOHW_SYSBLOCK=/nonexistent bash -c ". '$S/synohw-lib.sh'; synohw_disk_temp"); rc1=$?
r2=$(SYNOHW_DISKS_INI=$SCRATCH/ur.ini SYNOHW_DEVS_INI=/nonexistent SYNOHW_SYSBLOCK=/nonexistent bash -c ". '$S/synohw-lib.sh'; synohw_disk_temp"); rc2=$?
[ "$r1 $rc1" = "standby 0 0" ] && [ "$r2 $rc2" = "- 1 1" ] && ok "temp '*': spundown=1 -> standby (cool); spundown=0 -> unreadable, rc 1 (floor), never standby" || bad "standby vs unreadable [$r1 $rc1 / $r2 $rc2]"
# mutations: each broken copy must make disk_checks fail (proves the checks can fail)
MU=$SCRATCH/mut; mkdir -p "$MU"; mut_ok=0; mut_n=0
mutate() {  # mutate <name> <file: lib|php> <sed expr>
  local n=$1 lib=$S/synohw-lib.sh php=$P/include/synohw-status.php
  mut_n=$((mut_n + 1))
  if [ "$2" = lib ]; then sed "$3" "$lib" >"$MU/$n.sh"; cmp -s "$lib" "$MU/$n.sh" && { bad "mutation $n did not change the lib"; return; }; lib=$MU/$n.sh
  else sed "$3" "$php" >"$MU/$n.php"; cmp -s "$php" "$MU/$n.php" && { bad "mutation $n did not change the page lib"; return; }; php=$MU/$n.php; fi
  disk_checks "$lib" "$php" quiet && bad "mutation $n survived (checks did not fail)" || mut_ok=$((mut_ok + 1))
}
mutate no-np-skip   lib 's/  case "$_status" in DISK_NP\*) return 0 ;; esac//'
mutate no-usb-sysfs lib 's/^  synohw_is_usb "$_dev" \&\& return 0$//'
mutate no-devs-ini  lib 's/for f in "$SYNOHW_DISKS_INI" "$SYNOHW_DEVS_INI"; do/for f in "$SYNOHW_DISKS_INI"; do/'
mutate star-is-standby lib "s/elif \[ \"\$_temp\" = '\*' \] \&\& \[ \"\$_spun\" = 1 \]; then r='\*'/elif [ \"\$_temp\" = '*' ]; then r='*'/"
mutate php-no-usb   php 's/if ($tr === .usb. || synohw_is_usb($p, $dev)) continue;/if ($tr === "usb") continue;/'
mutate php-no-np    php 's/if (strncmp($status, .DISK_NP., 7) === 0 || $dev === ..) continue;/if ($dev === "") continue;/'
[ $mut_ok = $mut_n ] && ok "mutation check: $mut_ok/$mut_n broken copies (NP skip, USB sysfs, devs.ini, '*'=standby, page USB, page NP) all fail the disk checks" || bad "mutations: only $mut_ok/$mut_n caught"
# the page's port mapping equals synohw-macfix's (same fake /sys tree, same DOM MACs)
D=$SCRATCH/mf2; mkdir -p "$D/run"; "$S/synohw-vender" decode "$T/fixtures/vender" >"$D/run/macs"
php -r '$GLOBALS["synohw_path_override"]=["sysnet"=>$argv[1]."/net","run"=>$argv[1]."/run","bonding"=>"/nonexistent"]; require $argv[2];
  foreach (synohw_ports(synohw_paths())["ports"] as $p) echo "port {$p["nic"]} {$p["pci"]} {$p["cur"]} {$p["dom"]} {$p["state"]}\n";' "$D" "$P/include/synohw-status.php" >"$SCRATCH/php-ports"
mf "$D" status | grep '^port' >"$SCRATCH/mf-ports"
cmp -s "$SCRATCH/php-ports" "$SCRATCH/mf-ports" && ok "page port table == synohw-macfix status (PCI order, MAC n -> port n, state)" || { diff "$SCRATCH/php-ports" "$SCRATCH/mf-ports"; bad ports-drift; }

# --- Hardware section cache (2026.10.25g): synohw-inventory never queries a disk Unraid reports in standby, always passes
#     -n standby, skips USB, writes atomically, removes stale files, locks against overlap ---
inv_tree() {  # inv_tree <dir>: disks.ini/devs.ini + sysfs + stub smartctl/ethtool
  local D=$1; rm -rf "$D"; mkdir -p "$D/bin" "$D/sys/block" "$D/run/inventory" "$D/net/eth0" "$D/net/bond0"
  printf '["flash"]\nname="flash"\ndevice="sda"\ntemp="*"\n["parity"]\nname="parity"\ndevice="sdb"\nstatus="DISK_OK"\nspundown="0"\ntemp="35"\n["disk1"]\nname="disk1"\ndevice="sdc"\nstatus="DISK_OK"\nspundown="1"\ntemp="*"\n["disk2"]\nname="disk2"\ndevice="sdd"\nstatus="DISK_OK"\nspundown="0"\ntemp="*"\n' >"$D/disks.ini"
  printf '["dev1"]\ndevice="sde"\nspundown="0"\ntemp="*"\ntransport="usb"\n' >"$D/devs.ini"
  for dv in sda:usb1/1-1 sdb:ata1 sdc:ata2 sdd:ata3 sde:usb1/1-2; do mkdir -p "$D/sys/devices/x/${dv#*:}/host0" "$D/sys/block/${dv%%:*}"; ln -s "../../devices/x/${dv#*:}/host0" "$D/sys/block/${dv%%:*}/device"; done
  mkdir -p "$D/sys/devices/x/eth0"; ln -s ../../sys/devices/x/eth0 "$D/net/eth0/device"
  cat >"$D/bin/smartctl" <<'ST'
#!/bin/bash
echo "$*" >>"$INVT/calls"
case "$*" in *"-n standby"*/dev/sdd) echo "Device is in STANDBY mode, exit(2)"; exit 2 ;; esac
cat "$INVT/smart.txt"; exit 0
ST
  printf '#!/bin/bash\necho "ethtool $*" >>"$INVT/calls"; cat "$INVT/eth.txt"\n' >"$D/bin/ethtool"; chmod +x "$D/bin/smartctl" "$D/bin/ethtool"
  cp "$T/fixtures/hw-2026-10-25/smart-wd.txt" "$D/smart.txt"; cp "$T/fixtures/hw-2026-10-25/ethtool-up.txt" "$D/eth.txt"
  echo old >"$D/run/inventory/smart-sdz.txt"
  printf '#!/bin/bash\necho "[    1.0] usb 1-2: device descriptor read/64, error -71"\necho "[    2.0] rtc_cmos 00:01: setting system clock to 2026-10-25T15:46:09 UTC (1792943169)"\necho "[    3.0] ata1: SATA link up"\n' >"$D/bin/dmesg"; chmod +x "$D/bin/dmesg"
}
inv_run() { INVT=$1 SYNOHW_RUN=$1/run SYNOHW_DISKS_INI=$1/disks.ini SYNOHW_DEVS_INI=$1/devs.ini SYNOHW_SYSBLOCK=$1/sys/block SYNOHW_SYSNET=$1/net \
            SYNOHW_SMARTCTL=$1/bin/smartctl SYNOHW_ETHTOOL=$1/bin/ethtool SYNOHW_UEFI1=$T/fixtures/uefi1-ds2415p.bin SYNOHW_DMESG=$1/bin/dmesg bash "$2"; }
inv_checks() {  # inv_checks <inventory script> [quiet]: 0 = all pass
  local s=$1 q=${2:-} D=$SCRATCH/inv e=0 c; say() { [ -z "$q" ] && echo "$@"; return 0; }
  inv_tree "$D"; inv_run "$D" "$s"; c=$(grep -v '^ethtool' "$D/calls" 2>/dev/null)
  [ "$(wc -l <<<"$c")" = 2 ] && grep -q '/dev/sdb$' <<<"$c" && grep -q '/dev/sdd$' <<<"$c" && ! grep -qE '/dev/sd[ace]$' <<<"$c" \
    && say "PASS inventory: smartctl only for the awake disks sdb (35 C) and sdd (spun up, no reading); never sdc (standby), the flash or USB" || { say "FAIL inventory calls [$c]"; e=1; }
  [ "$(grep -c -- '-n standby -i -H -A -l error -l sataphy /dev/' <<<"$c")" = 2 ] && say "PASS inventory: every smartctl call carries -n standby" || { say "FAIL inventory: a call without -n standby [$c]"; e=1; }
  head -1 "$D/run/inventory/smart-sdb.txt" 2>/dev/null | grep -qE '^# synohw dev=sdb key=parity id=- at=[0-9]+ rc=0$' && grep -q 'PASSED' "$D/run/inventory/smart-sdb.txt" \
    && head -1 "$D/run/inventory/smart-sdd.txt" 2>/dev/null | grep -q 'rc=2$' && say "PASS inventory: cache files with header (dev, key, time, rc); the standby race recorded as rc=2" || { say "FAIL inventory cache files"; e=1; }
  [ ! -e "$D/run/inventory/smart-sdz.txt" ] && [ ! -e "$D/run/inventory/smart-sdc.txt" ] && ! ls "$D/run/inventory/"*.tmp >/dev/null 2>&1 \
    && say "PASS inventory: stale file of a vanished disk removed, no file for the standby disk, no tmp left" || { say "FAIL inventory stale/tmp files"; e=1; }
  [ -e "$D/run/inventory/ethtool-eth0.txt" ] && [ ! -e "$D/run/inventory/ethtool-bond0.txt" ] && grep -q '^smart=2$' "$D/run/inventory/at" && grep -q '^skipped_standby=1$' "$D/run/inventory/at" && [ ! -d "$D/run/inventory/.lock" ] \
    && say "PASS inventory: ethtool for the physical port only, 'at' summary (2 read, 1 standby skipped), lock released" || { say "FAIL inventory ethtool/at/lock"; e=1; }
  F=$D/run/inventory/firmware.txt   # 2026.10.26b: BIOS from the measured DS2415+ UEFI1 table, dmesg heuristics
  grep -qx 'bvdt=ok' "$F" && grep -qx 'bios=M.211' "$F" && grep -qx 'bios_board=MohonPeak' "$F" && grep -qx 'bios_extra=05.04.12' "$F" && grep -qx 'usb_enum_errors=1' "$F" \
    && grep -q '^usb_err=.*device descriptor read/64, error -71$' "$F" && grep -q '^rtc_clock=.*setting system clock to 2026-10-25T15:46:09 UTC' "$F" && grep -qx 'igb_ports=0' "$F" \
    && say "PASS inventory 26b: firmware.txt has the BVDT BIOS M.211 (MohonPeak, 05.04.12), 1 USB enumeration error with its line, the RTC clock line, igb count" || { say "FAIL inventory firmware.txt [$(tr '\n' ' ' <"$F" 2>/dev/null)]"; e=1; }
  mkdir "$D/run/inventory/.lock"; : >"$D/calls"; inv_run "$D" "$s"
  [ ! -s "$D/calls" ] && say "PASS inventory: a fresh lock (another run busy) -> no command at all" || { say "FAIL inventory ran despite the lock"; e=1; }
  return $e
}
nx=$(find "$S" -name 'synohw-*' ! -name synohw-lib.sh ! -perm -u+x); [ -z "$nx" ] && ok "every scripts/synohw-* except the library is executable in the source tree (the daemon checks -x)" || bad "not executable: $nx"
inv_checks "$S/synohw-inventory" && ok "inventory cache checks" || bad "inventory cache checks"
MU2=$SCRATCH/mut-inv; mkdir -p "$MU2"; inv_mut=0
for m in 's/if \[ "\$r" = .\*. \]; then n_skip=\$((n_skip + 1)); continue; fi/:/' 's/-n standby -i -H/-i -H/' 's/\[ -e "\$d\/device" \] || continue/:/'; do
  sed "$m" "$S/synohw-inventory" >"$MU2/x"; cmp -s "$S/synohw-inventory" "$MU2/x" && { bad "inventory mutation did not apply: $m"; continue; }
  cp "$S/synohw-lib.sh" "$MU2/"; inv_checks "$MU2/x" quiet && bad "inventory mutation survived: $m" || inv_mut=$((inv_mut + 1))
done
[ "$inv_mut" = 3 ] && ok "inventory: 3 mutations (standby disks queried, no -n standby, bond/bridge queried) each caught"

# --- daemon + rc + K hook against a pty "MCU" (python harness) ---
python3 -I "$T/mcu_pty_test.py" && ok "pty harness" || bad "pty harness"

# --- fan sweep (2026.10.25d): pty fake MCU, small timings (tests/sweep_pty_test.py) ---
find "$T" -name __pycache__ -prune -exec rm -rf {} +          # stale bytecode must not stand in for the code under test
python3 -I "$T/sweep_pty_test.py" && ok "sweep pty harness" || bad "sweep pty harness"
# mutation check: each broken copy must make the matching scenario FAIL (rc != 0)
MS=$SCRATCH/mut-sweep; rm -rf "$MS"; mkdir -p "$MS"; msw_ok=0; msw_n=0
sweep_mut() {  # sweep_mut <name> <scenario> <file: daemon|lib|rc> <sed expr>
  local n=$1 sc=$2 f=$3 e=$4 d=$MS/$1 src out
  mkdir -p "$d"; cp "$S"/synohw-* "$d"/; cp "$SRC/etc/rc.d/rc.synology-hardware" "$d/rc"
  case $f in daemon) src=$d/synohw-daemon ;; lib) src=$d/synohw-lib.sh ;; rc) src=$d/rc ;; esac
  cp "$src" "$src.orig"; sed -i "$e" "$src"; msw_n=$((msw_n + 1))
  cmp -s "$src" "$src.orig" && { bad "sweep mutation $n did not change $f"; return; }
  rm -f "$src.orig"; chmod +x "$d"/synohw-* "$d/rc"
  out=$(python3 -I "$T/sweep_pty_test.py" --scripts "$d" --rc "$d/rc" --only "$sc" 2>&1) && bad "sweep mutation $n survived (scenario $sc still passes)" \
    || { msw_ok=$((msw_ok + 1)); echo "  caught $n: $(grep -c '^FAIL' <<<"$out") failing check(s)"; }
}
sweep_mut no-unknown-byte-abort byte-abort daemon 's/\[ -n "\$sw_badbyte" \]/[ -n "" ]/g'
sweep_mut no-disk-limit        disk-abort daemon 's/^if \[ -n "\$sw_hotv" \] \&\& \[ "\$sw_hotv" -ge "\$SWEEP_DISK_ABORT" \]; then/if false; then/;s/^  if \[ -n "\$sw_hotv" \] \&\& \[ "\$sw_hotv" -ge "\$SWEEP_DISK_ABORT" \]; then/  if false; then/'
sweep_mut no-unreadable-abort  unreadable daemon 's/if \[ \$rd = 1 \]; then/if false; then/'
sweep_mut no-early-advance     settle     daemon 's/= yes \]; then why=settled/= never ]; then why=settled/'
sweep_mut plan-below-10-ok     badplan    rc     's/\^\[1-9\]\[0-9\]\$/^[0-9]+$/'
[ $msw_ok = $msw_n ] && ok "sweep mutation check: $msw_ok/$msw_n broken copies (unknown-byte abort, disk limit, unreadable abort, early advance, plan floor) fail their scenario" || bad "sweep mutations: only $msw_ok/$msw_n caught"

# --- DX1215 fan policy + DS fan modes (2026.10.25h): fake /sys/kernel/synology_dx + pty MCU (tests/dx_pty_test.py) ---
find "$T" -name __pycache__ -prune -exec rm -rf {} +
python3 -I "$T/dx_pty_test.py" && ok "DX pty harness" || bad "DX pty harness"
MD=$SCRATCH/mut-dx; rm -rf "$MD"; mkdir -p "$MD"; mdx_ok=0; mdx_n=0
dx_mut() {  # dx_mut <name> <scenario> <file: daemon|lib> <sed expr> [<sed expr 2>]
  local n=$1 sc=$2 f=$3 d=$MD/$1 src out
  mkdir -p "$d"; cp "$S"/synohw-* "$d"/; cp "$SRC/etc/rc.d/rc.synology-hardware" "$d/rc"
  case $f in daemon) src=$d/synohw-daemon ;; lib) src=$d/synohw-lib.sh ;; esac
  cp "$src" "$src.orig"; sed -i "$4" "$src"; [ -n "${5:-}" ] && sed -i "$5" "$src"; mdx_n=$((mdx_n + 1))
  cmp -s "$src" "$src.orig" && { bad "DX mutation $n did not change $f"; return; }
  rm -f "$src.orig"; chmod +x "$d"/synohw-* "$d/rc"
  out=$(python3 -I "$T/dx_pty_test.py" --scripts "$d" --rc "$d/rc" --only "$sc" 2>&1) && bad "DX mutation $n survived (scenario $sc still passes)" \
    || { mdx_ok=$((mdx_ok + 1)); echo "  caught $n: $(grep -c '^FAIL' <<<"$out") failing check(s)"; }
}
dx_mut no-alarm-full     alarm      daemon 's/^  if \[ \$al = 1 \]; then want=full; why=alarm; fi$/  :/'
dx_mut stop-allowed      nostop     lib    's/^    full|middle|low) ;;$/    full|middle|low|stop) ;;/' 's/DX_LEVEL) \[\[ \$v =~ ^(full|middle|low)\$ \]\] ;;/DX_LEVEL) [[ $v =~ ^(full|middle|low|stop)$ ]] ;;/'
dx_mut dx-uses-all-disks auto       lib    's/^    \[ "\$(synohw_disk_box "\$dev")" = dx \] || continue$/    :/'
dx_mut redundant-writes  auto       daemon 's/if \[ "\$want" != "\$lvl" \] || \[ \$((EPOCHSECONDS - dx_wrote_at))/if true || [ $((EPOCHSECONDS - dx_wrote_at))/'
dx_mut no-noread-middle  unreadable daemon 's/\[ "\$(synohw_dx_rank "\$want")" -lt 2 \] \&\& { want=middle; why=noread; }/:/'
dx_mut manual-no-alert   dsmode     daemon 's/\[ "\$want_alert" = 1 \] \&\& \[ "\$want_duty" -lt 99 \] \&\& { want_duty=99; chosen=alert; }/:/'
dx_mut led-no-dsbl       leds       lib    's/    case "\$_status" in DISK_DSBL|DISK_DSBL_NEW|DISK_INVALID|DISK_WRONG) r=1;/    case "$_status" in NEVER) r=1;/'
dx_mut led-no-smart      leds       lib    's/\$9 == "FAILING_NOW"/$9 == "NEVER"/'
dx_mut led-no-clear      leds       daemon 's/^  declare -F led_clear >\/dev\/null \&\& led_clear .*$/  :/'
dx_mut led-ignore-off    leds       daemon 's/\[ "\$(<"\$d\/leds_enabled")" = 1 \] \&\& want/true \&\& want/'
dx_mut led-health-no     leds       daemon 's/^  if \[ "\$LED_HEALTH" != yes \]; then$/  if false; then/'
[ $mdx_ok = $mdx_n ] && ok "DX mutation check: $mdx_ok/$mdx_n broken copies (alarm->full, never stop, DX disks only, no redundant writes, unreadable->middle, MANUAL alert->99) fail their scenario" || bad "DX mutations: only $mdx_ok/$mdx_n caught"
find "$T" -name __pycache__ -prune -exec rm -rf {} +
echo; [ $fails = 0 ] && echo "SUITE: ALL PASS" || echo "SUITE: $fails FAILED"; exit $fails
