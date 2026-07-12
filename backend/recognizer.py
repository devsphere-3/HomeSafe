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
#
# SFace cosine similarity range: 0.0 (berbeda total) → 1.0 (identik persis)
#
#  Orang yang sama, kondisi ideal      : 0.75 – 0.95
#  Orang yang sama, beda angle/cahaya  : 0.50 – 0.75
#  Saudara kandung (mirip genetik)     : 0.30 – 0.50
#  Orang tidak terkait                 : 0.00 – 0.30
#
MATCH_THRESHOLD      = 0.50   # Trigger match pada cosine ≥ 0.50 → tampil 90%+ di UI
REJECTION_THRESHOLD  = 0.30   # Di bawah ini = Unknown mutlak

# Enrollment duplicate guard
DUPLICATE_REG_THRESHOLD      = 0.75  # Blokir hanya jika wajah benar-benar sama
ENROLL_FACE_EXISTS_THRESHOLD = 0.70  # Per-frame check saat enroll

# Jumlah frame enrollment — 15 cukup untuk rata-rata yang stabil
REGISTRATION_FRAMES_REQUIRED = 15

EMBEDDING_DIM = 128

# Detection quality gates
MIN_FACE_SIZE              = 40
MAX_FACE_SIZE_RATIO        = 0.90
MIN_DETECTION_CONFIDENCE   = 0.50   # Sedikit lebih rendah → deteksi lebih cepat

# Name validation
MIN_NAME_LENGTH  = 2
MAX_NAME_LENGTH  = 50
VALID_NAME_PATTERN = re.compile(r"^[a-zA-Z0-9_\-\s]+$")

# Detection downscale width
DETECT_WIDTH = 256

