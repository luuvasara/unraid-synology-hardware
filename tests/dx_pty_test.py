#!/usr/bin/env python3
"""Offline test of the synology-hardware daemon's DX1215 fan policy (2026.10.25h) against a fake /sys/kernel/synology_dx tree and a
pty pair acting as the DS2415+ MCU. Written: 2026-10-25. Runs on the build host only; same harness idea as sweep_pty_test.py.
The fake unit files are plain files: the daemon's write to fan_level replaces the content (as the module's read-back would),
and a watcher thread records every write (mtime change) with the value written.
usage: dx_pty_test.py [--scripts DIR] [--rc FILE] [--only name,name]   (--scripts/--rc/--only: mutation checks)"""
import os, pty, re, subprocess, sys, tempfile, threading, time, tty

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RC = f"{ROOT}/src/etc/rc.d/rc.synology-hardware"
SCRIPTS = f"{ROOT}/src/usr/local/emhttp/plugins/synology-hardware/scripts"
ONLY = None
a = sys.argv[1:]
while a:
    k = a.pop(0)
    if k == "--scripts": SCRIPTS = a.pop(0)
    elif k == "--rc": RC = a.pop(0)
    elif k == "--only": ONLY = a.pop(0).split(",")
    else: sys.exit(f"unknown argument {k}")

master, slave = pty.openpty()
tty.setraw(master)
slave_name = os.ttyname(slave)
tmp = tempfile.mkdtemp(prefix="synohw-dx-test-")
RUN = f"{tmp}/run"; DX = f"{tmp}/dx"; UNIT = f"{DX}/unit"
hw = f"{tmp}/hwmon/hwmon0"; os.makedirs(hw)
open(f"{hw}/name", "w").write("coretemp\n"); open(f"{hw}/temp1_input", "w").write("40000\n")

# fake sysfs: sdb on the internal 88SE9215 (ata1), sde in the DX1215 (88SE9235, ata19 with port multiplier links)
def mkpci(path, vid, did):
    os.makedirs(f"{tmp}/sys/{path}", exist_ok=True)
    open(f"{tmp}/sys/{path}/vendor", "w").write(f"0x{vid}\n"); open(f"{tmp}/sys/{path}/device", "w").write(f"0x{did}\n")
INT = "devices/pci0000:00/0000:00:02.0/0000:05:00.0"; DXP = "devices/pci0000:00/0000:00:04.0/0000:07:00.0"
mkpci("devices/pci0000:00/0000:00:02.0", "8086", "1f11"); mkpci(INT, "1b4b", "9215")
mkpci("devices/pci0000:00/0000:00:04.0", "8086", "1f13"); mkpci(DXP, "1b4b", "9235")
for dev, tgt in (("sdb", f"{INT}/ata1/host0/target0:0:0/0:0:0:0"), ("sde", f"{DXP}/ata19/host18/target18:0:0/18:0:0:0")):
    os.makedirs(f"{tmp}/sys/{tgt}"); os.makedirs(f"{tmp}/sys/block/{dev}")
    os.symlink(f"../../{tgt}", f"{tmp}/sys/block/{dev}/device")
for l in ("link19", "link19.0", "link19.15"): os.makedirs(f"{tmp}/sys/{DXP}/ata19/{l}")

def set_disks(ds, dx):   # number | "*" (asleep: spundown 1) | "?" (spun up, temp "*")
    def one(name, dev, t):
        sb, tt = ("1", "*") if t == "*" else ("0", "*") if t == "?" else ("0", str(t))
        return f'["{name}"]\nname="{name}"\ndevice="{dev}"\nstatus="DISK_OK"\nspundown="{sb}"\ntemp="{tt}"\n'
    open(f"{tmp}/disks.ini.tmp", "w").write(one("parity", "sdb", ds) + one("disk1", "sde", dx))
    os.replace(f"{tmp}/disks.ini.tmp", f"{tmp}/disks.ini")
UNIT_DEF = dict(master_host="host18", unique="0x13", model="DX1215", fan_level="low", fan_code="2", fan_fail="0", fan1_fail="0", fan2_fail="0",
                power_on="1", psu1="0", psu2="0", switch_mode="1", last_error="", fan_stop_test_s="0")
