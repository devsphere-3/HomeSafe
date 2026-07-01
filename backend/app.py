"""
Smart Lock – Face Recognition API
Optimized for Raspberry Pi: dedicated camera thread + async pipeline

One-shot unlock logic:
  - Recognition runs continuously until 1 confirmed match is found
  - On match: save a WebP snapshot to history/, stop recognition, send 'unlocked_final'
  - Camera keeps streaming (for the access-granted visual), then frontend closes WS
"""

import os
import json
import time
import base64
import asyncio
import logging
import threading
import cv2
import numpy as np
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
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

# ── History directory ─────────────────────────────────────────────────────────
HISTORY_DIR  = Path("history")
HISTORY_JSON = Path("history.json")
HISTORY_DIR.mkdir(exist_ok=True)

app = FastAPI(title="Smart Lock Face Recognition API")

# Serve WebP snapshots as static files so frontend can load them by URL
app.mount("/history", StaticFiles(directory=str(HISTORY_DIR)), name="history")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1:8000",
        "http://localhost:8000",
    ],
    allow_origin_regex=r"http://192\.168\.\d+\.\d+(:\d+)?",
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

engine = FaceRecognizerEngine()

# ── Thread pool ────────────────────────────────────────────────────────────────
_thread_pool = ThreadPoolExecutor(max_workers=4)

# ─────────────────────────────────────────────────────────────────────────────
# DEDICATED CAMERA THREAD
# ─────────────────────────────────────────────────────────────────────────────

STREAM_W   = 320
STREAM_H   = 240
CAMERA_FPS = 30

_latest_frame: np.ndarray | None = None
_latest_frame_ts: float = 0.0
_frame_mutex   = threading.Lock()
_cap_mutex     = threading.Lock()
_cap: cv2.VideoCapture | None = None
_current_cam_id: int = 0
_camera_running = threading.Event()


def _open_cap(cam_id: int) -> cv2.VideoCapture | None:
    for backend in [cv2.CAP_V4L2, cv2.CAP_ANY]:
        cap = cv2.VideoCapture(cam_id, backend)
        if not cap.isOpened():
            cap.release()
            continue
        cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*"MJPG"))
        cap.set(cv2.CAP_PROP_FRAME_WIDTH,  STREAM_W)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, STREAM_H)
        cap.set(cv2.CAP_PROP_FPS, CAMERA_FPS)
        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        ok = False
        for _ in range(6):
            ret, frame = cap.read()
            if ret and frame is not None:
                ok = True
                break
        if not ok:
            cap.release()
            continue
        actual_w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        actual_h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        logger.info(f"✅ Camera {cam_id} ready — {actual_w}x{actual_h} @ {CAMERA_FPS}FPS")
        return cap
    logger.error(f"❌ Cannot open camera {cam_id}")
    return None


def _camera_thread_fn():
    global _cap, _latest_frame, _latest_frame_ts
    while True:
        _camera_running.wait()
        with _cap_mutex:
            cap = _open_cap(_current_cam_id)
            _cap = cap
        if cap is None:
            logger.warning("Camera thread: could not open camera, retrying in 2 s")
            time.sleep(2)
            continue
        logger.info("Camera thread: started capturing")
        consecutive_fail = 0
        while _camera_running.is_set():
            ret, frame = cap.read()
            if not ret or frame is None:
                consecutive_fail += 1
                if consecutive_fail > 10:
                    logger.warning("Camera thread: too many failures, reopening")
                    break
                time.sleep(0.01)
                continue
            consecutive_fail = 0
            with _frame_mutex:
                _latest_frame    = frame
                _latest_frame_ts = time.monotonic()
        cap.release()
        with _cap_mutex:
            _cap = None
        logger.info("Camera thread: stopped")


_cam_thread = threading.Thread(target=_camera_thread_fn, daemon=True)
_cam_thread.start()


