# synohw-lib.sh - shared functions for the synology-hardware plugin (DS2415+ front-panel MCU on ttyS1).
# Written: 2026-10-24, logging + safe cfg parser 2026-10-25, disks.ini NP slots + devs.ini + USB exclusion 2026-10-25c, fan sweep helpers 2026-10-25d, INVENTORY_PERIOD 2026-10-25g,
# fan modes DS_MODE/DS_FLOOR/DS_DUTY + DX1215 fan policy helpers (disk box from sysfs, DX table, the fan_level write guard) 2026-10-25h. Sourced by every synohw-* script and the tests.
# Pure bash + coreutils. Every path / external tool is overridable through SYNOHW_* variables so tests run offline.

SYNOHW_TTY=${SYNOHW_TTY:-/dev/ttyS1}
SYNOHW_RUN=${SYNOHW_RUN:-/var/run/synology-hardware}
SYNOHW_HWMON=${SYNOHW_HWMON:-/sys/class/hwmon}
SYNOHW_THERMAL=${SYNOHW_THERMAL:-/sys/class/thermal}
SYNOHW_DISKS_INI=${SYNOHW_DISKS_INI:-/var/local/emhttp/disks.ini}
SYNOHW_DEVS_INI=${SYNOHW_DEVS_INI:-/var/local/emhttp/devs.ini}
SYNOHW_SMARTCTL=${SYNOHW_SMARTCTL:-smartctl}
SYNOHW_SYSBLOCK=${SYNOHW_SYSBLOCK:-/sys/block}
SYNOHW_FD=${SYNOHW_FD:-3}
SYNOHW_DXSYS=${SYNOHW_DXSYS:-/sys/kernel/synology_dx}   # the synology-dx plugin's module (DX1215), 2026.10.25h
SYNOHW_SYSMODULE=${SYNOHW_SYSMODULE:-/sys/module}
SYNOHW_SMARTDIR=${SYNOHW_SMARTDIR:-/var/local/emhttp/smart}   # Unraid's own SMART cache (emhttpd refreshes it), 2026.10.26a

# ---- configuration (defaults; overridden by /boot/config/plugins/synology-hardware/synology-hardware.cfg) ----
synohw_defaults() {
  FAN_MODE=LOW            # LOW = DSM default table, HIGH = the cooler "DUAL_MODE_HIGH" table
  KEEPALIVE_SEC=10        # measured: scemd sends 'u' about every 10 s
  CPU_PERIOD=30           # scemd cpu_temperature period
  DISK_PERIOD=60          # scemd uses 300 s; we poll disks.ini + devs.ini (free) every 60 s
  HYST=2                  # scemd threshold="2": step down only 2 C below the step's threshold
  FAN_REASSERT=300        # re-send the current duty this often (in case the MCU reset)
  UNREADABLE_FLOOR=70     # duty used when one temperature source cannot be read ("MIDDLE")
  NO_DATA_DUTY=99         # duty used when NO temperature can be read at all ("FULL")
  TICK=1                  # main loop granularity in seconds (tests use 0.2)
  BEEP_ON_START=no
  AC_RECOVERY=keep        # keep|on|off  -> nothing | q | p   ('keep' = do not touch what DSM left)
  WOL=keep                # keep|on|off  -> nothing | l | k   (MCU wake-on-LAN)
  SHUTDOWN_ACTION=no      # yes = run /sbin/poweroff when a temperature reaches the SHUTDOWN band
  MACFIX=yes              # yes = keep /boot/config/udev/70-synology-hardware-mac.rules in place (real MACs)
  DEBUG=no                # yes = log every MCU byte and every command sent (noisy: the MCU streams "K3")
  MCU_LOG_SEC=600         # non-debug: MCU byte counts are summarised this often
  SWEEP_MIN_HOLD=600      # fan sweep: every step is held at least this long (s) ...
  SWEEP_MAX_HOLD=1800     # ... and at most this long
  SWEEP_SAMPLE=30         # one CSV row this often (s)
  SWEEP_SETTLE_WINDOW=600 # settled = no temperature moved more than SWEEP_SETTLE_DELTA over this many seconds
  SWEEP_SETTLE_DELTA=0.5  # C
  INVENTORY_PERIOD=600    # Hardware section cache (SMART of awake disks, ethtool): refreshed this often (s), 2026.10.25g
  PLACEHOLDER_MAC_PREFIX=00:a0:c9:00:00
  # 2026.10.25h: fan modes per box
  DS_MODE=AUTO            # AUTO = the FAN_MODE table (scemd.xml), MANUAL = DS_DUTY (alert band and unreadable floors still raise it)
  DS_FLOOR=20             # AUTO: duty of the table's lowest row (DSM: 20 %); 10..20
  DS_DUTY=40              # MANUAL: fixed duty 10..99
  DX_MODE=AUTO            # AUTO = DSM's DX1215 EBOX table on DX1215 disks only, MANUAL = DX_LEVEL, LINKED = follow the DS2415+ duty
  DX_LEVEL=low            # MANUAL: full|middle|low (never stop)
  DX_PERIOD=60            # read the synology_dx sysfs and decide the DX1215 level this often (s)
  # 2026.10.26a: bay LEDs. The synology-expansion plugin switches them on; this daemon owns the orange (fault) policy
  LED_HEALTH=yes          # yes = orange on a bay whose disk is unhealthy (synohw_disk_health), off otherwise; no = never set orange
  LED_PERIOD=60           # evaluate disk health and the fault bits this often (s)
}

