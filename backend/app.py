"""
Smart Lock – Face Recognition API
Raspberry Pi: dual camera (door + yard), asyncio pipeline, GPIO servo

Endpoints:
  WS  /ws           — face recognition stream (door camera)
  WS  /ws/enroll    — face enrollment (door camera)
  WS  /ws/motion    — CCTV motion detection stream (yard camera)
  GET /api/cameras  — list all video nodes
  POST /api/cameras/probe       — re-enumerate cameras
  POST /api/cameras/door/{id}   — set door camera
  POST /api/cameras/yard/{id}   — set yard camera
  GET/DELETE /api/history
  GET/DELETE /api/users/{name}
"""

import os
import json
import time
import base64
import asyncio
import logging
import threading
import atexit
import cv2
import numpy as np
from contextlib import asynccontextmanager
from datetime import datetime
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from fastapi import FastAPI, WebSocket, WebSocketDisconnect, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
import uvicorn
from recognizer import (
    FaceRecognizerEngine,
    MATCH_THRESHOLD,
    REGISTRATION_FRAMES_REQUIRED,
    CONSECUTIVE_MATCHES_REQUIRED,
)

# ── GPIO — deteksi platform ───────────────────────────────────────────────────
try:
    import RPi.GPIO as GPIO
    GPIO_AVAILABLE = True
except ImportError:
    GPIO_AVAILABLE = False
    class _MockGPIO:
        BCM = "BCM"; OUT = "OUT"; HIGH = True; LOW = False
        def setmode(self, *a): pass
        def setwarnings(self, *a): pass
        def setup(self, *a, **kw): pass
        def output(self, pin, state):
            logging.getLogger(__name__).debug(f"[MockGPIO] pin={pin} → {state}")
        def PWM(self, pin, freq):
            _log = logging.getLogger(__name__)
            class _P:
                def start(self, dc): _log.debug(f"[MockPWM] {pin} start {dc}")
                def ChangeDutyCycle(self, dc): _log.debug(f"[MockPWM] {pin} dc={dc}")
                def stop(self): _log.debug(f"[MockPWM] {pin} stop")
            return _P()
        def cleanup(self): pass
    GPIO = _MockGPIO()

# ── Pin config ────────────────────────────────────────────────────────────────
PIN_SERVO     = 18
PIN_LED_HIJAU = 27
PIN_LED_MERAH = 22
PIN_BUZZER    = 23

SERVO_FREQ  = 50

# ── Servo config — dibaca dari servo_config.json, bisa diupdate live ──────────
_SERVO_CONFIG_FILE = Path("servo_config.json")

def _load_servo_config() -> dict:
    """
    Baca servo_config.json jika ada.
    Fallback ke nilai default jika file tidak ada atau rusak.
    """
    defaults = {
        "pin":        PIN_SERVO,
        "freq":       SERVO_FREQ,
        "min_dc":     2.5,
        "max_dc":     12.5,
        "lock_angle": 0,
        "open_angle": 90,
        "open_time":  5,
    }
    if _SERVO_CONFIG_FILE.exists():
        try:
            with open(_SERVO_CONFIG_FILE) as f:
                data = json.load(f)
            defaults.update({k: data[k] for k in defaults if k in data})
            logging.getLogger(__name__).info(
                f"✅ servo_config.json dimuat — "
                f"lock={defaults['lock_angle']}° open={defaults['open_angle']}° "
                f"dc={defaults['min_dc']}–{defaults['max_dc']}%"
            )
        except Exception as e:
            logging.getLogger(__name__).warning(f"⚠️  servo_config.json error: {e} — pakai default")
    return defaults

# Mutable servo config — diupdate oleh endpoint /api/servo/config tanpa restart
_servo_cfg = _load_servo_config()

# Aksesor yang selalu baca dari _servo_cfg (bukan konstanta)
# Gunakan sebagai fungsi: LOCK_ANGLE() bukan LOCK_ANGLE
def LOCK_ANGLE()  -> float: return float(_servo_cfg["lock_angle"])
def OPEN_ANGLE()  -> float: return float(_servo_cfg["open_angle"])
def OPEN_TIME()   -> float: return float(_servo_cfg["open_time"])

def _angle_to_duty(angle: float) -> float:
    """Konversi sudut ke duty cycle menggunakan min/max DC dari config."""
    angle = max(0.0, min(180.0, float(angle)))
    return _servo_cfg["min_dc"] + (angle / 180.0) * (_servo_cfg["max_dc"] - _servo_cfg["min_dc"])

_servo_pwm  = None
_servo_lock = threading.Lock()

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)
if GPIO_AVAILABLE:
    logger.info("✅ RPi.GPIO tersedia — mode hardware aktif")
else:
    logger.warning("⚠️  RPi.GPIO tidak ditemukan — mode simulasi")

# ─────────────────────────────────────────────────────────────────────────────
# GPIO / SERVO
# ─────────────────────────────────────────────────────────────────────────────

def setup_gpio() -> None:
    global _servo_pwm
    try:
        GPIO.setwarnings(False)
        GPIO.setmode(GPIO.BCM)
        GPIO.setup(PIN_LED_HIJAU, GPIO.OUT, initial=GPIO.LOW)
        GPIO.setup(PIN_LED_MERAH, GPIO.OUT, initial=GPIO.HIGH)
        GPIO.setup(PIN_BUZZER,    GPIO.OUT, initial=GPIO.LOW)
        GPIO.setup(PIN_SERVO,     GPIO.OUT)
        lock_dc = _angle_to_duty(LOCK_ANGLE())
        pwm = GPIO.PWM(PIN_SERVO, SERVO_FREQ)
        pwm.start(lock_dc)
        with _servo_lock:
            _servo_pwm = pwm
        logger.info(
            f"✅ GPIO diinisialisasi — Servo=PIN{PIN_SERVO} "
            f"({SERVO_FREQ}Hz, {LOCK_ANGLE()}° dc={lock_dc:.2f} TERKUNCI), "
            f"LED_H=PIN{PIN_LED_HIJAU}, LED_M=PIN{PIN_LED_MERAH}, Buzzer=PIN{PIN_BUZZER}"
        )
    except Exception as e:
        logger.error(f"❌ Gagal inisialisasi GPIO: {e}")


def _set_servo_angle(angle: float) -> None:
    """Gerakkan servo lalu matikan sinyal (cegah getaran). Panggil dari thread."""
    dc = _angle_to_duty(angle)
    with _servo_lock:
        if _servo_pwm is not None:
            _servo_pwm.ChangeDutyCycle(dc)
    time.sleep(0.5)
    with _servo_lock:
        if _servo_pwm is not None:
            _servo_pwm.ChangeDutyCycle(0)