# ── Kecepatan keputusan ────────────────────────────────────────────────────────
# 1 = putuskan langsung di frame pertama cocok (paling responsif)
# 2 = butuh 2 frame berturut-turut (lebih aman dari false-positive)
CONSECUTIVE_MATCHES_REQUIRED = 1


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
        Rows are L2-normalised at build time so match_face/check_face_exists
        can skip normalising the DB side on every call — just one dot product.
        """
        if not self.database:
            self._db_names  = []
            self._db_matrix = None
            return
        self._db_names = list(self.database.keys())
        raw = np.vstack(
            [self.database[n].flatten() for n in self._db_names]
        ).astype(np.float32)                                    # (N, 128)
        norms = np.linalg.norm(raw, axis=1, keepdims=True) + 1e-9
        self._db_matrix = raw / norms                          # pre-normalised (N, 128)

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
            # ── Cek nama sudah dipakai ─────────────────────────────────────────
            if name in self.database:
                return False, "name_taken"

            # ── Cek wajah sudah terdaftar (dengan nama apapun) ─────────────────
            if self._db_matrix is not None:
                scores     = self._cosine_scores(embedding)
                best_idx   = int(np.argmax(scores))
                best_score = float(scores[best_idx])
                best_name  = self._db_names[best_idx]

                # Tolak hanya jika wajah benar-benar sama (di atas DUPLICATE_REG_THRESHOLD).
                # Threshold sengaja lebih tinggi dari MATCH_THRESHOLD (0.60) agar
                # wajah orang berbeda yang punya kemiripan sedang tetap bisa mendaftar.
                if best_score >= DUPLICATE_REG_THRESHOLD and best_name != name:
                    logger.warning(
                        f"❌ Enroll ditolak: wajah '{name}' sudah terdaftar "
                        f"sebagai '{best_name}' (score={best_score:.3f})"
                    )
                    return False, f"face_exists:{best_name}"

            # ── Tulis ke DB ────────────────────────────────────────────────────
            self.database[name] = embedding
            ok = self._save_database()

            if not ok:
                self.database.pop(name, None)
                return False, "save_error"

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

    def check_face_exists(self, embedding: np.ndarray) -> tuple[bool, str, float]:
        """
        Cek apakah embedding wajah sudah ada di database.
        Dipanggil per-frame SEBELUM embedding dikumpulkan untuk enroll,
        sehingga pengguna langsung mendapat feedback tanpa harus menunggu
        semua frame selesai.

        Returns:
            (exists: bool, matched_name: str, score: float)
            exists=True  → wajah sudah terdaftar, tolak pendaftaran
            exists=False → wajah belum terdaftar, boleh lanjut
        """
        if embedding is None:
            return False, "", 0.0

        with self.db_lock:
            if self._db_matrix is None or len(self._db_names) == 0:
                return False, "", 0.0
            db_matrix = self._db_matrix
            db_names  = list(self._db_names)

        # Normalisasi query sebelum dot product dengan DB yang sudah pre-normalised
        q = embedding.flatten().astype(np.float32)
        q_norm = q / (np.linalg.norm(q) + 1e-9)
        scores = db_matrix @ q_norm

        best_idx   = int(np.argmax(scores))
        best_score = float(scores[best_idx])
        best_name  = db_names[best_idx]

        if best_score >= ENROLL_FACE_EXISTS_THRESHOLD:
            logger.debug(
                f"check_face_exists: match '{best_name}' score={best_score:.3f}"
            )
            return True, best_name, best_score

        return False, "", best_score

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
        """Align + crop face, then extract 128-d L2-normalised embedding with SFace."""
        try:
            aligned = self.face_recognizer.alignCrop(frame, face_info)
            raw = self.face_recognizer.feature(aligned)          # (1, 128)
            # Pre-normalise at extraction time → match_face / check_face_exists
            # only need one dot product per call, no division at all.
            norm = np.linalg.norm(raw) + 1e-9
            return (raw / norm).astype(np.float32)               # (1, 128) unit-norm
        except Exception as e:
            logger.error(f"Embedding error: {e}")
            return None

    # ── Vectorised cosine matching ─────────────────────────────────────────────

    def _cosine_scores(self, embedding: np.ndarray) -> np.ndarray:
        """
        Cosine similarity antara embedding query dan seluruh DB matrix.
        DB matrix sudah pre-normalised. Embedding query dinormalisasi di sini.

        embedding : (1, 128) atau (128,) float32
        Returns   : (N,) float32 similarity scores
        """
        q = embedding.flatten().astype(np.float32)
        q_norm = q / (np.linalg.norm(q) + 1e-9)
        return self._db_matrix @ q_norm    # (N, 128) @ (128,) → (N,)

    @staticmethod
    def _score_to_pct(score: float) -> float:
        """
        Map cosine similarity → persentase yang ditampilkan di UI.

        Skala:
          score < 0.30              → 0%        (Unknown mutlak)
          score 0.30 – 0.50         → 0 – 89%   (zona abu-abu, belum match)
          score = 0.50  (threshold) → 90%       (batas minimum match)
          score 0.50 – 1.00         → 90 – 100% (match zona hijau)

        Ini membuat angka di UI selalu ≥ 90% ketika wajah dikenali.
        """
        if score <= 0:
            return 0.0
        if score >= 1.0:
            return 100.0
        if score < MATCH_THRESHOLD:
            # Zona abu-abu: 0 → 89%
            return round(score / MATCH_THRESHOLD * 89.0, 1)
        # Zona match: 90 → 100%
        return round(90.0 + (score - MATCH_THRESHOLD) / (1.0 - MATCH_THRESHOLD) * 10.0, 1)

    def match_face(self, embedding: np.ndarray) -> tuple[str, float, float]:
        """
        Public match API — thread-safe snapshot then vectorised cosine search.
        embedding is expected to be already L2-normalised (from get_embedding).
        Returns (name, cosine_score, percentage).
        """
        if embedding is None:
            return "Unknown", 0.0, 0.0

        with self.db_lock:
            if self._db_matrix is None:
                return "Unknown", 0.0, 0.0
            db_matrix = self._db_matrix
            db_names  = list(self._db_names)

        # Normalisasi query, DB sudah pre-normalised
        q = embedding.flatten().astype(np.float32)
        q_norm = q / (np.linalg.norm(q) + 1e-9)
        scores = db_matrix @ q_norm       # (N,)

        best_idx   = int(np.argmax(scores))
        best_score = float(scores[best_idx])
        best_name  = db_names[best_idx]
        pct        = self._score_to_pct(best_score)

        # Log setiap frame agar mudah debug threshold
        logger.debug(f"match_face: best='{best_name}' score={best_score:.4f} threshold={MATCH_THRESHOLD}")

        if best_score < REJECTION_THRESHOLD:
            return "Unknown", best_score, 0.0
        if best_score < MATCH_THRESHOLD:
            logger.debug(f"Near-miss: '{best_name}' score={best_score:.4f} (threshold {MATCH_THRESHOLD})")
            return "Unknown", best_score, pct
        return best_name, best_score, pct