def set_unit(**kv):
    for k, v in kv.items(): open(f"{UNIT}/{k}", "w").write(f"{v}\n")

os.makedirs(f"{tmp}/bin")
with open(f"{tmp}/bin/logger", "w") as f:
    f.write('#!/bin/bash\ntag=; msg=\nwhile [ $# -gt 0 ]; do case $1 in -t) tag=$2; shift 2 ;; --) shift; msg="$*"; break ;; *) msg="$*"; break ;; esac; done\n'
            'if [ -n "$msg" ]; then echo "$tag: $msg" >>"$SYNOHW_TEST_SYSLOG"; else while IFS= read -r l; do echo "$tag: $l" >>"$SYNOHW_TEST_SYSLOG"; done; fi\n')
open(f"{tmp}/bin/notify", "w").write('#!/bin/bash\necho "notify $*" >>"$SYNOHW_TEST_SYSLOG.notify"\n')
for f in ("logger", "notify"): os.chmod(f"{tmp}/bin/{f}", 0o755)
LOG = f"{tmp}/log"; open(LOG, "w").close()
def env(dxsys=DX):
    return dict(os.environ, SYNOHW_TTY=slave_name, SYNOHW_CFG=f"{tmp}/cfg", SYNOHW_HWMON=f"{tmp}/hwmon", SYNOHW_THERMAL=f"{tmp}/nothermal",
                SYNOHW_DISKS_INI=f"{tmp}/disks.ini", SYNOHW_DEVS_INI=f"{tmp}/devs.ini", SYNOHW_SYSBLOCK=f"{tmp}/sys/block", SYNOHW_RUN=RUN,
                SYNOHW_TEST_SYSLOG=LOG, SYNOHW_DIR=SCRIPTS, SYNOHW_DXSYS=dxsys, SYNOHW_SYSMODULE=f"{tmp}/sysmod", SYNOHW_NOTIFY=f"{tmp}/bin/notify",
                SYNOHW_SMARTCTL=f"{tmp}/no-smartctl", SYNOHW_SMARTDIR=f"{tmp}/smart", SYNOHW_ETHTOOL=f"{tmp}/no-ethtool", SYNOHW_SYSNET=f"{tmp}/nonet", PATH=f"{tmp}/bin:" + os.environ["PATH"])
ENV = env()

events = []; buf = ""
TOK = re.compile(r"LA[123]|LE[123]|[VW]\d\d|.", re.S)
def reader():
    global buf
    while True:
        try: data = os.read(master, 256)
        except OSError: return
        t = time.monotonic(); buf += data.decode("latin1")
        while buf:
            tok = TOK.match(buf).group(0)
            if tok in ("L", "V", "W") and len(buf) < 3: break
            events.append((t, tok)); buf = buf[len(tok):]
threading.Thread(target=reader, daemon=True).start()

writes = []   # (monotonic time, value) for every write to fan_level (mtime change) made by the daemon
QUIET = [False]   # True while the test itself sets the tree up: changes are not recorded
def watcher():
    last = None
    while True:
        try:
            st_ = os.stat(f"{UNIT}/fan_level"); key = (st_.st_mtime_ns, st_.st_ino, st_.st_size)
            if QUIET[0]: last = key; time.sleep(0.01); continue
            if last is not None and key != last:
                writes.append((time.monotonic(), open(f"{UNIT}/fan_level").read().strip()))
            last = key
        except FileNotFoundError: last = None
        time.sleep(0.01)
threading.Thread(target=watcher, daemon=True).start()

fails = 0
def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail and not cond else ""), flush=True)
    fails += not cond
def toks(since=0): return [t for (_, t) in events[since:]]
def vs(since=0): return [t for t in toks(since) if t[0] == "V"]
def wv(since=0): return [v for (_, v) in writes[since:]]
def hist_has(*parts): return lambda: any(all(x in l for x in parts) for l in open(f"{RUN}/history").read().splitlines()) if os.path.exists(f"{RUN}/history") else False   # 2026.10.25k: the history is sampled on its own tick; wait for a sample before the next step
def wait_for(pred, timeout=10):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if pred(): return True
        time.sleep(0.1)
    return False
