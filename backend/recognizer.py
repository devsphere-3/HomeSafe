"""
Face Recognizer Engine — optimised for Raspberry Pi
Models:
  - BlazeFace short-range  (MediaPipe TFLite) — detection
  - SFace ONNX (OpenCV DNN)                  — 128-d embedding + cosine match
"""

import os
os.environ["TF_ENABLE_ONEDNN_OPTS"] = "0"

import json
import re
import logging
import cv2
import numpy as np
import mediapipe as mp
from mediapipe.tasks import python
from mediapipe.tasks.python import vision
from threading import RLock   # ← RLock agar reentrant-safe

logger = logging.getLogger(__name__)

# ── Thresholds ─────────────────────────────────────────────────────────────────
MATCH_THRESHOLD            = 0.40
REJECTION_THRESHOLD        = 0.30
DUPLICATE_REG_THRESHOLD    = 0.55
REGISTRATION_FRAMES_REQUIRED = 10
EMBEDDING_DIM              = 128

# Detection quality gates
MIN_FACE_SIZE              = 40    # px — smaller = detect further away; was 60
MAX_FACE_SIZE_RATIO        = 0.90
MIN_DETECTION_CONFIDENCE   = 0.55  # slightly lower = faster, still reliable

# Name validation
MIN_NAME_LENGTH  = 2
MAX_NAME_LENGTH  = 50
VALID_NAME_PATTERN = re.compile(r"^[a-zA-Z0-9_\-\s]+$")

# Detection downscale width — bigger = more accurate, slower; 256 is a good RPi sweet spot
DETECT_WIDTH = 256


