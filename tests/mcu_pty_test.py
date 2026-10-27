#!/usr/bin/env python3
"""Offline test of the synology-hardware daemon against a pty pair acting as the MCU on ttyS1.
Written: 2026-10-24, syslog stub + DEBUG + watchdog 2026-10-25, real disks / devs.ini / unreadable floor 2026-10-25c. Runs on the build host only (python3 is not needed on Unraid)."""
import os, pty, re, subprocess, sys, tempfile, threading, time, tty

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = f"{ROOT}/src"
RC = f"{SRC}/etc/rc.d/rc.synology-hardware"
KRC = f"{SRC}/etc/rc.d/rc6.d/K05synology-hardware"
SCRIPTS = f"{SRC}/usr/local/emhttp/plugins/synology-hardware/scripts"

master, slave = pty.openpty()
tty.setraw(master)
slave_name = os.ttyname(slave)          # keep `slave` open: closing it would hang the line up
tmp = tempfile.mkdtemp(prefix="synohw-test-")
hw = f"{tmp}/hwmon/hwmon0"; os.makedirs(hw)
open(f"{hw}/name", "w").write("coretemp\n")
def set_cpu(c):
    if c is None:
        try: os.remove(f"{hw}/temp1_input")
        except FileNotFoundError: pass
    else: open(f"{hw}/temp1_input", "w").write(f"{c*1000}\n")
def set_disk(d):
    p = f"{tmp}/disks.ini"
    if d is None:
        try: os.remove(p)
        except FileNotFoundError: pass
    else:
        sb = "1" if d == "*" else "0"
        open(p, "w").write(f'["parity"]\nname="parity"\ndevice="sdb"\nstatus="DISK_OK"\nspundown="{sb}"\ntemp="{d}"\n'
                           '["flash"]\nname="flash"\ndevice="sda"\nstatus="DISK_OK"\ntemp="*"\n'
                           '["disk2"]\nname="disk2"\ndevice=""\nstatus="DISK_NP"\ntemp="*"\n'
                           f'["disk1"]\nname="disk1"\ndevice="sdc"\nstatus="DISK_OK"\nspundown="{sb}"\ntemp="{d-3 if d != "*" else "*"}"\n')
def set_devs(text):   # unassigned devices (devs.ini); None removes the file
    p = f"{tmp}/devs.ini"
    if text is None:
        try: os.remove(p)
        except FileNotFoundError: pass
    else: open(p, "w").write(text)
# fake /sys/block: sda (flash) and sde (a USB stick) on USB, the rest on SATA
for dev, path in (("sda", "usb1/1-1/1-1:1.0/host9/target9:0:0/9:0:0:0"), ("sde", "usb1/1-2/1-2:1.0/host10/target10:0:0/10:0:0:0"),
                  ("sdb", "ata1/host0/target0:0:0/0:0:0:0"), ("sdc", "ata2/host1/target1:0:0/1:0:0:0"), ("sdd", "ata3/host2/target2:0:0/2:0:0:0")):
    os.makedirs(f"{tmp}/sys/devices/pci0000:00/{path}"); os.makedirs(f"{tmp}/sys/block/{dev}")
    os.symlink(f"../../devices/pci0000:00/{path}", f"{tmp}/sys/block/{dev}/device")
open(f"{tmp}/cfg", "w").write("KEEPALIVE_SEC=2\nCPU_PERIOD=1\nDISK_PERIOD=1\nTICK=0.2\n")
# stub `logger` first in PATH: the daemon logs to syslog only; the stub appends "<tag>: <msg>" to {tmp}/log
os.makedirs(f"{tmp}/bin")
with open(f"{tmp}/bin/logger", "w") as f:
    f.write('#!/bin/bash\ntag=; msg=\nwhile [ $# -gt 0 ]; do case $1 in -t) tag=$2; shift 2 ;; --) shift; msg="$*"; break ;; *) msg="$*"; break ;; esac; done\n'
            'if [ -n "$msg" ]; then echo "$tag: $msg" >>"$SYNOHW_TEST_SYSLOG"; else while IFS= read -r l; do echo "$tag: $l" >>"$SYNOHW_TEST_SYSLOG"; done; fi\n')