def _get_latest_frame() -> np.ndarray | None:
    with _frame_mutex:
        return None if _latest_frame is None else _latest_frame.copy()


def _wait_for_frame(timeout: float = 3.0) -> np.ndarray | None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        f = _get_latest_frame()
        if f is not None:
            return f
        time.sleep(0.01)
    return None


async def ensure_camera() -> bool:
    _camera_running.set()
    loop = asyncio.get_event_loop()
    frame = await loop.run_in_executor(_thread_pool, _wait_for_frame, 3.0)
    return frame is not None


async def release_camera_async():
    _camera_running.clear()
    await asyncio.sleep(0.2)


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
# HISTORY  (saved on the Pi, served as static files)
# ─────────────────────────────────────────────────────────────────────────────

_history_lock = threading.Lock()


def _save_history_entry(name: str, frame: np.ndarray, percentage: float) -> dict:
    """
    Save a WebP snapshot and append an entry to history.json.
    Returns the new entry dict.
    """
    ts_iso  = datetime.now().isoformat(timespec="seconds")
    ts_safe = ts_iso.replace(":", "-")
    filename = f"{ts_safe}_{name.replace(' ', '_')}.webp"
    filepath = HISTORY_DIR / filename

    # Upscale tiny stream frame to a more usable resolution before saving
    save_frame = cv2.resize(frame, (640, 480), interpolation=cv2.INTER_LINEAR)
    cv2.imwrite(str(filepath), save_frame,
                [cv2.IMWRITE_WEBP_QUALITY, 85])

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
        records.insert(0, entry)          # newest first
        records = records[:200]           # keep at most 200 entries
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
# RECOGNITION WORKER  (one-shot: stops after first confirmed match)
# ─────────────────────────────────────────────────────────────────────────────

async def _recognition_worker(
    result_queue: asyncio.Queue,
    stop_event:   asyncio.Event,
    unlock_event: asyncio.Event,   # set when a match is confirmed
):
    """
    Runs detection+recognition continuously.

    State machine
    -------------
    SCANNING  →  face detected, recognised, similarity ≥ MATCH_THRESHOLD
              →  save history, set unlock_event, push 'unlocked_final', stop.

    'result' messages are pushed continuously for the live bbox/FPS display.
    Once unlock_event is set the worker exits — the frame sender keeps streaming
    for a few more seconds so the front-end can show the ACCESS GRANTED animation.
    """
    loop = asyncio.get_event_loop()
    skip_next = False

    while not stop_event.is_set() and not unlock_event.is_set():
        if skip_next:
            await asyncio.sleep(0.05)
            skip_next = False
            continue

        frame = _get_latest_frame()
        if frame is None:
            await asyncio.sleep(0.05)
            continue

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

        elapsed_ms = (time.perf_counter() - t0) * 1000

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
            "unlocked":        matched,
        }

        try:
            result_queue.put_nowait(result)
        except asyncio.QueueFull:
            pass

        # ── ONE-SHOT UNLOCK ──────────────────────────────────────────────────
        if matched:
            unlock_event.set()

            # Save history snapshot off-loop
            entry = await loop.run_in_executor(
                _thread_pool, _save_history_entry, match_name, frame, match_percentage
            )

            # Send the definitive unlock message
            final_msg = {
                "type":       "unlocked_final",
                "name":       match_name,
                "percentage": float(match_percentage),
                "timestamp":  entry["timestamp"],
                "image":      entry["image"],
            }
            try:
                result_queue.put_nowait(final_msg)
            except asyncio.QueueFull:
                # Drain one old result to make room
                try:
                    result_queue.get_nowait()
                    result_queue.put_nowait(final_msg)
                except Exception:
                    pass

            logger.info(f"🔓 Access granted: {match_name} ({match_percentage:.1f}%)")
            return   # worker exits; frame sender keeps running

        if elapsed_ms > 200:
            skip_next = True
        else:
            await asyncio.sleep(0.01)


