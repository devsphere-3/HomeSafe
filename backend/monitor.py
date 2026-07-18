#!/usr/bin/env python3
"""
monitor.py — HomeSafe Live Monitor
═══════════════════════════════════════════════════════════════════
Pantau dan kontrol semua endpoint legacy server HomeSafe dari terminal.
Jalankan saat server sudah running di Raspberry Pi.

Cara pakai:
    python3 monitor.py
    python3 monitor.py --server http://172.20.10.3:5001
    python3 monitor.py --server http://localhost:5001 --interval 3

Fitur:
  • Dashboard status server, kamera, servo, pengguna, history
  • Kontrol servo: open, lock, move, cycle, buzzer, LED
  • Update servo config live
  • Pantau riwayat akses terbaru
  • Auto-refresh dashboard setiap N detik
═══════════════════════════════════════════════════════════════════
"""

import sys
import time
import json
import argparse
import os
import threading

try:
    import urllib.request
    import urllib.error
    import urllib.parse
except ImportError:
    print("ERROR: urllib tidak tersedia.")
    sys.exit(1)

# ── Warna terminal ─────────────────────────────────────────────────────────────
CYAN    = "\033[96m"
GREEN   = "\033[92m"
YELLOW  = "\033[93m"
RED     = "\033[91m"
MAGENTA = "\033[95m"
BLUE    = "\033[94m"
BOLD    = "\033[1m"
DIM     = "\033[2m"
RESET   = "\033[0m"

def c(color, text): return f"{color}{text}{RESET}"
def clr():          os.system("clear" if os.name != "nt" else "cls")


# ─────────────────────────────────────────────────────────────────────────────
# HTTP helpers
# ─────────────────────────────────────────────────────────────────────────────

def http_get(url: str, timeout: float = 5.0) -> tuple[dict | None, str]:
    """GET request. Return (data, error_msg)."""
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            return json.loads(r.read().decode()), ""
    except urllib.error.URLError as e:
        return None, str(e.reason)
    except Exception as e:
        return None, str(e)


def http_post(url: str, body: dict | None = None, timeout: float = 8.0) -> tuple[dict | None, str]:
    """POST request with optional JSON body. Return (data, error_msg)."""
    payload = json.dumps(body).encode() if body else b""
    headers = {"Content-Type": "application/json"} if body else {}
    req     = urllib.request.Request(url, data=payload, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode()), ""
    except urllib.error.URLError as e:
        return None, str(e.reason)
    except Exception as e:
        return None, str(e)


def http_delete(url: str, timeout: float = 5.0) -> tuple[dict | None, str]:
    """DELETE request. Return (data, error_msg)."""
    req = urllib.request.Request(url, method="DELETE")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode()), ""
    except urllib.error.URLError as e:
        return None, str(e.reason)
    except Exception as e:
        return None, str(e)


# ─────────────────────────────────────────────────────────────────────────────
# Dashboard sections
# ─────────────────────────────────────────────────────────────────────────────

def fmt_ok(v):   return c(GREEN,  f"✅ {v}")
def fmt_err(v):  return c(RED,    f"❌ {v}")
def fmt_warn(v): return c(YELLOW, f"⚠️  {v}")
def fmt_val(v):  return c(CYAN,   str(v))


def section(title: str, width: int = 60) -> None:
    bar = "─" * width
    print(f"\n{BOLD}{CYAN}{bar}{RESET}")
    print(f"{BOLD}  {title}{RESET}")
    print(f"{CYAN}{bar}{RESET}")