# The cfg is written by the webGui (update.php writes KEY="value") and must never be executed:
# parse KEY=value lines, accept only known keys, and only values that pass the key's check.
synohw_cfg_ok() {  # synohw_cfg_ok KEY VALUE
  local v=$2
  case "$1" in
    FAN_MODE) [[ $v =~ ^(LOW|HIGH)$ ]] ;;
    KEEPALIVE_SEC) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 2 ] && [ "$v" -le 60 ] ;;
    CPU_PERIOD|DISK_PERIOD|FAN_REASSERT|MCU_LOG_SEC|SWEEP_MIN_HOLD|SWEEP_MAX_HOLD|SWEEP_SAMPLE|SWEEP_SETTLE_WINDOW) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 1 ] && [ "$v" -le 86400 ] ;;
    INVENTORY_PERIOD) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 60 ] && [ "$v" -le 86400 ] ;;
    HYST) [[ $v =~ ^[0-9]$ ]] ;;
    UNREADABLE_FLOOR|NO_DATA_DUTY) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 20 ] && [ "$v" -le 99 ] ;;
    TICK) [[ $v =~ ^[0-9]+(\.[0-9]+)?$ ]] ;;
    SWEEP_SETTLE_DELTA) [[ $v =~ ^[0-9](\.[0-9])?$ ]] ;;
    BEEP_ON_START|SHUTDOWN_ACTION|MACFIX|DEBUG) [[ $v =~ ^(yes|no)$ ]] ;;
    AC_RECOVERY|WOL) [[ $v =~ ^(keep|on|off)$ ]] ;;
    PLACEHOLDER_MAC_PREFIX) [[ $v =~ ^[0-9a-f]{2}(:[0-9a-f]{2}){1,5}$ ]] ;;
    DS_MODE) [[ $v =~ ^(AUTO|MANUAL)$ ]] ;;
    DS_FLOOR) [[ $v =~ ^[12][0-9]$ ]] && [ "$v" -ge 10 ] && [ "$v" -le 20 ] ;;
    DS_DUTY) [[ $v =~ ^[1-9][0-9]$ ]] ;;
    DX_MODE) [[ $v =~ ^(AUTO|MANUAL|LINKED)$ ]] ;;
    DX_LEVEL) [[ $v =~ ^(full|middle|low)$ ]] ;;
    DX_PERIOD) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 1 ] && [ "$v" -le 3600 ] ;;
    LED_HEALTH) [[ $v =~ ^(yes|no)$ ]] ;;
    MAIN_BADGES|AVR54_FIXED) [[ $v =~ ^(yes|no)$ ]] ;;   # 2026.10.26b: page-only settings (the daemon ignores them)
    LED_PERIOD) [[ $v =~ ^[0-9]+$ ]] && [ "$v" -ge 1 ] && [ "$v" -le 3600 ] ;;
    *) return 1 ;;
  esac
}

synohw_load_cfg() {
  synohw_defaults
  local f=${SYNOHW_CFG:-/boot/config/plugins/synology-hardware/synology-hardware.cfg} line k v
  [ -r "$f" ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    [[ $line =~ ^[[:space:]]*([A-Z_][A-Z0-9_]*)=(.*)$ ]] || continue
    k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
    v=${v%%[[:space:]]#*}                          # trailing ' # comment'
    v=${v%"${v##*[![:space:]]}"}                   # trailing blanks
    [[ $v =~ ^\"(.*)\"$ || $v =~ ^\'(.*)\'$ ]] && v=${BASH_REMATCH[1]}
    if synohw_cfg_ok "$k" "$v"; then printf -v "$k" '%s' "$v"
    else synohw_log "cfg: ignoring $k='$v' (unknown key or bad value)"; fi
  done <"$f"
  return 0
}

# Logging goes to syslog (Unraid: /var/log/syslog) with tag synology-hardware. `logger` is looked up in PATH.
synohw_log() {
  logger -t synology-hardware -- "$*" 2>/dev/null || true
  [ -n "${SYNOHW_STDERR:-}" ] && printf 'synology-hardware: %s\n' "$*" >&2
  return 0
}
synohw_debug() { [ "${DEBUG:-no}" = yes ] && synohw_log "$@"; return 0; }

# ---- command allow-list: the ONLY bytes that may ever reach ttyS1 ----
# Measured on the unit (research/2026-10-23-microp-commands.md). NOT allowed on purpose:
#   '1' (CPU grants power-off) and 'C' (system reset): never measured, can power the box off.
#   '3' (long beep): expected, never seen on the wire.
#   'M','P','Q' (LED brightness) and '=': measured but not needed; keep the list small.
# V is clamped to 10..99 so a bug can never stop the fan (scemd's lowest step is 20).
synohw_safe() {
  case "$1" in
    2|,|.|4|5|6|7|8|9|:|\;|@|A|B|l|k|q|p|s|r|u|t|O) return 0 ;;
    LA[123]|LE[123]) return 0 ;;
    W20) return 0 ;;
    V[1-9][0-9]) return 0 ;;
  esac
  return 1
}