# ─────────────────────────────────────────────────────────────────────────────
# CAMERA MANAGEMENT REST API
# ─────────────────────────────────────────────────────────────────────────────

def _list_cameras_sync() -> list:
    import glob
    nodes = sorted(glob.glob("/dev/video*")) or [str(i) for i in range(4)]
    available = []
    for node in nodes:
        try:
            idx = int(node.replace("/dev/video", "")) if "/" in node else int(node)
        except ValueError:
            continue
        cap = cv2.VideoCapture(idx, cv2.CAP_V4L2)
        if not cap.isOpened():
            cap.release()
            continue
        ret, _ = cap.read()
        if ret:
            available.append({
                "id":         idx,
                "name":       f"USB Camera {idx}",
                "resolution": (
                    f"{int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))}"
                    f"x{int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))}"
                ),
            })
        cap.release()
    return available


@app.get("/api/cameras")
async def list_cameras():
    loop = asyncio.get_event_loop()
    cameras = await loop.run_in_executor(_thread_pool, _list_cameras_sync)
    return {"cameras": cameras, "current": _current_cam_id}


@app.post("/api/cameras/select/{camera_id}")
async def select_camera(camera_id: int):
    global _current_cam_id
    await release_camera_async()
    _current_cam_id = camera_id
    ready = await ensure_camera()
    if not ready:
        return {"status": "error", "message": f"Failed to open camera {camera_id}"}
    return {"status": "success", "message": f"Camera {camera_id} selected",
            "camera_id": camera_id}


@app.get("/api/users")
async def get_users():
    return {"users": engine.get_users_list()}


@app.delete("/api/users/{name}")
async def delete_user(name: str):
    success = engine.delete_user(name)
    if success:
        return {"status": "success", "message": f"User '{name}' deleted."}
    raise HTTPException(status_code=404, detail=f"User '{name}' not found.")


@app.get("/api/history")
async def get_history(limit: int = 50):
    """Return the last N access history entries."""
    entries = _load_history()
    return {"history": entries[:limit], "total": len(entries)}


@app.delete("/api/history")
async def clear_history():
    """Delete all history entries and image files."""
    with _history_lock:
        if HISTORY_JSON.exists():
            HISTORY_JSON.unlink()
        for f in HISTORY_DIR.glob("*.webp"):
            f.unlink(missing_ok=True)
    return {"status": "success", "message": "History cleared"}


# ─────────────────────────────────────────────────────────────────────────────
# RECOGNITION WEBSOCKET
# ─────────────────────────────────────────────────────────────────────────────