def kv(path):
    try: return dict(l.split("=", 1) for l in open(path).read().splitlines() if "=" in l)
    except FileNotFoundError: return {}
def st(): return kv(f"{RUN}/state")
def rc(*args, e=None):
    return subprocess.run([RC, *args], env=e or ENV, capture_output=True, text=True, timeout=60)
BASE = "KEEPALIVE_SEC=2\nCPU_PERIOD=1\nDISK_PERIOD=1\nDX_PERIOD=1\nTICK=0.2\nFAN_REASSERT=3600\n"
def start(extra="", ds=30, dx=30, unit=True, level="low", e=None):
    rc("stop", e=e); time.sleep(0.3)
    import shutil
    QUIET[0] = True; time.sleep(0.05)
    shutil.rmtree(DX, ignore_errors=True)
    if unit:
        os.makedirs(UNIT); set_unit(**dict(UNIT_DEF, fan_level=level))
    set_disks(ds, dx)
    open(f"{tmp}/cfg", "w").write(BASE + extra)
    for f in ("state", "dx"):   # a previous daemon's files must not count as this one's first evaluation
        try: os.remove(f"{RUN}/{f}")
        except FileNotFoundError: pass
    time.sleep(0.1); QUIET[0] = False; time.sleep(0.05)
    n = len(writes)
    r = rc("start", e=e)
    check("daemon started", r.returncode == 0, r.stdout + r.stderr)
    wait_for(lambda: st().get("dx_at", "0") != "0" and st().get("dx_state") not in (None, "init"), 8)
    return n
def settle(s=2.5): time.sleep(s)

def sc_auto():
    n = start(ds=50, dx=30)   # DS disk 50 C: DS fan V70 (LOW table), the DX1215 must not follow a DS2415+ disk
    settle(3)
    s = st()
    check("auto: DS disk 50 C (V70 on the DS) does not raise the DX: wanted low, why auto, DX disk input 30 C = disk1 only",
          s.get("dx_want") == "low" and s.get("dx_why") == "auto" and s.get("dx_disk") == "30" and s.get("dx_hot") == "disk1" and s.get("dx_disks") == "1"
          and "V70" in vs(), str(s))
    check("auto: wanted == read (low): no write at all over 3 s (no redundant writes)", wv(n) == [], str(wv(n)))
    m = len(writes); set_disks(30, 49)
    check("auto: DX disk 49 C -> middle written (row from 48 C)", wait_for(lambda: wv(m) == ["middle"]), str(wv(m)))
    m = len(writes); set_disks(30, 47); settle(3)
    check("auto: 47 C -> stays middle (hysteresis 2 C below the 48 C row), no write", wv(m) == [] and st().get("dx_want") == "middle", str((wv(m), st().get("dx_want"))))
    m = len(writes); set_disks(30, 45)
    check("auto: 45 C -> low written", wait_for(lambda: wv(m) == ["low"]), str(wv(m)))
    m = len(writes); set_disks(30, 56)
    check("auto: 56 C -> full written (row from 55 C)", wait_for(lambda: wv(m) == ["full"]), str(wv(m)))
    wait_for(hist_has("@dx=full", "@dxt=56"))
    m = len(writes); set_disks(30, 61)
    check("auto: 61 C -> full kept (SHUTDOWN row), why shutdown, no write (already full)", wait_for(lambda: st().get("dx_why") == "shutdown") and wv(m) == [], str((st().get("dx_why"), wv(m))))
    m = len(writes); set_disks(30, "*")
    check("auto: the DX disk asleep -> low (hibernation_speed LOW), why standby", wait_for(lambda: wv(m) == ["low"] and st().get("dx_why") == "standby"), str((wv(m), st().get("dx_why"))))
    hist = open(f"{RUN}/history").read().splitlines()
    check("auto: history lines carry @dsm @dx @dxwant @dxwhy @dxal @dxt; old tokens unchanged",
          all(re.search(r" @mode=LOW @why=\w[\w-]* @want=\d+.* @dsm=AUTO @dx=(low|middle|full|stop|none) @dxwant=(low|middle|full|-) @dxwhy=[a-z-]+ @dxal=[0-3-] @dxt=(\d+|standby|-)$", l) for l in hist[-5:])
          and any("@dx=middle" in l for l in hist) and any("@dx=full" in l and "@dxt=56" in l for l in hist), str(hist[-2:]))
    snap = kv(f"{RUN}/dx")
    check("auto: the page's copy /var/run/synology-hardware/dx has every unit value + time", {"at", "model", "fan_level", "fan1_fail", "fan2_fail", "psu1", "psu2", "switch_mode", "last_error"} <= set(snap) and snap.get("model") == "DX1215", str(snap))
    check("auto: only full|middle|low were ever written", set(wv()) <= {"full", "middle", "low"}, str(wv()))

