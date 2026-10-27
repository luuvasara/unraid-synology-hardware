#!/usr/bin/env python3
"""Build a fake DS2415+ box tree for the status page tests and the local render harness.
Written: 2026-10-25 (2026.10.25b). Fake MACs (00:11:32:aa:bb:0X) and a fake serial: the real unit's
values are deliberately not used in anything that can end up in a screenshot.
usage: mkfixture.py <dir> <scenario>   scenario: normal | stopped | notty | crashed | hot | empty | measured | graphs | nodx | dxalarm | unknown
2026.10.25j: libata port_no per ata port, NIC speed, and "unknown" (an unrecognised main unit and a DX517 expansion unit).
2026.10.25h: the synology-dx module's sysfs (/sys/kernel/synology_dx/unit, /sys/module/synology_dx, hwmon synology_dx), the
daemon's dx copy and dx_* state keys; I354 NICs with the measured "Unknown" link files; nodx = normal without the module,
dxalarm = normal with fan 1 alarm set, DX at full by the alarm rule.
2026.10.25f: PCI vendor/device ids + port-multiplier links in the fake sysfs (DS2415+ internal vs DX1215), history lines
with the @mode/@why/@want/@sweep/core: tokens; "graphs" = 26 h of history with a sweep and a gap, plus a sweep.csv.
2026.10.25g: the Hardware section tree (PCI with link widths and AER, ata links, cpufreq/cpuidle, thermal trips, meminfo, USB,
NIC counters) and the inventory cache (SMART + ethtool from tests/fixtures/hw-2026-10-25) for normal/graphs/measured/hot.
2026.10.25c: devs.ini + fake /sys/block (SATA vs USB); "measured" = the DS2415+ disks.ini/devs.ini of
2026-10-25 01:52 (tests/fixtures/measured-2026-10-25), array unassigned, two HDDs unassigned, DOM on USB.
Prints the PHP override array (JSON) for $GLOBALS['synohw_path_override']."""
import json, os, random, shutil, sys, time

d, sc = sys.argv[1], sys.argv[2]
# 2026.10.25j "unknown": normal, but a main unit that is not recognised (88SE9215 ids changed) and an expansion unit the page has
# no drawing for (module reports DX517, unique 0x15): the Overview shows plain unit pictures, every value still in the tables.
unknown = sc == "unknown"
if unknown: sc = "normal"
now = int(time.time())
def w(p, s, mode=None):
    p = os.path.join(d, p); os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, "w").write(s)
run = "run/synology-hardware"
os.makedirs(os.path.join(d, run), exist_ok=True)
hot = sc == "hot"
disks = [("parity", "sdb", 47 if hot else 38), ("disk1", "sdc", 55 if hot else 36), ("disk2", "sdd", "*"),
         ("disk3", "sde", 50 if hot else 41), ("disk4", "sdf", "*")]
ini = '["flash"]\nname="flash"\ndevice="sda"\ntemp="*"\n'
for n, dev, t in disks:
    ini += f'["{n}"]\nname="{n}"\ndevice="{dev}"\nid="WDC_WD40EFRX-68N32N0_WD-WCC7K0000{dev[-1]}"\ntemp="{t}"\nspundown="{1 if t == "*" else 0}"\nrotational="1"\ntransport="ata"\nstatus="DISK_OK"\n'
ini += '["disk5"]\nname="disk5"\ndevice=""\ntemp="*"\nstatus="DISK_NP"\n'
if sc == "graphs":   # screenshots: 26 h of history (more than the 24 h range), 3 internal + 2 DX1215 disks, a sweep, a gap
    disks = [("parity", "sdb", 38), ("disk1", "sdc", 36), ("disk2", "sdd", "*"), ("disk3", "sde", 41), ("disk4", "sdf", 39)]
    ini = '["flash"]\nname="flash"\ndevice="sda"\ntemp="*"\n'
    for (n, dev, t), idv in zip(disks, ["ST4000VN008-2DR166_ZDH0AAA1", "ST4000VN008-2DR166_ZDH0AAA2", "WDC_WD40EFRX-68N32N0_WD-WCC7K0AAA3",
                                         "WDC_WD40EFRX-68N32N0_WD-WCC7K0AAA4", "ST1000DM003-1CH162_Z1D0AAA5"]):
        ini += f'["{n}"]\nname="{n}"\ndevice="{dev}"\nid="{idv}"\ntemp="{t}"\nspundown="{1 if t == "*" else 0}"\nrotational="1"\ntransport="ata"\nstatus="DISK_OK"\n'
