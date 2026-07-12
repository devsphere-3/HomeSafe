#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# cleanup_rpi.sh — Jalankan DI DALAM RPi setelah menerima file baru
# Hapus file lama yang sudah tidak diperlukan
#
# Cara pakai (di RPi):
#   bash cleanup_rpi.sh
# ─────────────────────────────────────────────────────────────────────────────

set -e
cd "$(dirname "$0")"

echo ""
echo "======================================================"
echo "  HomeSafe RPi — Cleanup file lama"
echo "======================================================"
echo ""

# File yang aman dihapus
OLD_FILES=(
    "benchmark.py"
    "camera_test.py"
    "detect_cameras.py"
    "servo_calibration.py"
    "test_buzzer.py"
    "test_hardware.py"
    "code-workspace.code-workspace"
    "uvicorn_config.py"
)

echo "🗑️  File yang akan dihapus:"
for f in "${OLD_FILES[@]}"; do
    if [ -f "$f" ]; then
        echo "   - $f"
    fi
done

echo ""
read -p "Lanjut hapus? (y/N) " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "Dibatalkan."
    exit 0
fi

echo ""
for f in "${OLD_FILES[@]}"; do
    if [ -f "$f" ]; then
        rm "$f"
        echo "   ✅ Dihapus: $f"
    else
        echo "   ⏭️  Skip (tidak ada): $f"
    fi
done

# Set permission
chmod +x start.sh 2>/dev/null && echo "" && echo "✅ start.sh sudah executable"

echo ""
echo "======================================================"
echo "  Struktur akhir:"
ls -la --color=never | grep -v "^total" | grep -v "^d" | awk '{print "  "$NF}'
echo ""
echo "  ✅ Cleanup selesai!"
echo "  Jalankan server: bash start.sh"
echo "======================================================"
echo ""