# synohw_send CMD: validate and write CMD as one write() to the tty fd. Returns 1 if refused.
synohw_send() {
  local cmd=$1
  if ! synohw_safe "$cmd"; then
    synohw_log "REFUSED command '$cmd' (not on the allow-list)"
    return 1
  fi
  if ! printf '%s' "$cmd" >&"$SYNOHW_FD"; then
    synohw_log "WRITE FAILED for '$cmd'"
    return 1
  fi
  synohw_debug "sent '$cmd'"
  sleep "${SYNOHW_GAP:-0.05}"
  return 0
}

# open the tty raw 9600 8N1 on fd $SYNOHW_FD (read+write: the reader logs MCU events)
synohw_tty_open() {
  [ -c "$SYNOHW_TTY" ] || { synohw_log "$SYNOHW_TTY is not a character device"; return 1; }
  stty -F "$SYNOHW_TTY" 9600 raw -echo -echoe -echok -echonl cs8 -cstopb -parenb -crtscts clocal cread min 1 time 0 \
    || { synohw_log "stty failed on $SYNOHW_TTY"; return 1; }
  eval "exec ${SYNOHW_FD}<>\"\$SYNOHW_TTY\"" || { synohw_log "cannot open $SYNOHW_TTY"; return 1; }
}

# ---- temperatures (integers in C; empty output + rc 1 = unreadable) ----
synohw_cpu_temp() {
  local max= f v n d
  for d in "$SYNOHW_HWMON"/hwmon*; do
    [ -r "$d/name" ] || continue
    n=$(<"$d/name")
    case "$n" in coretemp|k10temp|cpu_thermal) ;; *) continue ;; esac
    for f in "$d"/temp*_input; do
      [ -r "$f" ] || continue
      v=$(<"$f") || continue
      case "$v" in ''|*[!0-9-]*) continue ;; esac
      v=$((v / 1000))
      [ -z "$max" ] || [ "$v" -gt "$max" ] && max=$v
    done
  done
  if [ -z "$max" ]; then   # fallback: x86_pkg_temp thermal zone
    for d in "$SYNOHW_THERMAL"/thermal_zone*; do
      [ -r "$d/type" ] && [ "$(<"$d/type")" = x86_pkg_temp ] || continue
      v=$(<"$d/temp") || continue
      case "$v" in ''|*[!0-9-]*) continue ;; esac
      max=$((v / 1000))
    done
  fi
  [ -n "$max" ] || return 1
  echo "$max"
}

# ---- disks (2026.10.25c) ----
# Sources, both written by emhttpd and never touched by us (reading them wakes no disk):
#   disks.ini  array/pool slots. Skipped: the flash (name="flash"), slots whose status starts with DISK_NP
#              (DISK_NP, DISK_NP_MISSING, DISK_NP_DSBL: no device in the slot) and slots with device="".
#              Unraid does the same: webGui nchan/device_list:185-186, nchan/update_2:143, scripts/monitor:75.
#   devs.ini   unassigned devices (webGui reads it at nchan/device_list:984, keys device/id/sectors at :995,
#              device/spundown/temp at :1499-1507; scripts/monitor:27). Keyed by device, labelled with id.
# USB devices are never fan inputs (the DOM, f400:f400, a USB stick, a USB disk: none sits in a bay the
# fan cools). Decided by sysfs, not by name (sdX order changes between boots): the resolved
# /sys/block/<dev>/device path contains /usb. transport="usb" in the ini counts too. No sysfs entry ->
# kept (fail-safe: an extra input can only raise the fan).
# Reading per disk:
#   temp="NN"                 -> NN
#   temp="*" + spundown="1"   -> *  standby (asleep = cool, step 0; never woken)
#   anything else             -> ?  no reading. Measured 2026-10-25 01:52 on the DS2415+: devs.ini had
#                                spun-up disks with temp="*" spundown="0" (the DOM sdb, USB) next to
#                                sdd temp="28" and sdc temp="31". A spun-up disk without a temperature is
#                                not "cool": it counts toward UNREADABLE_FLOOR. smartctl is NOT run on it
#                                (that is the only way the plugin could wake or disturb a disk).

synohw_is_usb() {  # synohw_is_usb <dev>: rc 0 = USB, 1 = not USB or unknown
  local b=$SYNOHW_SYSBLOCK/$1 r
  if [ -e "$b/device" ]; then r=$(readlink -f "$b/device") || return 1
  elif [ -e "$b" ]; then r=$(readlink -f "$b") || return 1
  else return 1; fi
  [[ $r == */usb* ]]
}