def graphs_history():
    import math
    random.seed(11); out = []
    sw0, sw1 = now - 5 * 3600, now - 5 * 3600 + 80 * 60          # a fan sweep 5 h ago: 20, 15, 12, 10 % (20 min each)
    gap0, gap1 = now - 9 * 3600, now - 9 * 3600 + 25 * 60        # the daemon stopped for 25 min 9 h ago
    for ts in range(now - 26 * 3600, now, 30):
        if gap0 <= ts < gap1: continue
        day = math.sin((ts % 86400) / 86400 * 2 * math.pi - 1.9)    # warmer in the afternoon
        load = 1 if (ts // 1800) % 13 == 0 else 0                     # a parity-check-like burst now and then
        sweep = 1 + (ts - sw0) // 1200 if sw0 <= ts < sw1 else 0
        sduty = [20, 15, 12, 10][sweep - 1] if sweep else 0
        rise = (ts - sw0) / 1200 * 0.8 if sweep else 0
        cores = [round(41 + 3 * day + 4 * load + rise) + k % 2 for k in range(4)]
        dk = {"parity": 37 + 2 * day + 2 * load + rise, "disk1": 35 + 2 * day + rise, "disk3": 43 + 3 * day + 3 * load + rise, "disk4": 40 + 2.5 * day + rise}
        tbl = 40 if dk["disk3"] >= 46 or (out and "@want=40" in out[-1] and dk["disk3"] >= 44) else 20
        duty = sduty or tbl
        row = f"{ts} cpu={max(cores)} duty={duty}"
        for n in ("parity", "disk1"): row += f" {n}={round(dk[n])}"
        row += f" disk2={'*' if (ts // 3600) % 6 else round(33 + day)}"   # mostly asleep
        for n in ("disk3", "disk4"): row += f" {n}={round(dk[n])}"
        why = "disk" if tbl > 20 else "both"
        row += f" @mode=LOW @why={why} @want={tbl}" + (f" @sweep={sweep}" if sweep else "") + "".join(f" core:coretemp_temp{i}={c}" for i, c in enumerate(cores, 2))
        if ts >= now - 20 * 3600:   # 2026.10.25h tokens on the last 20 h: DX level from the DX disks (disk3, disk4), an alarm 2 h ago
            hx = max(round(dk["disk3"]), round(dk["disk4"]))
            alarm = now - 2 * 3600 <= ts < now - 2 * 3600 + 600
            lv = "full" if alarm else ("middle" if hx >= 48 or (out and "@dx=middle" in out[-1] and hx >= 46) else "low")
            row += f" @dsm=AUTO @dx={lv} @dxwant={lv} @dxwhy={'alarm' if alarm else 'auto'} @dxal={1 if alarm else 0} @dxt={hx}"
        out.append(row)
    return out
measured = sc == "measured"
def mkpci(path, vid, did):   # PCI function dir with vendor/device ids, as /sys/devices/pci0000:00/... has them
    os.makedirs(os.path.join(d, "sys", path), exist_ok=True)
    w(f"sys/{path}/vendor", f"0x{vid}\n"); w(f"sys/{path}/device", f"0x{did}\n")
# DS2415+ topology (lspci-link.txt, 2026-10-25): internal bays on 88SE9215s behind root ports; the DX1215 on the 88SE9235
# at 07:00.0 behind root port 00:04.0, one 88SM9705 port multiplier per ata port (ata19-22).
INT = "devices/pci0000:00/0000:00:02.0/0000:05:00.0"; DX = "devices/pci0000:00/0000:00:04.0/0000:07:00.0"
def sysblock(dev, usb, box="ds", port=None, pmp=None, ctrl=None):
    # /sys/block/<dev>/device -> a SCSI device under an ata port or under a USB interface (as on real sysfs)
    #   box ds: 88SE9215 (1b4b:9215) ataN, no port multiplier; box dx: 88SE9235 (1b4b:9235) ataN + PMP fan-out links
    n = port or (ord(dev[-1]) - 96)
    if usb:   # sda = the Unraid flash on the Etron xHCI USB 2.0 bus, others = the DOM behind the EHCI hub (pci-usb.txt)
        tgt = (f"devices/pci0000:00/0000:00:01.0/0000:01:00.0/0000:02:03.0/0000:04:00.0/usb2/2-1/2-1:1.0/host22/target22:0:0/22:0:0:0" if dev == "sda"
               else f"devices/pci0000:00/0000:00:16.0/usb1/1-1/1-1.1/1-1.1:1.0/host23/target23:0:0/23:0:0:0")
    elif box == "dx":
        mkpci("devices/pci0000:00/0000:00:04.0", "8086", "1f13"); mkpci(DX, "1b4b", "9235")
        for l in (f"link{n}", f"link{n}.0", f"link{n}.{pmp}", f"link{n}.15"): os.makedirs(os.path.join(d, "sys", DX, f"ata{n}", l), exist_ok=True)
        tgt = f"{DX}/ata{n}/host{n - 1}/target{n - 1}:{pmp}:0/{n - 1}:{pmp}:0:0"
    else:
        mkpci("devices/pci0000:00/0000:00:02.0", "8086", "1f11"); mkpci(INT, "1b4b", "9215")
        tgt = f"{ctrl or INT}/ata{n}/host{n - 1}/target{n - 1}:0:0/{n - 1}:0:0:0"
    if not usb:   # 2026.10.25j: libata's port_no (1-based port within the controller), as measured: ata7 -> 1, ata19..22 -> 1..4
        w(f"sys/class/ata_port/ata{n}/port_no", f"{(n - 18) if box == 'dx' else ((n - 7) % 4) + 1 if n >= 7 else ((n - 1) % 4) + 1}\n")
    os.makedirs(os.path.join(d, "sys", tgt), exist_ok=True); os.makedirs(os.path.join(d, "sys/block", dev), exist_ok=True)
    os.symlink(f"../../{tgt}", os.path.join(d, "sys/block", dev, "device"))
    if not usb:   # /sys/class/ata_link/link<N>[.<M>] -> the link dir, with the negotiated speed (#22)
        ln = f"link{n}" + (f".{pmp}" if box == "dx" else "")
        ldir = f"{DX if box == 'dx' else (ctrl or INT)}/ata{n}/{ln}"
        w(f"sys/{ldir}/sata_spd", ("3.0 Gbps" if dev == "sdc" and sc == "graphs" else "6.0 Gbps") + "\n"); w(f"sys/{ldir}/sata_spd_limit", "<unknown>\n"); w(f"sys/{ldir}/hw_sata_spd_limit", "<unknown>\n")
        os.makedirs(os.path.join(d, "sys/class/ata_link"), exist_ok=True)
        os.symlink(f"../../{ldir}", os.path.join(d, "sys/class/ata_link", ln))
        if box == "dx":   # the PMP's host link and its control port 15 exist too (no device on them)
            for extra in (f"link{n}", f"link{n}.15"):
                if not os.path.lexists(os.path.join(d, "sys/class/ata_link", extra)):
                    w(f"sys/{DX}/ata{n}/{extra}/sata_spd", ("6.0 Gbps" if extra == f"link{n}" else "<unknown>") + "\n")
                    os.symlink(f"../../{DX}/ata{n}/{extra}", os.path.join(d, "sys/class/ata_link", extra))
if measured:
    fx = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "measured-2026-10-25")
    for f in ("disks.ini", "devs.ini"):
        os.makedirs(os.path.join(d, "var/local/emhttp"), exist_ok=True); shutil.copy(os.path.join(fx, f), os.path.join(d, "var/local/emhttp", f))
    sysblock("sda", True); sysblock("sdb", True)
    # measured: sdc [6:0:0:0] ata7 on the 88SE9215 at 03:00.0 (port_no 1), sdd [20:1:0:0] PMP port 1 (2026.10.25j: on ata21, port_no 3,
    # read on the unit 2026-10-25 /sys/block/sdd/device -> 0000:07:00.0/ata21/host20/target20:1:0)
    sysblock("sdc", False, "ds", 7, ctrl="devices/pci0000:00/0000:00:01.0/0000:01:00.0/0000:02:02.0/0000:03:00.0"); sysblock("sdd", False, "dx", 21, 1)
    disks = [("sdd", "sdd", 28), ("sdc", "sdc", 31)]   # what the daemon's history holds for this box
