#!/usr/bin/env python3
"""
servo_calibration.py — Kalibrasi servo interaktif untuk HomeSafe
═══════════════════════════════════════════════════════════════════
Jalankan STANDALONE (tanpa server utama aktif):

    python3 servo_calibration.py

Atau saat server sudah running (config akan langsung teraplikasi):

    python3 servo_calibration.py --server http://localhost:5001

Fitur:
  • Gerakkan servo ke sudut bebas (0–180°)
  • Test posisi LOCK dan OPEN
  • Temukan duty cycle yang tepat untuk motor kamu
  • Scan otomatis seluruh range gerak
  • Simpan hasil ke servo_config.json
  • Push config ke server live (tanpa restart) jika server sedang berjalan

Pin default: GPIO 18 (BCM), 50 Hz
═══════════════════════════════════════════════════════════════════
"""

import sys
import time
import json
import os
import argparse

# ── Opsional: urllib untuk push ke server ────────────────────────────────────
try:
    import urllib.request
    import urllib.error
    HAS_URLLIB = True
except ImportError:
    HAS_URLLIB = False

# ── GPIO ──────────────────────────────────────────────────────────────────────
try:
    import RPi.GPIO as GPIO
    GPIO_AVAILABLE = True
except ImportError:
    GPIO_AVAILABLE = False
    print("⚠️  RPi.GPIO tidak ditemukan — mode simulasi (tidak ada gerakan nyata)")
    class _MockGPIO:
        BCM = "BCM"; OUT = "OUT"
        def setmode(self, *a): pass
        def setwarnings(self, *a): pass
        def setup(self, *a, **kw): pass
        def cleanup(self): pass
        def PWM(self, pin, freq):
            class _P:
                def start(self, dc):
                    print(f"   [SIM] PWM start  pin={pin}  dc={dc:.4f}%")
                def ChangeDutyCycle(self, dc):
                    print(f"   [SIM] PWM duty   pin={pin}  dc={dc:.4f}%")
                def stop(self):
                    print(f"   [SIM] PWM stop   pin={pin}")
            return _P()
    GPIO = _MockGPIO()

# ── Konfigurasi default ───────────────────────────────────────────────────────
CONFIG_FILE = "servo_config.json"
PIN_SERVO   = 18
SERVO_FREQ  = 50

# Duty cycle defaults (SG90 / MG90S)
DEFAULT_MIN_DC     = 2.5
DEFAULT_MAX_DC     = 12.5
DEFAULT_LOCK_ANGLE = 0
DEFAULT_OPEN_ANGLE = 90
DEFAULT_OPEN_TIME  = 5

# ── Warna terminal ─────────────────────────────────────────────────────────────
CYAN   = "\033[96m"
GREEN  = "\033[92m"
YELLOW = "\033[93m"
RED    = "\033[91m"
BOLD   = "\033[1m"
RESET  = "\033[0m"

def c(color, text): return f"{color}{text}{RESET}"


# ─────────────────────────────────────────────────────────────────────────────
# Config helpers
# ─────────────────────────────────────────────────────────────────────────────

def load_config() -> dict:
    defaults = {
        "pin":        PIN_SERVO,
        "freq":       SERVO_FREQ,
        "min_dc":     DEFAULT_MIN_DC,
        "max_dc":     DEFAULT_MAX_DC,
        "lock_angle": DEFAULT_LOCK_ANGLE,
        "open_angle": DEFAULT_OPEN_ANGLE,
        "open_time":  DEFAULT_OPEN_TIME,
    }
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE) as f:
                data = json.load(f)
            defaults.update({k: data[k] for k in defaults if k in data})
            print(c(CYAN, f"  📂 Config dimuat dari {CONFIG_FILE}"))
        except Exception as e:
            print(c(YELLOW, f"  ⚠️  Gagal baca {CONFIG_FILE}: {e} — pakai default"))
    return defaults


def save_config(cfg: dict) -> bool:
    """Simpan config ke servo_config.json."""
    try:
        with open(CONFIG_FILE, "w") as f:
            json.dump(cfg, f, indent=2)
        print(c(GREEN, f"\n  ✅ Config disimpan ke {CONFIG_FILE}"))
        return True
    except Exception as e:
        print(c(RED, f"\n  ❌ Gagal simpan: {e}"))
        return False