def print_dashboard(server: str, interval: int) -> None:
    """Tampilkan dashboard lengkap — satu snapshot."""
    now = time.strftime("%Y-%m-%d %H:%M:%S")

    clr()
    print(f"{BOLD}{MAGENTA}{'═'*60}{RESET}")
    print(f"{BOLD}  🏠 HomeSafe — Live Monitor{RESET}  {DIM}{now}{RESET}")
    print(f"  Server : {c(CYAN, server)}")
    print(f"  Refresh: setiap {interval}s  (Ctrl+C untuk menu){RESET}")
    print(f"{BOLD}{MAGENTA}{'═'*60}{RESET}")

    # ── 1. Server ping ──────────────────────────────────────────────────────
    section("1. Server & Kamera")
    cam_data, err = http_get(f"{server}/api/cameras")
    if err:
        print(f"  {fmt_err('Server tidak terjangkau — ' + err)}")
        print(f"\n  {c(YELLOW, 'Pastikan server sudah running:')}")
        print(f"    bash /home/pi/HomeSafe/backend/start.sh")
        return

    cams     = cam_data.get("cameras", [])
    door_id  = cam_data.get("door_cam_id", "?")
    yard_id  = cam_data.get("yard_cam_id", "?")
    avail    = [c_ for c_ in cams if c_.get("available")]
    unavail  = [c_ for c_ in cams if not c_.get("available")]

    print(f"  Status server : {fmt_ok('Online')}")
    print(f"  Kamera pintu  : {fmt_val('/dev/video' + str(door_id))}")
    print(f"  Kamera CCTV   : {fmt_val('/dev/video' + str(yard_id))}")
    print(f"  Node tersedia : {c(GREEN, str(len(avail)))} dari {len(cams)} total")
    for cam in cams:
        mark = fmt_ok(cam["node"]) if cam.get("available") else fmt_warn(cam["node"] + " [metadata]")
        res  = cam.get("resolution", "—")
        door = c(BLUE, " ← pintu") if cam["id"] == door_id else ""
        yard = c(GREEN, " ← CCTV")  if cam["id"] == yard_id else ""
        print(f"    {mark}  {DIM}{res}{RESET}{door}{yard}")

    # ── 2. Servo ────────────────────────────────────────────────────────────
    section("2. Servo & GPIO")
    sv, err = http_get(f"{server}/api/servo/status")
    if err:
        print(f"  {fmt_err('Gagal baca servo: ' + err)}")
    else:
        gpio_ok = sv.get("gpio_available", False)
        pwm_ok  = sv.get("pwm_active", False)
        print(f"  GPIO hardware : {fmt_ok('Aktif (RPi)') if gpio_ok else fmt_warn('Simulasi (bukan RPi)')}")
        print(f"  PWM aktif     : {fmt_ok('Ya') if pwm_ok else fmt_err('Tidak')}")
        print(f"  Pin servo     : GPIO {fmt_val(sv.get('pin', '?'))}")
        print(f"  Frekuensi     : {fmt_val(str(sv.get('freq_hz','?')) + ' Hz')}")
        print(f"  Posisi LOCK   : {fmt_val(str(sv.get('lock_angle','?')) + '°')}  "
              f"(duty={sv.get('duty_lock','?')}%)")
        print(f"  Posisi OPEN   : {fmt_val(str(sv.get('open_angle','?')) + '°')}  "
              f"(duty={sv.get('duty_open','?')}%)")
        print(f"  Waktu buka    : {fmt_val(str(sv.get('open_time_s','?')) + 's')}")
        print(f"  DC range      : {fmt_val(str(sv.get('min_dc','?')) + '%')} – "
              f"{fmt_val(str(sv.get('max_dc','?')) + '%')}")
        cfg_file = sv.get("config_file", "servo_config.json")
        print(f"  Config file   : {DIM}{cfg_file}{RESET}")

    # ── 3. Pengguna terdaftar ────────────────────────────────────────────────
    section("3. Pengguna Terdaftar (Face Profiles)")
    users_data, err = http_get(f"{server}/api/users")
    if err:
        print(f"  {fmt_err('Gagal baca users: ' + err)}")
    else:
        users = users_data.get("users", [])
        if not users:
            print(f"  {c(YELLOW, 'Belum ada pengguna terdaftar.')}")
        else:
            print(f"  Total : {c(GREEN, str(len(users)))} pengguna")
            for i, name in enumerate(users, 1):
                print(f"    {DIM}{i:2d}.{RESET}  {c(CYAN, name)}")

    # ── 4. Riwayat akses terbaru ─────────────────────────────────────────────
    section("4. Riwayat Akses (10 terakhir)")
    hist_data, err = http_get(f"{server}/api/history?limit=10")
    if err:
        print(f"  {fmt_err('Gagal baca history: ' + err)}")
    else:
        entries = hist_data.get("history", [])
        total   = hist_data.get("total", 0)
        if not entries:
            print(f"  {c(YELLOW, 'Belum ada riwayat akses.')}")
        else:
            print(f"  Total tersimpan: {fmt_val(total)}")
            print(f"  {'Waktu':<22}  {'Nama':<18}  {'Cocok':>6}  {'File'}")
            print(f"  {'─'*22}  {'─'*18}  {'─'*6}  {'─'*20}")
            for e in entries:
                ts   = e.get("timestamp", "")[:19].replace("T", " ")
                name = e.get("name", "Unknown")[:18]
                pct  = f"{e.get('percentage', 0):.1f}%"
                img  = e.get("image", "—")[:20]
                col  = GREEN if name != "Unknown" else YELLOW
                print(f"  {DIM}{ts}{RESET}  {c(col, f'{name:<18}')}"
                      f"  {c(CYAN, f'{pct:>6}')}  {DIM}{img}{RESET}")

    print(f"\n{DIM}{'─'*60}")
    print(f"  Tekan Ctrl+C untuk masuk ke menu kontrol{RESET}")