else:
    if sc != "empty": w("var/local/emhttp/disks.ini", ini)
    sysblock("sda", True)
    for i, (_, dev, _) in enumerate(disks):   # parity, disk1, disk2 internal; disk3, disk4 in the DX1215 (PMP ports 0, 2)
        if i < 3: sysblock(dev, False, "ds", i + 1)
        else: sysblock(dev, False, "dx", 16 + i, (i - 3) * 2)
w("boot/config/plugins/dynamix/dynamix.cfg", '[display]\nhot="45"\nmax="55"\n')
cpu = [("Core 0", 52 if hot else 44), ("Core 1", 53 if hot else 46), ("Core 2", 51 if hot else 43), ("Core 3", 54 if hot else 45)]
w("sys/class/hwmon/hwmon1/name", "coretemp\n")
for i, (l, t) in enumerate(cpu, 2):
    w(f"sys/class/hwmon/hwmon1/temp{i}_input", f"{t*1000}\n"); w(f"sys/class/hwmon/hwmon1/temp{i}_label", l + "\n")
w("sys/class/hwmon/hwmon0/name", "acpitz\n"); w("sys/class/hwmon/hwmon0/temp1_input", "27800\n")
mode = "HIGH" if hot else "LOW"
w("boot/config/plugins/synology-hardware/synology-hardware.cfg",
  f'FAN_MODE="{mode}"\nKEEPALIVE_SEC="10"\nBEEP_ON_START="no"\nAC_RECOVERY="keep"\nWOL="keep"\nSHUTDOWN_ACTION="no"\nMACFIX="yes"\nDEBUG="no"\n')
pid = 4242
running = sc in ("normal", "hot", "measured", "graphs", "nodx", "dxalarm")
dxmod = sc in ("normal", "hot", "measured", "graphs", "dxalarm")   # the synology-dx module is loaded
dxal = sc == "dxalarm"
if running:
    w(f"proc/{pid}/cmdline", "/bin/bash\0/usr/local/emhttp/plugins/synology-hardware/scripts/synohw-daemon\0")