def sc_alarm():
    n = start(dx=30)
    m = len(writes); set_unit(fan1_fail=1, fan_fail=1)
    check("alarm: fan1_fail=1 -> full written, why alarm", wait_for(lambda: wv(m) == ["full"] and st().get("dx_why") == "alarm"), str((wv(m), st().get("dx_why"))))
    wait_for(hist_has("@dxal=1", "@dxwhy=alarm"))
    check("alarm: syslog 'DX1215 FAN ALARM' and an Unraid notification", "DX1215 FAN ALARM" in open(LOG).read() and os.path.exists(LOG + ".notify") and "DX1215 fan alarm" in open(LOG + ".notify").read())
    m = len(writes); set_unit(fan1_fail=0, fan_fail=0)
    check("alarm: cleared -> back to the table level low", wait_for(lambda: wv(m) == ["low"]) and "alarm cleared" in open(LOG).read(), str(wv(m)))
    m = len(writes); set_unit(fan2_fail=1, fan_fail=1)
    check("alarm: fan2_fail=1 alone -> full (both alarm wires count; DSM checks only bit 0)", wait_for(lambda: wv(m) == ["full"]), str(wv(m)))
    wait_for(hist_has("@dxal=2"))
    m = len(writes); set_unit(fan2_fail=0, fan_fail=0); wait_for(lambda: wv(m) == ["low"])
    m = len(writes); set_unit(fan1_fail="garbage")
    check("alarm: an unreadable alarm bit counts as an alarm -> full", wait_for(lambda: wv(m) == ["full"]), str(wv(m)))
    hl = open(f"{RUN}/history").read()
    check("alarm: history records @dxal=1 and @dxal=2 with @dxwhy=alarm", "@dxal=1" in hl and "@dxal=2" in hl and "@dxwhy=alarm" in hl)
    set_unit(fan1_fail=0)

def sc_unreadable():
    n = start(dx="?")
    check("unreadable: DX disk spun up without a reading -> middle written, why noread", wait_for(lambda: wv(n) == ["middle"] and st().get("dx_why") == "noread"), str((wv(n), st().get("dx_why"))))
    rc("stop"); time.sleep(0.3)
    n = start("DX_MODE=MANUAL\nDX_LEVEL=low\n", dx="?")
    check("unreadable: MANUAL low is raised to middle too", wait_for(lambda: wv(n)[-1:] == ["middle"] and st().get("dx_why") == "noread"), str((wv(n), st().get("dx_why"))))

def sc_manual():
    n = start("DX_MODE=MANUAL\nDX_LEVEL=full\n", dx=30)
    check("manual: DX_LEVEL=full -> full written whatever the DX disk (30 C)", wait_for(lambda: wv(n) == ["full"] and st().get("dx_why") == "manual"), str((wv(n), st())))
    m = len(writes); settle(3)
    check("manual: no further write while it stays full", wv(m) == [], str(wv(m)))

def sc_nostop():
    n = start("DX_MODE=MANUAL\nDX_LEVEL=stop\n", dx=30, level="middle")
    check("never stop: DX_LEVEL=stop refused by the cfg parser (logged), the default low is used instead",
          wait_for(lambda: wv(n) == ["low"] and "ignoring DX_LEVEL='stop'" in open(LOG).read() and st().get("dx_cfg_level") == "low"), str((wv(n), st().get("dx_cfg_level"))))
    rc("stop"); time.sleep(0.3)
    n = start(dx=30, level="stop")   # the module reports stop (a fan_stop_test_s test): AUTO writes low, never stop
    check("never stop: level read 'stop' -> low written (not stop)", wait_for(lambda: wv(n)[:1] == ["low"]), str(wv(n)))
    m = len(writes)
    r = subprocess.run(["bash", "-c", '. "$1/synohw-lib.sh"; synohw_dx_set stop; echo "rc=$?"', "_", SCRIPTS], env=ENV, capture_output=True, text=True)
    check("never stop: synohw_dx_set stop -> rc 1, REFUSED logged, file untouched", "rc=1" in r.stdout and "REFUSED DX1215 fan_level 'stop'" in open(LOG).read() and wait_for(lambda: True, 0.3) and wv(m) == [], r.stdout + r.stderr)
    check("never stop: no 'stop' among all writes of every scenario so far", "stop" not in wv(), str(wv()))