def _beep(duration: float) -> None:
    GPIO.output(PIN_BUZZER, GPIO.HIGH)
    time.sleep(duration)
    GPIO.output(PIN_BUZZER, GPIO.LOW)


def _servo_unlock_sequence(name: str) -> None:
    """Urutan hardware akses diterima — berjalan di background thread."""
    logger.info(f"🔓 [SERVO] Unlock untuk: {name}")
    try:
        GPIO.output(PIN_LED_HIJAU, GPIO.HIGH)
        GPIO.output(PIN_LED_MERAH, GPIO.LOW)
        _beep(0.15); time.sleep(0.10); _beep(0.15)
        logger.info(f"🔓 [SERVO] Servo → {OPEN_ANGLE()}°")
        _set_servo_angle(OPEN_ANGLE())
        logger.info(f"⏳ [SERVO] Menunggu {OPEN_TIME()}s…")
        time.sleep(OPEN_TIME())
        logger.info(f"🔒 [SERVO] Servo → {LOCK_ANGLE()}°")
        _set_servo_angle(LOCK_ANGLE())
        GPIO.output(PIN_LED_MERAH, GPIO.HIGH)
        GPIO.output(PIN_LED_HIJAU, GPIO.LOW)
        logger.info(f"✅ [SERVO] Selesai untuk: {name}")
    except Exception as e:
        logger.error(f"❌ [SERVO] Error: {e}")
        try:
            _set_servo_angle(LOCK_ANGLE())
            GPIO.output(PIN_LED_MERAH, GPIO.HIGH)
            GPIO.output(PIN_LED_HIJAU, GPIO.LOW)
            GPIO.output(PIN_BUZZER,    GPIO.LOW)
        except Exception:
            pass


def trigger_unlock_servo(name: str) -> None:
    """Spawn background thread untuk urutan servo — tidak memblokir event loop."""
    t = threading.Thread(
        target=_servo_unlock_sequence, args=(name,),
        daemon=True, name=f"servo-unlock-{name}",
    )
    t.start()
    logger.info(f"🧵 [SERVO] Thread dimulai: {t.name}")

# ─────────────────────────────────────────────────────────────────────────────
# CAMERA CONFIG
# ─────────────────────────────────────────────────────────────────────────────

STREAM_W   = 320
STREAM_H   = 240
CAMERA_FPS = 30

_door_cam_id: int = 0   # /dev/video0 — face recognition
_yard_cam_id: int = 2   # /dev/video2 — CCTV / monitoring

# Door camera state
_door_frame: np.ndarray | None = None
_door_frame_ts: float = 0.0
_door_mutex     = threading.Lock()
_door_cap_mutex = threading.Lock()
_door_cap: cv2.VideoCapture | None = None
_door_running   = threading.Event()

# Yard camera state
_yard_frame: np.ndarray | None = None
_yard_frame_ts: float = 0.0
_yard_mutex     = threading.Lock()
_yard_cap_mutex = threading.Lock()
_yard_cap: cv2.VideoCapture | None = None
_yard_running   = threading.Event()


def _is_capture_node(video_path: str) -> bool:
    """
    Cek apakah V4L2 node adalah USB capture node yang bisa dipakai OpenCV.

    Filter berlapis:
    1. VIDIOC_QUERYCAP — harus punya flag VIDEO_CAPTURE
    2. Driver check — node ISP/metadata Pi (bcm2835-isp, unicam, rp1-cfe)
       lolos cap flag tapi tidak bisa di-capture oleh OpenCV → dibuang
    3. Index guard — node video10+ pada Pi umumnya ISP pipeline, bukan USB
    """
    import fcntl, struct

    # Node ≥ video10 di Pi hampir selalu ISP/metadata pipeline, bukan USB webcam
    try:
        idx = int(video_path.replace("/dev/video", ""))
        if idx >= 10:
            return False
    except ValueError:
        return False

    VIDIOC_QUERYCAP        = 0x80685600
    V4L2_CAP_VIDEO_CAPTURE = 0x00000001

    # Driver yang diketahui bukan USB capture
    BLOCKED_DRIVERS = {b"bcm2835-isp", b"unicam", b"rp1-cfe", b"bcm2835-v4l2"}

    try:
        with open(video_path, "rb") as f:
            buf    = b"\x00" * 104
            result = fcntl.ioctl(f, VIDIOC_QUERYCAP, buf)
            caps   = struct.unpack_from("<I", result, 64)[0]
            if not (caps & V4L2_CAP_VIDEO_CAPTURE):
                return False
            # Cek nama driver (byte 0–15)
            driver = result[:16].rstrip(b"\x00")
            if driver in BLOCKED_DRIVERS:
                return False
        return True
    except Exception:
        return False


def _open_cap(cam_id: int, width: int = STREAM_W, height: int = STREAM_H) -> cv2.VideoCapture | None:
    """
    Buka kamera via path langsung.
    - Gunakan path /dev/videoX (lebih andal dari integer index)
    - Hanya CAP_V4L2 — hindari obsensor/ISP backend
    - Lewati node yang tidak lolos _is_capture_node (video10+ Pi ISP)
    """
    import glob
    video_nodes   = sorted(glob.glob("/dev/video*"))
    matching_path = f"/dev/video{cam_id}"

    # Gunakan path string jika node valid, fallback ke integer hanya jika tidak ada
    if matching_path in video_nodes and _is_capture_node(matching_path):
        candidates: list[tuple] = [(matching_path, cv2.CAP_V4L2)]
    else:
        logger.warning(f"Camera {cam_id}: path {matching_path} tidak valid, coba integer index")
        candidates = [(cam_id, cv2.CAP_V4L2)]

    for src, backend in candidates:
        cap = cv2.VideoCapture(src, backend)
        if not cap.isOpened():
            cap.release(); continue
        cap.set(cv2.CAP_PROP_FOURCC,      cv2.VideoWriter_fourcc(*"MJPG"))
        cap.set(cv2.CAP_PROP_FRAME_WIDTH,  width)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, height)
        cap.set(cv2.CAP_PROP_FPS,          CAMERA_FPS)
        cap.set(cv2.CAP_PROP_BUFFERSIZE,   1)
        ok = False
        for _ in range(6):
            ret, frame = cap.read()
            if ret and frame is not None:
                ok = True; break
        if not ok:
            cap.release(); continue
        actual_w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        actual_h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        logger.info(f"✅ Camera {cam_id} ({src}) ready — {actual_w}x{actual_h} @ {CAMERA_FPS}FPS")
        return cap

    logger.error(f"❌ Cannot open camera {cam_id}")
    return None

# ── Camera threads ────────────────────────────────────────────────────────────