if sc in ("normal", "hot", "crashed", "stopped", "measured", "graphs", "nodx", "dxalarm"):
    w(f"{run}/pid", f"{pid}\n") if sc != "stopped" else None
    hd = max(t for _, _, t in disks if t != "*"); ct = max(t for _, t in cpu)
    if hot: step, duty = (49, 99) if hd >= 49 else (45, 70); cstep, cduty = 0, 20
    else: step, duty, cstep, cduty = 0, 20, 0, 20
    st = dict(pid=pid, started=now - 6 * 3600, updated=now - 3, mode=mode, cpu=ct, cpu_at=now - 12, cpu_step=cstep, cpu_duty=cduty,
              disk=hd, disk_at=now - 40, disk_step=step, disk_duty=duty, floor=0, chosen="disk" if duty > cduty else "both",
              duty=duty, sent=duty, sent_at=now - 3 * 3600, alert=1 if hot and hd >= 53 else 0, status="ok",
              ka_at=now - 3, ka_count=2160, ka_fail=0, keepalive_sec=10, disk_unread=0,
              ds_mode="AUTO", ds_floor=20, ds_duty=40, table_duty=duty)
    dxd = [t for n, dev, t in disks if n in ("disk3", "disk4") or (measured and dev == "sdd")]
    dxt = max([t for t in dxd if t != "*"], default="standby" if dxd else "")
    dxlv = "full" if dxal else ("full" if dxt != "standby" and dxt != "" and dxt >= 55 else "middle" if dxt not in ("standby", "") and dxt >= 48 else "low")
    if dxmod:
        st.update(dx_state="ok", dx_mode="AUTO", dx_cfg_level="low", dx_level=dxlv, dx_code={"full": 0, "middle": 1, "low": 2}[dxlv], dx_want=dxlv,
                  dx_why="alarm" if dxal else ("standby" if dxt == "standby" else "auto"), dx_base="low" if dxal else dxlv, dx_disk=dxt,
                  dx_hot="sdd" if measured else "disk3", dx_unread=0, dx_disks=len(dxd), dx_step=0,
                  dx_input="", dx_alarm1=1 if dxal else 0, dx_alarm2=0, dx_at=now - 20, dx_wrote_at=now - 20 if dxal else now - 200, dx_writes=1 if dxal else 0,
                  dx_write_fail=0, dx_err="", dx_period=60)
    else:
        st.update(dx_state="nomod", dx_mode="AUTO", dx_cfg_level="low", dx_level="", dx_code="", dx_want="", dx_why="nomod", dx_base="", dx_disk=dxt,
                  dx_hot="-", dx_unread=0, dx_disks=len(dxd), dx_step="", dx_input="", dx_alarm1="", dx_alarm2="", dx_at=now - 20, dx_wrote_at=now - 3600,
                  dx_writes=0, dx_write_fail=0, dx_err="", dx_period=60)
    w(f"{run}/state", "".join(f"{k}={v}\n" for k, v in st.items()))
    eff = dict(FAN_MODE=mode, KEEPALIVE_SEC=10, CPU_PERIOD=30, DISK_PERIOD=60, HYST=2, FAN_REASSERT=300, UNREADABLE_FLOOR=70,
               NO_DATA_DUTY=99, TICK=1, BEEP_ON_START="no", AC_RECOVERY="keep", WOL="keep", SHUTDOWN_ACTION="no", MACFIX="yes",
               DEBUG="no", MCU_LOG_SEC=600, PLACEHOLDER_MAC_PREFIX="00:a0:c9:00:00", DS_MODE="AUTO", DS_FLOOR=20, DS_DUTY=40,
               DX_MODE="AUTO", DX_LEVEL="low", DX_PERIOD=60)
    w(f"{run}/effective", "".join(f"{k}={v}\n" for k, v in eff.items()))
    w(f"{run}/mcu", f"since={now - 6*3600}\nlast=33\nlast_at={now - (400 if sc == 'crashed' else 2)}\ncount_4b=86390\ncount_33=86391\n")
    if sc == "stopped": w(f"{run}/stopped", "")
    random.seed(7); lines = []
    for ts in range(now - 6 * 3600, now, 30):
        ph = (ts % 86400) / 86400
        c = 43 + random.randint(0, 3) + (6 if 0.3 < ph < 0.4 else 0)
        row = f"{ts} cpu={c} duty={duty}"
        for n, _, t in disks:
            row += f" {n}={'*' if t == '*' else t - random.randint(0, 3)}"
        if ts >= now - 3 * 3600:   # 2026.10.25f tokens on the newer half; the older half keeps the 25b-e format
            row += f" @mode={mode} @why={'disk' if duty > 20 else 'both'} @want={duty}" + "".join(f" core:coretemp_temp{i}={t - random.randint(0, 2)}" for i, (_, t) in enumerate(cpu, 2))
        lines.append(row)
    if sc == "graphs": lines = graphs_history()
    w(f"{run}/history", "\n".join(lines) + "\n")
    if sc == "graphs":   # the sweep of graphs_history() as the daemon's sweep.csv + sweep.state (done)
        ids = ["ZDH0AAA1", "ZDH0AAA2", "WD-WCC7K0AAA4", "Z1D0AAA5"]
        hdr = "epoch,step,duty,cpu_max," + ",".join(f"core:coretemp_temp{i}" for i in range(2, 6)) + ",disk:ST4000VN008-2DR166_ZDH0AAA1,disk:ST4000VN008-2DR166_ZDH0AAA2,disk:WDC_WD40EFRX-68N32N0_WD-WCC7K0AAA4,disk:ST1000DM003-1CH162_Z1D0AAA5,mcu_33,mcu_4b,mcu_other"
        rows = [hdr]; sw0 = now - 5 * 3600
        for l in lines:
            m = dict(t.split("=", 1) for t in l.split()[1:])
            if "@sweep" not in m: continue
            ts = int(l.split()[0]); cores = [m[f"core:coretemp_temp{i}"] for i in range(2, 6)]
            rows.append(f"{ts},{m['@sweep']},{m['duty']},{m['cpu']}," + ",".join(cores) + f",{m['parity']},{m['disk1']},{m['disk3']},{m['disk4']},125,125,0")
        w(f"{run}/sweep.csv", "\n".join(rows) + "\n")
        last = int(rows[-1].split(",")[0])
        w(f"{run}/sweep.state", f"state=done\nplan=20 15 12 10\nsteps=4\nstep=4\nduty=10\nstarted={sw0}\nupdated={last}\nstep_started={last - 1170}\nhold=1200\nmin_hold=600\nmax_hold=1800\nsettled=yes\nsamples={len(rows) - 1}\ntemps=cpu=44\nreason=plan complete\ncsv=/var/run/synology-hardware/sweep.csv\nsummary=/var/run/synology-hardware/sweep-summary.txt\nresult_csv=\nresult_txt=\n")
