"""
diagnose_camera.py — Jalankan di Raspberry Pi untuk diagnosis kamera
Usage: python3 diagnose_camera.py
"""

import os
import sys
import glob
import struct
import subprocess

# ── Warna terminal ─────────────────────────────────────────────────────────────
OK   = "\033[92m✅"
FAIL = "\033[91m❌"
WARN = "\033[93m⚠️ "
INFO = "\033[94mℹ️ "
RST  = "\033[0m"

def section(title):
    print(f"\n{'='*55}")
    print(f"  {title}")
    print('='*55)

def run(cmd):
    try:
        r = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=5)
        return r.stdout.strip(), r.returncode
    except Exception as e:
        return str(e), 1

# ─────────────────────────────────────────────────────────────────────────────
# 1. Video nodes
# ─────────────────────────────────────────────────────────────────────────────
section("1. Video Nodes di /dev/video*")
nodes = sorted(glob.glob("/dev/video*"))
if not nodes:
    print(f"{FAIL} Tidak ada /dev/video* — kamera tidak terdeteksi OS{RST}")
    print(f"{INFO} Coba: lsusb  (pastikan webcam muncul){RST}")
    sys.exit(1)
else:
    print(f"{OK} Ditemukan {len(nodes)} node: {nodes}{RST}")

# ─────────────────────────────────────────────────────────────────────────────
# 2. VIDIOC_QUERYCAP — mana capture node, mana metadata
# ─────────────────────────────────────────────────────────────────────────────
section("2. Capability Check (capture vs metadata node)")
import fcntl

VIDIOC_QUERYCAP        = 0x80685600
V4L2_CAP_VIDEO_CAPTURE = 0x00000001

capture_nodes = []
for path in nodes:
    try:
        with open(path, "rb") as f:
            buf = b"\x00" * 104
            result = fcntl.ioctl(f, VIDIOC_QUERYCAP, buf)
            caps   = struct.unpack_from("<I", result, 64)[0]
            driver = result[:16].rstrip(b"\x00").decode(errors="replace")
            card   = result[16:48].rstrip(b"\x00").decode(errors="replace")
            is_cap = bool(caps & V4L2_CAP_VIDEO_CAPTURE)
            tag    = f"{OK} CAPTURE{RST}" if is_cap else f"{WARN}METADATA — akan dilewati{RST}"
            print(f"  {path}  {tag}  [{driver}] {card}")
            if is_cap:
                capture_nodes.append(path)
    except PermissionError:
        print(f"  {path}  {FAIL} Permission denied — jalankan: sudo usermod -aG video $USER{RST}")
    except Exception as e:
        print(f"  {path}  {WARN} {e}{RST}")

if not capture_nodes:
    print(f"\n{FAIL} Tidak ada capture node yang bisa dibuka!{RST}")
    sys.exit(1)
else:
    print(f"\n{OK} Capture node valid: {capture_nodes}{RST}")

# ─────────────────────────────────────────────────────────────────────────────
# 3. Cek apakah ada proses lain yang sedang pakai kamera
# ─────────────────────────────────────────────────────────────────────────────
section("3. Proses yang Sedang Menggunakan Kamera")
busy = False
for path in capture_nodes:
    out, _ = run(f"sudo fuser {path} 2>/dev/null")
    if out.strip():
        pids = out.strip()
        # Cari nama proses
        names = []
        for pid in pids.split():
            n, _ = run(f"ps -p {pid} -o comm= 2>/dev/null")
            names.append(f"PID {pid} ({n})" if n else f"PID {pid}")
        print(f"  {WARN} {path} sedang dipakai oleh: {', '.join(names)}{RST}")
        busy = True
    else:
        print(f"  {OK} {path} bebas (tidak ada proses lain){RST}")

if busy:
    print(f"\n{WARN} Ada kamera yang sedang dipakai proses lain.{RST}")
    print(f"  Solusi: sudo fuser -k /dev/video0 /dev/video2")

# ─────────────────────────────────────────────────────────────────────────────
# 4. Test buka kamera dengan OpenCV
# ─────────────────────────────────────────────────────────────────────────────
section("4. Test OpenCV — Buka & Baca Frame")
try:
    import cv2
except ImportError:
    print(f"{FAIL} OpenCV tidak terinstall: pip install opencv-python{RST}")
    sys.exit(1)

working_cameras = []
for path in capture_nodes:
    idx = int(path.replace("/dev/video", ""))
    print(f"\n  Testing {path} (index={idx})…")

    # Coba via path string dulu
    for src, label in [(path, "path-string"), (idx, "integer-index")]:
        cap = cv2.VideoCapture(src, cv2.CAP_V4L2)
        opened = cap.isOpened()
        if not opened:
            cap.release()
            print(f"    {FAIL} [{label}] cap.isOpened() = False{RST}")
            continue

        ret, frame = cap.read()
        if ret and frame is not None:
            h, w = frame.shape[:2]
            print(f"    {OK} [{label}] Berhasil! Frame: {w}x{h}{RST}")
            working_cameras.append({"id": idx, "path": path, "src": src})
            cap.release()
            break
        else:
            print(f"    {FAIL} [{label}] isOpened=True tapi read() gagal{RST}")
            cap.release()

# ─────────────────────────────────────────────────────────────────────────────
# 5. Cek permission grup video
# ─────────────────────────────────────────────────────────────────────────────
section("5. Permission User")
out, _ = run("id")
print(f"  User info: {out}")
if "video" in out:
    print(f"  {OK} User sudah ada di grup 'video'{RST}")
else:
    print(f"  {FAIL} User TIDAK ada di grup 'video'{RST}")
    print(f"  Solusi: sudo usermod -aG video $USER  (lalu logout & login ulang)")

# ─────────────────────────────────────────────────────────────────────────────
# 6. Ringkasan & Rekomendasi
# ─────────────────────────────────────────────────────────────────────────────
section("6. Ringkasan")
if working_cameras:
    print(f"{OK} Kamera yang berfungsi:{RST}")
    for cam in working_cameras:
        print(f"   - Index {cam['id']} via {cam['src']}")
    print()
    print(f"  Gunakan index ini di app.py atau ubah _current_cam_id:")
    ids = [str(c['id']) for c in working_cameras]
    print(f"  _current_cam_id = {ids[0]}  (default)")
    print()
    print(f"  Atau set lewat API setelah server jalan:")
    for cam in working_cameras:
        print(f"  curl -X POST http://localhost:5001/api/cameras/select/{cam['id']}")
else:
    print(f"{FAIL} Tidak ada kamera yang berhasil dibuka dengan OpenCV!{RST}")
    print()
    print("  Kemungkinan penyebab & solusi:")
    print("  1. Proses lain masih pakai kamera")
    print("     → sudo fuser -k /dev/video0 /dev/video2")
    print()
    print("  2. User tidak punya akses grup video")
    print("     → sudo usermod -aG video $USER")
    print("     → logout & login ulang")
    print()
    print("  3. Driver kamera belum load")
    print("     → sudo modprobe uvcvideo")
    print()
    print("  4. Kabel USB longgar atau port bermasalah")
    print("     → coba cabut & colok ulang, ganti port USB")
    print()
    print("  5. Kamera butuh power lebih (Pi USB 500mA limit)")
    print("     → pakai USB hub bertenaga eksternal")
