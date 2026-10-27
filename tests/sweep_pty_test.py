#!/usr/bin/env python3
"""Offline test of the synology-hardware fan sweep (2026.10.25d) against a pty pair acting as the MCU on ttyS1.
Written: 2026-10-25. Runs on the build host only. Same harness idea as mcu_pty_test.py; sweep timings are shrunk
through the cfg (SWEEP_SAMPLE=1, SWEEP_MIN_HOLD=2, SWEEP_MAX_HOLD=4, SWEEP_SETTLE_WINDOW=2).
usage: sweep_pty_test.py [--scripts DIR] [--rc FILE] [--only name,name]   (--scripts/--rc/--only: mutation checks)"""
import glob, os, pty, re, subprocess, sys, tempfile, threading, time, tty

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
tmp = tempfile.mkdtemp(prefix="synohw-sweep-test-")
RUN = f"{tmp}/run"; FLASH = f"{tmp}/flash"
hw = f"{tmp}/hwmon/hwmon0"; os.makedirs(hw)
open(f"{hw}/name", "w").write("coretemp\n")
def set_cpu(c, core=None):
    if c is None:
        for f in glob.glob(f"{hw}/temp*_input"): os.remove(f)
    else:
        open(f"{hw}/temp1_input", "w").write(f"{c*1000}\n")
        open(f"{hw}/temp2_input", "w").write(f"{(c if core is None else core)*1000}\n")
def set_disks(d1, d2, spun="0"):   # two unassigned SATA disks with serial-style ids; d = number or "*"
    open(f"{tmp}/devs.ini", "w").write(
        f'["dev1"]\ndevice="sdc"\nid="ST4000-AAA111"\nspundown="{spun}"\ntemp="{d1}"\n'
        f'["dev2"]\ndevice="sdd"\nid="WDC-BBB222"\nspundown="{spun}"\ntemp="{d2}"\n')
for dev, path in (("sdc", "ata2/host1/target1:0:0/1:0:0:0"), ("sdd", "ata3/host2/target2:0:0/2:0:0:0")):
    os.makedirs(f"{tmp}/sys/devices/pci0000:00/{path}"); os.makedirs(f"{tmp}/sys/block/{dev}")
    os.symlink(f"../../devices/pci0000:00/{path}", f"{tmp}/sys/block/{dev}/device")
os.makedirs(f"{tmp}/bin"); os.makedirs(f"{tmp}/nousb")
with open(f"{tmp}/bin/logger", "w") as f:
    f.write('#!/bin/bash\ntag=; msg=\nwhile [ $# -gt 0 ]; do case $1 in -t) tag=$2; shift 2 ;; --) shift; msg="$*"; break ;; *) msg="$*"; break ;; esac; done\n'
            'if [ -n "$msg" ]; then echo "$tag: $msg" >>"$SYNOHW_TEST_SYSLOG"; else while IFS= read -r l; do echo "$tag: $l" >>"$SYNOHW_TEST_SYSLOG"; done; fi\n')
os.chmod(f"{tmp}/bin/logger", 0o755)
open(f"{tmp}/log", "w").close()
env = dict(os.environ, SYNOHW_TTY=slave_name, SYNOHW_CFG=f"{tmp}/cfg", SYNOHW_HWMON=f"{tmp}/hwmon",
           SYNOHW_THERMAL=f"{tmp}/nothermal", SYNOHW_DISKS_INI=f"{tmp}/disks.ini", SYNOHW_DEVS_INI=f"{tmp}/devs.ini",
           SYNOHW_SYSBLOCK=f"{tmp}/sys/block", SYNOHW_RUN=RUN, SYNOHW_TEST_SYSLOG=f"{tmp}/log", SYNOHW_DIR=SCRIPTS,
           SYNOHW_SWEEP_FLASH=FLASH, SYNOHW_DXSYS=f"{tmp}/no-synology_dx", SYNOHW_SYSMODULE=f"{tmp}/nomod", SYNOHW_SMARTCTL=f"{tmp}/no-smartctl", SYNOHW_ETHTOOL=f"{tmp}/no-ethtool", SYNOHW_SYSNET=f"{tmp}/nonet", PATH=f"{tmp}/bin:" + os.environ["PATH"])

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

fails = 0
def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail and not cond else ""), flush=True)
    fails += not cond
def toks(since=0): return [t for (_, t) in events[since:]]
def vs(since=0): return [t for t in toks(since) if t[0] == "V"]
def wait_for(pred, timeout=15):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if pred(): return True
        time.sleep(0.1)
    return False