if dxmod or sc == "stopped":   # the synology-dx module (0.4.0): unit values as measured (phase 2a/2b), master host18
    u = dict(master_host="host18", unique="0x15" if unknown else "0x13", model="DX517" if unknown else "DX1215", fan_level=dxlv if dxmod else "low", fan_code={"full": 0, "middle": 1, "low": 2}[dxlv] if dxmod else 2,
             fan_fail=1 if dxal else 0, fan1_fail=1 if dxal else 0, fan2_fail=0, power_on=1, psu1=0, psu2=0, switch_mode=1, last_error="", fan_stop_test_s=0)
    for k, v in u.items(): w(f"sys/kernel/synology_dx/unit/{k}", f"{v}\n")
    w("sys/kernel/synology_dx/unit/summary", "".join(f"{k} {v if v != '' else '-'}\n" for k, v in u.items()))
    for k, v in (("version", "0.4.0"), ("srcversion", "A1B2C3D4E5F6A7B8C9D0E1F"), ("parameters/refresh_s", "30"), ("parameters/hosts", "18,19,20,21")):
        w(f"sys/module/synology_dx/{k}", v + "\n")
    w("sys/class/hwmon/hwmon2/name", "synology_dx\n"); w("sys/class/hwmon/hwmon2/fan1_alarm", f"{u['fan1_fail']}\n"); w("sys/class/hwmon/hwmon2/pwm1", "64\n")
    w("var/log/plugins/synology-expansion.plg", '<!ENTITY version "2026.10.25b">\n'); w("run/synology-expansion/status", "2026-10-25 17:30:00 loaded (A1B2C3D4E5F6A7B8C9D0E1F)\n")
    if running: w(f"{run}/dx", f"at={now - 20}\n" + "".join(f"{k}={v}\n" for k, v in u.items()))
elif sc == "nodx":
    w("var/log/plugins/synology-dx.plg", '<!ENTITY version "2026.10.25a">\n')
    w("run/synology-dx/status", "2026-10-25 17:30:00 no synology_dx build for kernel 6.18.99-Unraid in /usr/local/emhttp/plugins/synology-dx/kmod\n")
w(f"{run}/macs", "".join(f"mac {i} 00:11:32:aa:bb:0{i+1}\n" for i in range(4)) + "sn 0000ABC000000\n")
if sc != "notty":
    pass
# net: eth0..3 on 0000:00:14.0..3, bonded into bond0, bridged into br0; real (fake) MACs applied by udev
for i in range(4):
    n = f"sys/class/net/eth{i}"; pci = f"0000:00:14.{i}"
    os.makedirs(os.path.join(d, f"sys/devices/pci/{pci}"), exist_ok=True)
    w(f"{n}/address", f"00:11:32:aa:bb:0{i+1}\n"); w(f"{n}/addr_assign_type", "3\n"); w(f"{n}/operstate", "up\n" if i < 2 else "down\n")
    w(f"{n}/speed", "1000\n" if i < 2 else "-1\n")   # 2026.10.25j: measured eth0 1000, ports without link -1
    os.symlink(f"../../../devices/pci/{pci}", os.path.join(d, n, "device"))
    os.symlink("../bond0", os.path.join(d, n, "master"))