# ─────────────────────────────────────────────────────────────────────────────
# Menu kontrol
# ─────────────────────────────────────────────────────────────────────────────

def print_control_menu() -> None:
    print(f"""
{BOLD}{CYAN}{'═'*60}{RESET}
{BOLD}  🎛️  HomeSafe — Menu Kontrol{RESET}
{CYAN}{'─'*60}{RESET}

  {BOLD}Servo:{RESET}
  {c(CYAN,'[1]')}  Buka pintu (OPEN → auto-kunci setelah open_time)
  {c(CYAN,'[2]')}  Paksa kunci sekarang
  {c(CYAN,'[3]')}  Gerak ke sudut bebas
  {c(CYAN,'[4]')}  Test siklus LOCK → OPEN → LOCK
  {c(CYAN,'[5]')}  Update config servo live (lock/open angle, DC, waktu)

  {BOLD}Hardware:{RESET}
  {c(CYAN,'[6]')}  Buzzer ON/OFF
  {c(CYAN,'[7]')}  LED hijau ON/OFF
  {c(CYAN,'[8]')}  LED merah ON/OFF

  {BOLD}Kamera:{RESET}
  {c(CYAN,'[9]')}  Set kamera pintu (door)
  {c(CYAN,'[10]')} Set kamera CCTV (yard)
  {c(CYAN,'[11]')} Probe ulang semua kamera

  {BOLD}Data:{RESET}
  {c(CYAN,'[12]')} Lihat semua pengguna terdaftar
  {c(CYAN,'[13]')} Hapus pengguna
  {c(CYAN,'[14]')} Hapus seluruh riwayat akses
  {c(CYAN,'[15]')} Lihat status servo lengkap

  {BOLD}Monitor:{RESET}
  {c(CYAN,'[r]')}  Kembali ke dashboard (resume auto-refresh)
  {c(CYAN,'[q]')}  Keluar

{CYAN}{'─'*60}{RESET}""")


# ─────────────────────────────────────────────────────────────────────────────
# Aksi kontrol
# ─────────────────────────────────────────────────────────────────────────────

def ctrl_servo_open(server: str) -> None:
    print(c(CYAN, "\n  🔓 Membuka pintu..."))
    data, err = http_post(f"{server}/api/servo/open")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        print(fmt_ok(data.get("message", "Triggered")))


def ctrl_servo_lock(server: str) -> None:
    print(c(CYAN, "\n  🔒 Mengunci pintu..."))
    data, err = http_post(f"{server}/api/servo/lock")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        print(fmt_ok(data.get("message", "Locked")))


def ctrl_servo_move(server: str) -> None:
    try:
        angle = float(input("  Sudut (0–180): ").strip())
    except ValueError:
        print(fmt_warn("Input tidak valid.")); return
    print(c(CYAN, f"\n  ↗️  Menggerakkan servo ke {angle}°..."))
    data, err = http_post(f"{server}/api/servo/move/{angle}")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        print(fmt_ok(f"{data.get('message','OK')}  (duty={data.get('duty_cycle','?')}%)"))


def ctrl_servo_cycle(server: str) -> None:
    print(c(CYAN, "\n  🔄 Menjalankan siklus test..."))
    data, err = http_post(f"{server}/api/servo/cycle")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        print(fmt_ok(data.get("message", "Selesai")))


def ctrl_servo_config(server: str) -> None:
    """Update satu atau lebih field config servo secara live."""
    print(f"\n  {BOLD}Update config servo (Enter = skip){RESET}")
    # Ambil nilai saat ini
    sv, err = http_get(f"{server}/api/servo/status")
    if not err and sv:
        print(f"  Saat ini: lock={sv.get('lock_angle')}°  "
              f"open={sv.get('open_angle')}°  "
              f"time={sv.get('open_time_s')}s  "
              f"dc={sv.get('min_dc')}–{sv.get('max_dc')}%")

    body = {}
    fields = [
        ("lock_angle", "Posisi LOCK (0–180°)"),
        ("open_angle", "Posisi OPEN (0–180°)"),
        ("open_time",  "Waktu buka (1–60 detik)"),
        ("min_dc",     "MIN duty cycle (0–5%)"),
        ("max_dc",     "MAX duty cycle (5–20%)"),
    ]
    for key, label in fields:
        val = input(f"  {label}: ").strip()
        if val:
            try:
                body[key] = float(val)
            except ValueError:
                print(fmt_warn(f"  '{val}' bukan angka — skip"))

    if not body:
        print(fmt_warn("  Tidak ada perubahan.")); return

    data, err = http_post(f"{server}/api/servo/config", body)
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        cur = data.get("current", {})
        print(fmt_ok(
            f"Config diupdate!\n"
            f"    lock={cur.get('lock_angle')}°  "
            f"open={cur.get('open_angle')}°  "
            f"time={cur.get('open_time')}s\n"
            f"    dc={cur.get('min_dc')}–{cur.get('max_dc')}%  "
            f"duty_lock={cur.get('duty_lock')}%  "
            f"duty_open={cur.get('duty_open')}%"
        ))