def _door_camera_thread():
    global _door_cap, _door_frame, _door_frame_ts
    while True:
        _door_running.wait()
        with _door_cap_mutex:
            cap = _open_cap(_door_cam_id, STREAM_W, STREAM_H)
            _door_cap = cap
        if cap is None:
            logger.warning("Door camera: tidak bisa dibuka, retry 2s")
            time.sleep(2); continue
        logger.info("Door camera: mulai capture")
        fails = 0
        while _door_running.is_set():
            ret, frame = cap.read()
            if not ret or frame is None:
                fails += 1
                if fails > 10:
                    logger.warning("Door camera: terlalu banyak gagal, reopen")
                    break
                time.sleep(0.01); continue
            fails = 0
            with _door_mutex:
                _door_frame    = frame
                _door_frame_ts = time.monotonic()
        cap.release()
        with _door_cap_mutex:
            _door_cap = None
        logger.info("Door camera: berhenti")


def _yard_camera_thread():
    global _yard_cap, _yard_frame, _yard_frame_ts
    while True:
        _yard_running.wait()
        with _yard_cap_mutex:
            cap = _open_cap(_yard_cam_id, 640, 480)
            _yard_cap = cap
        if cap is None:
            logger.warning("Yard camera: tidak bisa dibuka, retry 2s")
            time.sleep(2); continue
        logger.info("Yard camera: mulai capture")
        fails = 0
        while _yard_running.is_set():
            ret, frame = cap.read()
            if not ret or frame is None:
                fails += 1
                if fails > 10:
                    logger.warning("Yard camera: terlalu banyak gagal, reopen")
                    break
                time.sleep(0.01); continue
            fails = 0
            with _yard_mutex:
                _yard_frame    = frame
                _yard_frame_ts = time.monotonic()
        cap.release()
        with _yard_cap_mutex:
            _yard_cap = None
        logger.info("Yard camera: berhenti")


threading.Thread(target=_door_camera_thread, daemon=True, name="door-cam").start()
threading.Thread(target=_yard_camera_thread, daemon=True, name="yard-cam").start()


def _get_door_frame() -> np.ndarray | None:
    with _door_mutex:
        return None if _door_frame is None else _door_frame.copy()

def _get_yard_frame() -> np.ndarray | None:
    with _yard_mutex:
        return None if _yard_frame is None else _yard_frame.copy()

def _wait_frame(get_fn, timeout: float = 3.0) -> np.ndarray | None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        f = get_fn()
        if f is not None:
            return f
        time.sleep(0.01)
    return None


async def ensure_door_camera() -> bool:
    _door_running.set()
    loop  = asyncio.get_event_loop()
    frame = await loop.run_in_executor(_thread_pool, _wait_frame, _get_door_frame, 3.0)
    return frame is not None

async def ensure_yard_camera() -> bool:
    _yard_running.set()
    loop  = asyncio.get_event_loop()
    frame = await loop.run_in_executor(_thread_pool, _wait_frame, _get_yard_frame, 3.0)
    return frame is not None

# ─────────────────────────────────────────────────────────────────────────────
# APP INIT  (lifespan didaftarkan di sini, setelah semua fungsi sudah terdefinisi)
# ─────────────────────────────────────────────────────────────────────────────

engine       = FaceRecognizerEngine()
_thread_pool = ThreadPoolExecutor(max_workers=6)

HISTORY_DIR  = Path("history")
HISTORY_JSON = Path("history.json")
HISTORY_DIR.mkdir(exist_ok=True)


@asynccontextmanager
async def lifespan(app_instance: FastAPI):
    """Startup + shutdown handler (menggantikan @app.on_event yang deprecated)."""
    # ── STARTUP ───────────────────────────────────────────────────────────────
    logger.info("Server starting — pre-warming camera dan ML models…")
    setup_gpio()

    def _hardware_cleanup():
        logger.info("🧹 Hardware cleanup (atexit)…")
        try:
            _set_servo_angle(LOCK_ANGLE())
            with _servo_lock:
                if _servo_pwm is not None:
                    _servo_pwm.stop()
                    logger.info("🔒 [atexit] PWM servo dihentikan")
        except Exception as e:
            logger.warning(f"[atexit] PWM stop error: {e}")
        try:
            GPIO.cleanup()
            logger.info("🧹 [atexit] GPIO.cleanup() selesai")
        except Exception as e:
            logger.warning(f"[atexit] GPIO.cleanup error: {e}")

    atexit.register(_hardware_cleanup)
    logger.info("✅ Hardware cleanup terdaftar via atexit")

    await ensure_door_camera()
    await ensure_yard_camera()

    loop = asyncio.get_event_loop()
    await loop.run_in_executor(_thread_pool, lambda: engine.face_detector)
    await loop.run_in_executor(_thread_pool, lambda: engine.face_recognizer)
    logger.info("✅ Ready")

    yield  # ← aplikasi berjalan

    # ── SHUTDOWN ──────────────────────────────────────────────────────────────
    logger.info("Server shutting down…")


app = FastAPI(title="Smart Lock Face Recognition API", lifespan=lifespan)

app.mount("/history", StaticFiles(directory=str(HISTORY_DIR)), name="history")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1:8000",
        "http://localhost:8000",
    ],
    allow_origin_regex=r"http://172\.20\.10\.\d+(:\d+)?",
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ─────────────────────────────────────────────────────────────────────────────
# ENCODE HELPERS
# ─────────────────────────────────────────────────────────────────────────────

def _encode_jpeg(frame: np.ndarray, quality: int = 50) -> str:
    h, w = frame.shape[:2]
    if w > 640:
        frame = cv2.resize(frame, (640, 480), interpolation=cv2.INTER_LINEAR)
    _, buf = cv2.imencode(".jpg", frame,
                          [cv2.IMWRITE_JPEG_QUALITY, quality,
                           cv2.IMWRITE_JPEG_OPTIMIZE, 1])
    return base64.b64encode(buf).decode("utf-8")


# ─────────────────────────────────────────────────────────────────────────────
# HISTORY
# ─────────────────────────────────────────────────────────────────────────────

_history_lock = threading.Lock()


def _save_history_entry(name: str, frame: np.ndarray, percentage: float) -> dict:
    ts_iso   = datetime.now().isoformat(timespec="seconds")
    ts_safe  = ts_iso.replace(":", "-")
    filename = f"{ts_safe}_{name.replace(' ', '_')}.webp"
    filepath = HISTORY_DIR / filename
    save_frame = cv2.resize(frame, (640, 480), interpolation=cv2.INTER_LINEAR)
    cv2.imwrite(str(filepath), save_frame, [cv2.IMWRITE_WEBP_QUALITY, 85])
    entry = {
        "name":       name,
        "timestamp":  ts_iso,
        "percentage": round(float(percentage), 1),
        "image":      filename,
    }
    with _history_lock:
        records: list = []
        if HISTORY_JSON.exists():
            try:
                records = json.loads(HISTORY_JSON.read_text())
            except Exception:
                records = []
        records.insert(0, entry)
        records = records[:200]
        HISTORY_JSON.write_text(json.dumps(records, indent=2))
    logger.info(f"📸 History saved: {filename}")
    return entry