# emit one record (called by synohw_disk_list with its locals in scope)
synohw__disk_emit() {
  [ -n "$_sec" ] || return 0
  local key r
  case "$_status" in DISK_NP*) return 0 ;; esac
  [ -n "$_dev" ] || return 0
  [[ $_dev =~ ^[a-z0-9]{1,16}$ ]] || return 0
  [[ " $_seen " == *" $_dev "* ]] && return 0
  if [ "$_src" = array ]; then
    [ "$_name" = flash ] && return 0
    key=$_name; [[ $key =~ ^[A-Za-z0-9_-]{1,32}$ ]] || key=$_dev
  else key=$_dev; fi
  [ "$_tr" = usb ] && return 0
  synohw_is_usb "$_dev" && return 0
  if [[ $_temp =~ ^[0-9]{1,3}$ ]]; then r=$((10#$_temp))
  elif [ "$_temp" = '*' ] && [ "$_spun" = 1 ]; then r='*'
  else r='?'; fi
  [[ $_id =~ ^[A-Za-z0-9._-]{1,64}$ ]] || _id=-
  _seen+=" $_dev"
  echo "$key $r $_dev $_id $_src"
}

# synohw_disk_list: one line per real disk: "<key> <NN|*|?> <device> <id|-> <array|unassigned>".
# rc 1 = neither disks.ini nor devs.ini readable.
synohw_disk_list() {
  local f _src _sec _name _dev _temp _spun _status _id _tr _seen= line k v any=1
  for f in "$SYNOHW_DISKS_INI" "$SYNOHW_DEVS_INI"; do
    [ -r "$f" ] || continue
    any=0
    [ "$f" = "$SYNOHW_DISKS_INI" ] && _src=array || _src=unassigned
    _sec=
    while IFS= read -r line || [ -n "$line" ]; do
      line=${line%$'\r'}
      if [[ $line =~ ^[[:space:]]*\[(.*)\][[:space:]]*$ ]]; then
        synohw__disk_emit
        _sec=${BASH_REMATCH[1]//\"/}; [ -n "$_sec" ] || _sec=-
        _name=$_sec _dev= _temp= _spun= _status= _id= _tr=
      elif [[ $line =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=[[:space:]]*(.*)$ ]]; then
        k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
        v=${v%"${v##*[![:space:]]}"}
        [[ $v =~ ^\"(.*)\"$ ]] && v=${BASH_REMATCH[1]}
        case "$k" in
          name) _name=$v ;; device) _dev=$v ;; temp) _temp=$v ;; spundown) _spun=$v ;;
          status) _status=$v ;; id) _id=$v ;; transport) _tr=$v ;;
        esac
      fi
    done <"$f"
    synohw__disk_emit
  done
  return $any
}

# Fan input. Output: "<max|standby> <n_unreadable>"
#   max      hottest readable disk; standby = disks exist, none has a temperature, none is unreadable
#   n_unreadable > 0 -> the daemon applies UNREADABLE_FLOOR
# rc 1 = no disk input at all (no real disk, or every real disk unreadable, or no source): floor too.
# Fallback only when neither ini exists (emhttpd not running): smartctl -n standby -A on non-USB /dev/sd?.
synohw_disk_temp() {
  local max= t key r unread=0 standby=0 list
  if list=$(synohw_disk_list); then
    while read -r key r _; do
      [ -n "$key" ] || continue
      case "$r" in
        '*') standby=1 ;;
        '?') unread=$((unread + 1)) ;;
        *) { [ -z "$max" ] || [ "$r" -gt "$max" ]; } && max=$r ;;
      esac
    done <<<"$list"
    if [ -n "$max" ]; then echo "$max $unread"; return 0; fi
    if [ $standby = 1 ]; then echo "standby $unread"; return 0; fi
    echo "- $unread"; return 1
  fi
  local d out
  for d in "$SYNOHW_SYSBLOCK"/sd*; do
    [ -e "$d" ] || continue
    synohw_is_usb "${d##*/}" && continue
    d=/dev/${d##*/}
    out=$("$SYNOHW_SMARTCTL" -n standby -A "$d" 2>/dev/null) || { [ $? -eq 2 ] && standby=1; continue; }
    t=$(awk '$1==194||$1==190 {print $10+0; exit}' <<<"$out")
    [[ $t =~ ^[0-9]+$ ]] && { { [ -z "$max" ] || [ "$t" -gt "$max" ]; } && max=$t; }
  done
  if [ -n "$max" ]; then echo "$max $unread"; return 0; fi
  if [ $standby = 1 ]; then echo "standby $unread"; return 0; fi
  echo "- $unread"; return 1
}

# Per-core readings for the fan sweep: "<hwmon-name>_<tempN> <C|?>", one line per temp*_input of the CPU
# hwmon devices (same devices as synohw_cpu_temp). '?' = the file exists but cannot be read.
synohw_cpu_cores() {
  local f v n d
  for d in "$SYNOHW_HWMON"/hwmon*; do
    [ -r "$d/name" ] || continue
    n=$(<"$d/name")
    case "$n" in coretemp|k10temp|cpu_thermal) ;; *) continue ;; esac
    for f in "$d"/temp*_input; do
      [ -e "$f" ] || continue
      v=$(<"$f") 2>/dev/null || v=
      f=${f##*/}; f=${f%_input}
      case "$v" in ''|*[!0-9-]*) echo "${n}_$f ?" ;; *) echo "${n}_$f $((v / 1000))" ;; esac
    done
  done
}