@app.websocket("/ws")
async def websocket_recognition(websocket: WebSocket):
    await websocket.accept()
    logger.info("Recognition WS connected")

    ready = await ensure_camera()
    if not ready:
        await websocket.send_json({"type": "error",
                                   "message": "Camera not available on server"})
        await websocket.close()
        return

    loop        = asyncio.get_event_loop()
    stop_event  = asyncio.Event()
    unlock_event = asyncio.Event()
    result_queue: asyncio.Queue = asyncio.Queue(maxsize=4)

    # ── Frame sender ──────────────────────────────────────────────────────────
    async def frame_sender():
        FRAME_INTERVAL = 1 / 20   # 20 FPS cap
        last_ts = 0.0
        while not stop_event.is_set():
            try:
                now = time.monotonic()
                if now - last_ts < FRAME_INTERVAL:
                    await asyncio.sleep(0.005)
                    continue
                frame = _get_latest_frame()
                if frame is None:
                    await asyncio.sleep(0.02)
                    continue
                b64 = await loop.run_in_executor(_thread_pool, _encode_jpeg, frame, 50)
                await websocket.send_json({"type": "frame", "image": b64})
                last_ts = time.monotonic()
            except Exception as e:
                logger.error(f"frame_sender error: {e}")
                break

    # ── Result sender ─────────────────────────────────────────────────────────
    async def result_sender():
        while not stop_event.is_set():
            try:
                result = await asyncio.wait_for(result_queue.get(), timeout=1.0)
                await websocket.send_json(result)

                # After final unlock message, keep camera alive briefly then stop
                if result.get("type") == "unlocked_final":
                    await asyncio.sleep(3.0)   # show ACCESS GRANTED for 3 s
                    stop_event.set()
                    break
            except asyncio.TimeoutError:
                continue
            except Exception as e:
                logger.error(f"result_sender error: {e}")
                break

    # ── Keepalive ─────────────────────────────────────────────────────────────
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
        asyncio.create_task(
            _recognition_worker(result_queue, stop_event, unlock_event)
        ),
    ]

    try:
        while not stop_event.is_set():
            try:
                msg = await asyncio.wait_for(websocket.receive_json(), timeout=1.0)
                if msg.get("type") == "pong":
                    continue
                # "restart" message — browser re-opens the WS for a new scan attempt
            except asyncio.TimeoutError:
                continue
    except WebSocketDisconnect:
        logger.info("Recognition WS disconnected")
    except Exception as e:
        logger.error(f"Recognition WS error: {e}", exc_info=True)
    finally:
        stop_event.set()
        for t in tasks:
            t.cancel()


# ─────────────────────────────────────────────────────────────────────────────
# ENROLLMENT WEBSOCKET
# ─────────────────────────────────────────────────────────────────────────────