def ctrl_buzzer(server: str) -> None:
    state = input("  Buzzer [on/off]: ").strip().lower()
    if state not in ("on", "off"):
        print(fmt_warn("Harus 'on' atau 'off'.")); return
    data, err = http_post(f"{server}/api/servo/buzzer/{state}")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        icon = "🔔" if state == "on" else "🔕"
        print(fmt_ok(f"{icon} Buzzer → {state}"))


def ctrl_led(server: str, color: str) -> None:
    label = "Hijau" if color == "green" else "Merah"
    state = input(f"  LED {label} [on/off]: ").strip().lower()
    if state not in ("on", "off"):
        print(fmt_warn("Harus 'on' atau 'off'.")); return
    data, err = http_post(f"{server}/api/servo/led/{color}/{state}")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        icon = "💚" if color == "green" else "🔴"
        print(fmt_ok(f"{icon} LED {label} → {state}"))


def ctrl_set_camera(server: str, role: str) -> None:
    label = "pintu" if role == "door" else "CCTV"
    cam_data, err = http_get(f"{server}/api/cameras")
    if err:
        print(fmt_err(f"Gagal baca kamera: {err}")); return

    cams = cam_data.get("cameras", [])
    print(f"\n  Kamera tersedia:")
    for cam in cams:
        avail = "✅" if cam.get("available") else "⛔"
        print(f"    [{cam['id']}] {avail} {cam['node']}  {cam.get('resolution','—')}")

    try:
        cam_id = int(input(f"  Pilih ID kamera untuk {label}: ").strip())
    except ValueError:
        print(fmt_warn("Input tidak valid.")); return

    data, err = http_post(f"{server}/api/cameras/{role}/{cam_id}")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    elif data.get("alive"):
        print(fmt_ok(data.get("message", "OK")))
    else:
        print(fmt_warn(data.get("message", "Kamera tidak merespons")))


def ctrl_probe_cameras(server: str) -> None:
    print(c(CYAN, "\n  🔍 Probing semua kamera..."))
    data, err = http_post(f"{server}/api/cameras/probe")
    if err:
        print(fmt_err(f"Gagal: {err}")); return
    cams = data.get("cameras", [])
    avail = [x for x in cams if x.get("available")]
    print(fmt_ok(f"Ditemukan {len(cams)} node, {len(avail)} tersedia"))
    for cam in cams:
        mark = "✅" if cam.get("available") else "⛔"
        print(f"    {mark} [{cam['id']}] {cam['node']}  {cam.get('resolution','—')}")


def ctrl_list_users(server: str) -> None:
    data, err = http_get(f"{server}/api/users")
    if err:
        print(fmt_err(f"Gagal: {err}")); return
    users = data.get("users", [])
    if not users:
        print(fmt_warn("Belum ada pengguna terdaftar.")); return
    print(f"\n  {c(BOLD, f'{len(users)} pengguna terdaftar:')}")
    for i, name in enumerate(users, 1):
        print(f"    {DIM}{i:2d}.{RESET}  {c(CYAN, name)}")


def ctrl_delete_user(server: str) -> None:
    ctrl_list_users(server)
    name = input("\n  Nama yang akan dihapus (Enter=batal): ").strip()
    if not name:
        print(fmt_warn("Dibatalkan.")); return
    confirm = input(f"  Hapus '{name}'? Tidak bisa dibatalkan. (y/N): ").strip().lower()
    if confirm != "y":
        print(fmt_warn("Dibatalkan.")); return
    req = urllib.request.Request(
        f"{server}/api/users/{urllib.parse.quote(name)}",
        method="DELETE"
    )
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            data = json.loads(r.read().decode())
            print(fmt_ok(data.get("message", f"'{name}' dihapus")))
    except urllib.error.HTTPError as e:
        if e.code == 404:
            print(fmt_err(f"Pengguna '{name}' tidak ditemukan"))
        else:
            print(fmt_err(f"HTTP {e.code}"))
    except Exception as e:
        print(fmt_err(str(e)))