# ---- fan sweep helpers (2026.10.25d); the state machine itself is in synohw-daemon ----
# Safety limits = the first raise thresholds of the LOW table (scemd.xml:17-29): disk 46 C -> 40 %, CPU 65 C -> 70 %.
SWEEP_DISK_ABORT=46
SWEEP_CPU_ABORT=65
SWEEP_DEFAULT_PLAN="20 15 12 10 20 40 70 99"
SWEEP_MAX_STEPS=24

# synohw_sweep_plan_ok <duty...>: rc 0 when 1..SWEEP_MAX_STEPS steps, each an integer 10..99 (never below 10)
synohw_sweep_plan_ok() {
  local d n=0
  for d in "$@"; do [[ $d =~ ^[1-9][0-9]$ ]] || return 1; n=$((n + 1)); done
  [ "$n" -ge 1 ] && [ "$n" -le "$SWEEP_MAX_STEPS" ]
}

# synohw_sweep_settled <csv> <step> <window s> <delta in tenths of a C>: prints yes|no.
# yes = for the step's last row, the CPU max and every disk differ by at most delta from the latest row of
# that step that is at least <window> s older. No such older row (step shorter than the window) = no.
synohw_sweep_settled() {
  awk -F, -v s="$2" -v win="$3" -v dt="$4" '
    FNR==1 { for (i=1;i<=NF;i++) if ($i=="cpu_max" || $i ~ /^disk:/) col[++nc]=i; next }
    $2==s { n++; E[n]=$1; for (c=1;c<=nc;c++) V[n,c]=$(col[c]) }
    END {
      if (n==0 || nc==0) { print "no"; exit }
      ref=0; for (r=1;r<=n;r++) if (E[r] <= E[n]-win) ref=r
      if (!ref) { print "no"; exit }
      for (c=1;c<=nc;c++) {
        if (V[n,c] !~ /^-?[0-9]+$/ || V[ref,c] !~ /^-?[0-9]+$/) { print "no"; exit }
        d=V[n,c]-V[ref,c]; if (d<0) d=-d
        if (d*10 > dt) { print "no"; exit }
      }
      print "yes" }' "$1"
}

# synohw_sweep_summary <steps-file> <csv> <window s>: per-step text. steps-file lines: "<idx> <duty> <hold s> <yes|no> <why>".
# Per step and for the CPU max and each disk: start / end / min / max and the slope in C per 10 min, measured
# from the latest row at least <window> s before the step's last row (else from its first row).
synohw_sweep_summary() {
  awk -F, -v win="$3" '
    FILENAME==ARGV[1] { split($0, m, " "); mi[++nm]=m[1]; md[m[1]]=m[2]; mh[m[1]]=m[3]; ms[m[1]]=m[4]; mr[m[1]]=m[5]; next }
    FNR==1 { for (i=1;i<=NF;i++) { H[i]=$i; if ($i=="cpu_max" || $i ~ /^disk:/) col[++nc]=i }; next }
    { s=$2; nr[s]++; r=nr[s]; E[s,r]=$1; for (c=1;c<=nc;c++) V[s,r,c]=$(col[c]) }
    END {
      for (k=1;k<=nm;k++) {
        s=mi[k]
        printf "step %s: duty %s %%, held %s s, settled %s, ended by %s\n", s, md[s], mh[s], ms[s], mr[s]
        if (!(s in nr)) { print "  no samples"; continue }
        last=nr[s]
        for (c=1;c<=nc;c++) {
          nm_=H[col[c]]; sub(/^disk:/, "", nm_); if (nm_=="cpu_max") nm_="CPU max"
          st=V[s,1,c]; en=V[s,last,c]; mn=""; mx=""
          for (r=1;r<=last;r++) { v=V[s,r,c]; if (v !~ /^-?[0-9]+$/) continue; if (mn==""||v+0<mn+0) mn=v; if (mx==""||v+0>mx+0) mx=v }
          ref=1; for (r=1;r<=last;r++) if (E[s,r] <= E[s,last]-win) ref=r
          dt_=E[s,last]-E[s,ref]
          if (dt_>0 && en ~ /^-?[0-9]+$/ && V[s,ref,c] ~ /^-?[0-9]+$/) sl=sprintf("%+.2f", (en-V[s,ref,c])*600/dt_); else sl="n/a"
          printf "  %-34s start %s  end %s  min %s  max %s  slope %s C/10min\n", nm_, st, en, mn, mx, sl
        }
      }
    }' "$1" "$2"
}

# Per-disk readings for the history ring: "<key> <NN|*|?>", real disks only (same list as the fan).
synohw_disk_temps() {
  local list
  list=$(synohw_disk_list) || return 1
  [ -n "$list" ] || return 0
  awk '{print $1, $2}' <<<"$list"
}