@app.websocket("/ws/enroll")
async def websocket_enrollment(websocket: WebSocket):
    await websocket.accept()
    logger.info("Enrollment WS connected")

    ready = await ensure_camera()
    if not ready:
        await websocket.send_json({"type": "error",
                                   "message": "Camera not available on server"})
        await websocket.close()
        return

    loop       = asyncio.get_event_loop()
    stop_event = asyncio.Event()

    reg_name:       str  = ""
    reg_embeddings: list = []
    is_scanning:    bool = False

    # ── Frame sender ──────────────────────────────────────────────────────────
    async def frame_sender():
        FRAME_INTERVAL = 1 / 15   # 15 FPS for enrollment preview
        last_ts = 0.0
        while not stop_event.is_set():
            try:
                now = time.monotonic()
                if now - last_ts < FRAME_INTERVAL:
                    await asyncio.sleep(0.005)
                    continue
                frame = _get_latest_frame()
                if frame is None:
                    await asyncio.sleep(0.02)
                    continue
                b64 = await loop.run_in_executor(_thread_pool, _encode_jpeg, frame, 55)
                await websocket.send_json({"type": "frame", "image": b64})
                last_ts = time.monotonic()
            except Exception as e:
                logger.error(f"Enroll frame_sender error: {e}")
                break

    frame_task = asyncio.create_task(frame_sender())

    try:
        while True:
            data     = await websocket.receive_json()
            msg_type = data.get("type")

            if msg_type == "register_start":
                reg_name = data.get("name", "").strip()
                if not reg_name:
                    await websocket.send_json({"type": "register_error",
                                               "message": "Name cannot be empty."})
                    continue
                is_scanning    = True
                reg_embeddings = []
                await websocket.send_json({
                    "type":    "status",
                    "message": f"Scanning '{reg_name}'… look straight at the camera.",
                })

            elif msg_type == "register_cancel":
                is_scanning    = False
                reg_embeddings = []
                await websocket.send_json({"type": "status",
                                           "message": "Registration cancelled."})

            elif msg_type == "scan":
                # Client polls for detection result on the latest camera frame
                frame = _get_latest_frame()
                if frame is None:
                    continue

                face_info, bbox, face_count, quality = await loop.run_in_executor(
                    _thread_pool, engine.extract_face_landmarks_and_box, frame
                )
                await websocket.send_json({
                    "type":        "preview",
                    "bbox":        bbox,
                    "face_count":  face_count,
                    "quality_issue": quality,
                })

                # Guidance messages even when not scanning
                if face_info is None and is_scanning:
                    warn_map = {
                        "multiple_faces": "Multiple faces detected — one person only.",
                        "too_small":      "Move closer to the camera.",
                        "too_close":      "Too close — move back a bit.",
                    }
                    msg = warn_map.get(quality, "No face detected — look at the camera.")
                    await websocket.send_json({"type": "warn", "message": msg})
                    continue

                if not is_scanning or face_info is None:
                    continue

                # Quality gates while scanning
                if quality in ("multiple_faces", "too_small", "too_close"):
                    warn_map = {
                        "multiple_faces": "Multiple faces — one person only.",
                        "too_small":      "Move closer.",
                        "too_close":      "Move back a bit.",
                    }
                    await websocket.send_json({"type": "warn",
                                               "message": warn_map[quality]})
                    continue

                embedding = await loop.run_in_executor(
                    _thread_pool, engine.get_embedding, frame, face_info
                )
                if embedding is None:
                    continue

                reg_embeddings.append(embedding)
                progress = int(len(reg_embeddings) * 100 / REGISTRATION_FRAMES_REQUIRED)
                await websocket.send_json({
                    "type":     "register_progress",
                    "progress": progress,
                    "count":    len(reg_embeddings),
                    "total":    REGISTRATION_FRAMES_REQUIRED,
                    "message":  f"Capturing biometric data… {progress}%",
                })

                if len(reg_embeddings) >= REGISTRATION_FRAMES_REQUIRED:
                    mean_emb = np.mean(reg_embeddings, axis=0).reshape(1, -1)
                    success, reason = engine.register_user(reg_name, mean_emb)
                    is_scanning    = False
                    reg_embeddings = []

                    if success:
                        await websocket.send_json({"type": "register_success",
                                                   "name": reg_name})
                    elif reason == "name_taken":
                        await websocket.send_json({
                            "type":    "register_error",
                            "message": f"Nama '{reg_name}' sudah terdaftar. "
                                       f"Hapus akun tersebut dulu jika ingin mendaftar ulang.",
                        })
                    elif reason.startswith("face_exists:"):
                        existing = reason.split(":", 1)[1]
                        await websocket.send_json({
                            "type":    "register_error",
                            "message": f"Wajah ini sudah terdaftar sebagai '{existing}'. "
                                       f"1 wajah hanya boleh untuk 1 akun.",
                        })
                    elif reason.startswith("duplicate:"):
                        dup_name = reason.split(":", 1)[1]
                        await websocket.send_json({
                            "type":    "register_error",
                            "message": f"Wajah terlalu mirip dengan profil '{dup_name}'.",
                        })
                    else:
                        await websocket.send_json({
                            "type":    "register_error",
                            "message": f"Pendaftaran gagal ({reason}).",
                        })

    except WebSocketDisconnect:
        logger.info("Enrollment WS disconnected")
    except Exception as e:
        logger.error(f"Enrollment WS error: {e}", exc_info=True)
    finally:
        stop_event.set()
        frame_task.cancel()


# ─────────────────────────────────────────────────────────────────────────────
# STARTUP
# ─────────────────────────────────────────────────────────────────────────────

@app.on_event("startup")
async def startup_event():
    logger.info("Server starting — pre-warming camera and ML models…")
    await ensure_camera()
    loop = asyncio.get_event_loop()
    await loop.run_in_executor(_thread_pool, lambda: engine.face_detector)
    await loop.run_in_executor(_thread_pool, lambda: engine.face_recognizer)
    logger.info("✅ Ready")


if __name__ == "__main__":
    print("=" * 55)
    print("  Smart Lock API — http://0.0.0.0:5001")
    print("=" * 55)
    uvicorn.run("app:app", host="0.0.0.0", port=5001, log_level="info")