def sc_linked():
    n = start("DX_MODE=LINKED\n", ds=30, dx=30)
    check("linked: DS V20 -> low (no write, already low)", wait_for(lambda: st().get("dx_why") == "linked" and st().get("dx_input") == "20") and wv(n) == [], str((st().get("dx_input"), wv(n))))
    m = len(writes); set_disks(47, 30)
    check("linked: DS disk 47 -> V40 -> middle", wait_for(lambda: "V40" in vs() and wv(m) == ["middle"]), str((vs()[-3:], wv(m))))
    m = len(writes); set_disks(51, 30)
    check("linked: DS disk 51 -> V70 -> full", wait_for(lambda: wv(m) == ["full"] and st().get("dx_input") == "70"), str((wv(m), st().get("dx_input"))))
    m = len(writes); set_disks(30, 30)
    check("linked: DS back to V20 -> low", wait_for(lambda: wv(m)[-1:] == ["low"]), str(wv(m)))

def sc_nomod():
    e = env(f"{tmp}/absent-dx")
    n = start(dx=30, unit=False, e=e)
    settle(3)
    check("nomod: no module -> state nomod, nothing created at the module path, DS fan still controlled (V20)",
          st().get("dx_state") == "nomod" and not os.path.exists(f"{tmp}/absent-dx") and "V20" in vs() and not os.path.exists(f"{RUN}/dx"), str(st().get("dx_state")))
    check("nomod: logged once", open(LOG).read().count("synology_dx module not loaded") == 1)
    rc("stop", e=e); time.sleep(0.3)
    import shutil; shutil.rmtree(DX, ignore_errors=True); os.makedirs(UNIT)   # unit dir without fan_level: no master
    open(f"{tmp}/cfg", "w").write(BASE); rc("start"); settle(3)
    check("nomod: unit dir without fan_level -> nomaster, fan_level never created", st().get("dx_state") == "nomaster" and not os.path.exists(f"{UNIT}/fan_level"), str(st().get("dx_state")))

def sc_reassert():
    n = start("FAN_REASSERT=2\n", dx=30)
    time.sleep(6.5)
    w = wv(n)
    check("reassert: level unchanged (low) but rewritten every FAN_REASSERT (2 s): 2..4 writes in 6.5 s, all low", 2 <= len(w) <= 4 and set(w) == {"low"}, str(w))

def sc_stopseq():
    n = start(dx=30)
    m = len(writes); rc("stop")
    check("stop: daemon stop sets the DX1215 to full (like V99 on the DS), logged", wait_for(lambda: wv(m) == ["full"], 5) and "stopping: DX1215 fans low -> full" in open(LOG).read(), str(wv(m)))

