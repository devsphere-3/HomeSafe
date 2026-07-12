#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# HomeSafe Backend — start script untuk Raspberry Pi
# Jalankan: bash start.sh
# ─────────────────────────────────────────────────────────────────────────────

set -e
cd "$(dirname "$0")"

# ── Pastikan virtual environment ada ─────────────────────────────────────────
if [ ! -d ".venv" ]; then
    echo "📦 Membuat virtual environment..."
    python3 -m venv .venv
fi

source .venv/bin/activate

# ── Install / update dependencies ─────────────────────────────────────────────
echo "📦 Memeriksa dependencies..."
pip install -q -r requirements.txt

# ── Download model jika belum ada ─────────────────────────────────────────────
if [ ! -f "models/blaze_face_short_range.tflite" ] || \
   [ ! -f "models/face_recognition_sface_2021dec.onnx" ]; then
    echo "📥 Mengunduh model ML..."
    python3 download_model.py
fi

# ── Jalankan server ───────────────────────────────────────────────────────────
echo ""
echo "======================================================"
echo "  HomeSafe Backend — http://0.0.0.0:5001"
echo "======================================================"
exec uvicorn app:app \
    --host 0.0.0.0 \
    --port 5001 \
    --log-level info \
    --ws-ping-interval 0 \
    --ws-max-size 8388608