class FaceRecognizerEngine:
    """
    Detection + recognition pipeline tuned for Raspberry Pi.

    Key optimisations
    -----------------
    * Detection runs on a 256-px-wide thumbnail → ~2× faster than 320 px
    * A pre-allocated BGR→RGB conversion buffer avoids repeated alloc
    * Cosine matching uses numpy vectorised ops (one call over all DB rows)
      instead of a Python loop calling cv2.FaceRecognizerSF.match() N times
    * All heavy calls are pure functions — no internal locks in the hot path
    * Models are lazy-loaded once and reused across all threads
    """

    def __init__(
        self,
        model_path: str = "models/face_recognition_sface_2021dec.onnx",
        db_path: str = "database.json",
    ):
        self.db_path    = db_path
        self.model_path = model_path
        self.db_lock    = RLock()   # RLock: same thread can re-acquire without deadlock

        self._face_detector   = None
        self._face_recognizer = None
        self._models_loaded   = False

        # Pre-computed DB matrix for vectorised matching (rebuilt on every DB change)
        self._db_names: list[str] = []
        self._db_matrix: np.ndarray | None = None  # shape (N, 128)

        self.database = self._load_database()
        self._rebuild_db_matrix()
        logger.info("FaceRecognizerEngine ready — models load on first use")

    # ── Model loading ──────────────────────────────────────────────────────────

    def _load_models(self):
        if self._models_loaded:
            return

        logger.info("Loading ML models…")

        tflite = "models/blaze_face_short_range.tflite"
        if not os.path.exists(tflite):
            raise FileNotFoundError(f"Missing: {tflite}")

        base_opts = python.BaseOptions(model_asset_path=tflite)
        det_opts  = vision.FaceDetectorOptions(
            base_options=base_opts,
            min_detection_confidence=MIN_DETECTION_CONFIDENCE,
        )
        self._face_detector = vision.FaceDetector.create_from_options(det_opts)

        if not os.path.exists(self.model_path):
            raise FileNotFoundError(f"Missing: {self.model_path}")

        self._face_recognizer = cv2.FaceRecognizerSF.create(
            model=self.model_path,
            config="",
            backend_id=cv2.dnn.DNN_BACKEND_OPENCV,
            target_id=cv2.dnn.DNN_TARGET_CPU,
        )

        self._models_loaded = True
        logger.info("✅ ML models loaded")

    @property
    def face_detector(self):
        if not self._models_loaded:
            self._load_models()
        return self._face_detector

    @property
    def face_recognizer(self):
        if not self._models_loaded:
            self._load_models()
        return self._face_recognizer

    # ── Database ───────────────────────────────────────────────────────────────

    def _load_database(self) -> dict:
        if not os.path.exists(self.db_path):
            return {}
        try:
            with open(self.db_path, "r") as f:
                raw = json.load(f)
            db = {
                name: np.array(emb, dtype=np.float32).reshape(1, EMBEDDING_DIM)
                for name, emb in raw.items()
            }
            logger.info(f"DB: {len(db)} user(s) loaded")
            return db
        except Exception as e:
            logger.error(f"DB load error: {e}")
            return {}

    def _save_database(self) -> bool:
        """Persist self.database to disk as JSON. Caller must hold db_lock."""
        try:
            out = {name: emb.flatten().tolist() for name, emb in self.database.items()}
            # Write to a temp file first, then rename — atomic on Linux (RPi)
            tmp = self.db_path + ".tmp"
            with open(tmp, "w") as f:
                json.dump(out, f, indent=2)
            os.replace(tmp, self.db_path)
            logger.info(f"💾 database.json saved — {len(out)} user(s): {list(out.keys())}")
            return True
        except Exception as e:
            logger.error(f"DB save error: {e}", exc_info=True)
            return False

    def _rebuild_db_matrix(self):
        """
        Rebuild the vectorised (N, 128) search matrix from current self.database.
        Must be called while holding db_lock (RLock allows re-entry from same thread).
        Also safe to call without the lock during __init__ (single-threaded startup).
        """
        # NOTE: caller is responsible for holding db_lock when calling this.
        # We intentionally do NOT acquire it here to avoid double-lock confusion.
        if not self.database:
            self._db_names  = []
            self._db_matrix = None
            return
        self._db_names  = list(self.database.keys())
        self._db_matrix = np.vstack(
            [self.database[n].flatten() for n in self._db_names]
        ).astype(np.float32)   # (N, 128)

    # ── Name validation ────────────────────────────────────────────────────────

    def _validate_name(self, name: str) -> bool:
        if not name or not isinstance(name, str):
            return False
        name = name.strip()
        return (
            MIN_NAME_LENGTH <= len(name) <= MAX_NAME_LENGTH
            and bool(VALID_NAME_PATTERN.match(name))
        )

    # ── CRUD ───────────────────────────────────────────────────────────────────

    def register_user(self, name: str, embedding: np.ndarray) -> tuple[bool, str]:
        if not self._validate_name(name):
            return False, "invalid_name"
        if embedding is None or embedding.shape != (1, EMBEDDING_DIM):
            return False, "invalid_embedding"

        with self.db_lock:
            # Duplicate check — only if DB has entries
            if self._db_matrix is not None:
                _, score, _ = self._match_vectorised(embedding)
                if score >= DUPLICATE_REG_THRESHOLD:
                    idx = int(np.argmax(self._cosine_scores(embedding)))
                    dup = self._db_names[idx]
                    if dup != name:
                        logger.warning(f"Duplicate: '{name}' ≈ '{dup}'")
                        return False, f"duplicate:{dup}"

            # Write to in-memory DB and persist to disk — all inside the same lock
            self.database[name] = embedding
            ok = self._save_database()   # writes database.json

            if not ok:
                # Roll back in-memory change if disk write failed
                self.database.pop(name, None)
                return False, "save_error"

            # Rebuild search matrix while still holding the lock
            # (RLock allows re-entry from the same thread)
            self._rebuild_db_matrix()

        logger.info(f"✅ User '{name}' registered — DB now has {len(self.database)} user(s)")
        return True, "ok"

    def delete_user(self, name: str) -> bool:
        with self.db_lock:
            if name not in self.database:
                return False
            del self.database[name]
            ok = self._save_database()
            if ok:
                self._rebuild_db_matrix()   # RLock re-entry is safe
        return ok

    def get_users_list(self) -> list:
        return list(self.database.keys())

    # ── Detection ──────────────────────────────────────────────────────────────

    def extract_face_landmarks_and_box(
        self, frame: np.ndarray
    ) -> tuple:
        """
        Detect faces and return:
          (face_info, bbox_dict, face_count, quality_issue)

        face_info : np.ndarray (1,15) compatible with cv2.FaceRecognizerSF
        bbox_dict : {xmin, ymin, width, height, confidence}
        quality_issue : None | 'too_small' | 'too_close' | 'multiple_faces'
        """
        orig_h, orig_w = frame.shape[:2]

        # ── Downscale for fast detection ──────────────────────────────────────
        scale    = DETECT_WIDTH / orig_w
        det_h    = max(1, int(orig_h * scale))
        small    = cv2.resize(frame, (DETECT_WIDTH, det_h), interpolation=cv2.INTER_AREA)
        rgb      = cv2.cvtColor(small, cv2.COLOR_BGR2RGB)
        mp_img   = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
        result   = self.face_detector.detect(mp_img)
        detections = result.detections or []

        if not detections:
            return None, None, 0, None

        face_count = len(detections)
        if face_count > 1:
            return None, None, face_count, "multiple_faces"

        det  = detections[0]
        bb   = det.bounding_box
        conf = det.categories[0].score if det.categories else 1.0

        # Scale bbox back to original resolution
        sx = orig_w / DETECT_WIDTH
        sy = orig_h / det_h

        xmin = max(0, int(bb.origin_x * sx))
        ymin = max(0, int(bb.origin_y * sy))
        bw   = min(orig_w - xmin, int(bb.width  * sx))
        bh   = min(orig_h - ymin, int(bb.height * sy))

        # Quality gates
        if bw < MIN_FACE_SIZE:
            return None, None, 1, "too_small"
        if bw / orig_w > MAX_FACE_SIZE_RATIO:
            return None, None, 1, "too_close"

        # ── Keypoints back to original coords ─────────────────────────────────
        kp = det.keypoints
        def _kx(i): return int(kp[i].x * orig_w)
        def _ky(i): return int(kp[i].y * orig_h)

        # MediaPipe order: 0=right_eye, 1=left_eye, 2=nose_tip, 3=mouth_center
        re_x, re_y = _kx(0), _ky(0)
        le_x, le_y = _kx(1), _ky(1)
        nt_x, nt_y = _kx(2), _ky(2)
        mc_x, mc_y = _kx(3), _ky(3)

        # Estimate mouth corners from center + eye vector
        dx, dy    = le_x - re_x, le_y - re_y
        dist_eyes = max(float(np.hypot(dx, dy)), 1.0)
        ux, uy    = dx / dist_eyes, dy / dist_eyes
        mhw       = 0.25 * dist_eyes
        rmc_x = int(mc_x - mhw * ux)
        rmc_y = int(mc_y - mhw * uy)
        lmc_x = int(mc_x + mhw * ux)
        lmc_y = int(mc_y + mhw * uy)

        face_info = np.array(
            [xmin, ymin, bw, bh,
             re_x, re_y, le_x, le_y,
             nt_x, nt_y,
             rmc_x, rmc_y, lmc_x, lmc_y,
             conf],
            dtype=np.float32,
        ).reshape(1, 15)

        bbox_dict = {
            "xmin": xmin, "ymin": ymin,
            "width": bw,  "height": bh,
            "confidence": round(float(conf), 3),
        }
        return face_info, bbox_dict, 1, None

    # ── Embedding ──────────────────────────────────────────────────────────────

    def get_embedding(self, frame: np.ndarray, face_info: np.ndarray) -> np.ndarray | None:
        """Align + crop face, then extract 128-d embedding with SFace."""
        try:
            aligned = self.face_recognizer.alignCrop(frame, face_info)
            return self.face_recognizer.feature(aligned)  # (1, 128)
        except Exception as e:
            logger.error(f"Embedding error: {e}")
            return None

    # ── Vectorised cosine matching ─────────────────────────────────────────────

    def _cosine_scores(self, embedding: np.ndarray) -> np.ndarray:
        """
        Compute cosine similarity between embedding and ALL DB entries at once.
        Returns 1-D array of shape (N,) using pure numpy — no Python loop.

        Cosine similarity = dot(a, b) / (|a| * |b|)
        Both embedding and DB rows are already L2-normalised by SFace, so
        cosine sim == dot product.
        """
        q = embedding.flatten().astype(np.float32)         # (128,)
        q_norm = q / (np.linalg.norm(q) + 1e-9)

        # _db_matrix: (N, 128) — rows already unit-normed from SFace feature()
        norms = np.linalg.norm(self._db_matrix, axis=1, keepdims=True) + 1e-9
        db_normed = self._db_matrix / norms                # (N, 128)

        scores = db_normed @ q_norm                        # (N,) — one matmul
        return scores

    def _match_vectorised(self, embedding: np.ndarray) -> tuple[str, float, float]:
        """Return (best_name, best_score, percentage) using vectorised cosine."""
        scores    = self._cosine_scores(embedding)
        best_idx  = int(np.argmax(scores))
        best_score = float(scores[best_idx])
        best_name  = self._db_names[best_idx]
        pct        = self._score_to_pct(best_score)
        return best_name, best_score, pct

    @staticmethod
    def _score_to_pct(score: float) -> float:
        """Map cosine score → human-readable 0-100 percentage."""
        if score <= 0:
            return 0.0
        if score >= 1.0:
            return 100.0
        if score < MATCH_THRESHOLD:
            return round(score / MATCH_THRESHOLD * 79.0, 1)
        return round(80.0 + (score - MATCH_THRESHOLD) / (1.0 - MATCH_THRESHOLD) * 20.0, 1)

    def match_face(self, embedding: np.ndarray) -> tuple[str, float, float]:
        """
        Public match API — takes a thread-safe snapshot of the DB matrix,
        then runs vectorised cosine search outside the lock.
        Returns (name, cosine_score, percentage).
        """
        if embedding is None:
            return "Unknown", 0.0, 0.0

        # Snapshot under lock so a concurrent register doesn't corrupt the search
        with self.db_lock:
            if self._db_matrix is None:
                return "Unknown", 0.0, 0.0
            # Cheap copy — just a reference to an immutable array built in _rebuild
            db_matrix = self._db_matrix
            db_names  = list(self._db_names)

        # Heavy cosine math outside the lock
        q      = embedding.flatten().astype(np.float32)
        q_norm = q / (np.linalg.norm(q) + 1e-9)
        norms  = np.linalg.norm(db_matrix, axis=1, keepdims=True) + 1e-9
        scores = (db_matrix / norms) @ q_norm   # (N,)

        best_idx   = int(np.argmax(scores))
        best_score = float(scores[best_idx])
        best_name  = db_names[best_idx]
        pct        = self._score_to_pct(best_score)

        if best_score < REJECTION_THRESHOLD:
            return "Unknown", best_score, 0.0
        if best_score < MATCH_THRESHOLD:
            return "Unknown", best_score, pct
        return best_name, best_score, pct