def _load_history() -> list:
    with _history_lock:
        if not HISTORY_JSON.exists():
            return []
        try:
            return json.loads(HISTORY_JSON.read_text())
        except Exception:
            return []

# ─────────────────────────────────────────────────────────────────────────────
# RECOGNITION WORKER
# ─────────────────────────────────────────────────────────────────────────────

async def _recognition_worker(
    result_queue: asyncio.Queue,
    stop_event:   asyncio.Event,
    unlock_event: asyncio.Event,
):
    loop      = asyncio.get_event_loop()

    # Cooldown setelah unlock — cegah trigger servo berulang
    UNLOCK_COOLDOWN = 10.0
    last_unlock_at: dict[str, float] = {}

    # Consecutive-match counter: butuh N frame berturut-turut cocok sebelum unlock
    # Mengurangi false positive tanpa menambah latency signifikan
    consecutive: dict[str, int] = {}   # name → hit count
    REQUIRED = CONSECUTIVE_MATCHES_REQUIRED   # dari recognizer.py (default 2)

    while not stop_event.is_set():
        frame = _get_door_frame()
        if frame is None:
            await asyncio.sleep(0.02)
            continue

        # ── Latency measurement: start immediately before recognition ─────────
        # Captures the full pipeline: detection → embedding → cosine match.
        # Stopped just before the GPIO signal is sent (grant or denial).
        recognition_start = time.perf_counter()

        t0 = time.perf_counter()

        face_info, bbox, face_count, quality = await loop.run_in_executor(
            _thread_pool, engine.extract_face_landmarks_and_box, frame
        )

        face_detected    = face_info is not None
        matched          = False
        match_name       = "Unknown"
        similarity       = 0.0
        match_percentage = 0.0

        if face_detected:
            embedding = await loop.run_in_executor(
                _thread_pool, engine.get_embedding, frame, face_info
            )
            if embedding is not None:
                match_name, similarity, match_percentage = await loop.run_in_executor(
                    _thread_pool, engine.match_face, embedding
                )
                matched = similarity >= MATCH_THRESHOLD

        # Reset consecutive counter jika wajah hilang atau berbeda
        if not matched:
            consecutive.clear()
        else:
            consecutive[match_name] = consecutive.get(match_name, 0) + 1
            # Hapus counter nama lain (hanya 1 wajah di frame)
            for k in list(consecutive):
                if k != match_name:
                    del consecutive[k]

        elapsed_ms = (time.perf_counter() - t0) * 1000

        # Cek apakah wajah ini sedang dalam cooldown (baru saja dibuka)
        now = time.monotonic()
        in_cooldown = matched and (now - last_unlock_at.get(match_name, 0) < UNLOCK_COOLDOWN)

        # Unlock hanya jika consecutive hits sudah cukup DAN tidak dalam cooldown
        do_unlock = matched and not in_cooldown and consecutive.get(match_name, 0) >= REQUIRED

        # ── Latency measurement: stop before GPIO signal ───────────────────────
        # For a grant: measured just before trigger_unlock_servo().
        # For a denial (no match): measured here, before result is queued.
        # Both paths complete the full recognition pipeline at this point.
        latency = time.perf_counter() - recognition_start

        result = {
            "type":            "result",
            "face_detected":   face_detected,
            "face_count":      face_count,
            "quality_issue":   quality,
            "bbox":            bbox,
            "matched":         matched,
            "name":            match_name,
            "similarity":      float(similarity),
            "percentage":      float(match_percentage),
            "process_time_ms": round(elapsed_ms, 2),
            "latency_ms":      round(latency * 1000, 2),
            "unlocked":        do_unlock,
        }
        try:
            result_queue.put_nowait(result)
        except asyncio.QueueFull:
            pass

        if do_unlock:
            last_unlock_at[match_name] = now
            consecutive.clear()          # reset setelah trigger
            unlock_event.set()

            # ── Latency: stop point for GRANT — right before servo signal ─────
            # latency already captured above; log it here with full context.
            logger.info(
                f"⏱️  Recognition latency (GRANT): {latency * 1000:.2f} ms "
                f"| name='{match_name}' similarity={similarity:.4f} ({match_percentage:.1f}%)"
            )

            trigger_unlock_servo(match_name)

            entry = await loop.run_in_executor(
                _thread_pool, _save_history_entry, match_name, frame, match_percentage
            )
            final_msg = {
                "type":       "unlocked_final",
                "name":       match_name,
                "percentage": float(match_percentage),
                "timestamp":  entry["timestamp"],
                "image":      entry["image"],
                "latency_ms": round(latency * 1000, 2),
            }
            try:
                result_queue.put_nowait(final_msg)
            except asyncio.QueueFull:
                try:
                    result_queue.get_nowait()
                    result_queue.put_nowait(final_msg)
                except Exception:
                    pass

            logger.info(f"🔓 Access granted: {match_name} ({match_percentage:.1f}%)")

        elif face_detected and not matched:
            # ── Latency: stop point for DENIAL — face seen but not recognised ─
            logger.info(
                f"⏱️  Recognition latency (DENY):  {latency * 1000:.2f} ms "
                f"| name='{match_name}' similarity={similarity:.4f}"
            )

        # Beri napas event loop tanpa skip frame:
        # Jika proses lambat (>80 ms) yield sedikit lebih lama agar tasks lain jalan.
        # Jika cepat, langsung lanjut — tidak ada artificial delay.
        if elapsed_ms < 50:
            await asyncio.sleep(0)          # yield satu tick, segera kembali
        elif elapsed_ms < 150:
            await asyncio.sleep(0.005)
        else:
            await asyncio.sleep(0.02)

# ─────────────────────────────────────────────────────────────────────────────
# CAMERA MANAGEMENT API
# ─────────────────────────────────────────────────────────────────────────────