def ctrl_clear_history(server: str) -> None:
    confirm = input("  Hapus SELURUH riwayat akses? (y/N): ").strip().lower()
    if confirm != "y":
        print(fmt_warn("Dibatalkan.")); return
    data, err = http_delete(f"{server}/api/history")
    if err:
        print(fmt_err(f"Gagal: {err}"))
    else:
        print(fmt_ok(data.get("message", "Riwayat dihapus")))


def ctrl_servo_status(server: str) -> None:
    data, err = http_get(f"{server}/api/servo/status")
    if err:
        print(fmt_err(f"Gagal: {err}")); return
    print(f"\n  {BOLD}Status Servo Lengkap:{RESET}")
    for k, v in data.items():
        val_str = c(GREEN, str(v)) if v is True else \
                  c(RED,   str(v)) if v is False else \
                  c(CYAN,  str(v))
        print(f"    {k:<18} = {val_str}")


# ─────────────────────────────────────────────────────────────────────────────
# Main loop
# ─────────────────────────────────────────────────────────────────────────────

def run_dashboard(server: str, interval: int) -> None:
    """Auto-refresh dashboard sampai Ctrl+C ditekan."""
    try:
        while True:
            print_dashboard(server, interval)
            time.sleep(interval)
    except KeyboardInterrupt:
        pass  # masuk ke menu kontrol


def run_control_menu(server: str) -> str:
    """
    Tampilkan menu kontrol, proses pilihan, return 'quit' atau 'resume'.
    """
    clr()
    print_control_menu()

    choice = input(c(BOLD, "  Pilih: ")).strip().lower()
    print()

    dispatch = {
        "1":  lambda: ctrl_servo_open(server),
        "2":  lambda: ctrl_servo_lock(server),
        "3":  lambda: ctrl_servo_move(server),
        "4":  lambda: ctrl_servo_cycle(server),
        "5":  lambda: ctrl_servo_config(server),
        "6":  lambda: ctrl_buzzer(server),
        "7":  lambda: ctrl_led(server, "green"),
        "8":  lambda: ctrl_led(server, "red"),
        "9":  lambda: ctrl_set_camera(server, "door"),
        "10": lambda: ctrl_set_camera(server, "yard"),
        "11": lambda: ctrl_probe_cameras(server),
        "12": lambda: ctrl_list_users(server),
        "13": lambda: ctrl_delete_user(server),
        "14": lambda: ctrl_clear_history(server),
        "15": lambda: ctrl_servo_status(server),
    }

    if choice == "q":
        return "quit"
    if choice == "r":
        return "resume"
    if choice in dispatch:
        dispatch[choice]()
        input(c(DIM, "\n  Tekan Enter untuk kembali ke menu..."))
        return "menu"   # tetap di menu setelah aksi
    print(fmt_warn("Pilihan tidak dikenal."))
    time.sleep(1)
    return "menu"


def main() -> None:
    parser = argparse.ArgumentParser(description="HomeSafe Live Monitor")
    parser.add_argument(
        "--server", "-s",
        default="http://localhost:5001",
        metavar="URL",
        help="URL server (default: http://localhost:5001)",
    )
    parser.add_argument(
        "--interval", "-i",
        type=int,
        default=5,
        metavar="DETIK",
        help="Interval auto-refresh dashboard dalam detik (default: 5)",
    )
    args = parser.parse_args()

    server   = args.server.rstrip("/")
    interval = max(1, args.interval)

    print(f"\n{c(BOLD, '  🏠 HomeSafe Monitor')}")
    print(f"  Server   : {c(CYAN, server)}")
    print(f"  Interval : {interval}s")
    print(c(DIM, "  Tekan Ctrl+C di dashboard untuk masuk menu kontrol\n"))
    time.sleep(1)

    state = "dashboard"
    while True:
        if state == "dashboard":
            run_dashboard(server, interval)
            state = "menu"              # Ctrl+C → menu

        elif state == "menu":
            result = run_control_menu(server)
            if result == "quit":
                print(c(GREEN, "\n  Selesai. Sampai jumpa!\n"))
                sys.exit(0)
            elif result == "resume":
                state = "dashboard"
            else:
                state = "menu"          # tetap di menu setelah aksi


if __name__ == "__main__":
    main()
