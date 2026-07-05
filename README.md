# HomeSafe — Sistem Kunci Pintu Berbasis Face Recognition

> Proyek tugas akhir / prototype sistem keamanan pintu cerdas menggunakan Raspberry Pi, kamera, dan pengenalan wajah secara real-time.

---

## Daftar Isi

- [Gambaran Sistem](#gambaran-sistem)
- [Arsitektur](#arsitektur)
- [Hardware](#hardware)
- [Struktur Proyek](#struktur-proyek)
- [Prasyarat](#prasyarat)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Menjalankan Sistem](#menjalankan-sistem)
- [Halaman Web](#halaman-web)
- [API Reference](#api-reference)
- [WebSocket Protocol](#websocket-protocol)
- [GPIO & Hardware](#gpio--hardware)
- [Troubleshooting](#troubleshooting)

---

## Gambaran Sistem

HomeSafe adalah sistem kunci pintu otomatis yang bekerja dengan cara:

1. **Kamera pintu** menangkap wajah yang mendekati pintu secara real-time
2. **Raspberry Pi** menjalankan model ML untuk mendeteksi dan mengenali wajah
3. Jika wajah **dikenali**, servo motor membuka kunci pintu selama 5 detik
4. **LED** dan **buzzer** memberikan feedback visual dan audio
5. **Kamera CCTV** kedua memantau area halaman dengan deteksi gerakan
6. **Dashboard web** (Laravel) menampilkan feed kedua kamera, riwayat akses, dan log gerakan secara real-time

---

## Arsitektur

```
┌─────────────────────────────────┐      HTTP/WS       ┌──────────────────────┐
│        Raspberry Pi 4           │ ◄────────────────► │   Laravel (Server)   │
│                                 │                    │   PHP 8.2 / Laravel  │
│  ┌──────────┐  ┌─────────────┐  │                    │   12.x               │
│  │ Camera 0 │  │  Camera 2   │  │                    │   port 8000          │
│  │ (Pintu)  │  │  (CCTV)     │  │                    └──────────────────────┘
│  │ 320x240  │  │  640x480    │  │                             │
│  └────┬─────┘  └──────┬──────┘  │                             │
│       │               │         │                    ┌────────▼─────────────┐
│  ┌────▼───────────────▼──────┐  │                    │   Browser Client     │
│  │   FastAPI (app.py)        │  │                    │   WebSocket streams  │
│  │   Python 3.11             │  │                    │   Dashboard UI       │
│  │   port 5001               │  │                    └──────────────────────┘
│  │                           │  │
│  │  • Face Detection         │  │
│  │    (BlazeFace TFLite)     │  │
│  │  • Face Recognition       │  │
│  │    (SFace ONNX)           │  │
│  │  • Motion Detection       │  │
│  └───────────┬───────────────┘  │
│              │                  │
│  ┌───────────▼───────────────┐  │
│  │   GPIO Hardware           │  │
│  │   • Servo SG90 (PIN 18)   │  │
│  │   • LED Hijau  (PIN 27)   │  │
│  │   • LED Merah  (PIN 22)   │  │
│  │   • Buzzer     (PIN 23)   │  │
│  └───────────────────────────┘  │
└─────────────────────────────────┘
```

---

## Hardware

### Komponen Utama

| Komponen | Spesifikasi | Fungsi |
|---|---|---|
| **Raspberry Pi 4 Model B** | 2GB/4GB RAM | Prosesor utama |
| **USB Webcam (×2)** | Logitech C270 HD | Kamera pintu + CCTV |
| **Servo Motor** | SG90 | Aktuator kunci pintu |
| **LED Hijau** | 5mm, 3.3V | Indikator akses diterima |
| **LED Merah** | 5mm, 3.3V | Indikator pintu terkunci |
| **Buzzer Aktif** | 5V | Sinyal audio |
| **Resistor** | 330Ω (×2) | Pembatas arus LED |

### Wiring GPIO

```
Raspberry Pi GPIO (BCM numbering)
═══════════════════════════════════════

Servo SG90:
  Signal (oranye)  → GPIO 18 (Pin fisik 12)
  VCC    (merah)   → Pin 2 atau 4 (5V) ⚠️ BUKAN 3.3V
  GND    (coklat)  → Pin 6 (GND)

LED Hijau (via resistor 330Ω):
  Anoda  → GPIO 27 (Pin fisik 13)
  Katoda → GND

LED Merah (via resistor 330Ω):
  Anoda  → GPIO 22 (Pin fisik 15)
  Katoda → GND

Buzzer Aktif:
  + (positif) → GPIO 23 (Pin fisik 16)
  - (negatif) → GND
```

> **Catatan:** Servo SG90 membutuhkan 5V. Jika servo bergetar atau tidak stabil, gunakan power supply eksternal 5V terpisah dan hubungkan GND-nya ke GND Raspberry Pi.

### Duty Cycle Servo

| Sudut | Duty Cycle | Status |
|---|---|---|
| 0° | 2.5 | **TERKUNCI** (default) |
| 90° | 7.5 | **TERBUKA** |

Formula: `duty = 2.5 + (angle / 18)`

---

## Struktur Proyek

```
HomeSafe/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── FaceRecognitionController.php   # Controller Laravel
│   └── Models/
│       └── User.php
├── backend/                                     # FastAPI (dijalankan di Pi)
│   ├── app.py                                   # Server utama
│   ├── recognizer.py                            # Engine face recognition
│   ├── requirements.txt                         # Dependensi Python
│   ├── download_model.py                        # Download model ML
│   └── history/                                 # Snapshot akses (auto-generated)
├── config/
│   └── app.php                                  # Konfigurasi backend_url
├── models/                                      # Model ML (di Pi)
│   ├── blaze_face_short_range.tflite            # BlazeFace detector
│   └── face_recognition_sface_2021dec.onnx      # SFace recognizer
├── resources/
│   └── views/
│       ├── layouts/
│       │   └── app.blade.php                    # Layout utama
│       └── recognition/
│           ├── index.blade.php                  # Dashboard utama (2 kamera)
│           ├── enroll.blade.php                 # Pendaftaran wajah
│           ├── history.blade.php                # Riwayat akses
│           └── users.blade.php                  # Daftar pengguna terdaftar
├── routes/
│   └── web.php                                  # Routing Laravel
├── .env                                         # Konfigurasi environment
└── .env.ex                                      # Template environment
```

---

## Prasyarat

### Raspberry Pi (Backend)

- Raspberry Pi OS Bullseye atau Bookworm (64-bit)
- Python 3.11+
- pip / virtualenv

```bash
# Cek versi Python
python3 --version

# Install dependensi sistem
sudo apt update
sudo apt install -y python3-pip python3-venv libopencv-dev
sudo apt install -y python3-rpi.gpio   # untuk GPIO
```

### Server/PC (Laravel Frontend)

- PHP 8.2+
- Composer
- Node.js 18+ & npm
- Git

---

## Instalasi

### 1. Clone Repository

```bash
git clone https://github.com/<username>/HomeSafe.git
cd HomeSafe
```

### 2. Setup Backend (Raspberry Pi)

```bash
cd backend

# Buat virtual environment
python3 -m venv venv
source venv/bin/activate

# Install dependensi
pip install -r requirements.txt

# Download model ML
python3 download_model.py
```

### 3. Setup Frontend (Laravel)

```bash
# Di root proyek
composer install

# Copy environment file
cp .env.ex .env

# Generate app key
php artisan key:generate

# Install asset
npm install
npm run build

# Jalankan migrasi database
php artisan migrate
```

---

## Konfigurasi

### File `.env` (wajib diubah)

```dotenv
# URL FastAPI di Raspberry Pi — sesuaikan dengan IP Pi kamu
BACKEND_URL=http://172.20.10.3:5001

# Password untuk halaman enrollment
ENROLL_PASSWORD=homesafe123
```

### Konfigurasi Kamera di Backend

Edit variabel berikut di `backend/app.py` jika index kamera berbeda:

```python
_door_cam_id: int = 0   # /dev/video0 — kamera face recognition
_yard_cam_id: int = 2   # /dev/video2 — kamera CCTV
```

### Kalibrasi Servo

Edit konstanta berikut di `backend/app.py` sesuai hasil kalibrasi fisik:

```python
LOCK_ANGLE  = 0     # Derajat posisi TERKUNCI
OPEN_ANGLE  = 90    # Derajat posisi TERBUKA
OPEN_TIME   = 5     # Detik pintu terbuka sebelum dikunci kembali
```

---

## Menjalankan Sistem

### Backend (Raspberry Pi)

```bash
cd ~/HomeSafe/backend
source venv/bin/activate
python app.py
```

Log startup yang diharapkan:

```
✅ RPi.GPIO tersedia — mode hardware aktif
✅ GPIO diinisialisasi — Servo=PIN18 (50Hz, 0° dc=2.50 TERKUNCI)
✅ Camera 0 (/dev/video0) ready — 320x240 @ 30FPS
✅ Camera 2 (/dev/video2) ready — 640x480 @ 30FPS
✅ Ready
INFO: Application startup complete.
INFO: Uvicorn running on http://0.0.0.0:5001
```

### Frontend (Server/PC)

```bash
cd HomeSafe
php artisan serve
```

Akses dashboard: `http://localhost:8000`

### Menjalankan dengan Systemd (Production di Pi)

Buat file service agar backend otomatis jalan saat Pi dinyalakan:

```bash
sudo nano /etc/systemd/system/homesafe.service
```

```ini
[Unit]
Description=HomeSafe Face Recognition Backend
After=network.target

[Service]
User=admin
WorkingDirectory=/home/admin/HomeSafe/backend
ExecStart=/home/admin/HomeSafe/backend/venv/bin/python app.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable homesafe
sudo systemctl start homesafe
sudo systemctl status homesafe
```

---

## Halaman Web

| URL | Halaman | Deskripsi |
|---|---|---|
| `/` | Dashboard | Feed 2 kamera real-time, riwayat akses, log gerakan |
| `/enroll` | Pendaftaran | Daftarkan wajah baru ke sistem |
| `/users` | Pengguna | Daftar wajah terdaftar, hapus pengguna |
| `/history` | Riwayat | Riwayat lengkap akses pintu dengan foto |

---

## API Reference

Base URL: `http://<IP_PI>:5001`

### Kamera

| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET` | `/api/cameras` | List semua video node yang terdeteksi |
| `POST` | `/api/cameras/probe` | Re-enumerate kamera |
| `POST` | `/api/cameras/door/{id}` | Set kamera face recognition |
| `POST` | `/api/cameras/yard/{id}` | Set kamera CCTV |

**Response `GET /api/cameras`:**

```json
{
  "cameras": [
    {
      "id": 0,
      "node": "/dev/video0",
      "name": "USB Camera 0",
      "available": true,
      "resolution": "320x240"
    }
  ],
  "door_cam_id": 0,
  "yard_cam_id": 2
}
```

### Pengguna

| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET` | `/api/users` | List semua pengguna terdaftar |
| `DELETE` | `/api/users/{name}` | Hapus pengguna |

### Riwayat Akses

| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET` | `/api/history?limit=50` | Ambil riwayat akses |
| `DELETE` | `/api/history` | Hapus semua riwayat |

### Static Files

| Endpoint | Deskripsi |
|---|---|
| `GET /history/{filename}` | Akses foto snapshot akses (format WebP) |

---

## WebSocket Protocol

### `/ws` — Face Recognition (Kamera Pintu)

**Server → Client:**

```json
// Frame video (base64 JPEG)
{ "type": "frame", "image": "<base64>" }

// Hasil deteksi
{
  "type": "result",
  "face_detected": true,
  "face_count": 1,
  "quality_issue": null,
  "bbox": { "xmin": 80, "ymin": 40, "width": 120, "height": 140, "confidence": 0.97 },
  "matched": true,
  "name": "Budi",
  "similarity": 0.87,
  "percentage": 94.2,
  "process_time_ms": 145.3,
  "unlocked": true
}

// Akses diterima (unlock final)
{
  "type": "unlocked_final",
  "name": "Budi",
  "percentage": 94.2,
  "timestamp": "2026-07-05T14:30:00",
  "image": "2026-07-05T14-30-00_Budi.webp"
}

// Keepalive
{ "type": "ping" }
```

**Client → Server:**

```json
{ "type": "pong" }
```

---

### `/ws/motion` — CCTV Motion Detection (Kamera Halaman)

**Server → Client:**

```json
// Frame + status motion (digabung dalam satu pesan)
{
  "type": "frame",
  "image": "<base64>",
  "motion": true,
  "motion_ratio": 3.45
}
```

> `motion_ratio` adalah persentase pixel yang berubah (0–100). Alert ditampilkan jika `motion_ratio >= 2.0` (≥2% area bergerak).

---

### `/ws/enroll` — Face Enrollment

**Client → Server:**

```json
{ "type": "register_start", "name": "Nama Pengguna" }
{ "type": "scan" }
{ "type": "register_cancel" }
```

**Server → Client:**

```json
{ "type": "frame", "image": "<base64>" }
{ "type": "preview", "bbox": {...}, "face_count": 1, "quality_issue": null }
{ "type": "register_progress", "progress": 60, "count": 6, "total": 10 }
{ "type": "register_success", "name": "Nama Pengguna" }
{ "type": "register_error", "message": "Wajah sudah terdaftar" }
{ "type": "warn", "message": "Terlalu jauh dari kamera" }
```

---

## GPIO & Hardware

### Alur Kerja saat Wajah Dikenali

```
Wajah terdeteksi & dikenali
         │
         ▼
┌─────────────────────┐
│  LED Hijau ON       │  ← feedback visual akses diterima
│  LED Merah OFF      │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  Buzzer 2x beep     │  ← 0.15s ON, 0.10s OFF, 0.15s ON
│  (konfirmasi audio) │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  Servo → 90°        │  ← pintu terbuka
│  (OPEN_ANGLE)       │
└─────────┬───────────┘
          │
          ▼ (tunggu OPEN_TIME = 5 detik)
          │
          ▼
┌─────────────────────┐
│  Servo → 0°         │  ← pintu dikunci kembali
│  (LOCK_ANGLE)       │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  LED Merah ON       │  ← kembali ke kondisi terkunci
│  LED Hijau OFF      │
└─────────────────────┘
```

### Kondisi Default (Fail-Secure)

Saat startup atau jika terjadi error, sistem selalu kembali ke kondisi aman:
- Servo → 0° (terkunci)
- LED Merah → ON
- LED Hijau → OFF
- Buzzer → OFF

---

## Troubleshooting

### Kamera tidak terbuka

```bash
# Cek device yang terdeteksi
ls -la /dev/video*

# Cek apakah proses lain sedang pakai kamera
sudo fuser /dev/video0 /dev/video2

# Bebaskan kamera
sudo fuser -k /dev/video0 /dev/video2

# Cek apakah user punya akses
groups $USER
sudo usermod -aG video $USER   # jika belum ada
```

### GPIO: "channel already in use"

Terjadi saat proses lama crash tanpa cleanup. Sudah ditangani otomatis oleh `GPIO.setwarnings(False)` di kode. Jika masih muncul:

```bash
sudo systemctl restart homesafe
```

### Servo tidak bergerak / bergetar

1. Pastikan VCC servo dihubungkan ke **5V**, bukan 3.3V
2. Jika Pi tidak mampu supply arus: gunakan power supply eksternal 5V, hubungkan GND bersama
3. Sesuaikan `LOCK_ANGLE` dan `OPEN_ANGLE` via kalibrasi

### Backend tidak bisa diakses dari Laravel

```bash
# Cek IP Pi saat ini
hostname -I

# Update .env Laravel
# BACKEND_URL=http://<IP_BARU>:5001

php artisan config:clear
```

> Saat menggunakan **iPhone hotspot**, IP Pi bisa berubah setiap kali hotspot dimatikan. Selalu cek dengan `hostname -I` sebelum koneksi.

### Face recognition tidak akurat

- Pastikan pencahayaan cukup dan merata
- Wajah harus menghadap kamera langsung (frontal)
- Jarak optimal: 30–80 cm dari kamera
- Jika perlu, hapus profil lama dan daftar ulang:
  ```
  /users → pilih nama → Hapus → /enroll → daftar ulang
  ```

### Motion detection terlalu sensitif / tidak sensitif

Edit threshold di `backend/app.py`:

```python
MOTION_THRESHOLD = 0.02   # 2% = default
# Naikkan (0.05) untuk kurangi false positive
# Turunkan (0.01) untuk lebih sensitif
```

---

## Tech Stack

| Layer | Teknologi |
|---|---|
| **Frontend** | Laravel 12, Blade, Tailwind CSS, Vanilla JS |
| **Backend** | FastAPI, Python 3.11, uvicorn |
| **Face Detection** | MediaPipe BlazeFace (TFLite) |
| **Face Recognition** | OpenCV SFace (ONNX) |
| **Motion Detection** | OpenCV frame differencing |
| **Realtime** | WebSocket (native browser API) |
| **Hardware** | RPi.GPIO, PWM servo |
| **Database** | SQLite (Laravel session/cache) + JSON flat file (history) |
| **ML Models** | `blaze_face_short_range.tflite`, `face_recognition_sface_2021dec.onnx` |

---

## Lisensi

Proyek ini dibuat untuk keperluan Project Based Learning