def kv(path):
    try: return dict(l.split("=", 1) for l in open(path).read().splitlines() if "=" in l)
    except FileNotFoundError: return {}
def st(): return kv(f"{RUN}/sweep.state")
def rc(*args, timeout=60):
    return subprocess.run([RC, *args], env=env, capture_output=True, text=True, timeout=timeout)
LONG = "SWEEP_MIN_HOLD=30\nSWEEP_MAX_HOLD=60\n"   # steps that never end by themselves during a scenario
BASE = "KEEPALIVE_SEC=2\nCPU_PERIOD=1\nDISK_PERIOD=1\nTICK=0.2\nSWEEP_SAMPLE=1\nSWEEP_MIN_HOLD=2\nSWEEP_MAX_HOLD=4\nSWEEP_SETTLE_WINDOW=2\n"
def start(extra="", cpu=40, d1=31, d2=28):
    rc("stop"); time.sleep(0.3)
    set_cpu(cpu); set_disks(d1, d2)
    open(f"{tmp}/cfg", "w").write(BASE + extra)
    for f in glob.glob(f"{FLASH}/*") + glob.glob(f"{RUN}/sweep*"): os.remove(f)
    r = rc("start"); n = len(events)
    ok = r.returncode == 0 and wait_for(lambda: "V20" in vs(n) or "V20" in vs()[-2:], 8)
    time.sleep(1.2)           # first evaluation done
    return len(events)
def csv_rows():
    try: lines = open(f"{RUN}/sweep.csv").read().splitlines()
    except FileNotFoundError: return [], []
    return lines[0].split(","), [l.split(",") for l in lines[1:]]
def flash_pair(): return sorted(glob.glob(f"{FLASH}/sweep-*.csv")), sorted(glob.glob(f"{FLASH}/sweep-*.txt"))

def sc_settle():
    m = start("SWEEP_MAX_HOLD=8\n")   # max hold far above min hold: only an early advance on settle gives ~2 s steps
    r = rc("sweep", "start", "20 15 12"); n = len(events)
    check("settle: rc sweep start rc=0, state running", r.returncode == 0 and "running" in r.stdout, r.stdout + r.stderr)
    os.write(master, b"K3K3K3"); time.sleep(0.4); os.write(master, b"K3K3")
    check("settle: plan ran to the end (state done)", wait_for(lambda: st().get("state") == "done", 25), str(st()))
    seq = vs(m)
    check("settle: sent V20 V15 V12 in order, then the table duty V20", seq[-4:] == ["V20", "V15", "V12", "V20"], str(seq))
    ts = [t for (t, k) in events[m:] if k[0] == "V"][-4:]
    gaps = [round(b - a, 1) for a, b in zip(ts, ts[1:])]
    check(f"settle: steps advanced on settle, well before the max hold (8 s), not before min hold 2 s (gaps {gaps})",
          # the daemon measures hold in whole seconds (EPOCHSECONDS), so a 2 s minimum ends after 1.0..2.x s of real time;
          # 2026.10.25f moved the loop's phase (read_cpu also reads the cores) and the old 1.8 s bound became 1.2-1.6 s
          len(gaps) == 3 and all(1.0 <= g < 3.9 for g in gaps[:2]), str(gaps))
    hdr, rows = csv_rows()
    check("settle: csv header epoch,step,duty,cpu_max,core:..,disk:<id> (serial keyed),mcu columns",
          hdr[:4] == ["epoch", "step", "duty", "cpu_max"] and "disk:ST4000-AAA111" in hdr and "disk:WDC-BBB222" in hdr
          and "core:coretemp_temp2" in hdr and hdr[-3:] == ["mcu_33", "mcu_4b", "mcu_other"] and not any("sdc" in h for h in hdr), str(hdr))
    check("settle: samples carry step and duty, steps 1..3 present", {r[1] for r in rows} == {"1", "2", "3"} and {r[2] for r in rows} == {"20", "15", "12"}, str(rows[:3]))
    i33, i4b, ioth = hdr.index("mcu_33"), hdr.index("mcu_4b"), hdr.index("mcu_other")
    check("settle: MCU byte counts per sample (5 x '3', 5 x 'K' sent), none unknown",
          sum(int(r[i33]) for r in rows) == 5 and sum(int(r[i4b]) for r in rows) == 5 and sum(int(r[ioth]) for r in rows) == 0,
          str([(r[i33], r[i4b], r[ioth]) for r in rows]))
    summ = open(f"{RUN}/sweep-summary.txt").read()
    check("settle: summary has per-step duty/hold/settled and start/end/min/max/slope per CPU and disk",
          "step 1: duty 20 %" in summ and "settled yes" in summ and "CPU max" in summ and "ST4000-AAA111" in summ
          and "slope +0.00 C/10min" in summ and "min 40  max 40" in summ, summ)
    c, t = flash_pair()
    check("settle: csv + summary copied once to flash dir as sweep-YYYYmmdd-HHMM.{csv,txt}",
          len(c) == 1 and len(t) == 1 and re.search(r"sweep-\d{8}-\d{4}\.csv$", c[0]) and open(c[0]).read() == open(f"{RUN}/sweep.csv").read(), str((c, t)))
    check("settle: state done, rc sweep status shows it", rc("sweep", "status").stdout.count("done") >= 1 and st().get("result_csv") == c[0], str(st()))
    check("settle: keepalive u kept flowing during the sweep", toks(m).count("u") >= 2)
    hl = open(f"{RUN}/history").read().splitlines()
    sw_lines = [l for l in hl if " @sweep=" in l]
    check("settle: history marks the sweep (@sweep=<step>, duty = the sweep duty sent) and lines after it have no @sweep",
          sw_lines and {re.search(r" @sweep=(\d+)", l).group(1) for l in sw_lines} <= {"1", "2", "3"}
          and all(re.search(r" duty=(\d+) ", l).group(1) in ("20", "15", "12") for l in sw_lines), str(sw_lines[-3:]))
    check("settle: no sweep files outside the run dir (flash only gets the final pair)", sorted(os.listdir(FLASH)) == sorted(os.path.basename(x) for x in c + t))