def push_to_server(cfg: dict, server_url: str) -> bool:
    """
    Kirim config ke server yang sedang berjalan via POST /api/servo/config.
    Server akan langsung update _servo_cfg tanpa restart.
    Mengembalikan True jika berhasil.
    """
    if not HAS_URLLIB:
        print(c(YELLOW, "  ⚠️  urllib tidak tersedia — tidak bisa push ke server"))
        return False

    url     = server_url.rstrip("/") + "/api/servo/config"
    payload = json.dumps({
        "lock_angle": cfg["lock_angle"],
        "open_angle": cfg["open_angle"],
        "open_time":  cfg["open_time"],
        "min_dc":     cfg["min_dc"],
        "max_dc":     cfg["max_dc"],
    }).encode("utf-8")

    req = urllib.request.Request(
        url,
        data    = payload,
        headers = {"Content-Type": "application/json"},
        method  = "POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            body = json.loads(resp.read().decode())
            cur  = body.get("current", {})
            print(c(GREEN,
                f"  ✅ Server diupdate via {url}\n"
                f"     lock={cur.get('lock_angle')}°  "
                f"open={cur.get('open_angle')}°  "
                f"time={cur.get('open_time')}s  "
                f"dc={cur.get('min_dc')}–{cur.get('max_dc')}%"
            ))
            return True
    except urllib.error.URLError as e:
        print(c(YELLOW, f"  ⚠️  Server tidak terjangkau ({url}): {e.reason}"))
        print(c(YELLOW,  "      Config sudah disimpan ke file — akan aktif saat server restart."))
        return False
    except Exception as e:
        print(c(YELLOW, f"  ⚠️  Push gagal: {e}"))
        return False


def save_and_push(cfg: dict, server_url: str | None) -> None:
    """Simpan ke file, lalu push ke server jika URL tersedia."""
    saved = save_config(cfg)
    if not saved:
        return

    if server_url:
        print(c(CYAN, f"\n  📡 Push ke server {server_url}..."))
        ok = push_to_server(cfg, server_url)
        if not ok and not server_url:
            print(c(YELLOW,
                "  ℹ️  Jalankan dengan --server untuk push otomatis:\n"
                f"      python3 servo_calibration.py --server http://localhost:5001"
            ))
    else:
        print(c(YELLOW,
            "\n  ℹ️  Server URL tidak dikonfigurasi.\n"
            "      Config disimpan ke file — aktif saat server restart.\n"
            "      Untuk update live, jalankan:\n"
            f"        python3 servo_calibration.py --server http://localhost:5001\n"
            "      Atau dari terminal lain:\n"
            f"        curl -X POST http://localhost:5001/api/servo/config \\\n"
            f"             -H 'Content-Type: application/json' \\\n"
            f"             -d '{{\"lock_angle\":{cfg['lock_angle']},\"open_angle\":{cfg['open_angle']}}}'"
        ))


# ─────────────────────────────────────────────────────────────────────────────
# Servo controller
# ─────────────────────────────────────────────────────────────────────────────

class ServoCalibrator:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.pwm = None

    def _duty(self, angle: float) -> float:
        angle = max(0.0, min(180.0, float(angle)))
        return self.cfg["min_dc"] + (angle / 180.0) * (self.cfg["max_dc"] - self.cfg["min_dc"])

    def setup(self) -> None:
        GPIO.setwarnings(False)
        GPIO.setmode(GPIO.BCM)
        GPIO.setup(self.cfg["pin"], GPIO.OUT)
        self.pwm = GPIO.PWM(self.cfg["pin"], self.cfg["freq"])
        dc = self._duty(0)
        self.pwm.start(dc)
        time.sleep(0.3)
        print(c(GREEN, f"  ✅ Servo GPIO{self.cfg['pin']} siap @ {self.cfg['freq']}Hz"))

    def move(self, angle: float, hold: float = 0.5) -> float:
        """Gerak ke sudut, tahan hold detik, matikan sinyal. Return duty cycle."""
        dc = self._duty(angle)
        self.pwm.ChangeDutyCycle(dc)
        time.sleep(hold)
        self.pwm.ChangeDutyCycle(0)
        return dc

    def move_dc(self, dc: float, hold: float = 0.5) -> None:
        """Gerak langsung dengan duty cycle untuk fine-tuning."""
        dc = max(0.0, min(100.0, float(dc)))
        self.pwm.ChangeDutyCycle(dc)
        time.sleep(hold)
        self.pwm.ChangeDutyCycle(0)

    def cleanup(self) -> None:
        if self.pwm:
            dc = self._duty(self.cfg["lock_angle"])
            self.pwm.ChangeDutyCycle(dc)
            time.sleep(0.4)
            self.pwm.ChangeDutyCycle(0)
            self.pwm.stop()
        GPIO.cleanup()


# ─────────────────────────────────────────────────────────────────────────────
# Menu actions
# ─────────────────────────────────────────────────────────────────────────────

def menu_move_angle(servo: ServoCalibrator) -> None:
    try:
        angle = float(input("  Masukkan sudut (0–180): ").strip())
        dc    = servo.move(angle, hold=1.0)
        print(c(GREEN, f"  ✅ Servo → {angle:.1f}°  (duty cycle = {dc:.4f}%)"))
    except ValueError:
        print(c(YELLOW, "  Input tidak valid."))


def menu_test_lock(servo: ServoCalibrator) -> None:
    angle = servo.cfg["lock_angle"]
    dc    = servo.move(angle, hold=1.0)
    print(c(GREEN, f"  ✅ LOCK → {angle}°  (dc={dc:.4f}%)"))


def menu_test_open(servo: ServoCalibrator) -> None:
    angle = servo.cfg["open_angle"]
    dc    = servo.move(angle, hold=1.0)
    print(c(GREEN, f"  ✅ OPEN → {angle}°  (dc={dc:.4f}%)"))


def menu_test_cycle(servo: ServoCalibrator) -> None:
    lock = servo.cfg["lock_angle"]
    opn  = servo.cfg["open_angle"]
    hold = servo.cfg["open_time"]
    print(f"  Siklus: LOCK({lock}°) → OPEN({opn}°, {hold}s) → LOCK({lock}°)")
    dc1 = servo.move(lock, hold=0.5)
    print(c(CYAN,  f"    LOCK  {lock}°   dc={dc1:.4f}%"))
    time.sleep(0.3)
    dc2 = servo.move(opn, hold=float(hold))
    print(c(GREEN, f"    OPEN  {opn}°   dc={dc2:.4f}%  (tahan {hold}s)"))
    time.sleep(0.3)
    dc3 = servo.move(lock, hold=0.5)
    print(c(CYAN,  f"    LOCK  {lock}°   dc={dc3:.4f}%"))
    print(c(GREEN, "  ✅ Siklus selesai"))


def menu_scan(servo: ServoCalibrator) -> None:
    print("  Scan 0°→180°→0° (step 10°) — perhatikan gerakan servo...")
    steps = list(range(0, 181, 10)) + list(range(170, -1, -10))
    for angle in steps:
        dc = servo.move(angle, hold=0.25)
        print(f"    {angle:3d}°  dc={dc:.4f}%")
    print(c(GREEN, "  ✅ Scan selesai"))


def menu_fine_tune_dc(servo: ServoCalibrator) -> None:
    print("  Masukkan duty cycle langsung (0.0 – 15.0).")
    print("  Berguna untuk servo yang range DC-nya tidak standar.")
    try:
        dc = float(input("  Duty cycle: ").strip())
        servo.move_dc(dc, hold=1.0)
        print(c(GREEN, f"  ✅ Servo digerakkan dc={dc:.4f}%"))

        if input("  Jadikan ini posisi LOCK? (y/N): ").strip().lower() == "y":
            rng   = servo.cfg["max_dc"] - servo.cfg["min_dc"]
            angle = round((dc - servo.cfg["min_dc"]) / rng * 180, 1) if rng else 0
            servo.cfg["lock_angle"] = angle
            print(c(CYAN, f"  → lock_angle diset ke {angle}°"))

        if input("  Jadikan ini posisi OPEN? (y/N): ").strip().lower() == "y":
            rng   = servo.cfg["max_dc"] - servo.cfg["min_dc"]
            angle = round((dc - servo.cfg["min_dc"]) / rng * 180, 1) if rng else 90
            servo.cfg["open_angle"] = angle
            print(c(CYAN, f"  → open_angle diset ke {angle}°"))
    except ValueError:
        print(c(YELLOW, "  Input tidak valid."))


def menu_set_lock(servo: ServoCalibrator) -> None:
    try:
        raw   = input(f"  lock_angle saat ini = {servo.cfg['lock_angle']}°. Nilai baru: ").strip()
        angle = float(raw)
        if not (0 <= angle <= 180): raise ValueError
        servo.cfg["lock_angle"] = angle
        dc = servo.move(angle, hold=0.8)
        print(c(GREEN, f"  ✅ lock_angle → {angle}°  (dc={dc:.4f}%)"))
    except ValueError:
        print(c(YELLOW, "  Input tidak valid (harus 0–180)."))


def menu_set_open(servo: ServoCalibrator) -> None:
    try:
        raw   = input(f"  open_angle saat ini = {servo.cfg['open_angle']}°. Nilai baru: ").strip()
        angle = float(raw)
        if not (0 <= angle <= 180): raise ValueError
        servo.cfg["open_angle"] = angle
        dc = servo.move(angle, hold=0.8)
        print(c(GREEN, f"  ✅ open_angle → {angle}°  (dc={dc:.4f}%)"))
    except ValueError:
        print(c(YELLOW, "  Input tidak valid (harus 0–180)."))


def menu_set_open_time(servo: ServoCalibrator) -> None:
    try:
        raw = input(f"  open_time saat ini = {servo.cfg['open_time']}s. Nilai baru (1–60): ").strip()
        t   = float(raw)
        if not (1 <= t <= 60): raise ValueError
        servo.cfg["open_time"] = t
        print(c(GREEN, f"  ✅ open_time → {t}s"))
    except ValueError:
        print(c(YELLOW, "  Input tidak valid (harus 1–60)."))


def menu_set_dc_range(servo: ServoCalibrator) -> None:
    print(f"  MIN DC saat ini = {servo.cfg['min_dc']:.2f}%  (untuk 0°)")
    print(f"  MAX DC saat ini = {servo.cfg['max_dc']:.2f}%  (untuk 180°)")
    print( "  SG90/MG90S standar: MIN=2.5, MAX=12.5")
    print( "  Jika servo bergerak kurang dari 180°, naikkan MAX sedikit.")
    try:
        mn = input("  MIN DC baru (Enter=skip): ").strip()
        mx = input("  MAX DC baru (Enter=skip): ").strip()
        changed = False
        if mn:
            servo.cfg["min_dc"] = float(mn); changed = True
        if mx:
            servo.cfg["max_dc"] = float(mx); changed = True
        if changed:
            print(c(GREEN, f"  ✅ DC range → {servo.cfg['min_dc']:.2f}% – {servo.cfg['max_dc']:.2f}%"))
            print("  Test ulang 0° → 90° → 0°...")
            servo.move(0,  hold=0.5)
            servo.move(90, hold=0.5)
            servo.move(0,  hold=0.5)
    except ValueError:
        print(c(YELLOW, "  Input tidak valid."))


def menu_show_config(cfg: dict, server_url: str | None) -> None:
    print(f"\n  {BOLD}Konfigurasi saat ini:{RESET}")
    for k, v in cfg.items():
        print(f"    {k:12} = {v}")
    if server_url:
        print(f"\n  Server target : {server_url}")
    else:
        print(f"\n  Server target : (tidak dikonfigurasi — gunakan --server)")


def print_header(cfg: dict, server_url: str | None) -> None:
    print(f"\n{BOLD}{CYAN}{'═'*58}{RESET}")
    print(c(BOLD, "  🔧 HomeSafe — Servo Calibration Tool"))
    print(f"{BOLD}{CYAN}{'═'*58}{RESET}")
    print(f"  Pin      : GPIO {cfg['pin']} (BCM)")
    print(f"  Freq     : {cfg['freq']} Hz")
    print(f"  DC range : {cfg['min_dc']:.2f}% (0°) – {cfg['max_dc']:.2f}% (180°)")
    print(f"  Lock     : {cfg['lock_angle']}°")
    print(f"  Open     : {cfg['open_angle']}°  (tahan {cfg['open_time']}s)")
    if server_url:
        print(f"  Server   : {c(GREEN, server_url)}  {c(CYAN,'← config akan langsung teraplikasi')}")
    else:
        print(f"  Server   : {c(YELLOW,'tidak dikonfigurasi')}  (config hanya disimpan ke file)")
    print(f"{CYAN}{'─'*58}{RESET}")


def print_menu() -> None:
    print(f"""
  {BOLD}Gerak & Test:{RESET}
  {c(CYAN,'[a]')}  Gerak ke sudut bebas (0–180°)
  {c(CYAN,'[b]')}  Test posisi LOCK
  {c(CYAN,'[c]')}  Test posisi OPEN
  {c(CYAN,'[d]')}  Test siklus LOCK → OPEN → LOCK (dengan open_time)
  {c(CYAN,'[e]')}  Scan otomatis 0°→180°→0° (lihat range gerak penuh)
  {c(CYAN,'[f]')}  Fine-tune duty cycle langsung

  {BOLD}Atur Posisi:{RESET}
  {c(CYAN,'[g]')}  Set posisi LOCK baru
  {c(CYAN,'[h]')}  Set posisi OPEN baru
  {c(CYAN,'[i]')}  Set waktu OPEN (open_time)
  {c(CYAN,'[j]')}  Ubah range duty cycle (MIN/MAX DC)

  {BOLD}Simpan & Terapkan:{RESET}
  {c(CYAN,'[s]')}  {c(BOLD, 'Simpan config + push ke server (jika running)')}
  {c(CYAN,'[p]')}  Tampilkan konfigurasi saat ini
  {c(CYAN,'[q]')}  Keluar (servo kembali ke LOCK)
""")


# ─────────────────────────────────────────────────────────────────────────────
# Entry point
# ─────────────────────────────────────────────────────────────────────────────

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="HomeSafe servo calibration tool"
    )
    p.add_argument(
        "--server", "-s",
        metavar="URL",
        default=None,
        help="URL server HomeSafe (contoh: http://localhost:5001). "
             "Jika diisi, config akan langsung di-push ke server saat disimpan.",
    )
    return p.parse_args()