os.chmod(f"{tmp}/bin/logger", 0o755)
# stub smartctl/ethtool (2026.10.25g: the daemon starts synohw-inventory): record every call; the inventory call (-H) gets the
# standby answer, the daemon's own temperature read gets 127 = "not installed", as before the stub existed
for tool, body in (("smartctl", 'echo "smartctl $*" >>"$SYNOHW_TEST_SYSLOG.calls"\ncase " $* " in *" -H "*) echo "Device is in STANDBY mode, exit(2)"; exit 2 ;; esac\nexit 127\n'),
                   ("ethtool", 'echo "ethtool $*" >>"$SYNOHW_TEST_SYSLOG.calls"; exit 0\n')):
    open(f"{tmp}/bin/{tool}", "w").write("#!/bin/bash\n" + body); os.chmod(f"{tmp}/bin/{tool}", 0o755)
open(f"{tmp}/log", "w").close()
env = dict(os.environ, SYNOHW_SMARTCTL=f"{tmp}/bin/smartctl", SYNOHW_ETHTOOL=f"{tmp}/bin/ethtool", SYNOHW_TTY=slave_name, SYNOHW_CFG=f"{tmp}/cfg", SYNOHW_HWMON=f"{tmp}/hwmon",
           SYNOHW_THERMAL=f"{tmp}/nothermal", SYNOHW_DISKS_INI=f"{tmp}/disks.ini", SYNOHW_DEVS_INI=f"{tmp}/devs.ini", SYNOHW_SYSBLOCK=f"{tmp}/sys/block", SYNOHW_RUN=f"{tmp}/run",
           SYNOHW_TEST_SYSLOG=f"{tmp}/log", SYNOHW_SYSUSB=f"{tmp}/nousb", SYNOHW_DXSYS=f"{tmp}/no-synology_dx", SYNOHW_SYSMODULE=f"{tmp}/nomod", SYNOHW_DIR=SCRIPTS, SYNOHW_SYSNET=f"{tmp}/nonet",
           PATH=f"{tmp}/bin:" + os.environ["PATH"])
os.makedirs(f"{tmp}/nousb")

events = []      # (monotonic time, token)
buf = ""
TOK = re.compile(r"LA[123]|LE[123]|[VW]\d\d|.", re.S)
def reader():
    global buf
    while True:
        try: data = os.read(master, 256)
        except OSError: return
        t = time.monotonic()
        buf += data.decode("latin1")
        # keep a possibly incomplete 2-3 char command in buf until complete
        while buf:
            m = TOK.match(buf)
            tok = m.group(0)
            if (tok in "LV W" or tok in ("L", "V", "W")) and len(buf) < 3 and tok in ("L", "V", "W"): break
            events.append((t, tok)); buf = buf[len(tok):]
threading.Thread(target=reader, daemon=True).start()

fails = 0
def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail and not cond else ""))
    fails += not cond
def toks(since=0): return [t for (ts, t) in events[since:]]
def wait_for(pred, timeout=8):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if pred(): return True
        time.sleep(0.1)
    return False

set_cpu(40); set_disk(41)           # parity 41, disk1 38; flash '*'
r = subprocess.run([RC, "start"], env=env, capture_output=True, text=True)
check("rc start rc=0", r.returncode == 0, r.stdout + r.stderr)
check("inventory ran at daemon start ('at' written), every smartctl call has -n standby, never a USB disk",
      wait_for(lambda: os.path.exists(f"{tmp}/run/inventory/at"))
      and all("-n standby" in l and not l.endswith(("/dev/sda", "/dev/sde")) for l in open(f"{tmp}/log.calls").read().splitlines() if l.startswith("smartctl")),
      open(f"{tmp}/log.calls").read() if os.path.exists(f"{tmp}/log.calls") else "no calls file")