def _list_cameras_sync() -> list:
    """
    Enumerate hanya USB capture node yang bisa dipakai OpenCV.
    Node Pi ISP (video10+, bcm2835-isp, unicam) dilewati otomatis
    oleh _is_capture_node sehingga tidak ada timeout warning.
    """
    import glob
    # Suppress OpenCV V4L2 warning saat probe kamera
    prev_log = cv2.getLogLevel()
    cv2.setLogLevel(0)   # 0 = silent
    result = []
    try:
        for node in sorted(glob.glob("/dev/video*")):
            try:
                idx = int(node.replace("/dev/video", ""))
            except ValueError:
                continue

            # _is_capture_node sudah filter node ISP/metadata — tidak ada timeout
            if not _is_capture_node(node):
                continue

            entry = {"id": idx, "node": node, "name": f"USB Camera {idx}",
                     "available": False, "resolution": "—"}

            cap = cv2.VideoCapture(node, cv2.CAP_V4L2)
            if cap.isOpened():
                ret, _ = cap.read()
                if ret:
                    entry["available"]  = True
                    entry["resolution"] = (
                        f"{int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))}"
                        f"x{int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))}"
                    )
                cap.release()

            result.append(entry)
    finally:
        cv2.setLogLevel(prev_log)   # kembalikan log level ke semula
    return result


@app.get("/api/cameras")
async def list_cameras():
    cameras = await asyncio.get_event_loop().run_in_executor(_thread_pool, _list_cameras_sync)
    return {"cameras": cameras, "door_cam_id": _door_cam_id, "yard_cam_id": _yard_cam_id}


@app.post("/api/cameras/probe")
async def probe_cameras():
    cameras = await asyncio.get_event_loop().run_in_executor(_thread_pool, _list_cameras_sync)
    return {"cameras": cameras, "door_cam_id": _door_cam_id, "yard_cam_id": _yard_cam_id}


@app.post("/api/cameras/door/{camera_id}")
async def select_door_camera(camera_id: int):
    global _door_cam_id
    _door_running.clear()
    await asyncio.sleep(0.3)
    _door_cam_id = camera_id
    alive = await ensure_door_camera()
    if not alive:
        return {"status": "error", "message": f"Kamera {camera_id} tidak bisa dibuka", "alive": False}
    return {"status": "success", "message": f"Kamera pintu → /dev/video{camera_id}",
            "camera_id": camera_id, "alive": True}


@app.post("/api/cameras/yard/{camera_id}")
async def select_yard_camera(camera_id: int):
    global _yard_cam_id
    _yard_running.clear()
    await asyncio.sleep(0.3)
    _yard_cam_id = camera_id
    alive = await ensure_yard_camera()
    if not alive:
        return {"status": "error", "message": f"Kamera {camera_id} tidak bisa dibuka", "alive": False}
    return {"status": "success", "message": f"Kamera CCTV → /dev/video{camera_id}",
            "camera_id": camera_id, "alive": True}


@app.post("/api/cameras/select/{camera_id}")
async def select_camera_legacy(camera_id: int):
    return await select_door_camera(camera_id)


@app.get("/api/users")
async def get_users():
    """
    Kembalikan daftar nama pengguna terdaftar dari in-memory database.
    Setelah enroll selesai, engine sudah langsung update _db_matrix —
    tidak perlu restart atau reload apapun.
    """
    return {"users": engine.get_users_list()}


@app.get("/api/users/detail")
async def get_users_detail():
    """
    Detail lengkap semua profil wajah terdaftar dari database.json Pi.
    Laravel bisa fetch ini langsung — tidak perlu copy file JSON ke mana-mana.
    """
    import glob
    with engine.db_lock:
        names = list(engine.database.keys())

    result = []
    for i, name in enumerate(names):
        result.append({
            "id":    i + 1,
            "name":  name,
            "node":  "database.json",
        })
    return {
        "profiles": result,
        "total":    len(result),
        "source":   "raspberry_pi_local",
    }


@app.delete("/api/users/{name}")
async def delete_user(name: str):
    success = engine.delete_user(name)
    if success:
        return {"status": "success", "message": f"User '{name}' deleted."}
    raise HTTPException(status_code=404, detail=f"User '{name}' not found.")


@app.get("/api/history")
async def get_history(limit: int = 50):
    entries = _load_history()
    return {"history": entries[:limit], "total": len(entries)}


@app.delete("/api/history")
async def clear_history():
    with _history_lock:
        if HISTORY_JSON.exists():
            HISTORY_JSON.unlink()
        for f in HISTORY_DIR.glob("*.webp"):
            f.unlink(missing_ok=True)
    return {"status": "success", "message": "History cleared"}

# ─────────────────────────────────────────────────────────────────────────────
# SERVO LEGACY CONTROL  — aktif saat server sudah running
#
#  Endpoint ini bisa dipanggil dari terminal, curl, atau browser
#  tanpa perlu mematikan server utama:
#
#  curl -X POST http://localhost:5001/api/servo/open
#  curl -X POST http://localhost:5001/api/servo/lock
#  curl -X POST http://localhost:5001/api/servo/move/90
#  curl      http://localhost:5001/api/servo/status
# ─────────────────────────────────────────────────────────────────────────────

@app.get("/api/servo/status")
async def servo_status():
    """Kembalikan konfigurasi servo dan state saat ini."""
    with _servo_lock:
        pwm_active = _servo_pwm is not None
    return {
        "gpio_available": GPIO_AVAILABLE,
        "pwm_active":     pwm_active,
        "pin":            PIN_SERVO,
        "freq_hz":        SERVO_FREQ,
        "lock_angle":     LOCK_ANGLE(),
        "open_angle":     OPEN_ANGLE(),
        "open_time_s":    OPEN_TIME(),
        "min_dc":         _servo_cfg["min_dc"],
        "max_dc":         _servo_cfg["max_dc"],
        "formula":        "duty = min_dc + (angle / 180) * (max_dc - min_dc)",
        "duty_lock":      round(_angle_to_duty(LOCK_ANGLE()), 4),
        "duty_open":      round(_angle_to_duty(OPEN_ANGLE()), 4),
        "config_file":    str(_SERVO_CONFIG_FILE),
    }


@app.post("/api/servo/open")
async def servo_open():
    """Buka servo ke posisi OPEN_ANGLE, tahan OPEN_TIME detik, lalu kunci kembali."""
    loop = asyncio.get_event_loop()
    await loop.run_in_executor(_thread_pool, _servo_unlock_sequence, "manual-open")
    return {
        "status":  "triggered",
        "action":  "open",
        "angle":   OPEN_ANGLE(),
        "hold_s":  OPEN_TIME(),
        "message": f"Servo → {OPEN_ANGLE()}°, akan kembali ke {LOCK_ANGLE()}° setelah {OPEN_TIME()}s",
    }


@app.post("/api/servo/lock")
async def servo_lock():
    """Paksa servo ke posisi LOCK_ANGLE sekarang juga."""
    loop = asyncio.get_event_loop()

    def _force_lock():
        _set_servo_angle(LOCK_ANGLE())
        try:
            GPIO.output(PIN_LED_MERAH, GPIO.HIGH)
            GPIO.output(PIN_LED_HIJAU, GPIO.LOW)
        except Exception:
            pass
        logger.info(f"🔒 [API] Servo force-lock → {LOCK_ANGLE()}°")

    await loop.run_in_executor(_thread_pool, _force_lock)
    return {
        "status":  "ok",
        "action":  "lock",
        "angle":   LOCK_ANGLE(),
        "message": f"Servo dikunci ke {LOCK_ANGLE()}°",
    }