w("sys/class/net/bond0/address", "00:11:32:aa:bb:01\n"); os.symlink("../br0", os.path.join(d, "sys/class/net/bond0/master"))
w("sys/class/net/br0/address", "00:11:32:aa:bb:01\n")
w("proc/net/bonding/bond0", "".join(f"Slave Interface: eth{i}\nMII Status: up\nPermanent HW addr: 00:a0:c9:00:00:0{i}\n\n" for i in range(4)))
rule = "".join(f'ACTION=="add", SUBSYSTEM=="net", KERNELS=="0000:00:14.{i}", x\nACTION=="add", y\n' for i in range(4))
w("boot/config/udev/70-synology-hardware-mac.rules", rule); w("etc/udev/rules.d/70-synology-hardware-mac.rules", rule)
w("boot/config/plugins/synology-hardware/synology-hardware.cron", "*/5 * * * * /etc/rc.d/rc.synology-hardware watchdog >/dev/null 2>&1\n")
w("etc/cron.d/root", "# Generated\n*/5 * * * * /etc/rc.d/rc.synology-hardware watchdog >/dev/null 2>&1\n")
w("var/log/plugins/synology-hardware.plg", '<!ENTITY version "2026.10.25b">\n')
# ---- Hardware section (2026.10.25g): measured DS2415+ values (experiments/2026-10-25-hw-inventory), fake serials ----
HWFX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "hw-2026-10-25")
PCIS = [  # addr, id, class, driver, cur speed, cur width, max speed, max width, aer   (lspci-link.txt, misc3.txt)
    ("00:00.0", "8086:1f0c", "0x060000", "", None, None, None, None, False), ("00:01.0", "8086:1f10", "0x060400", "pcieport", "5.0", 2, "5.0", 4, True),
    ("00:02.0", "8086:1f11", "0x060400", "pcieport", "5.0", 1, "5.0", 4, True), ("00:03.0", "8086:1f12", "0x060400", "pcieport", "5.0", 1, "5.0", 4, True),
    ("00:04.0", "8086:1f13", "0x060400", "pcieport", "5.0", 2, "5.0", 4, True), ("00:0e.0", "8086:1f14", "0x060000", "", None, None, None, None, False),
    ("00:0f.0", "8086:1f16", "0x080600", "", None, None, None, None, True), ("00:13.0", "8086:1f15", "0x088000", "ismt_smbus", None, None, None, None, True),
    ("00:14.0", "8086:1f41", "0x020000", "igb", None, None, None, None, True), ("00:14.1", "8086:1f41", "0x020000", "igb", None, None, None, None, True),
    ("00:14.2", "8086:1f41", "0x020000", "igb", None, None, None, None, True), ("00:14.3", "8086:1f41", "0x020000", "igb", None, None, None, None, True),
    ("00:16.0", "8086:1f2c", "0x0c0320", "ehci-pci", None, None, None, None, False), ("00:17.0", "8086:1f22", "0x010601", "ahci", None, None, None, None, False),
    ("00:18.0", "8086:1f32", "0x010601", "ahci", None, None, None, None, False), ("00:1f.0", "8086:1f38", "0x060100", "lpc_ich", None, None, None, None, False),
    ("00:1f.3", "8086:1f3c", "0x0c0500", "i801_smbus", None, None, None, None, False), ("01:00.0", "10b5:8605", "0x060400", "pcieport", "5.0", 2, "5.0", 2, True),
    ("02:02.0", "10b5:8605", "0x060400", "pcieport", "5.0", 1, "5.0", 1, True), ("02:03.0", "10b5:8605", "0x060400", "pcieport", "5.0", 1, "5.0", 1, True),
    ("03:00.0", "1b4b:9215", "0x010601", "ahci", "5.0", 1, "5.0", 1, True), ("04:00.0", "1b6f:7052", "0x0c0330", "xhci_hcd", "5.0", 1, "5.0", 1, False),
    ("05:00.0", "1b4b:9215", "0x010601", "ahci", "5.0", 1, "5.0", 1, True), ("06:00.0", "1b4b:9215", "0x010601", "ahci", "5.0", 1, "5.0", 1, True),
    ("07:00.0", "1b4b:9235", "0x010601", "ahci", "5.0", 2, "5.0", 2, True)]
PARENT = {"01:00.0": "00:01.0", "02:02.0": "01:00.0", "02:03.0": "01:00.0", "03:00.0": "02:02.0", "04:00.0": "02:03.0", "05:00.0": "00:02.0", "06:00.0": "00:03.0", "07:00.0": "00:04.0"}
def pcipath(a):
    chain = [a]
    while chain[-1] in PARENT: chain.append(PARENT[chain[-1]])
    return "devices/pci0000:00/" + "/".join("0000:" + x for x in reversed(chain))