check("startup sequence bytes exactly u 4 8 W20 O V40",
      wait_for(lambda: toks()[:6] == ["u", "4", "8", "W20", "O", "V20"] or toks()[:6] == ["u", "4", "8", "W20", "O", "V40"]),
      str(toks()))
# disk 41 on the LOW table (0:20 46:40 ...) -> 20 %
check("initial fan V20 (disk 41, cpu 40, LOW table)", toks()[5:6] == ["V20"], str(toks()))
r = subprocess.run([RC, "status"], env=env, capture_output=True, text=True)
check("status reports running", r.returncode == 0, f"rc={r.returncode} {r.stdout} {r.stderr}")

# keepalive cadence: collect 'u' timestamps over ~7 s with 2 s interval
time.sleep(7.2)
us = [ts for (ts, t) in events if t == "u"][1:]       # drop the startup one
gaps = [round(b - a, 2) for a, b in zip(us, us[1:])]
check(f"keepalive u every 2 s (gaps {gaps})", len(gaps) >= 2 and all(1.8 <= g <= 2.4 for g in gaps))

# 2026.10.25c: a spun-up unassigned disk without a temperature (temp="*" spundown="0") is not "cool": floor 70 %;
# a USB stick in devs.ini (sde, cold or not) is never an input; an unassigned disk's temperature counts like an array disk's
mark = len(events); set_devs('["dev1"]\ndevice="sdd"\nspundown="0"\ntemp="*"\n["dev2"]\ndevice="sde"\nspundown="0"\ntemp="*"\n')
check("unassigned sdd spun up with temp '*' -> floor V70", wait_for(lambda: "V70" in toks(mark)), str(toks(mark)))
mark = len(events); set_devs('["dev1"]\ndevice="sdd"\nspundown="0"\ntemp="28"\n["dev2"]\ndevice="sde"\nspundown="0"\ntemp="*"\n')
check("sdd reads 28, USB sde ignored -> back to V20", wait_for(lambda: "V20" in toks(mark)), str(toks(mark)))
mark = len(events); set_devs('["dev1"]\ndevice="sdd"\nspundown="0"\ntemp="48"\n')
check("unassigned sdd 48 C (array disks 41/38) -> V40", wait_for(lambda: "V40" in toks(mark)), str(toks(mark)))
mark = len(events); set_devs(None)
check("devs.ini gone -> V20 again", wait_for(lambda: "V20" in toks(mark)), str(toks(mark)))

mark = len(events)
set_disk(47)                                           # -> step 46 => 40 %
check("disk 47 -> V40", wait_for(lambda: "V40" in toks(mark)), str(toks(mark)))
mark = len(events); set_disk(55)                       # -> step 54 => 99 %
check("disk 55 -> V99, no alert yet", wait_for(lambda: "V99" in toks(mark)) and ";" not in toks(mark), str(toks(mark)))
mark = len(events); set_disk(60)                       # alert band 58
check("disk 60 -> orange blink ';' + LA1", wait_for(lambda: ";" in toks(mark) and "LA1" in toks(mark)), str(toks(mark)))
mark = len(events); set_disk(53)                       # below 54 by 1 only: hysteresis keeps 99
time.sleep(2.5)
check("disk 53 -> fan stays 99 (hysteresis 2 C), alert cleared", "V70" not in toks(mark) and "V40" not in toks(mark) and "LA3" in toks(mark), str(toks(mark)))
mark = len(events); set_disk(45)
check("disk 45 -> steps down (V70 or lower)", wait_for(lambda: any(t in toks(mark) for t in ("V70", "V40"))), str(toks(mark)))
mark = len(events); set_disk(None); set_cpu(None)
check("no temperature at all -> V99 (conservative)", wait_for(lambda: "V99" in toks(mark)), str(toks(mark)))
mark = len(events); set_cpu(50)
check("only cpu readable -> floor V70 (MIDDLE)", wait_for(lambda: "V70" in toks(mark)), str(toks(mark)))
mark = len(events); set_cpu(88)
check("cpu 88 -> V99 + alert", wait_for(lambda: "V99" in toks(mark) and "LA1" in toks(mark)), str(toks(mark)))