# ---- fan tables from scemd.xml (DS2415+, measured): "threshold:duty" ascending ----
# Source: capture/2026-10-23/bin/usr/syno/etc.defaults/scemd.xml lines 3-15 (DUAL_MODE_HIGH) and 17-29
# (DUAL_MODE_LOW). The settings page has a copy in include/synohw-status.php; tests/run-tests.sh checks
# that both copies equal the XML.
synohw_tables() {
  if [ "$FAN_MODE" = HIGH ]; then
    DISK_STEPS=(0:20 41:40 45:70 49:99); DISK_ALERT=53
  else
    DISK_STEPS=(0:20 46:40 50:70 54:99); DISK_ALERT=58
  fi
  DISK_SHUTDOWN=61
  CPU_STEPS=(0:20 65:70 85:99); CPU_ALERT=85; CPU_SHUTDOWN=95
  # 2026.10.25h: DS_FLOOR (10..20, default 20 = DSM) replaces the duty of the lowest row of both tables; nothing else changes
  local fl=${DS_FLOOR:-20}
  if [[ $fl =~ ^[12][0-9]$ ]] && [ "$fl" -ge 10 ] && [ "$fl" -lt 20 ]; then DISK_STEPS[0]=0:$fl; CPU_STEPS[0]=0:$fl; fi
  # DX1215: scemd.xml:357-370 (Synology-DX1215, DUAL_MODE_HIGH_EBOX and _LOW_EBOX are identical): LOW from 0, MIDDLE from 48,
  # FULL from 55, FULL + SHUTDOWN at 61; threshold="2" (hysteresis), hibernation_speed="LOW" (disks asleep -> low)
  DX_STEPS=(0:low 48:middle 55:full); DX_SHUTDOWN=61
}

# synohw_step_level <temp> <prev_level> <steps...>  -> echoes new level index (with hysteresis)
synohw_step_level() {
  local t=$1 prev=$2; shift 2
  local -a steps=("$@"); local i target=0 h=0 thr
  for i in "${!steps[@]}"; do
    thr=${steps[$i]%%:*}
    [ "$t" -ge "$thr" ] && target=$i
    [ $((t + HYST)) -ge "$thr" ] && h=$i
  done
  if [ "$target" -ge "$prev" ]; then echo "$target"; else [ "$h" -lt "$prev" ] && echo "$h" || echo "$prev"; fi
}

# synohw_level_duty <level> <steps...> -> duty of that level
synohw_level_duty() { local lv=$1; shift; local -a s=("$@"); echo "${s[$lv]#*:}"; }

# ---- DX1215 (2026.10.25h) ----
# Which box a disk sits in, from sysfs (never from the sdX name). Same rules as synohw_disk_box() in include/synohw-status.php
# (tests/run-tests.sh compares both on the fixtures): /sys/block/<dev>/device resolves through the PCI controller and the ataN
# port. Controller 1b4b:9235 (Marvell 88SE9235, the DS2415+'s expansion port) or a port multiplier on the ata port
# (ataN/linkN.0 exists: libata makes fan-out links for a PMP only; the DX1215 has 88SM9705 PMPs) -> dx; any other ata port -> ds;
# a USB path -> usb; no sysfs entry or no ata port -> unknown. Output: one word.
synohw_disk_box() {
  local dev=$1 r c prefix= dx=0 ata=0 v d
  [[ $dev =~ ^[a-z0-9]{1,16}$ ]] || { echo unknown; return 0; }
  [ -e "$SYNOHW_SYSBLOCK/$dev/device" ] || { echo unknown; return 0; }
  r=$(readlink -f "$SYNOHW_SYSBLOCK/$dev/device") || { echo unknown; return 0; }
  [[ $r == */usb* ]] && { echo usb; return 0; }
  local IFS=/
  for c in $r; do
    [ -n "$c" ] || continue
    prefix+=/$c
    if [[ $c =~ ^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$ ]]; then
      v=; d=; [ -r "$prefix/vendor" ] && v=$(<"$prefix/vendor"); [ -r "$prefix/device" ] && d=$(<"$prefix/device")
      [ "${v,,}:${d,,}" = 0x1b4b:0x9235 ] && dx=1
    elif [[ $c =~ ^ata([0-9]{1,3})$ ]]; then
      ata=1; [ -d "$prefix/link${BASH_REMATCH[1]}.0" ] && dx=1
    fi
  done
  if [ $ata = 0 ]; then echo unknown; elif [ $dx = 1 ]; then echo dx; else echo ds; fi
}

# DX1215 fan input: "<max|standby|-> <n_unreadable> <n_dx_disks> <hottest key|->", real disks in the DX1215 only.
# rc 1 = neither disks.ini nor devs.ini readable.
synohw_dx_temp() {
  local list key r dev max= unread=0 standby=0 n=0 hot=-
  list=$(synohw_disk_list) || { echo "- 0 0 -"; return 1; }
  while read -r key r dev _; do
    [ -n "$key" ] || continue
    [ "$(synohw_disk_box "$dev")" = dx ] || continue
    n=$((n + 1))
    case "$r" in
      '*') standby=1 ;;
      '?') unread=$((unread + 1)) ;;
      *) if [ -z "$max" ] || [ "$r" -gt "$max" ]; then max=$r; hot=$key; fi ;;
    esac
  done <<<"$list"
  if [ -n "$max" ]; then echo "$max $unread $n $hot"
  elif [ $standby = 1 ]; then echo "standby $unread $n -"
  else echo "- $unread $n -"; fi
  return 0
}