@app.post("/api/servo/move/{angle}")
async def servo_move(angle: float):
    """
    Gerakkan servo ke sudut bebas (0–180°).
    Berguna untuk kalibrasi saat server sedang berjalan.

    Contoh:
        curl -X POST http://pi:5001/api/servo/move/45
        curl -X POST http://pi:5001/api/servo/move/90
    """
    if not (0 <= angle <= 180):
        raise HTTPException(status_code=400, detail="Sudut harus antara 0 dan 180")

    loop = asyncio.get_event_loop()
    dc   = _angle_to_duty(angle)

    def _move():
        with _servo_lock:
            if _servo_pwm is not None:
                _servo_pwm.ChangeDutyCycle(dc)
        time.sleep(0.5)
        with _servo_lock:
            if _servo_pwm is not None:
                _servo_pwm.ChangeDutyCycle(0)
        logger.info(f"🔧 [API] Servo → {angle}° (dc={dc:.4f}%)")

    await loop.run_in_executor(_thread_pool, _move)
    return {
        "status":     "ok",
        "angle":      angle,
        "duty_cycle": round(dc, 4),
        "message":    f"Servo digerakkan ke {angle}° (duty={dc:.4f}%)",
    }


@app.post("/api/servo/cycle")
async def servo_cycle():
    """
    Jalankan siklus LOCK → OPEN → LOCK satu kali untuk test.
    Tidak mengaktifkan LED/buzzer — murni test mekanis.
    """
    loop = asyncio.get_event_loop()

    def _cycle():
        logger.info("🔧 [API] Servo test cycle dimulai")
        _set_servo_angle(LOCK_ANGLE());  time.sleep(0.3)
        _set_servo_angle(OPEN_ANGLE());  time.sleep(2.0)
        _set_servo_angle(LOCK_ANGLE())
        logger.info("🔧 [API] Servo test cycle selesai")

    await loop.run_in_executor(_thread_pool, _cycle)
    return {
        "status":  "ok",
        "action":  "cycle",
        "message": f"Siklus {LOCK_ANGLE()}° → {OPEN_ANGLE()}° → {LOCK_ANGLE()}° selesai",
    }


@app.post("/api/servo/buzzer/{state}")
async def servo_buzzer(state: str):
    """
    Aktifkan atau matikan buzzer manual.
    state: 'on' atau 'off'

    Contoh:
        curl -X POST http://pi:5001/api/servo/buzzer/on
        curl -X POST http://pi:5001/api/servo/buzzer/off
    """
    if state not in ("on", "off"):
        raise HTTPException(status_code=400, detail="State harus 'on' atau 'off'")
    loop = asyncio.get_event_loop()
    gpio_state = GPIO.HIGH if state == "on" else GPIO.LOW

    def _buzz():
        try:
            GPIO.output(PIN_BUZZER, gpio_state)
            logger.info(f"🔔 [API] Buzzer → {state}")
        except Exception as e:
            logger.warning(f"Buzzer error: {e}")

    await loop.run_in_executor(_thread_pool, _buzz)
    return {"status": "ok", "buzzer": state}


@app.post("/api/servo/led/{color}/{state}")
async def servo_led(color: str, state: str):
    """
    Kontrol LED manual.
    color: 'green' atau 'red'
    state: 'on'   atau 'off'

    Contoh:
        curl -X POST http://pi:5001/api/servo/led/green/on
        curl -X POST http://pi:5001/api/servo/led/red/off
    """
    if color not in ("green", "red"):
        raise HTTPException(status_code=400, detail="Color harus 'green' atau 'red'")
    if state not in ("on", "off"):
        raise HTTPException(status_code=400, detail="State harus 'on' atau 'off'")

    pin        = PIN_LED_HIJAU if color == "green" else PIN_LED_MERAH
    gpio_state = GPIO.HIGH if state == "on" else GPIO.LOW
    loop       = asyncio.get_event_loop()

    def _led():
        try:
            GPIO.output(pin, gpio_state)
            logger.info(f"💡 [API] LED {color} GPIO{pin} → {state}")
        except Exception as e:
            logger.warning(f"LED error: {e}")

    await loop.run_in_executor(_thread_pool, _led)
    return {"status": "ok", "led": color, "state": state, "pin": pin}


@app.post("/api/servo/config")
async def update_servo_config(body: dict):
    """
    Update konfigurasi servo secara live — tanpa restart server.
    Dipanggil otomatis oleh servo_calibration.py saat menyimpan config.

    Payload (semua opsional):
        {
            "lock_angle": 0,
            "open_angle": 90,
            "open_time":  5,
            "min_dc":     2.5,
            "max_dc":     12.5
        }

    Contoh dari terminal:
        curl -X POST http://pi:5001/api/servo/config \\
             -H "Content-Type: application/json" \\
             -d '{"lock_angle": 5, "open_angle": 85}'
    """
    allowed  = {"lock_angle", "open_angle", "open_time", "min_dc", "max_dc"}
    updated  = {}

    for key in allowed:
        if key in body:
            try:
                val = float(body[key])
            except (TypeError, ValueError):
                raise HTTPException(status_code=400, detail=f"Nilai '{key}' harus angka")

            # Validasi range
            if key == "lock_angle" and not (0 <= val <= 180):
                raise HTTPException(status_code=400, detail="lock_angle harus 0–180")
            if key == "open_angle" and not (0 <= val <= 180):
                raise HTTPException(status_code=400, detail="open_angle harus 0–180")
            if key == "open_time" and not (1 <= val <= 60):
                raise HTTPException(status_code=400, detail="open_time harus 1–60 detik")
            if key == "min_dc" and not (0 <= val <= 5):
                raise HTTPException(status_code=400, detail="min_dc harus 0–5")
            if key == "max_dc" and not (5 <= val <= 20):
                raise HTTPException(status_code=400, detail="max_dc harus 5–20")

            _servo_cfg[key] = val
            updated[key]    = val

    if not updated:
        raise HTTPException(status_code=400, detail="Tidak ada field valid yang diterima")

    # Simpan ke file agar persisten setelah restart
    try:
        with open(_SERVO_CONFIG_FILE, "w") as f:
            json.dump(_servo_cfg, f, indent=2)
        logger.info(f"💾 servo_config.json diupdate: {updated}")
    except Exception as e:
        logger.warning(f"Gagal simpan servo_config.json: {e}")

    # Gerakkan servo ke posisi LOCK terbaru agar langsung terasa
    if "lock_angle" in updated or "min_dc" in updated or "max_dc" in updated:
        loop = asyncio.get_event_loop()
        await loop.run_in_executor(_thread_pool, _set_servo_angle, LOCK_ANGLE())
        logger.info(f"🔧 Servo langsung diposisikan ke LOCK={LOCK_ANGLE()}° setelah update config")

    return {
        "status":  "ok",
        "updated": updated,
        "current": {
            "lock_angle": LOCK_ANGLE(),
            "open_angle": OPEN_ANGLE(),
            "open_time":  OPEN_TIME(),
            "min_dc":     _servo_cfg["min_dc"],
            "max_dc":     _servo_cfg["max_dc"],
            "duty_lock":  round(_angle_to_duty(LOCK_ANGLE()), 4),
            "duty_open":  round(_angle_to_duty(OPEN_ANGLE()), 4),
        },
    }