def sc_dsmode():
    n0 = len(events)
    start("DS_MODE=MANUAL\nDS_DUTY=30\n", ds=30, dx=30)
    check("ds manual: DS_DUTY=30 -> V30 at cool temperatures, chosen manual", wait_for(lambda: "V30" in vs(n0) and st().get("chosen") == "manual"), str((vs(n0), st().get("chosen"))))
    m = len(events); set_disks(52, 30)
    settle(2.5)
    check("ds manual: disk 52 C (LOW table would give 70 %) stays V30: the table rows do not apply in MANUAL", "V70" not in vs(m) and st().get("duty") == "30" and st().get("table_duty") == "70", str((vs(m), st().get("table_duty"))))
    m = len(events); set_disks(58, 30)
    check("ds manual: disk 58 C (LOW ALERT band) -> V99, chosen alert, alert LEDs", wait_for(lambda: "V99" in vs(m) and st().get("chosen") == "alert" and "LA1" in toks(m)), str((vs(m), st().get("chosen"))))
    m = len(events); set_disks("?", 30)
    check("ds manual: a spinning DS disk without a reading -> V70 floor (chosen floor)", wait_for(lambda: "V70" in vs(m) and st().get("chosen") == "floor"), str((vs(m), st().get("chosen"))))
    hl = open(f"{RUN}/history").read()
    check("ds manual: history @dsm=MANUAL, @why=manual/alert/floor, @want = the table duty", "@dsm=MANUAL" in hl and "@why=manual" in hl and "@why=alert" in hl and re.search(r"duty=30 .*@why=manual @want=70", hl), hl[-300:])
    rc("stop"); time.sleep(0.3)
    m = len(events)
    start("DS_FLOOR=12\n", ds=30, dx=30)
    check("ds floor: AUTO with DS_FLOOR=12 -> V12 at cool temperatures (lowest row lowered)", wait_for(lambda: "V12" in vs(m) and st().get("duty") == "12"), str(vs(m)))
    m = len(events); set_disks(47, 30)
    check("ds floor: disk 47 -> V40 as in the table (only the lowest row changes)", wait_for(lambda: "V40" in vs(m)), str(vs(m)))
    rc("stop"); time.sleep(0.3)
    m = len(events)
    start("DS_FLOOR=9\nDS_DUTY=5\nDS_MODE=MANUAL\n", ds=30, dx=30)
    check("ds cfg: DS_FLOOR=9 and DS_DUTY=5 refused by the parser (logged), MANUAL with the default 40",
          wait_for(lambda: "V40" in vs(m)) and "ignoring DS_FLOOR='9'" in open(LOG).read() and "ignoring DS_DUTY='5'" in open(LOG).read(), str(vs(m)))

# 2026.10.26a: bay LED orange from disk health. sdb = parity on 05:00.0 port 0 (DS bay 5), sde = disk1 behind the DX master
# host18 (EMID 0) port multiplier port 0 (DX bay 1). Fake driver files: ds/0000:05:00.0/{leds_enabled,fault_mask,ports}, host18/*.
def mkleds(ds_en="1", mask="0"):
    d = f"{DX}/ds/0000:05:00.0"; os.makedirs(d, exist_ok=True)
    open(f"{d}/leds_enabled", "w").write(ds_en + "\n"); open(f"{d}/fault_mask", "w").write("0\n")
    open(f"{d}/ports", "w").write("port0 ata1\nport1 ata2\nport2 ata3\nport3 ata4\n")
    h = f"{DX}/host18"; os.makedirs(h, exist_ok=True)
    for k, v in (("ata_port", "ata19"), ("emid", "0"), ("led_mask", mask), ("fault", "0")): open(f"{h}/{k}", "w").write(v + "\n")
def rd(p):
    try: return open(p).read().strip()
    except FileNotFoundError: return None
def disks_state(parity="DISK_OK", disk1="DISK_OK", color1="green-on"):
    one = lambda n, d, s, c: f'["{n}"]\nname="{n}"\ndevice="{d}"\nstatus="{s}"\ncolor="{c}"\nspundown="0"\ntemp="30"\n'
    open(f"{tmp}/disks.ini.tmp", "w").write(one("parity", "sdb", parity, "green-on") + one("disk1", "sde", disk1, color1)); os.replace(f"{tmp}/disks.ini.tmp", f"{tmp}/disks.ini")
SMART_FAIL = ("ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
              "  5 Reallocated_Sector_Ct   0x0033   005   005   010    Pre-fail  Always   FAILING_NOW 3000\n"
              "194 Temperature_Celsius     0x0022   031   043   000    Old_age   Always       -       31\n")