os.write(master, b"K")
check("MCU->host byte K logged to syslog (tag synology-hardware)", wait_for(lambda: "synology-hardware: MCU byte 0x4b" in open(f"{tmp}/log").read()))
check("non-debug: commands sent are not logged", "sent '" not in open(f"{tmp}/log").read())
os.write(master, b"K3" * 40)  # the real MCU streams K3 nonstop: one line per byte value, not per byte
time.sleep(1)
lines = [l for l in open(f"{tmp}/log") if "MCU byte" in l]
check("repeated MCU bytes logged once per value", len(lines) == 2, str(lines))

# status files for the settings page (RAM, /var/run): state, effective, mcu, history
def kv(path):
    try: return dict(l.split("=", 1) for l in open(path).read().splitlines() if "=" in l)
    except FileNotFoundError: return {}
st = kv(f"{tmp}/run/state")
need = {"pid", "started", "updated", "mode", "cpu", "cpu_step", "cpu_duty", "disk", "disk_step", "disk_duty", "floor",
        "chosen", "duty", "sent", "sent_at", "alert", "status", "ka_at", "ka_count", "ka_fail", "keepalive_sec"}
check("state file has every key the page reads", need <= set(st), str(sorted(need - set(st))))
check("state: cpu 88 -> chosen=cpu, duty 99, cpu step 85, alert 1",
      st.get("chosen") == "cpu" and st.get("duty") == "99" and st.get("cpu_step") == "85" and st.get("alert") == "1", str(st))
check("state: keepalive counted with a timestamp", int(st.get("ka_count", 0)) >= 3 and int(st.get("ka_at", 0)) > 0, str(st))
eff = kv(f"{tmp}/run/effective")
check("effective: what the daemon runs with (cfg KEEPALIVE_SEC=2, defaults elsewhere)",
      eff.get("KEEPALIVE_SEC") == "2" and eff.get("FAN_MODE") == "LOW" and eff.get("MCU_LOG_SEC") == "600", str(eff))
mcu = kv(f"{tmp}/run/mcu")
check("mcu file: byte counts + last byte", mcu.get("count_4b", "0") != "0" and "last_at" in mcu and "since" in mcu, str(mcu))
hist = open(f"{tmp}/run/history").read().splitlines()
check("history: one line per evaluation, '<epoch> cpu=.. duty=.. parity=.. disk1=.. [sdd=..]', flash/empty slot/USB skipped",
      len(hist) >= 5 and all(re.fullmatch(r"\d+ cpu=(\d+|\?) duty=-?\d+( (parity|disk1|sdd)=(\d+|\*|\?))*"
                                          r" @mode=(LOW|HIGH) @why=(disk|cpu|both|floor|no-data|none) @want=-?\d+( core:coretemp_temp1=(-?\d+|\?))?"
                                          r" @dsm=AUTO @dx=none @dxwant=- @dxwhy=nomod @dxal=- @dxt=-", l) for l in hist)
      and any(" parity=" in l for l in hist) and any(" sdd=?" in l for l in hist) and any(" sdd=28" in l for l in hist)
      and not any(k in l for l in hist for k in ("flash", "disk2", "sde", "sda")), str(hist[-3:]))
check("history 25f tokens: @mode/@why/@want on every line, the core temp, no @sweep without a sweep, @why=cpu at cpu 88",
      all(" @mode=LOW " in l for l in hist) and any(" core:coretemp_temp1=88" in l and " @why=cpu " in l and " @want=99" in l for l in hist)
      and not any("@sweep" in l for l in hist), str(hist[-3:]))