if sc in ("normal", "graphs", "measured", "hot", "nodx", "dxalarm", "stopped"):   # stopped: 25j (the drawn main unit with idle fans)
    for a, pid, cls, drv, cs, cw, ms, mw, aer in PCIS:
        if unknown and pid == "1b4b:9215": pid = "1b4b:9230"   # not the DS2415+ topology
        pp = pcipath(a); mkpci(pp, *pid.split(":")); w(f"sys/{pp}/class", cls + "\n")
        if cs: w(f"sys/{pp}/current_link_speed", f"{cs} GT/s PCIe\n"); w(f"sys/{pp}/current_link_width", f"{cw}\n"); w(f"sys/{pp}/max_link_speed", f"{ms} GT/s PCIe\n"); w(f"sys/{pp}/max_link_width", f"{mw}\n")
        elif pid == "8086:1f41":   # on-chip I354: the files exist, measured "Unknown" x0 now / "Unknown" x255 max (webgui-2026-10-25g-hardware.png)
            w(f"sys/{pp}/current_link_speed", "Unknown\n"); w(f"sys/{pp}/current_link_width", "0\n"); w(f"sys/{pp}/max_link_speed", "Unknown\n"); w(f"sys/{pp}/max_link_width", "255\n")
        if aer:
            w(f"sys/{pp}/aer_dev_correctable", "RxErr 0\nBadTLP 0\nBadDLLP 0\nRollover 0\nTimeout 0\nTOTAL_ERR_COR 0\n")
            w(f"sys/{pp}/aer_dev_nonfatal", "DLP 0\nSDES 0\nTOTAL_ERR_NONFATAL 0\n"); w(f"sys/{pp}/aer_dev_fatal", "DLP 0\nTOTAL_ERR_FATAL 0\n")
        if drv:
            os.makedirs(os.path.join(d, f"sys/bus/pci/drivers/{drv}"), exist_ok=True)
            os.symlink(os.path.relpath(os.path.join(d, f"sys/bus/pci/drivers/{drv}"), os.path.join(d, "sys", pp)), os.path.join(d, "sys", pp, "driver"))
        os.makedirs(os.path.join(d, "sys/bus/pci/devices"), exist_ok=True)
        os.symlink(f"../../../{pp}", os.path.join(d, "sys/bus/pci/devices", "0000:" + a))
    # CPU (cpu.txt, extra2.txt): 4 cores, acpi-cpufreq ondemand, POLL/C1/C6, cpu0 time_in_state
    for i in range(4):
        c = f"sys/devices/system/cpu/cpu{i}"
        for k, v in (("scaling_cur_freq", [1795501, 1900000, 1200000, 2400000][i]), ("cpuinfo_min_freq", 1200000), ("cpuinfo_max_freq", 2400000), ("scaling_governor", "ondemand"), ("scaling_driver", "acpi-cpufreq")):
            w(f"{c}/cpufreq/{k}", f"{v}\n")
        for j, (nm, lat, use, tm) in enumerate((("POLL", 0, 5326, 211459), ("C1", 2, 204513, 51162002), ("C6", 15, 10182525, 55355763167))):
            w(f"{c}/cpuidle/state{j}/name", nm + "\n"); w(f"{c}/cpuidle/state{j}/latency", f"{lat}\n"); w(f"{c}/cpuidle/state{j}/usage", f"{use}\n"); w(f"{c}/cpuidle/state{j}/time", f"{tm}\n")
    w("sys/devices/system/cpu/cpu0/cpufreq/stats/time_in_state", "2400000 177284\n2300000 14213\n2200000 18104\n2100000 24394\n2000000 17489\n1900000 15079\n1800000 17216\n1700000 17182\n1600000 20744\n1500000 41572\n1400000 95760\n1300000 296078\n1200000 5017908\n")
    w("sys/devices/system/cpu/cpu0/cpufreq/stats/total_trans", "1179526\n"); w("sys/devices/system/cpu/cpuidle/current_driver", "intel_idle\n")
    w("proc/cpuinfo", "".join(f"processor\t: {i}\nmodel name\t: Intel(R) Atom(TM) CPU  C2538  @ 2.40GHz\nmicrocode\t: 0x12d\n\n" for i in range(4)))
    z = "sys/class/thermal/thermal_zone0"   # misc2.txt: acpitz, step_wise, trips critical 100 / passive 80 / active 70
    for k, v in (("type", "acpitz"), ("temp", "35000"), ("policy", "step_wise"), ("mode", "enabled"), ("trip_point_0_type", "critical"), ("trip_point_0_temp", "100000"),
                 ("trip_point_1_type", "passive"), ("trip_point_1_temp", "80000"), ("trip_point_2_type", "active"), ("trip_point_2_temp", "70000")): w(f"{z}/{k}", v + "\n")
    w("proc/meminfo", "MemTotal:       16754840 kB\nMemFree:        14000000 kB\nMemAvailable:   15512340 kB\nSwapTotal:             0 kB\n")
    w("proc/uptime", "57906.12 220000.00\n"); w("proc/loadavg", "0.33 0.30 0.28 1/312 4321\n"); w("proc/sys/kernel/osrelease", "6.18.58-Unraid\n")
    w("proc/version", "Linux version 6.18.58-Unraid (root@build) (gcc 14.2.0) #2 SMP PREEMPT_DYNAMIC Tue Oct 21 2026\n")
    w("proc/cmdline", "initrd=/bzroot console=tty0 console=ttyS0,115200n8 panic=10\n")
    # USB (pci-usb.txt), fake serials
    for dv, kv in (("usb1", dict(busnum=1, idVendor="1d6b", idProduct="0002", manufacturer="Linux 6.18.58-Unraid ehci_hcd", product="EHCI Host Controller", serial="0000:00:16.0", speed=480, version=" 2.00", bMaxPower="0mA", bDeviceClass="09")),
                   ("1-1", dict(busnum=1, idVendor="8087", idProduct="07db", manufacturer="", product="", serial="", speed=480, version=" 2.00", bMaxPower="0mA", bDeviceClass="09")),
                   ("1-1.1", dict(busnum=1, idVendor="f400", idProduct="f400", manufacturer="Synology", product="DiskStation", serial="0000FAKEDOM0001", speed=480, version=" 2.00", bMaxPower="200mA", bDeviceClass="00")),
                   ("usb2", dict(busnum=2, idVendor="1d6b", idProduct="0002", manufacturer="Linux 6.18.58-Unraid xhci-hcd", product="xHCI Host Controller", serial="0000:04:00.0", speed=480, version=" 2.00", bMaxPower="0mA", bDeviceClass="09")),
                   ("2-1", dict(busnum=2, idVendor="0951", idProduct="1697", manufacturer="Kingston", product="DT Ultimate G3", serial="0000FAKEFLASH001", speed=480, version=" 2.10", bMaxPower="300mA", bDeviceClass="00")),
                   ("usb3", dict(busnum=3, idVendor="1d6b", idProduct="0003", manufacturer="Linux 6.18.58-Unraid xhci-hcd", product="xHCI Host Controller", serial="0000:04:00.0", speed=5000, version=" 3.00", bMaxPower="0mA", bDeviceClass="09"))):
        for k, v in kv.items(): w(f"sys/bus/usb/devices/{dv}/{k}", f"{v}\n")
    for dv in ("1-1:1.0", "1-1.1:1.0"): os.makedirs(os.path.join(d, "sys/bus/usb/devices", dv), exist_ok=True)   # interfaces: not listed
    os.makedirs(os.path.join(d, "sys/firmware/acpi"), exist_ok=True); os.makedirs(os.path.join(d, "sys/class/powercap"), exist_ok=True)
    # NIC link state + counters (nic.txt): eth0 1000 full up, eth1-3 no link; and the inventory cache
    for i in range(4):
        n = f"sys/class/net/eth{i}"
        w(f"{n}/carrier", "1\n" if i == 0 else "0\n"); w(f"{n}/speed", "1000\n" if i == 0 else "-1\n"); w(f"{n}/duplex", "full\n" if i == 0 else "unknown\n"); w(f"{n}/carrier_changes", "2\n" if i == 0 else "1\n")
        for k in ("rx_errors", "tx_errors", "rx_crc_errors", "rx_missed_errors", "rx_fifo_errors", "rx_length_errors", "collisions", "rx_dropped", "tx_dropped"): w(f"{n}/statistics/{k}", "0\n")
        os.makedirs(os.path.join(d, "sys/bus/pci/drivers/igb"), exist_ok=True)
        os.symlink(os.path.relpath(os.path.join(d, "sys/bus/pci/drivers/igb"), os.path.join(d, f"sys/devices/pci/0000:00:14.{i}")), os.path.join(d, f"sys/devices/pci/0000:00:14.{i}", "driver"))
    inv = f"{run}/inventory"
    for (n, dev, t) in disks:
        if t == "*": continue
        txt = open(os.path.join(HWFX, "smart-wd.txt" if dev in ("sdb", "sdd", "sdf") else "smart-seagate.txt")).read()
        w(f"{inv}/smart-{dev}.txt", f"# synohw dev={dev} key={n} id=- at={now - 240} rc=0\n" + txt)
    for i in range(4):
        w(f"{inv}/ethtool-eth{i}.txt", f"# synohw nic=eth{i} at={now - 240} rc=0\n" + open(os.path.join(HWFX, "ethtool-up.txt" if i == 0 else "ethtool-nolink.txt")).read())
    w(f"{inv}/at", f"at={now - 240}\nduration=2\nsmart={sum(1 for x in disks if x[2] != '*')}\nskipped_standby={sum(1 for x in disks if x[2] == '*')}\nnics=4\n")