# level order for "raise only": stop < low < middle < full; anything else (unread, unavailable) = -1
synohw_dx_rank() { case "$1" in stop) echo 0 ;; low) echo 1 ;; middle) echo 2 ;; full) echo 3 ;; *) echo -1 ;; esac; }
synohw_dx_max() { [ "$(synohw_dx_rank "$1")" -ge "$(synohw_dx_rank "$2")" ] && echo "$1" || echo "$2"; }

# LINKED: the DS2415+ duty -> a DX1215 level. <40 low, 40..69 middle, >=70 full (the DS table's 40 % and 70 % rows).
synohw_dx_linked() {
  local d=$1
  [[ $d =~ ^[0-9]+$ ]] || { echo middle; return 0; }
  if [ "$d" -ge 70 ]; then echo full; elif [ "$d" -ge 40 ]; then echo middle; else echo low; fi
}

# one sysfs value of the module's unit directory: first line, printable ASCII only, at most 64 chars; '' = not readable
synohw_dx_val() {
  local f=$SYNOHW_DXSYS/unit/$1 v
  [ -r "$f" ] || return 1
  IFS= read -r v <"$f" || [ -n "$v" ] || return 1
  v=${v//[^[:print:]]/}
  printf '%s' "${v:0:64}"
}

# THE write path to the DX1215 fans. Only full, middle or low can ever be written (the module refuses stop on fan_level
# too; this is the second guard). Never creates the file (no module = no sysfs file = nothing written). rc 1 = refused/failed.
synohw_dx_set() {
  local lv=$1 f=$SYNOHW_DXSYS/unit/fan_level err
  case "$lv" in
    full|middle|low) ;;
    *) synohw_log "REFUSED DX1215 fan_level '$lv' (only full, middle, low)"; return 1 ;;
  esac
  if [ ! -f "$f" ] || [ ! -w "$f" ]; then synohw_log "DX1215: $f missing or not writable, '$lv' not written"; return 1; fi
  if ! err=$( { printf '%s\n' "$lv" >"$f"; } 2>&1 ); then
    synohw_log "DX1215: writing '$lv' to fan_level failed: ${err:-no message}"; return 1
  fi
  return 0
}