def sc_maxhold():
    m = start()
    stop = threading.Event()
    def warm():
        v = 40
        while not stop.is_set() and v < 58: v += 1; set_cpu(v); time.sleep(0.6)
    th = threading.Thread(target=warm, daemon=True); th.start()
    rc("sweep", "start", "30 25")
    ok = wait_for(lambda: "V25" in vs(m), 12)
    ts = [t for (t, k) in events[m:] if k in ("V30", "V25")]
    check(f"maxhold: temperatures keep moving -> step 1 lasts the max hold (4 s), gap {round(ts[-1]-ts[-2],1) if len(ts)>1 else None}",
          ok and len(ts) >= 2 and 2.9 <= ts[-1] - ts[-2] <= 6.5, str(vs(m)))
    n = len(events)
    r = rc("sweep", "stop")
    check("maxhold: sweep stop rc=0 -> state stopped, reason says so", r.returncode == 0 and st().get("state") == "stopped", r.stdout + r.stderr + str(st()))
    time.sleep(1.5); stop.set()
    check("maxhold: stop resent the table duty V20 and nothing else (plan step 3 never sent)", vs(n) == ["V20"], str(vs(n)))
    summ = open(f"{RUN}/sweep-summary.txt").read()
    check("maxhold: summary says step 1 ended by max-hold, settled no; step 2 ended by stopped",
          re.search(r"step 1: duty 30 %, held [3-6] s, settled no, ended by max-hold", summ) and "step 2: duty 25 %" in summ and "ended by stopped" in summ, summ)
    check("maxhold: stopped sweep also copied to flash", len(flash_pair()[0]) == 1)
    check("maxhold: rc sweep stop when nothing runs is harmless", rc("sweep", "stop").returncode == 0)

def sc_disk_abort():
    m = start(LONG)
    rc("sweep", "start", "30 25 20")
    wait_for(lambda: "V30" in vs(m), 5); time.sleep(0.5); n = len(events)
    set_disks(46, 40)
    check("disk abort: disk at 46 C aborts the sweep", wait_for(lambda: st().get("state") == "aborted", 6), str(st()))
    check("disk abort: reason names the disk (by id) and the limit", "WDC" not in st().get("reason", "") and "ST4000-AAA111 at 46 C >= 46 C" in st().get("reason", ""), st().get("reason"))
    time.sleep(0.8)
    check("disk abort: table duty resent at once (disk 46 C on LOW = V40), plan steps 2/3 never sent", vs(n) == ["V40"] and "V25" not in vs(m), str(vs(m)))
    check("disk abort: reason in syslog", "SWEEP ABORTED: disk ST4000-AAA111 at 46 C" in open(f"{tmp}/log").read())
    hdr, rows = csv_rows()
    check("disk abort: last csv row records the 46", rows and rows[-1][hdr.index("disk:ST4000-AAA111")] == "46", str(rows[-1:]))
    check("disk abort: results still copied to flash", len(flash_pair()[1]) == 1 and "result: aborted" in open(f"{RUN}/sweep-summary.txt").read())
    time.sleep(1.5)
    check("disk abort: stays on the table afterwards (no further sweep V)", "V25" not in vs(m) and "V30" not in vs(n))