def main() -> None:
    args       = parse_args()
    server_url = args.server
    cfg        = load_config()
    servo      = ServoCalibrator(cfg)

    print_header(cfg, server_url)
    print(c(YELLOW, "\n  Menginisialisasi servo..."))
    servo.setup()

    try:
        while True:
            print_menu()
            choice = input(c(BOLD, "  Pilih [a-j/s/p/q]: ")).strip().lower()
            print()

            if   choice == "a": menu_move_angle(servo)
            elif choice == "b": menu_test_lock(servo)
            elif choice == "c": menu_test_open(servo)
            elif choice == "d": menu_test_cycle(servo)
            elif choice == "e": menu_scan(servo)
            elif choice == "f": menu_fine_tune_dc(servo)
            elif choice == "g": menu_set_lock(servo)
            elif choice == "h": menu_set_open(servo)
            elif choice == "i": menu_set_open_time(servo)
            elif choice == "j": menu_set_dc_range(servo)
            elif choice == "s": save_and_push(cfg, server_url)
            elif choice == "p": menu_show_config(cfg, server_url)
            elif choice == "q":
                print(c(YELLOW, "\n  Kembali ke posisi LOCK dan keluar..."))
                break
            else:
                print(c(YELLOW, "  Pilihan tidak dikenal."))

    except KeyboardInterrupt:
        print(c(YELLOW, "\n\n  Interrupted — kembali ke LOCK..."))
    finally:
        servo.cleanup()
        print(c(GREEN, "  GPIO dibersihkan. Selesai.\n"))


if __name__ == "__main__":
    main()