# ---- bay LEDs (2026.10.26a): disk health -> orange ----
# Health of every real disk (same set as synohw_disk_list: no flash, no USB), from what Unraid already has; nothing is run
# against a disk (no smartctl: no disk is woken). One line per disk: "<device> <0|1> <reason>", 1 = fault (orange):
#   array slot (disks.ini) status DISK_DSBL, DISK_DSBL_NEW, DISK_INVALID or DISK_WRONG, or color red-* (Unraid's red X)
#   Unraid's SMART cache $SYNOHW_SMARTDIR/<name> (name = disks.ini slot or devs.ini devN; emhttpd writes it, monitor reads it):
#   an attribute with WHEN_FAILED = FAILING_NOW (value at or below threshold now), or "overall-health ... FAILED"
# Not a fault: "In_the_past", read errors (numErrors), a missing SMART file (shown as "no SMART record").
synohw_disk_health() {
  local f _src _sec _name _dev _status _color _tr _seen= line k v r why
  for f in "$SYNOHW_DISKS_INI" "$SYNOHW_DEVS_INI"; do
    [ -r "$f" ] || continue
    [ "$f" = "$SYNOHW_DISKS_INI" ] && _src=array || _src=unassigned
    _sec=
    while IFS= read -r line || [ -n "$line" ]; do
      line=${line%$'\r'}
      if [[ $line =~ ^[[:space:]]*\[(.*)\][[:space:]]*$ ]] || [ "$line" = __END__ ]; then
        k=${BASH_REMATCH[1]//\"/}                  # before the emit: its own =~ tests overwrite BASH_REMATCH
        synohw__health_emit
        [ "$line" = __END__ ] && break
        _sec=$k; _name=$_sec _dev= _status= _color= _tr=
      elif [[ $line =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=[[:space:]]*(.*)$ ]]; then
        k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
        v=${v%"${v##*[![:space:]]}"}
        [[ $v =~ ^\"(.*)\"$ ]] && v=${BASH_REMATCH[1]}
        case "$k" in name) _name=$v ;; device) _dev=$v ;; status) _status=$v ;; color) _color=$v ;; transport) _tr=$v ;; esac
      fi
    done < <(cat "$f"; echo __END__)
  done
  return 0
}
synohw__health_emit() {
  [ -n "$_sec" ] && [ -n "$_dev" ] || return 0
  [[ $_dev =~ ^[a-z0-9]{1,16}$ ]] || return 0
  case "$_status" in DISK_NP*) return 0 ;; esac
  [ "$_src" = array ] && [ "$_name" = flash ] && return 0
  [ "$_tr" = usb ] && return 0
  synohw_is_usb "$_dev" && return 0
  [[ " $_seen " == *" $_dev "* ]] && return 0
  _seen+=" $_dev"
  local r=0 why= sm
  if [ "$_src" = array ]; then
    case "$_status" in DISK_DSBL|DISK_DSBL_NEW|DISK_INVALID|DISK_WRONG) r=1; why="Unraid disk state $_status" ;; esac
    [ $r = 0 ] && [[ $_color == red* ]] && { r=1; why="Unraid shows the disk red ($_color)"; }
  fi
  sm=$SYNOHW_SMARTDIR/$_name
  if [ $r = 0 ] && [[ $_name =~ ^[A-Za-z0-9_-]{1,32}$ ]] && [ -r "$sm" ]; then
    why=$(awk '$1 ~ /^[0-9]+$/ && $9 == "FAILING_NOW" {print "SMART " $1 " " $2 " FAILING_NOW"; exit}
               /overall-health self-assessment test result: FAILED/ {print "SMART overall health FAILED"; exit}' "$sm" 2>/dev/null)
    if [ -n "$why" ]; then r=1; else why="healthy: SMART attributes ok"; fi
  elif [ $r = 0 ]; then why="healthy: no SMART record from Unraid yet"; fi
  why=${why//[^[:print:]]/}
  echo "$_dev $r ${why:0:120}"
}

# Bay of a disk and the LED bit that lights its orange: "ds <pci> <port 0-3> <bay>" or "dx host<N> <pmp port 0-2> <bay>",
# rc 1 = not in a bay the driver can light. Bay numbers as Synology prints them, both confirmed at the box by blink count
# (experiments/2026-10-26-bay-map/RESULT.md): DS bay = 4 x controller index (03:00.0, 05:00.0, 06:00.0) + port + 1, the port
# taken from the driver's own ds/<pci>/ports; DX bay = 3 x EMID + port multiplier port + 1, EMID from hostN/emid.
synohw_bay_target() {
  local dev=$1 r c ata= pci= pm= h a e i line
  [[ $dev =~ ^[a-z0-9]{1,16}$ ]] && [ -e "$SYNOHW_SYSBLOCK/$dev/device" ] || return 1
  r=$(readlink -f "$SYNOHW_SYSBLOCK/$dev/device") || return 1
  [[ $r == */usb* ]] && return 1
  local IFS=/
  for c in $r; do
    if [[ $c =~ ^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$ ]]; then pci=$c
    elif [[ $c =~ ^ata([0-9]{1,3})$ ]]; then ata=${BASH_REMATCH[1]}
    elif [[ $c =~ ^target[0-9]+:([0-9]+):[0-9]+$ ]]; then pm=${BASH_REMATCH[1]}; fi
  done
  unset IFS
  [ -n "$ata" ] || return 1
  if [ -d "${r%%/ata$ata/*}/ata$ata/link$ata.0" ]; then   # behind a port multiplier: the DX1215
    for h in "$SYNOHW_DXSYS"/host*; do
      [ -r "$h/ata_port" ] || continue
      a=$(<"$h/ata_port"); [ "$a" = "ata$ata" ] || continue
      e=$(<"$h/emid"); [[ $e =~ ^[0-3]$ ]] && [[ $pm =~ ^[0-2]$ ]] || return 1
      echo "dx ${h##*/} $pm $((3 * e + pm + 1))"; return 0
    done
    return 1
  fi
  case "$pci" in 0000:03:00.0) i=0 ;; 0000:05:00.0) i=4 ;; 0000:06:00.0) i=8 ;; *) return 1 ;; esac
  [ -r "$SYNOHW_DXSYS/ds/$pci/ports" ] || return 1
  while read -r line; do
    [[ $line =~ ^port([0-3])\ ata([0-9]+)$ ]] && [ "${BASH_REMATCH[2]}" = "$ata" ] && { echo "ds $pci ${BASH_REMATCH[1]} $((i + BASH_REMATCH[1] + 1))"; return 0; }
  done <"$SYNOHW_DXSYS/ds/$pci/ports"
  return 1
}

# THE write path for orange: ds/<pci>/fault_mask (0..15) or host<N>/fault (0..7), existing files only. rc 1 = refused/failed.
synohw_led_set() {   # synohw_led_set ds|dx <pci|hostN> <mask>
  local f err
  case "$1" in
    ds) [[ $2 =~ ^0000:0[356]:00\.0$ ]] && [[ $3 =~ ^([0-9]|1[0-5])$ ]] || { synohw_log "REFUSED LED write ds '$2' '$3'"; return 1; }
        f=$SYNOHW_DXSYS/ds/$2/fault_mask ;;
    dx) [[ $2 =~ ^host[0-9]{1,3}$ ]] && [[ $3 =~ ^[0-7]$ ]] || { synohw_log "REFUSED LED write dx '$2' '$3'"; return 1; }
        f=$SYNOHW_DXSYS/$2/fault ;;
    *) synohw_log "REFUSED LED write '$1'"; return 1 ;;
  esac
  [ -f "$f" ] && [ -w "$f" ] || { synohw_log "bay LEDs: $f missing or not writable"; return 1; }
  if ! err=$( { printf '%s\n' "$3" >"$f"; } 2>&1 ); then synohw_log "bay LEDs: writing $3 to $f failed: ${err:-no message}"; return 1; fi
  return 0
}