SMART_OK = SMART_FAIL.replace("005   005   010    Pre-fail  Always   FAILING_NOW 3000", "100   100   010    Pre-fail  Always       -       0")
def sc_leds():
    os.makedirs(f"{tmp}/smart", exist_ok=True)
    open(f"{tmp}/smart/parity", "w").write(SMART_OK); open(f"{tmp}/smart/disk1", "w").write(SMART_OK)
    start("LED_PERIOD=1\n", ds=30, dx=30); disks_state(); mkleds()
    F = f"{DX}/ds/0000:05:00.0/fault_mask"; H = f"{DX}/host18/fault"
    check("leds: healthy disks -> leds file lists DS bay 5 (sdb) and DX bay 1 (sde), healthy, no orange",
          wait_for(lambda: "ds\t5\tsdb\t0\thealthy: SMART attributes ok" in (rd(f"{RUN}/leds") or "") and "dx\t1\tsde\t0\t" in (rd(f"{RUN}/leds") or ""), 5)
          and rd(F) == "0" and rd(H) == "0", str((rd(f"{RUN}/leds"), rd(F), rd(H))))
    open(f"{tmp}/smart/parity", "w").write(SMART_FAIL)
    check("leds: parity SMART 5 FAILING_NOW -> ds 05:00.0 fault_mask 1 (port 0), logged", wait_for(lambda: rd(F) == "1", 5)
          and "ds 0000:05:00.0 orange mask 0 -> 1" in open(LOG).read() and "sdb\t1\tSMART 5 Reallocated_Sector_Ct FAILING_NOW" in rd(f"{RUN}/leds"), str((rd(F), rd(f"{RUN}/leds"))))
    disks_state(disk1="DISK_DSBL", color1="red-on")
    check("leds: disk1 DISK_DSBL -> host18 fault 1 (port multiplier port 0)", wait_for(lambda: rd(H) == "1", 5) and "Unraid disk state DISK_DSBL" in rd(f"{RUN}/leds"), str((rd(H), rd(f"{RUN}/leds"))))
    open(f"{tmp}/smart/parity", "w").write(SMART_OK)
    check("leds: parity healthy again -> fault_mask back to 0", wait_for(lambda: rd(F) == "0", 5), str(rd(F)))
    open(f"{DX}/ds/0000:05:00.0/leds_enabled", "w").write("0\n"); open(f"{tmp}/smart/parity", "w").write(SMART_FAIL)
    settle(2.5)
    check("leds: controller LEDs off -> its fault_mask is not written (the driver would refuse)", rd(F) == "0", str(rd(F)))
    m = len(open(LOG).read()); rc("stop")
    check("leds: daemon stop clears the orange it set (host18 fault 1 -> 0)", wait_for(lambda: rd(H) == "0", 5) and "orange cleared (daemon stopped" in open(LOG).read()[m:], str(rd(H)))
    open(f"{DX}/ds/0000:05:00.0/leds_enabled", "w").write("1\n")
    start("LED_PERIOD=1\nLED_HEALTH=no\n", ds=30, dx=30); disks_state(disk1="DISK_DSBL", color1="red-on"); mkleds()
    settle(3)
    check("leds: LED_HEALTH=no -> nothing written although disk1 is disabled, leds file says health no",
          rd(H) == "0" and rd(F) == "0" and (rd(f"{RUN}/leds") or "").startswith("#at ") and "#health no" in rd(f"{RUN}/leds"), str((rd(H), rd(f"{RUN}/leds"))))
    rc("stop"); time.sleep(0.3)

SC = {"auto": sc_auto, "dsmode": sc_dsmode, "alarm": sc_alarm, "unreadable": sc_unreadable, "manual": sc_manual, "nostop": sc_nostop,
      "linked": sc_linked, "nomod": sc_nomod, "reassert": sc_reassert, "stopseq": sc_stopseq, "leds": sc_leds}
for name, fn in SC.items():
    if ONLY and name not in ONLY: continue
    print(f"# scenario {name}", flush=True)
    try: fn()
    except Exception as ex: check(f"scenario {name} raised", False, repr(ex))
rc("stop"); rc("stop", e=env(f"{tmp}/absent-dx"))
check("no 'stop' was ever written to fan_level", "stop" not in wv(), str(wv()))
allowed = re.compile(r"^(?:[2,.4-9:;@ABlkqpsrutO]|LA[123]|LE[123]|W20|V[1-9]\d)$")
bad = [t for t in toks() if not allowed.match(t)]
check("every MCU byte on the wire is allow-listed", not bad, str(bad))
print("RESULT:", "ALL PASS" if not fails else f"{fails} FAILED")
sys.exit(1 if fails else 0)