tty = os.path.join(d, "dev/ttyS1")
os.makedirs(os.path.dirname(tty), exist_ok=True)
ov = dict(run=f"{d}/{run}", cfg=f"{d}/boot/config/plugins/synology-hardware/synology-hardware.cfg",
          tty="/dev/null" if sc != "notty" else f"{d}/dev/ttyS1", disks_ini=f"{d}/var/local/emhttp/disks.ini",
          devs_ini=f"{d}/var/local/emhttp/devs.ini", sysblock=f"{d}/sys/block",
          dynamix_cfg=f"{d}/boot/config/plugins/dynamix/dynamix.cfg", hwmon=f"{d}/sys/class/hwmon", thermal=f"{d}/sys/class/thermal",
          sysnet=f"{d}/sys/class/net", bonding=f"{d}/proc/net/bonding", proc=f"{d}/proc",
          udev_rom=f"{d}/boot/config/udev/70-synology-hardware-mac.rules", udev_ram=f"{d}/etc/udev/rules.d/70-synology-hardware-mac.rules",
          cron_file=f"{d}/boot/config/plugins/synology-hardware/synology-hardware.cron", crontab=f"{d}/etc/cron.d/root",
          plg_link=f"{d}/var/log/plugins/synology-hardware.plg", plg_flash=f"{d}/none.plg", now=now,
          pcidev=f"{d}/sys/bus/pci/devices", atalink=f"{d}/sys/class/ata_link", cpusys=f"{d}/sys/devices/system/cpu", cpuinfo=f"{d}/proc/cpuinfo",
          meminfo=f"{d}/proc/meminfo", uptime=f"{d}/proc/uptime", loadavg=f"{d}/proc/loadavg", osrelease=f"{d}/proc/sys/kernel/osrelease",
          kversion=f"{d}/proc/version", cmdline=f"{d}/proc/cmdline", usbdev=f"{d}/sys/bus/usb/devices", sysfw=f"{d}/sys/firmware",
          dxsys=f"{d}/sys/kernel/synology_dx", sysmodule=f"{d}/sys/module", dx_plg_link=f"{d}/var/log/plugins/synology-expansion.plg", dx_plg_flash=f"{d}/none-dx.plg",
          dx_run=f"{d}/run/synology-expansion", dx_plg_link_old=f"{d}/var/log/plugins/synology-dx.plg", dx_plg_flash_old=f"{d}/none-dx-old.plg",
          dx_run_old=f"{d}/run/synology-dx", ataport=f"{d}/sys/class/ata_port",
          edac=f"{d}/sys/devices/system/edac", powercap=f"{d}/sys/class/powercap", watchdog=f"{d}/sys/class/watchdog", leds=f"{d}/sys/class/leds", gpio=f"{d}/sys/class/gpio")
print(json.dumps(ov))