@app.websocket("/ws")
async def websocket_recognition(websocket: WebSocket):
    await websocket.accept()
    logger.info("Recognition WS connected")

    if not await ensure_door_camera():
        await websocket.send_json({"type": "error", "message": "Kamera pintu tidak tersedia"})
        await websocket.close(); return

    loop         = asyncio.get_event_loop()
    stop_event   = asyncio.Event()
    unlock_event = asyncio.Event()
    result_queue: asyncio.Queue = asyncio.Queue(maxsize=4)

    async def frame_sender():
        INTERVAL = 1 / 20
        last = 0.0
        while not stop_event.is_set():
            try:
                now = time.monotonic()
                if now - last < INTERVAL:
                    await asyncio.sleep(0.005); continue
                frame = _get_door_frame()
                if frame is None:
                    await asyncio.sleep(0.02); continue
                b64 = await loop.run_in_executor(_thread_pool, _encode_jpeg, frame, 50)
                await websocket.send_json({"type": "frame", "image": b64})
                last = time.monotonic()
            except Exception:
                # Koneksi tutup — keluar tanpa log noise
                break

    async def result_sender():
        while not stop_event.is_set():
            try:
                result = await asyncio.wait_for(result_queue.get(), timeout=1.0)
                await websocket.send_json(result)
                # TIDAK stop setelah unlocked_final — biarkan stream terus berjalan
                # Frontend menampilkan overlay sementara lalu lanjut scanning sendiri
            except asyncio.TimeoutError:
                continue
            except Exception:
                # Koneksi tutup — keluar tanpa log noise
                break

    async def keepalive():
        while not stop_event.is_set():
            await asyncio.sleep(10)
            try:
                await websocket.send_json({"type": "ping"})
            except Exception:
                break

    tasks = [
        asyncio.create_task(frame_sender()),
        asyncio.create_task(result_sender()),
        asyncio.create_task(keepalive()),
        asyncio.create_task(_recognition_worker(result_queue, stop_event, unlock_event)),
    ]
    try:
        while not stop_event.is_set():
            try:
                msg = await asyncio.wait_for(websocket.receive_json(), timeout=1.0)
                if msg.get("type") == "pong":
                    continue
            except asyncio.TimeoutError:
                continue
            except (WebSocketDisconnect, RuntimeError):
                # Browser tutup koneksi atau WS belum/sudah closed — keluar normal
                break
    except WebSocketDisconnect:
        pass
    except Exception as e:
        # Hanya log error yang benar-benar tidak terduga
        if "accept" not in str(e).lower() and "close" not in str(e).lower():
            logger.error(f"Recognition WS error: {e}", exc_info=True)
    finally:
        stop_event.set()
        for t in tasks:
            t.cancel()
        logger.info("Recognition WS disconnected")

# ─────────────────────────────────────────────────────────────────────────────
# WEBSOCKET — Enrollment  (/ws/enroll)
# ─────────────────────────────────────────────────────────────────────────────