def sc_cpu_abort():
    m = start(LONG)
    rc("sweep", "start", "30 25")
    wait_for(lambda: "V30" in vs(m), 5); n = len(events)
    set_cpu(65)
    check("cpu abort: CPU at 65 C aborts", wait_for(lambda: st().get("state") == "aborted", 6) and "CPU at 65 C >= 65 C" in st().get("reason", ""), str(st()))
    time.sleep(0.8)
    check("cpu abort: table duty resent (CPU 65 on the table = V70)", vs(n) == ["V70"], str(vs(n)))

def sc_byte_abort():
    m = start(LONG)
    rc("sweep", "start", "30 25")
    wait_for(lambda: "V30" in vs(m), 5)
    os.write(master, b"K3K3K3")
    time.sleep(2.5)
    check("byte abort: the known bytes '3' and 'K' do not abort", st().get("state") == "running", str(st()))
    n = len(events); os.write(master, b"X")
    check("byte abort: an unknown byte (0x58) aborts", wait_for(lambda: st().get("state") == "aborted", 4), str(st()))
    check("byte abort: reason names the byte and the alarm risk", "0x58" in st().get("reason", "") and "fan-fail" in st().get("reason", ""), st().get("reason"))
    time.sleep(0.8)
    check("byte abort: table duty V20 resent, plan step 2 never sent", vs(n) == ["V20"] and "V25" not in vs(m), str(vs(m)))
    hdr, rows = csv_rows()
    check("byte abort: final row counts the byte in mcu_other", rows and sum(int(r[hdr.index("mcu_other")]) for r in rows) == 1, str(rows[-2:]))
    m2 = start(LONG)
    rc("sweep", "start", "30 25"); wait_for(lambda: "V30" in vs(m2 + 1), 5); os.write(master, b"\x00")
    check("byte abort: a NUL byte is unknown too", wait_for(lambda: st().get("state") == "aborted" and "0x00" in st().get("reason", ""), 4), str(st()))

def sc_unreadable():
    m = start(LONG)
    rc("sweep", "start", "30 25"); wait_for(lambda: "V30" in vs(m), 5); n = len(events)
    set_cpu(None)
    check("unreadable: CPU temperature gone -> abort", wait_for(lambda: st().get("state") == "aborted", 6) and "unreadable" in st().get("reason", "") and "cpu" in st().get("reason", ""), str(st()))
    time.sleep(0.8)
    check("unreadable: table duty back (one input unreadable = floor V70), no plan step after it", vs(n) == ["V70"] and "V25" not in vs(m), str(vs(n)))
    m = start(LONG)
    rc("sweep", "start", "30 25"); wait_for(lambda: "V30" in vs(m), 5)
    set_disks("*", 28, spun="1")
    check("unreadable: a disk in standby (no temperature) -> abort", wait_for(lambda: st().get("state") == "aborted", 6) and "disk:ST4000-AAA111" in st().get("reason", ""), str(st()))
    m = start(LONG)
    rc("sweep", "start", "30 25"); wait_for(lambda: "V30" in vs(m), 5)
    os.remove(f"{tmp}/devs.ini")
    check("unreadable: disk sources gone -> abort", wait_for(lambda: st().get("state") == "aborted" and "disk-list" in st().get("reason", ""), 6), str(st()))

def sc_start_unreadable():
    m = start(cpu=None)
    n = len(events)
    r = rc("sweep", "start", "30 25")
    time.sleep(0.8)
    check("start unreadable: CPU unreadable before the first step -> aborted, rc!=0", r.returncode != 0 and st().get("state") == "aborted", r.stdout + str(st()))
    check("start unreadable: no plan duty ever sent (only the table's V70)", "V30" not in vs(n) and "V25" not in vs(n) and set(vs(n)) <= {"V70"}, str(vs(n)))