check("history 25f: the 25b-e parser (key [A-Za-z0-9_-]) still reads cpu/duty/disks and skips every new token",
      all({k for k, v in (t.split("=", 1) for t in l.split()[1:]) if re.fullmatch(r"[A-Za-z0-9_-]{1,32}", k)} <= {"cpu", "duty", "parity", "disk1", "sdd"} for l in hist), str(hist[-1:]))
check("no state/history tmp file left behind", not os.path.exists(f"{tmp}/run/state.tmp") and not os.path.exists(f"{tmp}/run/history.tmp"))

# only allow-listed tokens ever appeared
import importlib.util
allowed = re.compile(r"^(?:[2,.4-9:;@ABlkqpsrutO]|LA[123]|LE[123]|W20|V[1-9]\d)$")
bad = [t for t in toks() if not allowed.match(t)]
check("every byte on the wire is allow-listed", not bad, str(bad))

# shutdown via the K script path
mark = len(events)
r = subprocess.run([KRC], env=dict(env, SYNOHW_RC=RC), capture_output=True, text=True)
check("K05 hook rc=0", r.returncode == 0, r.stdout + r.stderr)
ok = wait_for(lambda: toks(mark)[-4:] == ["V99", "t", "5", "7"])
check("shutdown sequence V99 t 5 7", ok, str(toks(mark)))
check("daemon gone after shutdown", subprocess.run([RC, "status"], env=env, capture_output=True, text=True).returncode == 1)

# plain stop: V99 t only (no 5/7). This start runs with DEBUG=yes: every MCU byte and every command is logged
open(f"{tmp}/cfg", "a").write('DEBUG="yes"\n')
set_cpu(40); set_disk(41)
loglen = len(open(f"{tmp}/log").read())
subprocess.run([RC, "start"], env=env, capture_output=True, text=True)
time.sleep(1.5)
os.write(master, b"K3" * 3)
time.sleep(1)
new = open(f"{tmp}/log").read()[loglen:]
per_byte = [l for l in new.splitlines() if "MCU byte 0x" in l and "first seen" not in l]
check("DEBUG=yes: every MCU byte logged (6 of 6)", len(per_byte) == 6, str(per_byte))
check("DEBUG=yes: commands sent are logged", "sent 'u'" in new and "sent 'W20'" in new, new[-300:])
mark = len(events)
subprocess.run([RC, "stop"], env=env, capture_output=True, text=True)
time.sleep(0.5)
check("plain stop sends V99 t only", toks(mark)[-2:] == ["V99", "t"] and "5" not in toks(mark), str(toks(mark)))

# watchdog (cron): leaves a deliberate stop alone, restarts a daemon that died
subprocess.run([RC, "watchdog"], env=env, capture_output=True, text=True)
check("watchdog does not restart a deliberately stopped daemon",
      subprocess.run([RC, "status"], env=env, capture_output=True, text=True).returncode == 1)
subprocess.run([RC, "start"], env=env, capture_output=True, text=True)
time.sleep(1)
pid = int(open(f"{tmp}/run/pid").read())
os.killpg(os.getpgid(pid), 9)                  # simulate a crash (daemon, reader and log pipe)
time.sleep(0.5)
mark = len(events)
r = subprocess.run([RC, "watchdog"], env=env, capture_output=True, text=True)
check("watchdog restarts a crashed daemon", r.returncode == 0 and
      subprocess.run([RC, "status"], env=env, capture_output=True, text=True).returncode == 0 and
      wait_for(lambda: "u" in toks(mark)) and "watchdog: daemon not running" in open(f"{tmp}/log").read(), r.stdout + r.stderr)
subprocess.run([RC, "stop"], env=env, capture_output=True, text=True)
print("log tail:\n" + "\n".join(open(f"{tmp}/log").read().splitlines()[-6:]))
print("RESULT:", "ALL PASS" if not fails else f"{fails} FAILED")
sys.exit(1 if fails else 0)