@app.websocket("/ws/enroll")
async def websocket_enrollment(websocket: WebSocket):
    await websocket.accept()
    logger.info("Enrollment WS connected")

    if not await ensure_door_camera():
        await websocket.send_json({"type": "error", "message": "Kamera pintu tidak tersedia"})
        await websocket.close(); return

    loop           = asyncio.get_event_loop()
    stop_event     = asyncio.Event()
    reg_name       = ""
    reg_embeddings: list = []
    is_scanning    = False

    async def frame_sender():
        INTERVAL = 1 / 15
        last = 0.0
        while not stop_event.is_set():
            try:
                now = time.monotonic()
                if now - last < INTERVAL:
                    await asyncio.sleep(0.005); continue
                frame = _get_door_frame()
                if frame is None:
                    await asyncio.sleep(0.02); continue
                b64 = await loop.run_in_executor(_thread_pool, _encode_jpeg, frame, 55)
                await websocket.send_json({"type": "frame", "image": b64})
                last = time.monotonic()
            except Exception as e:
                logger.error(f"enroll frame_sender: {e}"); break

    frame_task = asyncio.create_task(frame_sender())

    try:
        while True:
            data     = await websocket.receive_json()
            msg_type = data.get("type")

            if msg_type == "register_start":
                reg_name = data.get("name", "").strip()
                if not reg_name:
                    await websocket.send_json({"type": "register_error",
                                               "message": "Name cannot be empty."}); continue
                is_scanning = True; reg_embeddings = []
                await websocket.send_json({"type": "status",
                                           "message": f"Scanning '{reg_name}'… look at the camera."})

            elif msg_type == "register_cancel":
                is_scanning = False; reg_embeddings = []
                await websocket.send_json({"type": "status", "message": "Registration cancelled."})

            elif msg_type == "scan":
                frame = _get_door_frame()
                if frame is None: continue

                face_info, bbox, face_count, quality = await loop.run_in_executor(
                    _thread_pool, engine.extract_face_landmarks_and_box, frame
                )
                await websocket.send_json({"type": "preview", "bbox": bbox,
                                           "face_count": face_count, "quality_issue": quality})

                warn_map = {
                    "multiple_faces": "Multiple faces — one person only.",
                    "too_small":      "Move closer.",
                    "too_close":      "Move back a bit.",
                }
                if face_info is None and is_scanning:
                    msg = warn_map.get(quality, "No face detected — look at the camera.")
                    await websocket.send_json({"type": "warn", "message": msg}); continue
                if not is_scanning or face_info is None: continue
                if quality in warn_map:
                    await websocket.send_json({"type": "warn",
                                               "message": warn_map[quality]}); continue

                embedding = await loop.run_in_executor(
                    _thread_pool, engine.get_embedding, frame, face_info
                )
                if embedding is None: continue

                # ── CEK WAJAH SUDAH TERDAFTAR (per-frame, sebelum dikumpulkan) ──
                # Pengecekan ini berjalan setiap frame agar pengguna langsung
                # mendapat feedback tanpa menunggu semua frame selesai.
                exists, existing_name, score = await loop.run_in_executor(
                    _thread_pool, engine.check_face_exists, embedding
                )
                if exists:
                    is_scanning    = False
                    reg_embeddings = []
                    pct = engine._score_to_pct(score)
                    logger.warning(
                        f"❌ [ENROLL] Wajah '{reg_name}' diblokir — "
                        f"sudah terdaftar sebagai '{existing_name}' (score={score:.3f})"
                    )
                    await websocket.send_json({
                        "type":    "register_error",
                        "message": (
                            f"Wajah ini sudah terdaftar sebagai '{existing_name}' "
                            f"({round(pct, 1)}% kemiripan). "
                            f"1 wajah hanya boleh untuk 1 akun."
                        ),
                    })
                    continue
                # ─────────────────────────────────────────────────────────────────

                reg_embeddings.append(embedding)
                progress = int(len(reg_embeddings) * 100 / REGISTRATION_FRAMES_REQUIRED)
                await websocket.send_json({
                    "type": "register_progress", "progress": progress,
                    "count": len(reg_embeddings), "total": REGISTRATION_FRAMES_REQUIRED,
                    "message": f"Capturing biometric data… {progress}%",
                })

                if len(reg_embeddings) >= REGISTRATION_FRAMES_REQUIRED:
                    mean_emb = np.mean(reg_embeddings, axis=0).reshape(1, -1)
                    success, reason = engine.register_user(reg_name, mean_emb)
                    is_scanning = False; reg_embeddings = []
                    if success:
                        await websocket.send_json({"type": "register_success", "name": reg_name})
                    elif reason == "name_taken":
                        await websocket.send_json({"type": "register_error",
                            "message": f"Nama '{reg_name}' sudah terdaftar."})
                    elif reason.startswith("face_exists:"):
                        existing = reason.split(":", 1)[1]
                        await websocket.send_json({"type": "register_error",
                            "message": f"Wajah ini sudah terdaftar sebagai '{existing}'. "
                                       f"1 wajah hanya boleh untuk 1 akun."})
                    elif reason.startswith("duplicate:"):
                        dup = reason.split(":", 1)[1]
                        await websocket.send_json({"type": "register_error",
                            "message": f"Wajah terlalu mirip dengan profil '{dup}'."})
                    else:
                        await websocket.send_json({"type": "register_error",
                            "message": f"Pendaftaran gagal ({reason})."})
    except WebSocketDisconnect:
        pass
    except Exception as e:
        if "accept" not in str(e).lower() and "close" not in str(e).lower():
            logger.error(f"Enrollment WS error: {e}", exc_info=True)
    finally:
        stop_event.set(); frame_task.cancel()
        logger.info("Enrollment WS disconnected")

# ─────────────────────────────────────────────────────────────────────────────
# WEBSOCKET — Motion Detection CCTV  (/ws/motion)
# ─────────────────────────────────────────────────────────────────────────────

@app.websocket("/ws/motion")
async def websocket_motion(websocket: WebSocket):
    await websocket.accept()
    logger.info("Motion WS connected")

    if not await ensure_yard_camera():
        await websocket.send_json({"type": "error", "message": "Kamera CCTV tidak tersedia"})
        await websocket.close(); return

    loop       = asyncio.get_event_loop()
    stop_event = asyncio.Event()

    MOTION_THRESHOLD = 0.02   # 2% pixel berubah = ada gerakan
    prev_gray: np.ndarray | None = None

    def _detect_motion(frame: np.ndarray):
        nonlocal prev_gray
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (11, 11), 0)
        if prev_gray is None:
            prev_gray = gray
            return False, 0.0, frame
        diff  = cv2.absdiff(prev_gray, gray)
        prev_gray = gray
        _, thresh = cv2.threshold(diff, 25, 255, cv2.THRESH_BINARY)
        ratio = float(np.count_nonzero(thresh)) / thresh.size
        annotated = frame.copy()
        if ratio >= MOTION_THRESHOLD:
            contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL,
                                           cv2.CHAIN_APPROX_SIMPLE)
            for cnt in contours:
                if cv2.contourArea(cnt) < 500:
                    continue
                x, y, w, h = cv2.boundingRect(cnt)
                cv2.rectangle(annotated, (x, y), (x + w, y + h), (0, 0, 255), 2)
        return ratio >= MOTION_THRESHOLD, ratio, annotated

    async def motion_sender():
        INTERVAL = 1 / 15
        last = 0.0
        while not stop_event.is_set():
            try:
                now = time.monotonic()
                if now - last < INTERVAL:
                    await asyncio.sleep(0.005); continue
                frame = _get_yard_frame()
                if frame is None:
                    await asyncio.sleep(0.02); continue
                motion, ratio, annotated = await loop.run_in_executor(
                    _thread_pool, _detect_motion, frame
                )
                b64 = await loop.run_in_executor(_thread_pool, _encode_jpeg, annotated, 55)
                await websocket.send_json({
                    "type":         "frame",
                    "image":        b64,
                    "motion":       motion,
                    "motion_ratio": round(ratio * 100, 2),
                })
                last = time.monotonic()
            except Exception:
                break

    async def keepalive():
        while not stop_event.is_set():
            await asyncio.sleep(10)
            try:
                await websocket.send_json({"type": "ping"})
            except Exception:
                break

    tasks = [
        asyncio.create_task(motion_sender()),
        asyncio.create_task(keepalive()),
    ]
    try:
        while not stop_event.is_set():
            try:
                msg = await asyncio.wait_for(websocket.receive_json(), timeout=1.0)
                if msg.get("type") == "pong":
                    continue
            except asyncio.TimeoutError:
                continue
            except (WebSocketDisconnect, RuntimeError):
                break
    except WebSocketDisconnect:
        pass
    except Exception:
        pass
    finally:
        stop_event.set()
        for t in tasks:
            t.cancel()
        logger.info("Motion WS disconnected")


# ─────────────────────────────────────────────────────────────────────────────
# ENTRYPOINT
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    print("=" * 55)
    print("  Smart Lock API — http://0.0.0.0:5001")
    print("=" * 55)
    uvicorn.run(
        "app:app",
        host="0.0.0.0",
        port=5001,
        log_level="info",
        # Matikan ping internal uvicorn — kita handle keepalive sendiri
        # Ini menghilangkan "keepalive ping failed / AssertionError"
        ws_ping_interval=None,
        ws_ping_timeout=None,
        # Izinkan frame video besar (max 8MB per pesan WS)
        ws_max_size=8 * 1024 * 1024,
    )