def sc_reassert():
    m = start("FAN_REASSERT=2\nSWEEP_MIN_HOLD=30\nSWEEP_MAX_HOLD=60\n")
    rc("sweep", "start", "15")
    wait_for(lambda: "V15" in vs(m), 5); n = len(events); time.sleep(6)
    check("reassert: the sweep duty is re-sent every FAN_REASSERT (2 s): >= 2 more V15 in 6 s, nothing else", vs(n).count("V15") >= 2 and set(vs(n)) == {"V15"}, str(vs(n)))
    rc("sweep", "stop")

def sc_badplan():
    m = start("SWEEP_MIN_HOLD=30\nSWEEP_MAX_HOLD=60\n"); n = len(events)
    for plan in ("20 9", "5", "100", "abc", "20 09", "10.5", " ".join(["20"] * 25), "-20"):
        r = rc("sweep", "start", plan)
        check(f"bad plan '{plan[:20]}' refused by the command (rc!=0)", r.returncode != 0, r.stdout + r.stderr)
    time.sleep(1.2)
    check("bad plans: no request, no sweep state, nothing sent", not os.path.exists(f"{RUN}/sweep.request") and not os.path.exists(f"{RUN}/sweep.state") and vs(n) == [], str(vs(n)))
    r = rc("sweep", "start", "10 99")
    check("plan boundaries 10 and 99 are accepted", r.returncode == 0 and wait_for(lambda: "V10" in vs(n), 5), r.stdout + r.stderr)
    check("lowest step sent is V10", "V10" in vs(n) and all(int(v[1:]) >= 10 for v in vs()), str(vs(n)))
    rc("sweep", "stop")
    rc("stop"); r = rc("sweep", "start", "20")
    check("sweep start with the daemon stopped fails", r.returncode != 0, r.stdout + r.stderr)

def sc_running():
    m = start("SWEEP_MIN_HOLD=30\nSWEEP_MAX_HOLD=60\n")
    r = rc("sweep", "start")
    check("default plan: 20 15 12 10 20 40 70 99, first V20", st().get("plan") == "20 15 12 10 20 40 70 99" and st().get("steps") == "8" and st().get("step") == "1", str(st()))
    r2 = rc("sweep", "start", "40")
    check("second start while running is refused, plan unchanged", r2.returncode != 0 and "already running" in r2.stdout and st().get("plan") == "20 15 12 10 20 40 70 99", r2.stdout)
    check("status panel keys present", {"state", "step", "steps", "duty", "step_started", "hold", "temps", "reason", "csv", "summary"} <= set(st()), str(sorted(st())))
    rc("sweep", "stop")

def sc_restart():
    m = start()
    rc("sweep", "start", "30 25"); wait_for(lambda: "V30" in vs(m), 5); n = len(events)
    rc("restart")
    time.sleep(1.5)
    check("restart: the sweep ended with the daemon (aborted, reason says daemon stopped), not resumed",
          st().get("state") == "aborted" and "daemon stopped" in st().get("reason", ""), str(st()))
    check("restart: stop sequence V99 t, then the normal startup, no plan duty after it",
          "V99" in vs(n) and "t" in toks(n) and "V30" not in vs(n) and "V25" not in vs(n), str(toks(n)))
    check("restart: results saved on the way out", len(flash_pair()[0]) == 1)
    check("restart: rc sweep status after the restart still says aborted, not running", "aborted" in rc("sweep", "status").stdout, rc("sweep", "status").stdout)

SC = {"settle": sc_settle, "maxhold": sc_maxhold, "disk-abort": sc_disk_abort, "cpu-abort": sc_cpu_abort, "byte-abort": sc_byte_abort,
      "unreadable": sc_unreadable, "start-unreadable": sc_start_unreadable, "reassert": sc_reassert, "badplan": sc_badplan,
      "running": sc_running, "restart": sc_restart}
for name, fn in SC.items():
    if ONLY and name not in ONLY: continue
    print(f"# scenario {name}", flush=True)
    try: fn()
    except Exception as e: check(f"scenario {name} raised", False, repr(e))
rc("stop")
allowed = re.compile(r"^(?:[2,.4-9:;@ABlkqpsrutO]|LA[123]|LE[123]|W20|V[1-9]\d)$")
bad = [t for t in toks() if not allowed.match(t)]
check("every byte on the wire is allow-listed and no V below 10 ever left the daemon", not bad, str(bad))
print("RESULT:", "ALL PASS" if not fails else f"{fails} FAILED")
sys.exit(1 if fails else 0)
